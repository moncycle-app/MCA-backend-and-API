<?php
/* moncycle.app
**
** licence Creative Commons CC BY-NC-SA
**
** https://www.moncycle.app
** https://github.com/moncycle-app/backend-api-web-app
*/

declare(strict_types=1);

// What the app does with its data, on top of lib/db_querie.php: a day built and saved, the descriptions (the free-text labels of a day), the
// sync, the public numbers, and the SQL files of script/db/. The shape of a day is lib/day_format.php.

require_once __DIR__ . "/date.php";
require_once __DIR__ . "/day_format.php";
require_once __DIR__ . "/db_ops.php";
require_once __DIR__ . "/db_querie.php";
require_once __DIR__ . "/http.php";
require_once __DIR__ . "/log.php";

// ---------------------------------------------------------------------------
// Reading days
// ---------------------------------------------------------------------------

// A day as lib/day_format.php reads it: the row (or just ["date_obs" => ...] for a date never written), the first day of its cycle
// and its position in it, and its descriptions (none for a date never written).
function data_day(array $row, ?string $cycle, ?array $descriptions = null): array {
	$day = ["cycle" => $cycle];
	if ($cycle) $day["pos"] = date_cycle_day($cycle, $row["date_obs"]);
	return is_null($descriptions) ? $day + $row : array_merge($day, $row, ["description" => $descriptions]);
}

// One day read from the database.
function data_construct_day($db, string $date, $no_user_account): array {
	$cycle = db_querie_select_cycle($db, $date, $no_user_account);
	$row = db_querie_select_day_timeline($db, $date, $no_user_account);
	if (is_null($row)) return data_day(["date_obs" => $date], $cycle);
	return data_day($row, $cycle, db_querie_select_all_description_for_day_timeline($db, $no_user_account, $row["no_day"]));
}

// The descriptions of day rows (in date order) in one query, by no_day.
function data_descriptions_by_day($db, array $rows, $no_user_account): array {
	$by_day = [];
	if (empty($rows)) return $by_day;
	foreach (db_querie_select_descriptions_for_day_timeline_frame($db, $rows[0]["date_obs"], end($rows)["date_obs"], $no_user_account) as $link) {
		$by_day[$link["no_day"]][] = $link;
	}
	return $by_day;
}

// The stored days from $first to $last (both included), built: one query for the cycle they start in, one for the days, one for their
// descriptions.
function data_days_range($db, string $first, string $last, $no_user_account): array {
	$rows = db_querie_select_day_timelines_range($db, $first, date_next_day($last), $no_user_account);
	$descriptions = data_descriptions_by_day($db, $rows, $no_user_account);
	$cycle = db_querie_select_cycle($db, $first, $no_user_account);
	$days = [];
	foreach ($rows as $row) {
		if ($row["cycle_1st_day"]) $cycle = $row["date_obs"];
		$days[] = data_day($row, $cycle, $descriptions[$row["no_day"]] ?? []);
	}
	return $days;
}

function data_description_to_json(array $row): array {
	return [
		"id" => intval($row["no_description"]),
		"name" => $row["name"],
		"type" => DESCRIPTION_TYPE_NAMES[intval($row["type"])] ?? DESCRIPTION_TYPE_NAMES[DESCRIPTION_TYPE_UNDEFINED],
		"useCount" => intval($row["use_count"] ?? 0),
	];
}

// ---------------------------------------------------------------------------
// Writing days: the API (one day) and the NFP import (many) write through these
// ---------------------------------------------------------------------------

// The description of a name, found or created with $type. $known keeps the answers between calls (a file repeats a few names on every
// day). A name is one row whatever type it is asked as: "type" is the one it has, "created" whether this call made it.
function data_resolve_description($db, int $no_user_account, string $name, int $type, string $last_write_client_UTC, array &$known = []): array {
	$name = trim($name);
	if (isset($known[$name])) return $known[$name] + ["created" => false];

	$existing = db_querie_select_description_exact_name($db, $no_user_account, $name);
	$created = is_null($existing);
	$known[$name] = [
		"id" => $created ? intval(db_querie_insert_description($db, $no_user_account, $name, $type, $last_write_client_UTC)) : intval($existing["no_description"]),
		"type" => $created ? $type : intval($existing["type"]),
	];
	return $known[$name] + ["created" => $created];
}

// A day's row (made when $existing is null), its fields and the set of descriptions it carries. No transaction of its own: the caller's.
function data_write_day($db, int $no_user_account, string $date, ?array $existing, array $fields, array $no_descriptions, string $last_write_client_UTC): int {
	$no_day = is_null($existing) ? intval(db_querie_insert_day_timeline($db, $date, $no_user_account)) : intval($existing["no_day"]);
	db_querie_update_day_timeline($db, $date, $no_user_account, $last_write_client_UTC, $fields);

	$linked = array_map('intval', array_column(db_querie_select_all_description_for_day_timeline($db, $no_user_account, $no_day), "no_description"));
	$wanted = array_values(array_unique($no_descriptions));
	foreach (array_diff($linked, $wanted) as $no_description) db_querie_delete_linked_descriptions($db, $no_day, $no_description);
	foreach (array_diff($wanted, $linked) as $no_description) db_querie_insert_link_description_day_timeline($db, $no_day, $no_description);
	return $no_day;
}

// Inside the write's transaction: the account held, then the stored day (null when none), or false when the day was written after the
// moment the write carries (a device that was offline replaying an old change). The same moment is not later: a write sent twice goes
// through. The stored moment counts as at most the DB's own, so that a client whose clock runs ahead cannot shut the others out.
function data_day_for_write($db, int $no_user_account, string $date, string $last_write_client_UTC): array|false|null {
	db_querie_select_user_account_for_update($db, $no_user_account);
	$existing = db_querie_select_day_timeline($db, $date, $no_user_account);
	if (is_null($existing)) return null;
	log_context(["day" => intval($existing["no_day"])]);
	if (empty($existing["last_write_client_UTC"])) return $existing;
	$last_write = empty($existing["last_write_db"]) ? $existing["last_write_client_UTC"] : min($existing["last_write_client_UTC"], $existing["last_write_db"]);
	return $last_write > $last_write_client_UTC ? false : $existing;
}

// A day checked by day_format_validate(), with its descriptions (created when a name is new), in one transaction. True when the day is
// new, null when the stored day is later than the write. The log names the day and the descriptions made, never the ones the day
// carries (one join would give back what the day holds).
function data_save_day($db, int $no_user_account, string $date, array $day, string $last_write_client_UTC): ?bool {
	$saved = db_ops_transaction($db, function () use ($db, $no_user_account, $date, $day, $last_write_client_UTC) {
		$existing = data_day_for_write($db, $no_user_account, $date, $last_write_client_UTC);
		if ($existing === false) return null;

		$known = [];
		$no_descriptions = [];
		$created = [];
		foreach ([DESCRIPTION_TYPE_OBSERVATION => 'freeMucusObservation', DESCRIPTION_TYPE_SENSATION => 'freeMucusSensation', DESCRIPTION_TYPE_UNDEFINED => 'freeOther'] as $type => $field) {
			foreach ($day[$field] ?? [] as $name) {
				$description = data_resolve_description($db, $no_user_account, $name, $type, $last_write_client_UTC, $known);
				$no_descriptions[] = $description["id"];
				if ($description["created"]) $created[] = $description["id"];
			}
		}

		log_context(["day" => data_write_day($db, $no_user_account, $date, $existing, day_format_from_json($day), $no_descriptions, $last_write_client_UTC)]);
		return [is_null($existing), $created];
	});
	if (is_null($saved)) return null;

	[$is_new, $created] = $saved;
	log_event("data.day_saved", ["dt" => $date, "new" => $is_new, "dsc" => $created ?: null]);
	return $is_new;
}

// The row stays, empty and stamped: that is how the sync tells the other clients the day is gone. A date never written has nothing to
// clear and logs nothing. False when the stored day is later than the clear.
function data_clear_day($db, int $no_user_account, string $date, string $last_write_client_UTC): bool {
	[$cleared_row, $cleared] = db_ops_transaction($db, function () use ($db, $no_user_account, $date, $last_write_client_UTC) {
		$existing = data_day_for_write($db, $no_user_account, $date, $last_write_client_UTC);
		if ($existing === false) return [false, false];
		if (!is_null($existing)) data_write_day($db, $no_user_account, $date, $existing, [], [], $last_write_client_UTC);
		return [!is_null($existing), true];
	});

	if ($cleared_row) log_event("data.day_cleared", ["dt" => $date]);
	return $cleared;
}

// A write by the owner counts as activity: the "are you still there" flag goes.
function data_reactivate_account($db, array &$user_account): void {
	if (empty($user_account["is_inactive"])) return;
	db_querie_update_is_inactive($db, $user_account["no_user_account"], 0);
	$user_account["is_inactive"] = 0;
	log_event("account.reactivated");
}

// The account and all it holds (cascade). $why: "user_request", "inactivity" or "unverified". The cookie is the endpoint's to clear.
function data_delete_account($db, array $account, string $why): void {
	$no_user_account = intval($account["no_user_account"]);
	$days = db_querie_count_days_of_account($db, $no_user_account);
	$descriptions = db_querie_count_descriptions_of_account($db, $no_user_account);
	db_querie_delete_user_account($db, $no_user_account);
	log_event("account.deleted", ["uid" => $no_user_account, "why" => $why, "ndy" => $days, "nds" => $descriptions]);
}

// ---------------------------------------------------------------------------
// Descriptions
// ---------------------------------------------------------------------------

// Creates one ($no_description null) or renames and / or retypes one ($name or $type null stay as they are). A day shows its labels by
// name and type, so the days that carry it are stamped for the sync. Answers ["outcome" => "created" | "updated" | "duplicate_name" |
// "not_found", "description" => [no_description, name, type] or null, "touched" => days stamped]. The log says how many days, not which.
function data_save_description($db, int $no_user_account, ?int $no_description, ?string $name, ?int $type, string $last_write_client_UTC): array {
	$saved = db_ops_transaction($db, function () use ($db, $no_user_account, $no_description, $name, $type, $last_write_client_UTC) {
		db_querie_select_user_account_for_update($db, $no_user_account);

		$current = is_null($no_description) ? null : db_querie_select_description($db, $no_description, $no_user_account);
		if (!is_null($no_description) && is_null($current)) return ["outcome" => "not_found", "description" => null];
		if (!is_null($no_description)) log_context(["dsc" => $no_description]);

		$name ??= $current["name"];
		$type ??= intval($current["type"]);
		if (db_querie_select_description_name_exists($db, $name, $no_user_account, $no_description ?? 0)) return ["outcome" => "duplicate_name", "description" => null];

		if (is_null($no_description)) {
			$no_description = intval(db_querie_insert_description($db, $no_user_account, $name, $type, $last_write_client_UTC));
			log_context(["dsc" => $no_description]);
			return ["outcome" => "created", "description" => ["no_description" => $no_description, "name" => $name, "type" => $type]];
		}

		db_querie_update_description_name_type($db, $no_user_account, $no_description, $name, $type, $last_write_client_UTC);
		$touched = db_querie_update_day_timeline_touch_description($db, $no_user_account, $no_description);
		return ["outcome" => "updated", "description" => ["no_description" => $no_description, "name" => $name, "type" => $type], "touched" => $touched];
	});

	if (!is_null($saved["description"])) log_event("data.description_saved", ["new" => $saved["outcome"] === "created", "tch" => $saved["touched"] ?? null]);
	return $saved;
}

// The days it is on are stamped first (the links go with it), so that the sync reports them without it.
function data_delete_description($db, int $no_user_account, int $no_description): void {
	log_context(["dsc" => $no_description]);
	$touched = db_ops_transaction($db, function () use ($db, $no_user_account, $no_description) {
		$touched = db_querie_update_day_timeline_touch_description($db, $no_user_account, $no_description);
		db_querie_delete_descriptions($db, $no_description, $no_user_account);
		return $touched;
	});
	log_event("data.description_deleted", ["tch" => $touched]);
}

// ---------------------------------------------------------------------------
// GET /api/v2/timeline_sync
// ---------------------------------------------------------------------------

// The ranges a set of written days changes: a day's cycleStartDate and cycleDay come from the first day of its cycle, which the write may
// have set or cleared, so a written day changes every day up to the next first day. $starts: the first days, oldest first. A range is
// [first date, end excluded], null for no end; ranges that touch are one.
function data_cycle_tails(array $dates, array $starts): array {
	sort($dates);
	$ranges = [];
	foreach ($dates as $date) {
		$end = null;
		foreach ($starts as $start) {
			if ($start > $date) {
				$end = $start;
				break;
			}
		}
		$last = count($ranges) - 1;
		if ($last >= 0 && ($ranges[$last][1] === null || $date <= $ranges[$last][1])) $ranges[$last][1] = $end;
		else $ranges[] = [$date, $end];
	}
	return $ranges;
}

// The days written since $from_timestamp (the DB clock, less SYNC_OVERLAP_SECONDS) and the days that follow them in their cycles, in
// date order; a cleared day is among them, as an empty row. Two queries a range, whatever the number of days.
function data_sync_days($db, int $no_user_account, string $from_timestamp): array {
	$written = db_querie_select_day_timeline_dates_written_since($db, $from_timestamp, SYNC_OVERLAP_SECONDS, $no_user_account);
	if (empty($written)) return [];

	$starts = array_reverse(db_querie_select_cycles($db, $no_user_account));
	$days = [];
	foreach (data_cycle_tails($written, $starts) as [$range_start, $range_end]) {
		$rows = db_querie_select_day_timelines_range($db, $range_start, $range_end, $no_user_account);
		$descriptions = data_descriptions_by_day($db, $rows, $no_user_account);
		$cycle = null;
		$next_start = 0;
		foreach ($rows as $row) {
			while ($next_start < count($starts) && $starts[$next_start] <= $row["date_obs"]) $cycle = $starts[$next_start++];
			$days[] = data_day($row, $cycle, $descriptions[$row["no_day"]] ?? []);
		}
	}
	return $days;
}

// The cursor for next time (the DB clock, read first, so that what is written meanwhile comes next time), the days, and every
// description and every first day of a cycle and of a pregnancy: they are few, and coming whole is how a deletion reaches a client.
function data_sync($db, array $user_account, string $from_timestamp): array {
	$no_user_account = intval($user_account["no_user_account"]);
	$now = db_querie_select_now($db);
	return [
		"syncTimestamp" => http_iso8601($now),
		"days" => array_map('day_format_to_json', data_sync_days($db, $no_user_account, $from_timestamp)),
		"descriptions" => array_map('data_description_to_json', db_querie_select_description_with_count($db, $no_user_account)),
		"allCyclesFirstDay" => db_querie_select_cycles($db, $no_user_account),
		"allPregnancyDates" => db_querie_select_pregnancies($db, $no_user_account),
	];
}

// ---------------------------------------------------------------------------
// GET /api/v2/pub_stat: counting walks day_timeline, so the cron counts once a day and stores
// ---------------------------------------------------------------------------

function data_public_stats_count($db): array {
	$counts = ["accounts" => db_querie_count_user_accounts($db), "cycles" => db_querie_count_cycles($db), "observations" => db_querie_count_days($db)];
	foreach ($counts as $name => $count) $counts[$name] = round($count, PUB_STAT_KEYS[$name][1]);
	return $counts;
}

function data_public_stats_store($db): void {
	foreach (data_public_stats_count($db) as $name => $count) db_querie_upsert_key_value($db, PUB_STAT_KEYS[$name][0], intval($count));
}

// What the cron stored, or counted now before its first run.
function data_public_stats($db): array {
	$stats = [];
	foreach (PUB_STAT_KEYS as $name => [$key]) {
		$value = db_querie_select_key_value($db, $key);
		if ($value === false || is_null($value)) return data_public_stats_count($db);
		$stats[$name] = intval($value);
	}
	return $stats;
}

// ---------------------------------------------------------------------------
// The SQL files of script/db/
// ---------------------------------------------------------------------------

function data_read_sql_file(string $path): string {
	$sql = is_readable($path) ? file_get_contents($path) : false;
	if ($sql === false || trim($sql) === "") throw new RuntimeException("The SQL file " . basename($path) . " cannot be read.");
	return $sql;
}

// A database with no table gets the schema, and the demo accounts when DEMO_ENABLED; one with tables is left as it is. Answers whether
// the schema was made. DDL commits as it goes: a failure after the first table leaves a database that is no longer empty.
function data_init_database($db): bool {
	if (db_querie_count_tables($db) > 0) return false;
	db_ops_exec_script($db, data_read_sql_file(DB_SCRIPT_TABLES));
	if (DEMO_ENABLED) data_reload_demo_accounts($db);
	return true;
}

// The demo accounts back as demo.sql makes them, dated from today, in one transaction: what visitors wrote, and their sessions, go.
function data_reload_demo_accounts($db): void {
	$sql = data_read_sql_file(DB_SCRIPT_DEMO);
	db_ops_transaction($db, fn() => db_ops_exec_script($db, $sql));
}
