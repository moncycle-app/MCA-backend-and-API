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

require_once __DIR__ . "/../config.php";
require_once __DIR__ . "/../lib/db_ops.php";
require_once __DIR__ . "/../lib/db_querie.php";

header("Content-Type: text/plain");

// one metric per line, "moncycle_app_<name> <value>"; gauges, percentages are 0-100
$metrics = ["maintenance" => MAINTENANCE_MODE ? 1 : 0];

// OPcache lives in the web server's memory, so it is read here, over HTTP, and not from the command line (off there)
$opcache = function_exists("opcache_get_status") ? opcache_get_status(false) : false;
if (is_array($opcache)) {
	$metrics += [
		"php_opcache_hit_percent" => round($opcache["opcache_statistics"]["opcache_hit_rate"], 2),
		"php_opcache_memory_used_bytes" => $opcache["memory_usage"]["used_memory"],
		"php_opcache_memory_free_bytes" => $opcache["memory_usage"]["free_memory"],
		"php_opcache_memory_wasted_percent" => round($opcache["memory_usage"]["current_wasted_percentage"], 2),
		"php_opcache_memory_used_percent" => round($opcache["memory_usage"]["used_memory"] / ($opcache["memory_usage"]["used_memory"] + $opcache["memory_usage"]["free_memory"]) * 100, 1),
		"php_opcache_cached_scripts" => $opcache["opcache_statistics"]["num_cached_scripts"],
		"php_opcache_max_cached_keys" => $opcache["opcache_statistics"]["max_cached_keys"],
		"php_opcache_cached_scripts_percent" => round($opcache["opcache_statistics"]["num_cached_scripts"] / $opcache["opcache_statistics"]["max_cached_keys"] * 100, 1),
		"php_opcache_oom_restarts_total" => $opcache["opcache_statistics"]["oom_restarts"],
		"php_opcache_hash_restarts_total" => $opcache["opcache_statistics"]["hash_restarts"],
		"php_opcache_manual_restarts_total" => $opcache["opcache_statistics"]["manual_restarts"],
	];
}

// in maintenance the database may be down or being restored: it is not opened
if (!MAINTENANCE_MODE) {
	$db = db_ops_open();
	$accounts = db_querie_count_user_accounts($db);
	$sessions = db_querie_count_auth_tokens($db);
	$unverified = db_querie_select_user_accounts_unverified_summary($db);
	$active = db_querie_count_user_accounts_active($db);

	$metrics += [
		"accounts" => $accounts,
		"sessions" => $sessions,
		"accounts_active" => $active,
		"accounts_active_percent" => $accounts > 0 ? round(($active / $accounts) * 100, 1) : 0,
		"accounts_with_totp" => $totp = db_querie_count_user_accounts_with_totp($db),
		"accounts_with_totp_percent" => $accounts > 0 ? round(($totp / $accounts) * 100, 1) : 0,
		"sessions_per_account" => $accounts > 0 ? round($sessions / $accounts, 2) : 0,
		"accounts_unverified" => intval($unverified["nb"]),
		"accounts_unverified_oldest_seconds" => intval($unverified["oldest_seconds"] ?? 0),
	];

	// the cron: alert when now - cron_last_success_timestamp_seconds is over a day
	foreach ([CRON_LAST_SUCCESS_KEY => "cron_last_success_timestamp_seconds", CRON_LAST_DURATION_KEY => "cron_last_duration_ms"] as $key => $name) {
		$value = db_querie_select_key_value($db, $key);
		if ($value !== false && $value !== null) $metrics[$name] = intval($value);
	}
	if (isset($metrics["cron_last_success_timestamp_seconds"])) {
		$metrics["cron_last_success_age_seconds"] = max(0, time() - $metrics["cron_last_success_timestamp_seconds"]);
	}

	// MariaDB: connections, locks, queries without a good index, size
	$status = db_querie_select_global_status($db);
	foreach (STAT_DB_STATUS as $variable => $name) {
		if (isset($status[$variable])) $metrics[$name] = $status[$variable];
	}
	$max_connections = db_querie_select_max_connections($db);
	$metrics["db_max_connections"] = $max_connections;
	if ($max_connections > 0) {
		$metrics["db_connections_used_percent"] = round((($status["Threads_connected"] ?? 0) / $max_connections) * 100, 1);
		$metrics["db_max_connections_used_percent"] = round((($status["Max_used_connections"] ?? 0) / $max_connections) * 100, 1);
	}
	$connections = $status["Connections"] ?? 0;
	if ($connections > 0) {
		$metrics["db_questions_per_connection"] = round(($status["Questions"] ?? 0) / $connections, 1);
		$metrics["db_aborted_clients_percent"] = round((($status["Aborted_clients"] ?? 0) / $connections) * 100, 2);
	}
	$lock_waits = $status["Innodb_row_lock_waits"] ?? 0;
	if ($lock_waits > 0) $metrics["db_innodb_row_lock_average_wait_ms"] = round(($status["Innodb_row_lock_time"] ?? 0) / $lock_waits, 1);
	$reads = $status["Innodb_buffer_pool_reads"] ?? 0;
	$requests = $status["Innodb_buffer_pool_read_requests"] ?? 0;
	if ($requests > 0) $metrics["db_innodb_buffer_pool_hit_percent"] = round((1 - $reads / $requests) * 100, 2);
	$bytes = 0;
	foreach (db_querie_select_tables_size($db) as $table) {
		$metrics["db_table_rows{table=\"{$table["name"]}\"}"] = intval($table["nb"]);
		$metrics["db_table_bytes{table=\"{$table["name"]}\"}"] = intval($table["bytes"]);
		$bytes += intval($table["bytes"]);
	}
	$metrics["db_size_bytes"] = $bytes;

	// the rest only means something with active accounts
	if ($active > 0) {
		foreach (NFP_METHOD_BY_ID as $nfp_method => [$method, $temperature]) {
			$name = strtolower($method) . ($temperature ? "_temp" : "");
			$nb = db_querie_count_user_accounts_active_by_method($db, $nfp_method);
			$metrics["accounts_active_$name"] = $nb;
			$metrics["accounts_active_{$name}_percent"] = round(($nb / $active) * 100, 1);
		}

		$metrics += [
			"accounts_recent" => round(db_querie_count_user_accounts_recent($db)),
			"cycles" => db_querie_count_cycles($db),
			"cycles_recent" => db_querie_count_cycles_recent($db),
			"age_average" => round(db_querie_select_average_age($db) ?? 0, 1),
			"age_average_recent" => round(db_querie_select_average_age($db, true) ?? 0, 1),
			"observations_total" => db_querie_count_days($db),
			"observations_today" => db_querie_count_days_today($db),
		];
		foreach ([1, 5, 15, 30] as $days) {
			$metrics["observations_last_{$days}d"] = db_querie_count_days_since($db, $days);
		}
		$metrics["observations_last_30d_per_active_account"] = round($metrics["observations_last_30d"] / $active, 1);
	}
}

foreach ($metrics as $name => $value) echo "moncycle_app_$name $value" . PHP_EOL;
