<?php
/* moncycle.app
**
** licence Creative Commons CC BY-NC-SA
**
** https://www.moncycle.app
** https://github.com/moncycle-app/backend-api-web-app
*/

require_once "../config.php";
require_once "../lib/api.php";
require_once "../lib/data.php";
require_once "../lib/input.php";

[$db, $user_account] = api_start();
$no_user_account = $user_account["no_user_account"];

// /day lists days; /day/{date} is one day: read, replaced (written whole), cleared
$path = input_path();
input_exit_unless_path_fits($path, 1);
$method = http_method();
if (!in_array($method, $path === [] ? ["GET"] : ["GET", "PUT", "DELETE"], true)) http_exit_method_not_allowed($path === [] ? ["GET"] : ["GET", "PUT", "DELETE"]);

$issues = [];
$date = $path === [] ? null : input_path_date($path[0], $issues);

// READING ONE DAY: an empty day when nothing is stored for it
if ($method === "GET" && $path !== []) {
	input_query([], $issues);
	input_check_no_body($issues);
	input_exit_if_issues($issues);

	http_data(200, day_format_to_json(data_construct_day($db, $date, $no_user_account)));
}

// READING SEVERAL DAYS: the dates asked for, or a range
elseif ($method === "GET") {
	$query = input_query(["dates", "from", "to"], $issues);
	input_check_no_body($issues);

	$dates = [];
	if (isset($query["dates"])) {
		$given = array_map('trim', explode(",", $query["dates"]));
		if (count($given) > DAY_LIMIT_QUERY_DAYS) {
			$issues[] = input_issue("query.dates", "too_many", "'dates' holds " . count($given) . " dates; the limit is " . DAY_LIMIT_QUERY_DAYS . ". Use 'from' and 'to' for a longer period, or GET /timeline_sync for everything.");
		}
		else {
			foreach ($given as $position => $one) {
				$parsed = date_parse_ymd($one);
				if (is_null($parsed)) $issues[] = input_issue("query.dates", "invalid_date", "Entry " . ($position + 1) . " of 'dates' is not a real date in YYYY-MM-DD format.");
				else $dates[$parsed] = $parsed;
			}
		}
		if (isset($query["from"]) || isset($query["to"])) $issues[] = input_issue("query.dates", "invalid_value", "Send 'dates' or 'from' with 'to', not both.");
	}

	$range = [];
	if (isset($query["from"]) || isset($query["to"])) {
		foreach (["from", "to"] as $name) {
			if (!isset($query[$name])) $issues[] = input_issue("query.$name", "missing_field", "'from' and 'to' go together: 'from' is the first date and 'to' the last one, both included.");
			else $range[$name] = input_query_date($query, $name, $issues);
		}
		if (count($range) === 2 && !in_array(null, $range, true)) {
			if ($range["from"] > $range["to"]) $issues[] = input_issue("query.from", "invalid_range", "'from' must not be after 'to'.");
			else {
				$days = date_diff(date_create($range["from"]), date_create($range["to"]))->days + 1;
				if ($days > DAY_LIMIT_QUERY_DAYS) $issues[] = input_issue("query.to", "too_many", "The period from 'from' to 'to' is $days days; the limit is " . DAY_LIMIT_QUERY_DAYS . ". Ask for it in several calls, or use GET /timeline_sync for everything.");
			}
		}
	}

	if (!isset($query["dates"]) && !isset($query["from"]) && !isset($query["to"])) {
		$issues[] = input_issue("query", "missing_field", "Say which days: 'dates=2026-03-10,2026-03-11' (at most " . DAY_LIMIT_QUERY_DAYS . "), 'from=2026-03-01&to=2026-03-31' (at most " . DAY_LIMIT_QUERY_DAYS . " days), or GET /day/2026-03-10 for one.");
	}
	input_exit_if_issues($issues);

	$result = [];
	foreach ($dates as $one) {
		$result[$one] = day_format_to_json(data_construct_day($db, $one, $no_user_account));
	}

	$all_days = [];
	if ($range) $all_days = db_select_day_timelines_frame($db, $range["from"], $range["to"], $no_user_account);

	// the days come in date order: the cycle is the one the last first day started
	$descriptions = data_descriptions_by_day($db, $all_days, $no_user_account);
	$cycle_date = null;
	foreach ($all_days as $row) {
		if ($row["cycle_1st_day"]) $cycle_date = $row["date_obs"];
		$result[$row["date_obs"]] = day_format_to_json(data_construct_day($db, $row["date_obs"], $no_user_account, $row, $cycle_date, null, $descriptions[$row["no_day"]] ?? []));
	}
	ksort($result);

	log_note(["n" => count($result)]);
	http_data(200, array_values($result));
}

// WRITING ONE DAY: it is replaced whole, what is not sent is empty
elseif ($method === "PUT") {
	input_query([], $issues);
	$body = input_body(DAY_INPUT_FIELDS, $issues, DAY_OUTPUT_ONLY_FIELDS);
	if (array_key_exists("date", $body) && $body["date"] !== $date) {
		$issues[] = input_issue("date", "date_mismatch", "'date' is not the date of the URL. Leave it out, or send the same date.");
	}
	unset($body["date"]);

	$day = day_format_validate($body, $issues);
	$last_write_client_UTC = http_client_timestamp(input_timestamp($body["lastWriteClientUtc"] ?? null, "lastWriteClientUtc", $issues));
	input_exit_if_issues($issues);

	data_reactivate_account($db, $user_account);

	$is_new = data_save_day($db, $no_user_account, $date, $day, $last_write_client_UTC);
	if (is_null($is_new)) {
		http_error(409, "stale_write", "This day was written more recently than this change: it was not saved.");
	}

	if ($is_new) http_location("day/$date");
	http_data($is_new ? 201 : 200, day_format_to_json(data_construct_day($db, $date, $no_user_account)));
}

// CLEARING ONE DAY
else {
	$query = input_query(["lastWriteClientUtc"], $issues);
	input_check_no_body($issues);
	$last_write_client_UTC = http_client_timestamp(input_timestamp($query["lastWriteClientUtc"] ?? null, "query.lastWriteClientUtc", $issues));
	input_exit_if_issues($issues);

	if (!data_clear_day($db, $no_user_account, $date, $last_write_client_UTC)) {
		http_error(409, "stale_write", "This day was written more recently than this deletion: it was not cleared.");
	}

	http_no_content();
}
