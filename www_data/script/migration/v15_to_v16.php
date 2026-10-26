<?php
/* moncycle.app
**
** Copyright (C) 2021-2026 Tech 4 Fertility, aka. T4F (https://tech4fertility.org)
** SPDX-License-Identifier: AGPL-3.0-or-later
**
** This program is free software: you can redistribute it and/or modify it under
** the terms of the GNU Affero General Public License as published by the Free
** Software Foundation, either version 3 of the License, or (at your option) any
** later version. It is distributed WITHOUT ANY WARRANTY; see the licence for details.
**
** https://www.moncycle.app
** https://github.com/moncycle-app/backend-api-web-app
*/

declare(strict_types=1);

if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit;
}

require_once __DIR__ . "/../../config.php";
require_once __DIR__ . "/../../lib/db_ops.php";

print("MONCYCLE.APP");
print(PHP_EOL);

print("DB migration script from v15 to v16.");
print(PHP_EOL);
print("IMPORTANT : this script runs the file v15_to_v16.sql. It is run by script/auto_upgrade.php, which checks the version of the database first: DO NOT RUN IT ASIDE!");
print(PHP_EOL);

print("-----");
print(PHP_EOL);
print(PHP_EOL);

$db = db_ops_open();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// NOTE: MariaDB commits at every ALTER TABLE / CREATE TABLE / RENAME, so the transaction below protects the
// data steps only, not the schema ones. A run that stops half way leaves a half migrated database: restore the
// dump taken before (docs/upgrading.md), do not run the script again on it.
try {

    $db->exec("START TRANSACTION");

    print("reading v15_to_v16.sql file ...");
    print(PHP_EOL);

    $v15_to_v16_schema_migration = file_get_contents(__DIR__ . "/v15_to_v16.sql");

    if ($v15_to_v16_schema_migration === false) {
        throw new RuntimeException("cannot read v15_to_v16.sql: nothing has been changed");
    }

    // Data steps (before the schema ones) go here.

    print("migrating schema (executing v15_to_v16.sql) ...");
    print(PHP_EOL);

    $db->exec($v15_to_v16_schema_migration);

    print("commiting DB changes ...");
    print(PHP_EOL);

    $db->exec("COMMIT");

    print("DONE !");
    print(PHP_EOL);

} catch (\Throwable $th) {
    $db->exec("ROLLBACK");
    $db = null;

    throw $th;
}

$db = null;
