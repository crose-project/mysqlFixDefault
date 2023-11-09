#!/usr/bin/bash

#
# Skript updates all columnns in a <DB> to have a default value.
# Usage: mysqlFixDefault.sh <dbname>
#
# Skript should run 
# * locally on the DB server
# * as a MYSQL user who has 'SELECT' and 'ALTER' rights on the given DB.
#
# Script needs: php, mysql, mysqldump

SCRIPT=`dirname $0`/mysqlFixDefault.php
DBSCHEME=`mktemp XXXX.sql`

[ -z "$1" ] && echo "Usage: $0 <dbname>" && exit 1

# Stop on every error
set -e

# Show every command
set -x

# DUMP DB Schema
mysqldump --no-data --skip-triggers $1 > $DBSCHEME

# Get all alter commands
php $SCRIPT $DBSCHEME > ${DBSCHEME}.alter

# Play alter commands
mysql $1 < ${DBSCHEME}.alter
set +x

echo 
echo "Changed: `wc -l ${DBSCHEME}.alter`"
rm ${DBSCHEME}.alter ${DBSCHEME}

echo "Done"


