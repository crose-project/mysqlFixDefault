<?php

#
# Script parses a MySQL DB-scheme dump and creates updates for all columns which do not have a default value defined.
# 
# Usage:
# - Create DB scheme dump via phpMyAdmin or 'mysqldump --no-data DB_NAME > scheme.sql'.
# - Call: `php mysqlFixDefault.php scheme.sql [-e]` > schemeUpdate.sql`
# - Play `schemeUpdate.sql` in phpMyAdmin or via 'mysql DB_NAME < schemeUpdate.sql'.
#
# Options:
# - -e : Extended mode - also overwrite existing empty defaults for text/varchar columns
#
# Note: 
# - for enum/set the first value will be taken as default. 
#   - If the first value is not '', the update statement will be listed at the end of the output - to make it easier to check if the assumption is ok.
# - Columns with NULL and without DEFAULT get the same DEFAULT as NOT NULL columns (e.g.: the default will not be NULL!)
#
# What it does: Create column update statements ...
# - text,varchar without default. DEFAULT: ''.
# - datetime without default. DEFAULT: '0000-00-00 00:00:00'.
# - date without default. DEFAULT: '0000-00-00'.
# - time without default. DEFAULT: '00:00:00'.
# - int without default. DEFAULT: 0.
# - enum,set without default. DEFAULT: <first value of enum/set definition>.
#
#  Example statements from scheme:
#  `date_of_birth` date DEFAULT NULL,
#  `project_start` date NOT NULL DEFAULT '0000-00-00',  
#  `multidisciplinarity` enum('','yes','no') NOT NULL, 
#  `id` int(11) NOT NULL,
#
#  Numeration keys:
#      0       1   2   3      4            5
#  `lastRun` date NOT NULL,
#  `lastRun` date NULL, 
#  `lastRun` date NOT NULL   DEFAULT '0000-00-00 00:00:00',
#  `lastRun` date NULL DEFAULT '0000-00-00 00:00:00',
#  `modified` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
#

$updateEnumSet='';
$dirty=false;

$extendedMode=false;

# Parse command line arguments
if (isset($argv[2]) && $argv[2] == '-e') {
    $extendedMode = true;
} elseif (isset($argv[1]) && $argv[1] == '-e') {
    echo "Usage: " . $argv[0] . " <file.sql> [-e]\n";
    echo "  -e : Extended mode - also overwrite existing empty defaults for text/varchar\n";
    exit(1);
}

#
# Check if the current default is an empty string for text/varchar columns
#
function isEmptyDefault($args, $columnType) {
    global $extendedMode;
    
    if (!$extendedMode) {
        return false;
    }
    
    # Only check text and varchar columns
    $token = explode('(', $columnType);
    $baseType = $token[0];
    
    if (!in_array($baseType, ['varchar', 'tinytext', 'text', 'longtext'])) {
        return false;
    }
    
    # Find DEFAULT position and check the value
    for ($i = 0; $i < count($args); $i++) {
        if ($args[$i] == 'DEFAULT') {
            $defaultValue = $args[$i + 1] ?? '';
            # Remove trailing comma if present
            $defaultValue = rtrim($defaultValue, ',');
            
            # Check if it's an empty string default (various formats from mysqldump)
            # '' = simple empty string
            # ''' = escaped single quote version  
            # '''''' = mysqldump format for empty string (two escaped quotes)
            if ($defaultValue == "''" || 
                $defaultValue == "'''" || 
                $defaultValue == "''''''"|| # Common mysqldump format for empty string
                $defaultValue == "\\'\\''" ||  # Backslash escaped version
                $defaultValue == "'\\'\\''") {  # Common mysqldump format for empty string
                return true;
            }
        }
    }
    
    return false;
}

#
# If there is no 'DEFAULT': Inject " DEFAULT $default" at the correct position and return the update string.
# In extended mode: Also handle existing empty defaults for text/varchar columns.
#
function injectDefault($args, $tableName, $default, $lineNr, $columnType){
    global $extendedMode;
    global $dirty;
  $flagComma=false;
  $flagNull=false;
    $hasDefault=false;
    $defaultPos=-1;
  
  # Iterate over each token.
  for($ii=0; $ii<COUNT($args); $ii++) {
  
    switch($args[$ii]){
      case 'NULL,':
        if(!$flagComma){
          $args[$ii]='NULL';
          $flagComma=true;
        }
      case 'NULL':
        if(!$flagNull){
          $pos=$ii;
          $flagNull=true;
        }
        break;
        
      case 'DEFAULT':
                $hasDefault=true;
                $defaultPos=$ii;
                break;
        
      default:
        break;
    }
  }
  
    # If we have a default and we're not in extended mode, skip
    if ($hasDefault && !$extendedMode) {
        return '';
    }
    
    # If we have a default and we're in extended mode, check if it should be overwritten
    if ($hasDefault && $extendedMode) {
        if (!isEmptyDefault($args, $columnType)) {
            return '';
        }
        # Replace existing default value - preserve any trailing comma from original
        $originalValue = $args[$defaultPos + 1];
        $hasTrailingComma = (substr($originalValue, -1) == ',');
        
        $args[$defaultPos + 1] = $default;
        if ($hasTrailingComma && substr($default, -1) != ',') {
            $args[$defaultPos + 1] .= ',';
        }
        
        if ($defaultPos + 1 == COUNT($args) - 1) {
            $args[$defaultPos + 1] = rtrim($args[$defaultPos + 1], ',') . ';';
        }
        return "ALTER TABLE $tableName CHANGE " . $args[0] . " " . implode(' ', $args);
    }
    
    # Handle case where there's no default
  if(($pos??0)==0){
  
    fwrite(STDERR, "[$tableName, $lineNr] Missing NULL: " . implode(' ', $args) . "\n");
    exit ;
  }
  
  # ALTER TABLE `person` CHANGE `switch_user_last` `switch_user_last` DATETIME NULL DEFAULT NULL;
  $args[$pos] .= " DEFAULT $default";
  
  if($pos==(COUNT($args)-1)){
    $args[$pos] .= ';';
  }

  $dirty=true;
  return "ALTER TABLE $tableName CHANGE " . $args[0] . " " . implode(' ', $args) ;
}

#
# $line: "position enum('Master Student','PhD Student','PostDoc','Faculty','Other') NOT NULL,"
# Return: "'Master Student'"
#
function getFirstValueFromEnumSet($line){
  $first=strpos($line, "('");  
  $last=strpos($line, "',", $first);

  return substr($line, $first+1, $last - $first);
}

#
# All updates for table $tableName
# 
function updateTable($tableName, $lines, $lineNr) {
  global $updateEnumSet;
  $cnt=0;

  # Given $lineNr is until end of table definition. Subtract table definition and count from there.
  $lineNr-=count($lines);
  
  # Example $line: `name` varchar(200) NOT NULL DEFAULT '',
  foreach($lines as $line){
    $lineNr++;
    
    $str='';
    
    $args=explode(' ', trim($line));
    if(count($args)<2) {
      continue;
    }
    
    if($args[0]=='`id`'){
      continue;
    }
    
    # Skip: mysqldump key definition. a) "PRIMARY KEY (`id`)", b)  "KEY `splitId` (`splitId`),"
    if($args[0][0]!='`'){
      continue;
    }
    
    # Split 'varchar(123)' into 'varchar'
    $token=explode('(',$args[1]);

    # Get default depending on the column type
    switch($token[0]){
      case 'date': 
                $str=injectDefault($args,$tableName, "'0000-00-00'", $lineNr, $args[1]);
        break;
      case 'datetime': 
      case 'timestamp': 
                $str=injectDefault($args,$tableName, "'0000-00-00 00:00:00'", $lineNr, $args[1]);
        break;
      case 'time': 
                $str=injectDefault($args,$tableName, "'00:00:00'", $lineNr, $args[1]);
        break;
      case 'char': 
      case 'varchar': 
      case 'tinytext': 
      case 'text': 
      case 'mediumtext': 
      case 'longtext': 
      case 'blob': 
      case 'tinyblob': 
      case 'mediumblob': 
      case 'longblob': 
                $str=injectDefault($args,$tableName, "''", $lineNr, $args[1]);
        break;
      case 'tinyint': 
      case 'smallint': 
      case 'mediumint': 
      case 'int': 
      case 'integer': 
      case 'bigint': 
      case 'decimal': 
      case 'number': 
      case 'float': 
      case 'double': 
      case 'bit': 
                $str=injectDefault($args,$tableName, '0', $lineNr, $args[1]);
        break;
      case 'enum': 
      case 'set': 
        $default=getFirstValueFromEnumSet($line);
                if(''!==($update=injectDefault($args,$tableName, $default, $lineNr, $args[1]))){
          if($default=="''"){
            # In case the default is '': handle it like all other.
            $str=$update;
          } else {
            # In case the default is different than '': print it at the end.
            $updateEnumSet .= '# ' . $line . $update . "\n";
          }
        }
        break;
      default:
        fwrite(STDERR, "[$tableName, $lineNr] Type unhandled: " . $args[1] . "\n");
    }

    if($str!='') {
      # If the line ends with ',', replace it by ';'
      if(substr($str,-1)==',') {
        $str=substr($str,0, strlen($str)-1 ) .';';
      }
      
      echo $str. "\n";
    }
  }
}

#
# Main
#

if ( ($argv[1]??'') == '') {
    echo "Usage: " . $argv[0] . " <file.sql> [-e]\n";
    echo "  -e : Extended mode - also overwrite existing empty defaults for text/varchar\n";
  exit(1);
}

$tableName='';
$lines=array();
$lineNr=0;

# Read file, line by line
foreach(file($argv[1]) as $line) {
  $matches = explode(' ', trim($line));
  $lineNr++;
  
  # Line 'CREATE TABLE': remember table name
  if(($matches[0]??'') == 'CREATE' && ($matches[1]??'') == 'TABLE') {
    $tableName=$matches[2]??'';
    continue;
  }

  # Closing 'CREATE TABLE': ') ...'
  if(($matches[0]??'') == ')' && $tableName!='') {
    updateTable($tableName, $lines, $lineNr);
    $tableName='';
    $lines=array();
    continue;
  }
  
  # Collect lines from CREATE TABLE block.
  if($tableName!='' && $matches[0]!='') {
    $lines[]=$line;
  }
}

if($updateEnumSet!=''){
  echo "\n#\n# SET/ENUM - first value as default - check these before updating:\n#\n\n";
  echo $updateEnumSet;
}

if(!$dirty){
  echo "All columns with defaults - nothing to do\n";
}
?>
