# mysqlFixDefault
Starting with MariaDB 10.2, a DEFAULT definition per column is necessary for INSERT statements and unspecified columns. 

It is possible to redefine the old behaviour via `SET sql_mode = 'ERROR_FOR_DIVISION_BY_ZERO,NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION';` - but it's better to respect the new defaults.

If there are tons of tables and columns, it's time consuming to do this manually. There are two scripts, `mysqlFixDefault.sh` (one step solution) and `mysqlFixDefault.php` (verify all updates and apply manually) will do the job.

## Apply changes directly on the DB

`mysqlFixDefault.sh DB_NAME`

## Generate update statements, verify and apply manually

Script mysqlFixDefault.php parses a MySQL DB-scheme dump and show updates for all columns which do not have a default value defined.

Usage:

- Create DB scheme dump via phpMyAdmin or `mysqldump --no-data DB_NAME > scheme.sql`.
- Call: `php mysqlFixDefaults.php scheme.sql > schemeUpdate.sql`
- Play `schemeUpdate.sql` in phpMyAdmin or via `mysql DB_NAME < schemeUpdate.sql`.

## General

- For enum/set the first value will be taken as default. 
  - If the first value is not '', the update statement will be listed at the end of the output - to make it easier to check if the assumption is ok.
- Columns with NULL and without DEFAULT get the same DEFAULT as NOT NULL columns (e.g.: the default will not be NULL!)

What the script does: Create column update statements ...

- text,varchar without default. DEFAULT: ''.
- datetime without default. DEFAULT: '0000-00-00 00:00:00'.
- date without default. DEFAULT: '0000-00-00'.
- time without default. DEFAULT: '00:00:00'.
- int without default. DEFAULT: 0.
- enum,set without default. DEFAULT: <first value of enum/set definition>.

