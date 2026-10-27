<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Empties the test database (every table dropped); script/db_init.php then rebuilds it from schema.sql and demo.sql.
// Refuses any database whose name does not start with test_.
//
//   php -d open_basedir= tests/support/reset_db.php
require "/var/www/html/config.php";
if (!str_starts_with(DB_NAME, "test_")) exit("reset_db: DB_NAME is " . DB_NAME . ", not a test_* database: refused\n");
$db = new PDO("mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME, DB_ID, DB_PASSWORD, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->exec("SET FOREIGN_KEY_CHECKS = 0");
foreach ($db->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN) as $table) $db->exec("DROP TABLE `$table`");
