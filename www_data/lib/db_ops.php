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

// The database connection and the way a statement is run: prepared once per connection, parameters bound by PHP type, transactions.
// No SQL of the app here: the statements are lib/db_querie.php.

// The charset is in the DSN: PDO knows it, and the server hears it in the handshake (no SET NAMES round trip).
function db_ops_dsn(): string {
	return "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4";
}

// Persistent in a web request: the worker keeps the connection for its next request, which saves the connect and the login (about 250
// of 650 us, script/db_perf.php). Safe because nothing leaves a state on a connection (no SET, GET_LOCK, temporary table or autocommit
// change: db_perf.php scans for them), PDO replaces a dead one, and a transaction left open by a request that died is rolled back here.
// Each worker holds one: max_connections must cover the workers of every app container plus the cron.
function db_ops_open(?bool $persistent = null): PDO {
	$db = new PDO(db_ops_dsn(), DB_ID, DB_PASSWORD, [PDO::ATTR_PERSISTENT => $persistent ?? PHP_SAPI !== "cli"]);
	if ($db->inTransaction()) $db->exec("ROLLBACK");
	return $db;
}

// ---------------------------------------------------------------------------
// Running a statement
// ---------------------------------------------------------------------------

// $sql with ":name" parameters, prepared once per connection. An int or a bool binds as an integer, null as NULL, the rest as a string.
function db_ops_run($db, string $sql, array $params = []): PDOStatement {
	static $statements = [];
	$statement = $statements[spl_object_id($db)][$sql] ??= $db->prepare($sql);

	foreach ($params as $name => $value) {
		$type = match (true) {
			is_null($value) => PDO::PARAM_NULL,
			is_int($value), is_bool($value) => PDO::PARAM_INT,
			default => PDO::PARAM_STR,
		};
		$statement->bindValue(":$name", is_bool($value) ? intval($value) : $value, $type);
	}
	$statement->execute();
	return $statement;
}

function db_ops_rows($db, string $sql, array $params = []): array {
	return db_ops_run($db, $sql, $params)->fetchAll(PDO::FETCH_ASSOC);
}

function db_ops_row($db, string $sql, array $params = []): ?array {
	$statement = db_ops_run($db, $sql, $params);
	$row = $statement->fetch(PDO::FETCH_ASSOC);
	$statement->closeCursor();
	return $row === false ? null : $row;
}

function db_ops_column($db, string $sql, array $params = []): array {
	return db_ops_run($db, $sql, $params)->fetchAll(PDO::FETCH_COLUMN);
}

// the first column of the first row, false when there is none
function db_ops_value($db, string $sql, array $params = []): mixed {
	$statement = db_ops_run($db, $sql, $params);
	$value = $statement->fetchColumn();
	$statement->closeCursor();
	return $value;
}

function db_ops_exec($db, string $sql, array $params = []): int {
	return db_ops_run($db, $sql, $params)->rowCount();
}

function db_ops_insert($db, string $sql, array $params = []): string {
	db_ops_run($db, $sql, $params);
	return $db->lastInsertId();
}

// What $write answers, or null when a unique key refuses it (MariaDB error 1062): the key decides between two requests that race.
function db_ops_unless_duplicate(callable $write): mixed {
	try {
		return $write();
	} catch (\PDOException $error) {
		if (intval($error->errorInfo[1] ?? 0) === 1062) return null;
		throw $error;
	}
}

// $work in one transaction: committed and its result returned, or rolled back when it throws.
function db_ops_transaction($db, callable $work): mixed {
	$db->exec("START TRANSACTION");
	try {
		$result = $work();
		$db->exec("COMMIT");
		return $result;
	} catch (\Throwable $error) {
		$db->exec("ROLLBACK");
		throw $error;
	}
}

// A script of several statements (script/db/*.sql), sent as it is: every result is read, or a failure after the first would go unseen.
function db_ops_exec_script($db, string $sql): void {
	$statement = $db->query($sql);
	do {
	} while ($statement->nextRowset());
}
