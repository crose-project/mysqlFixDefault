<?php

#
# Script parses a MySQL DB-scheme dump and creates updates for all columns which do not have a default value defined.
# 
# Usage:
# - Create DB scheme dump via phpMyAdmin or 'mysqldump --no-data DB_NAME > scheme.sql'.
# - Call: `php mysqlFixDefault.php scheme.sql > schemeUpdate.sql`
# - Play `schemeUpdate.sql` in phpMyAdmin or via 'mysql DB_NAME < schemeUpdate.sql'.
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

#
# If there is no 'DEFAULT': Inject " DEFAULT $default" at the correct position and return the update string.
#
function injectDefault($args, $tableName, $default, $lineNr){
  $flagComma=false;
  $flagNull=false;
  
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
        return '';
        
      default:
        break;
    }
  }
  
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
        $str=injectDefault($args,$tableName, "'0000-00-00'", $lineNr);
        break;
      case 'datetime': 
      case 'timestamp': 
        $str=injectDefault($args,$tableName, "'0000-00-00 00:00:00'", $lineNr);
        break;
      case 'time': 
        $str=injectDefault($args,$tableName, "'00:00:00'", $lineNr);
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
        $str=injectDefault($args,$tableName, "''", $lineNr);
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
        $str=injectDefault($args,$tableName, '0', $lineNr);
        break;
      case 'enum': 
      case 'set': 
        $default=getFirstValueFromEnumSet($line);
        if(''!==($update=injectDefault($args,$tableName, $default, $lineNr))){
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

  echo "Usage: " . $argv[0] . " <file.sql>\n";
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

if($dirty){
  echo "All columns with defaults - nothing to do\n";
}
?>
