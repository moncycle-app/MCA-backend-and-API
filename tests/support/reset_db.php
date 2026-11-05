<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Empties the test database (every table dropped); script/db_init.php then rebuilds it from schema.sql and demo.sql.
// Refuses any database whose name does not start with test_.
//
//   php -d open_basedir= tests/support/reset_db.php
require "/var/www/html/config.php";
require "/var/www/html/lib/db_ops.php";
$db = db_ops_connect([PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
// the database the connection is really on, whatever DB_DSN looks like
$name = strval($db->query("SELECT DATABASE()")->fetchColumn());
if (!str_starts_with($name, "test_")) exit("reset_db: the database is $name, not a test_* one: refused\n");
$db->exec("SET FOREIGN_KEY_CHECKS = 0");
foreach ($db->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN) as $table) $db->exec("DROP TABLE `$table`");
