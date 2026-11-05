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
require_once __DIR__ . "/../../lib/upgrade.php";

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

    print("reading v15_to_v16.sql file ...");
    print(PHP_EOL);

    $v15_to_v16_schema_migration = file_get_contents(__DIR__ . "/v15_to_v16.sql");

    if ($v15_to_v16_schema_migration === false) {
        throw new RuntimeException("cannot read v15_to_v16.sql: nothing has been changed");
    }

    // The FertilityCare note, split in columns: they must exist before the data step that fills them, and v15_to_v16.sql drops fc_score
    // after it. Here and not in the .sql, which runs as a whole, after the data steps. An ALTER TABLE commits: before the transaction.
    $db->exec("ALTER TABLE `day_timeline`
        ADD `fc_bleeding` varchar(2) COLLATE utf8mb4_bin DEFAULT NULL AFTER `fc_score`,
        ADD `fc_bleeding_brown` tinyint(1) unsigned DEFAULT NULL AFTER `fc_bleeding`,
        ADD `fc_mucus_sensation` varchar(4) COLLATE utf8mb4_bin DEFAULT NULL AFTER `fc_bleeding_brown`,
        ADD `fc_mucus_observation` set('B','C','G','K','P','Y','L') COLLATE utf8mb4_bin DEFAULT NULL AFTER `fc_mucus_sensation`,
        ADD `fc_number_observations` varchar(2) COLLATE utf8mb4_bin DEFAULT NULL AFTER `fc_mucus_observation`,
        ADD `fc_pain` varchar(3) COLLATE utf8mb4_bin DEFAULT NULL AFTER `fc_number_observations`,
        ALGORITHM=INSTANT");

    $db->exec("START TRANSACTION");

    // Data steps (before the schema ones).
    // user_setting.value_str holds 255 characters, notification_emails held 1024: a list that does not fit keeps the addresses that do.
    $too_long = $db->query("SELECT `no_user_account`, `notification_emails` FROM `user_account` WHERE CHAR_LENGTH(`notification_emails`) > 255")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($too_long as $row) {
        $kept = [];
        foreach (explode(";", $row["notification_emails"]) as $address) {
            if (mb_strlen(implode(";", [...$kept, $address])) <= 255) $kept[] = $address;
        }
        $update = $db->prepare("UPDATE `user_account` SET `notification_emails` = :list WHERE `no_user_account` = :no_user_account");
        $update->execute(["list" => implode(";", $kept), "no_user_account" => $row["no_user_account"]]);
        print("account " . $row["no_user_account"] . ": notification_emails cut to " . count($kept) . " addresses to fit 255 characters");
        print(PHP_EOL);
    }

    $fc_split = upgrade_split_fc_score($db);
    print($fc_split["read"] . " FertilityCare notes split in columns");
    print(PHP_EOL);
    foreach ($fc_split["approximated"] as $no_day) {
        print("day " . $no_day . ": its FertilityCare note said more than the columns hold, the strongest codes were kept");
        print(PHP_EOL);
    }

    // A label with no account: no read ever shows it (they all ask for an account), and the column becomes NOT NULL.
    $orphans = $db->exec("DELETE FROM `description` WHERE `no_user_account` IS NULL");
    print($orphans . " labels without an account deleted");
    print(PHP_EOL);

    print("commiting the data steps ...");
    print(PHP_EOL);

    $db->exec("COMMIT");

    // The ids become BIGINT: a key and what refers to it must change together, so the foreign keys go first and v15_to_v16.sql makes them
    // again, each table rebuilt once. Their names differ from one database to another (those of schema.sql, or those of the older migrations).
    print(upgrade_drop_foreign_keys($db) . " foreign keys dropped, v15_to_v16.sql makes them again");
    print(PHP_EOL);

    print("migrating schema (executing v15_to_v16.sql): the ids of every table are rewritten, about 1 minute per 7 million days, plan for it ...");
    print(PHP_EOL);

    db_ops_exec_script($db, $v15_to_v16_schema_migration);

    print("DONE !");
    print(PHP_EOL);

} catch (\Throwable $th) {
    $db->exec("ROLLBACK");
    $db = null;

    throw $th;
}

$db = null;
