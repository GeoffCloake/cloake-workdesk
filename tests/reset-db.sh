#!/usr/bin/env bash
# Rebuild a disposable test database and point the local private config at it.
#   ./reset-db.sh fixture   synthetic data (shareable)
#   ./reset-db.sh replica   real-data replica from a private local snapshot (never packaged)
set -euo pipefail
cd "$(dirname "$0")"
MODE="${1:-fixture}"
CFG=/home/cloakecrea/cloake-manager/private/config.php
case "$MODE" in
  fixture)
    DB=cloake_fixture
    mysql -e "DROP DATABASE IF EXISTS $DB; CREATE DATABASE $DB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
    for f in ../db/01_schema_reference_m2.sql ../db/02_m3d_allow_unassigned_tasks.sql ../db/03_m3b_legacy_tables_ddl_only.sql ../db/04_m5_work_date_ASSUMED.sql; do mysql "$DB" < "$f"; done
    php fixture/make_fixture.php | mysql "$DB"
    ;;
  replica)
    DB=cloakecrea_manager_devt
    SNAP=/home/claude/private-local/replica_checkpoint_2026-10-30.sql.gz
    mysql -e "DROP DATABASE IF EXISTS $DB; CREATE DATABASE $DB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
    zcat "$SNAP" | mysql "$DB"
    ;;
  *) echo "usage: $0 fixture|replica"; exit 2;;
esac
mysql -e "CREATE USER IF NOT EXISTS 'cloakecrea_manager'@'localhost' IDENTIFIED BY 'localtest-only'; GRANT SELECT,INSERT,UPDATE,DELETE ON \`$DB\`.* TO 'cloakecrea_manager'@'localhost'; FLUSH PRIVILEGES;"
php -r '$c=require $argv[1]; $c["db_name"]=$argv[2]; file_put_contents($argv[1], "<?php\nreturn ".var_export($c,true).";\n");' "$CFG" "$DB"
echo "Reset $MODE -> $DB: $(mysql -N "$DB" -e "SELECT CONCAT((SELECT COUNT(*) FROM clients),' clients, ',(SELECT COUNT(*) FROM contacts),' contacts, ',(SELECT COUNT(*) FROM tasks),' tasks, ',(SELECT COUNT(*) FROM work_log),' work, ',(SELECT COUNT(*) FROM enquiries),' enquiries')")"
