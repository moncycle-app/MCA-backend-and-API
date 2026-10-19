<?php
/* moncycle.app
**
** licence Creative Commons CC BY-NC-SA
**
** https://www.moncycle.app
** https://github.com/moncycle-app/backend-api-web-app
*/

declare(strict_types=1);

require_once __DIR__ . "/../config.php";
require_once __DIR__ . "/../lib/upgrade.php";

// php auto_upgrade.php [--wait=SECONDS] [--skip-if-maintenance]. Compares the schema_version of the database with APP_VERSION and, if the database
// is behind, runs the migrations that lead to it (script/migration/), printing what they do. The Docker entrypoint runs it at every start, after
// db_init.php; it works as well from a shell, with the settings of config.php. A migration cannot be undone: dump a database that holds real
// data first (docs/upgrading.md).
// --wait: how long to keep trying while the database does not answer (default 0).
// --skip-if-maintenance: do nothing when MAINTENANCE_MODE is on (the entrypoint: the database may be down or being restored). Without it a
// run in maintenance goes on, which is how an operator upgrades behind the maintenance page.
// Exit status: 0 the database is at APP_VERSION (already, or now) or nothing was asked (maintenance), 1 the database does not answer, is newer
// than the code, cannot be upgraded or a migration failed, 2 usage. An empty database is db_init.php's: run that first. Command line only.
if (PHP_SAPI !== "cli") {
	http_response_code(404);
	exit;
}

$wait = 0;
$skip_if_maintenance = false;
foreach (array_slice($_SERVER["argv"], 1) as $argument) {
	if ($argument === "--skip-if-maintenance") {
		$skip_if_maintenance = true;
	}
	elseif (preg_match('/^--wait=([0-9]{1,3})$/', $argument, $found)) {
		$wait = intval($found[1]);
	}
	else {
		fwrite(STDERR, "usage: php auto_upgrade.php [--wait=SECONDS] [--skip-if-maintenance]" . PHP_EOL);
		exit(2);
	}
}

if ($skip_if_maintenance && MAINTENANCE_MODE) {
	echo "auto_upgrade: MAINTENANCE_MODE is on, the database is not looked at." . PHP_EOL;
	exit(0);
}

$deadline = time() + $wait;
$db = null;
while (is_null($db)) {
	try {
		$db = db_ops_open();
	}
	catch (\PDOException $e) {
		if (time() >= $deadline) {
			fwrite(STDERR, "auto_upgrade: the database does not answer: " . $e->getMessage() . PHP_EOL);
			exit(1);
		}
		echo "auto_upgrade: waiting for the database..." . PHP_EOL;
		sleep(2);
	}
}
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

if (db_querie_count_tables($db) === 0) {
	echo "auto_upgrade: the database has no table, nothing to upgrade (db_init.php makes the schema)." . PHP_EOL;
	exit(0);
}

try {
	exit(upgrade_run($db) ? 0 : 1);
}
catch (\Throwable $e) {
	fwrite(STDERR, "auto_upgrade: the migration failed: " . $e->getMessage() . PHP_EOL
		. "auto_upgrade: MariaDB commits at every ALTER TABLE, so the database may be half migrated: restore the dump taken before, do not run it again on it (docs/upgrading.md)." . PHP_EOL);
	exit(1);
}
