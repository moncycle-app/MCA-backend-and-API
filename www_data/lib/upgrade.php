<?php
/* moncycle.app
**
** licence Creative Commons CC BY-NC-SA
**
** https://www.moncycle.app
** https://github.com/moncycle-app/backend-api-web-app
*/

declare(strict_types=1);

require_once __DIR__ . "/db.php";

// The version the database is at: the row schema_version of key_value, or UPGRADE_UNVERSIONED_VERSION for a v14 database (no row, the table
// still called cle_valeur). null: no version and not a v14 database, which no migration knows.
function upgrade_database_version($db): ?int {
	if (db_exists_table($db, "key_value")) {
		$value = db_select_key_value($db, SCHEMA_VERSION_KEY);
		if ($value !== false && !is_null($value)) return intval($value);
	}
	return db_exists_table($db, "cle_valeur") ? UPGRADE_UNVERSIONED_VERSION : null;
}

// A migration runner in a scope of its own: it sets variables ($db) that must not reach the caller's.
function upgrade_include_runner(string $file): void {
	require $file;
}

// Brings the database from its version to APP_VERSION, one migration (script/migration/vN-1_to_vN.php, else .sql) at a time, and checks that
// each one set the version it leads to. Says what it does on the standard output. One instance at a time (a server lock): another waits,
// then finds the work done. false: nothing more was done, and the reason is printed; a migration that throws leaves the exception to the caller.
function upgrade_run($db): bool {
	if (!db_get_lock($db, UPGRADE_LOCK_NAME, UPGRADE_LOCK_WAIT_SECONDS)) {
		echo "auto_upgrade: another instance has been upgrading the database for more than " . UPGRADE_LOCK_WAIT_SECONDS . " seconds, giving up." . PHP_EOL;
		return false;
	}
	try {
		$from = upgrade_database_version($db);
		if ($from === null) {
			echo "auto_upgrade: the database has no schema version and is not a v" . UPGRADE_UNVERSIONED_VERSION . " one: nothing here can upgrade it." . PHP_EOL;
			return false;
		}
		echo "auto_upgrade: the code is at version " . APP_VERSION . ", the database at version " . $from . "." . PHP_EOL;
		if ($from === APP_VERSION) {
			echo "auto_upgrade: nothing to upgrade." . PHP_EOL;
			return true;
		}
		if ($from > APP_VERSION) {
			echo "auto_upgrade: the database is newer than this code (a newer release made it): update the code, a migration never goes back." . PHP_EOL;
			return false;
		}
		for ($to = $from + 1; $to <= APP_VERSION; $to++) {
			$base = __DIR__ . "/../script/migration/v" . ($to - 1) . "_to_v" . $to;
			echo "auto_upgrade: migrating to version " . $to . " ..." . PHP_EOL;
			if (is_file($base . ".php")) {
				upgrade_include_runner($base . ".php");
			}
			elseif (is_file($base . ".sql")) {
				$sql = file_get_contents($base . ".sql");
				if ($sql === false) throw new RuntimeException("cannot read " . basename($base) . ".sql: nothing has been changed");
				db_exec_script($db, $sql);
			}
			else {
				echo "auto_upgrade: no migration file " . basename($base) . ".php or .sql: stopped at version " . ($to - 1) . "." . PHP_EOL;
				return false;
			}
			$now = upgrade_database_version($db);
			if ($now !== $to) {
				echo "auto_upgrade: the migration to version " . $to . " ran but the database says version " . ($now ?? "none") . ": stopped, restore the dump taken before (docs/upgrading.md)." . PHP_EOL;
				return false;
			}
			echo "auto_upgrade: the database is at version " . $to . "." . PHP_EOL;
		}
		return true;
	}
	finally {
		db_release_lock($db, UPGRADE_LOCK_NAME);
	}
}
