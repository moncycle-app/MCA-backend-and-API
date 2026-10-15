<?php
/* MONCYCLE.APP
**
** licence Creative Commons CC BY-NC-SA
**
** https://www.moncycle.app
** https://github.com/moncycle-app/backend-api-web-app
*/

// The NFP export: an account's days as an NFP file, to move to another app. The day is the JSON day of lib/day_format.php with the few
// fields the format names differently; the way back is lib/nfp_import.php, and a file this app exports must always import.

require_once __DIR__ . "/data.php";
require_once __DIR__ . "/date.php";
require_once __DIR__ . "/day_format.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/server.php";
require_once __DIR__ . "/user_account.php";

// One JSON day as an NFP day, with only what the format defines and nothing empty ([] is a day with nothing, "{}" in the file). Every
// stored value is written whatever the account's method: dropping data from a portability file is worse than a field a receiving app
// does not expect, and the format allows custom values there.
function nfp_export_day(array $day): array {
	$nfp = [];

	// The descriptions with no type (freeOther) have no field in the format: they ride in "comments" after the day's own, joined with
	// ", ". The import joins the entries into the one comment of the day and refuses it past DAY_LIMIT_COMMENT_CHARS, so a name that does
	// not fit is left out.
	$comment = day_format_clean_text((string) ($day['comment'] ?? ''));
	$room = DAY_LIMIT_COMMENT_CHARS - ($comment !== '' ? mb_strlen($comment) + 1 : 0);
	$other = '';
	foreach ($day['freeOther'] ?? [] as $name) {
		$name = day_format_clean_text($name);
		$joined = $other === '' ? $name : $other . ', ' . $name;
		if ($name !== '' && mb_strlen($joined) <= $room) $other = $joined;
	}
	$comments = array_values(array_filter([$comment, $other], fn($entry) => $entry !== ''));
	if (!empty($comments)) $nfp['comments'] = $comments;

	if (!empty($day['dayNotObserved'])) $nfp['mucusNotObserved'] = true;
	if (day_format_filled($day, 'stampColor')) $nfp['stampColor'] = $day['stampColor'];
	if (!empty($day['stampBaby'])) $nfp['stampBaby'] = true;
	foreach ([...array_keys(DAY_FORMAT_FC_GROUPS), 'codifiedArrow'] as $field) {
		if (day_format_filled($day, $field)) $nfp[$field] = (string) $day[$field];
	}
	foreach (['freeMucusSensation', 'freeMucusObservation'] as $field) {
		if (!empty($day[$field]) && is_array($day[$field])) $nfp[$field] = array_values($day[$field]);
	}
	if (!empty($day['temperature'])) {   // 0 is "none recorded"
		$nfp['temperature'] = round(floatval($day['temperature']), 2);
		if (day_format_filled($day, 'temperatureTime')) $nfp['temperatureTime'] = $day['temperatureTime'];
	}
	if (!empty($day['isPeak'])) $nfp['isPeak'] = true;
	if (!empty($day['booleanPregnancyDetected'])) $nfp['booleanPregnancyDetected'] = true;
	if (!empty($day['sexUnion'])) $nfp['sexUnion'] = 'Union';   // the app records whether there was one, not which kind
	if (!empty($day['counterStart'])) $nfp['counterStart'] = intval($day['counterStart']);
	return $nfp;
}

// The cycles of a period, split at each cycle's first day. The period is widened back to the first day of the cycle $start_date is in,
// so that no cycle is cut in half, and never runs past today. Three queries. $rows_read: the day rows read (a gap is none).
function nfp_export_cycles($db, string $start_date, string $end_date, array $user_account, ?int &$rows_read = null): array {
	$no_user_account = intval($user_account["no_user_account"]);
	$method = user_account_method_name(intval($user_account["nfp_method"]));
	$cycle_start_date = db_select_cycle($db, $start_date, $no_user_account) ?? $start_date;

	$rows = db_select_day_timelines_range($db, $cycle_start_date, date_next_day($end_date), $no_user_account);
	$descriptions = data_descriptions_by_day($db, $rows, $no_user_account);
	$rows = array_column($rows, null, 'date_obs');
	$rows_read = count($rows);

	$cycles = [];
	$current = ["method" => $method, "cycleStartDate" => $cycle_start_date, "days" => []];
	$last_date = min($end_date, date('Y-m-d'));
	for ($date = $cycle_start_date; $date <= $last_date; $date = date_next_day($date)) {
		if (!isset($rows[$date])) {
			$current["days"][] = new stdClass();   // a gap: "{}"
			continue;
		}
		$row = $rows[$date];
		if (!empty($row["cycle_1st_day"]) && $date !== $current["cycleStartDate"]) {
			$cycles[] = $current;
			$current = ["method" => $method, "cycleStartDate" => $date, "days" => []];
		}
		$nfp_day = nfp_export_day(day_format_to_json(data_day($row, null, $descriptions[$row["no_day"]] ?? [])));
		$current["days"][] = empty($nfp_day) ? new stdClass() : $nfp_day;
	}
	$cycles[] = $current;
	return $cycles;
}

// The whole file. $anonymous leaves out what identifies the account (to share with a practitioner); the data is the same.
function nfp_export_file($db, string $start_date, string $end_date, array $user_account, bool $anonymous, string $app_version, ?int &$rows_read = null): array {
	$file = [
		"schemaVersion" => NFP_SCHEMA_VERSION,
		"fileInformation" => ["sourceApp" => "moncycle.app", "sourceAppVersion" => $app_version, "fileCreationTimestamp" => date('c')],
	];
	if (!$anonymous) {
		// no birthDate: the account holds a birth year, and the format wants a full date
		$user = ["identifier" => strval($user_account["no_user_account"])];
		if (!empty($user_account["name_user_account"])) $user["firstName"] = $user_account["name_user_account"];
		if (!empty($user_account["login_email"])) $user["email"] = $user_account["login_email"];
		$file["userInformation"] = $user;
	}
	$file["userMethodPreferences"] = ["preferredMethod" => user_account_method_name(intval($user_account["nfp_method"]))];
	$file["cycles"] = nfp_export_cycles($db, $start_date, $end_date, $user_account, $rows_read);
	return $file;
}

// The file as text: accents and the timestamp readable, a round 37.0 kept a number; $pretty to read it in the browser.
function nfp_export_json($db, string $start_date, string $end_date, array $user_account, bool $anonymous, bool $pretty = false, ?int &$rows_read = null): string {
	$file = nfp_export_file($db, $start_date, $end_date, $user_account, $anonymous, server_build()["version"], $rows_read);
	$flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION;
	return json_encode($file, $pretty ? ($flags | JSON_PRETTY_PRINT) : $flags);
}
