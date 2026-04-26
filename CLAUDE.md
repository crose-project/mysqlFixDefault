# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this tool does

`mysqlFixDefault.php` parses a MySQL/MariaDB schema dump (`mysqldump --no-data`) and generates `ALTER TABLE ... CHANGE` statements to add missing `DEFAULT` values to columns. Needed because MariaDB 10.2+ requires explicit defaults for INSERT on unspecified columns.

`mysqlFixDefault.sh` is a one-step wrapper: dumps the schema, runs the PHP script, and applies the ALTER statements directly to a live DB.

## Running

```bash
# Generate ALTER statements for manual review
php mysqlFixDefault.php scheme.sql > schemeUpdate.sql

# Extended mode: also overwrite existing empty-string defaults on text/varchar columns
php mysqlFixDefault.php scheme.sql -e > schemeUpdate.sql

# Apply directly to a live DB (requires php, mysql, mysqldump in PATH)
./mysqlFixDefault.sh DB_NAME
```

There are no tests, no build step, and no dependencies beyond PHP CLI.

## Code structure

Everything lives in `mysqlFixDefault.php` (single file, ~337 lines, no classes):

- **Main loop** (bottom of file): reads the SQL file line by line, detects `CREATE TABLE` / closing `)` boundaries, collects column definition lines into `$lines[]`, then calls `updateTable()`.
- **`updateTable()`**: iterates column lines, dispatches by type to `injectDefault()`. Enum/set defaults with non-empty first value are deferred into `$updateEnumSet` and printed last so the user can review them.
- **`injectDefault()`**: tokenizes a column definition by spaces, locates the NULL/NOT NULL position, and splices in `DEFAULT <value>`. In `-e` (extended) mode it also replaces empty-string defaults using `isEmptyDefault()`.
- **`isEmptyDefault()`**: checks whether an existing DEFAULT token is an empty string in the various formats mysqldump can produce.
- **Global `$dirty`**: set to `true` whenever a change is emitted; if still `false` at the end, the script prints "All columns with defaults - nothing to do".
- **Global `$updateEnumSet`**: accumulates enum/set ALTER statements that need human review before applying.

## Default values applied per type

| Type | Default |
|------|---------|
| `varchar`, `char`, `text*`, `blob*` | `''` |
| `date` | `'0000-00-00'` |
| `datetime`, `timestamp` | `'0000-00-00 00:00:00'` |
| `time` | `'00:00:00'` |
| `int*`, `float`, `double`, `decimal`, `bit` | `0` |
| `enum`, `set` | first value in the definition |

## Key behaviours / gotchas

- `id` columns are always skipped.
- Lines not starting with a backtick (KEY, PRIMARY KEY, etc.) are skipped.
- NULL columns without DEFAULT get the same non-NULL default as NOT NULL columns — intentional design.
- Unrecognised column types print a warning to STDERR and continue.
- If a column definition has no NULL/NOT NULL token, the script exits with an error message to STDERR.
