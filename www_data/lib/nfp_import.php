<?php
/* MONCYCLE.APP
**
** licence Creative Commons CC BY-NC-SA
**
** https://www.moncycle.app
** https://github.com/moncycle-app/backend-api-web-app
*/

declare(strict_types=1);

// The NFP import, in four stages the endpoint calls in order: nfp_import_parse() (the body -> the canonical object, or a refusal of the
// transport), nfp_import_schema_errors() (structure and types), nfp_import_build_plan() (meaning and consistency -> the days to write),
// nfp_import_write_plan() (the only one that writes, in one transaction). Nothing is written before the three checks have passed: a
// file is imported whole or not at all. A day of the file is read into the JSON day of lib/day_format.php and stored by data_write_day(),
// like a day posted to /api/v2/day.

require_once __DIR__ . "/user_account.php";
require_once __DIR__ . "/data.php";
require_once __DIR__ . "/day_format.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/nfp_format.php";

// ---------------------------------------------------------------------------
// Stage 1: the body
// ---------------------------------------------------------------------------

// ["ok" => true, "file" => object, "warnings" => [its nulls, for stage 3's report]] or ["ok" => false, "code", "message", "details"]:
// too big, not UTF-8, not JSON, or a schema version this build does not implement.
function nfp_import_parse(string $raw, ?int $content_length = null): array {
	$refuse = fn(string $code, string $message, array $details = []) =>
		["ok" => false, "code" => $code, "message" => $message, "details" => $details];

	if ($raw === '') {
		// Apache refuses a body over the limit before PHP starts; behind another server PHP drops one over post_max_size and leaves
		// php://input empty, so an empty body with a Content-Length is almost always a file too big.
		if (!is_null($content_length) && $content_length > 0) {
			return $refuse("file_too_large", sprintf(
				"The request body was dropped before it reached the application: %d bytes against a server limit of %s (post_max_size). Split the file into fewer cycles.",
				$content_length, ini_get('post_max_size')
			));
		}
		return $refuse("empty_body", "The request body is empty. Send the content of an .nfp file.");
	}

	if (strlen($raw) > NFP_LIMIT_BODY_BYTES) {
		return $refuse("file_too_large", sprintf(
			"The file is %d bytes; the limit is %d. Split it into fewer cycles.",
			strlen($raw), NFP_LIMIT_BODY_BYTES
		));
	}

	if (!mb_check_encoding($raw, 'UTF-8')) {
		return $refuse("invalid_encoding", "The file is not valid UTF-8.");
	}

	try {
		$decoded = json_decode($raw, false, NFP_LIMIT_JSON_DEPTH, JSON_THROW_ON_ERROR);
	} catch (JsonException $e) {
		return $refuse("invalid_json", "The file is not valid JSON.", ["reason" => $e->getMessage()]);
	}

	if (!is_object($decoded)) {
		return $refuse("invalid_json", "The top level of the file must be a JSON object.");
	}

	// before the normalising: a version this build does not implement must say so plainly, not fail later on fields
	$version = nfp_format_get($decoded, "schemaVersion");
	if (!is_string($version) || trim($version) === '') {
		return $refuse("missing_schema_version", "'schemaVersion' is required and must not be empty.");
	}
	$version = trim($version);
	if (!preg_match('/^\d+\.\d+$/', $version)) {
		return $refuse("invalid_schema_version", sprintf("'schemaVersion' must look like 1.0; got '%s'.", $version));
	}
	if (!in_array($version, NFP_SUPPORTED_SCHEMA_VERSIONS, true)) {
		return $refuse("unsupported_schema_version", sprintf(
			"Schema version %s is not supported. Supported: %s.",
			$version, implode(", ", NFP_SUPPORTED_SCHEMA_VERSIONS)
		));
	}

	$nulls = [];
	$file = nfp_format_drop_nulls(nfp_format_normalize($decoded), '', $nulls);
	return ["ok" => true, "file" => $file, "warnings" => nfp_import_null_warnings($nulls)];
}

// The nulls nfp_format_drop_nulls() took out of the file -> one warning per field.
function nfp_import_null_warnings(array $nulls): array {
	$warnings = [];
	foreach ($nulls as $where => $count) {
		$warnings[] = sprintf(
			"[%s] null%s: read as %s.",
			$where, $count > 1 ? sprintf(" on %d entries", $count) : "", $where === 'cycles[].days[]' ? "a day with nothing on it" : "not set"
		);
	}
	return $warnings;
}

// ---------------------------------------------------------------------------
// Stage 2: structure and types
// ---------------------------------------------------------------------------

// Errors "[where] what is wrong" ("cycles[0].days[7].temperature"). A wrong value in a field this app never stores breaks nothing: it is
// a warning, and stage 3 reports the field as ignored.
function nfp_import_schema_errors(object $file, array &$warnings = []): array {
	$schema = json_decode(NFP_FILE_SCHEMA);

	$storage = new \JsonSchema\SchemaStorage();
	$storage->addSchema('internal://nfp-file', $schema);
	$validator = new \JsonSchema\Validator(new \JsonSchema\Constraints\Factory($storage));
	$validator->validate($file, $schema);

	if ($validator->isValid()) return [];

	$errors = [];
	foreach ($validator->getErrors() as $error) {
		$where = $error['property'] !== '' ? $error['property'] : '(file)';
		if (nfp_import_is_unstored_field($where)) {
			$warnings[] = sprintf("[%s] %s. This app does not store that field: it was ignored.", $where, $error['message']);
			continue;
		}
		$errors[] = sprintf("[%s] %s", $where, $error['message']);
	}
	// a file wrong in one way is wrong on every day: keep the answer readable
	if (count($errors) > 50) {
		$remaining = count($errors) - 50;
		$errors = array_slice($errors, 0, 50);
		$errors[] = sprintf("... and %d more.", $remaining);
	}
	return $errors;
}

// Is the path inside a field this app never stores: the two blocks about the user, or a field of the NOT_STORED lists?
function nfp_import_is_unstored_field(string $where): bool {
	if (preg_match('/^(?:userInformation|userMethodPreferences)(?:[.\[]|$)/', $where)) return true;
	if (preg_match('/^cycles\[\d+\]\.days\[\d+\]\.(\w+)(?:[.\[]|$)/', $where, $m)) return isset(NFP_DAY_FIELDS_NOT_STORED[$m[1]]);
	if (preg_match('/^cycles\[\d+\]\.(\w+)(?:[.\[]|$)/', $where, $m)) return isset(NFP_CYCLE_FIELDS_NOT_STORED[$m[1]]);
	return false;
}

// ---------------------------------------------------------------------------
// Stage 3: meaning, consistency, and the days to write
// ---------------------------------------------------------------------------

// The file read into a report, every message starting with where it is ("[cycles[0]] ...", "[2026-04-01] ..."): issues (any one refuses
// the file), warnings (accepted, but say so), ignored (a field this app has nowhere to store), mapped (stored narrower than the file
// said), duplicates (dates the file holds twice, also in issues, as a list for the dry run). Issue or warning is whether the value can be
// stored at all: whatever this app's own export writes must stay a warning, or it would write files it refuses to read back. Answers the
// report with "plan" (one entry per day to write, encoded for db_update_day_timeline()), "cyclesRead" and "daysRead". $warnings are the
// earlier stages': they open the list. Nothing here touches the DB.
function nfp_import_build_plan(object $file, array $user_account, array $warnings = []): array {
	$account_method = user_account_method_name(intval($user_account["nfp_method"]));
	$report = ["issues" => [], "warnings" => $warnings, "ignored" => [], "mapped" => [], "duplicates" => []];
	$plan = [];
	$days_read = 0;

	nfp_import_account_notes($file, $account_method, $report);

	$cycles = nfp_format_get($file, "cycles");
	if (!is_array($cycles)) {
		$report["issues"][] = "[file] 'cycles' must be an array.";
		return nfp_import_plan_result($report, $plan, 0, 0);
	}

	// The count limits are advisory (the export is bounded by its dates, not by them): reported, and the file read in full. The body
	// size of stage 1 is what bounds the work.
	if (count($cycles) > NFP_LIMIT_CYCLES) {
		$report["warnings"][] = sprintf("[file] %d cycles, more than the %d a file usually carries. All of them were read.", count($cycles), NFP_LIMIT_CYCLES);
	}

	$dates_seen = [];
	foreach ($cycles as $index => $cycle) {
		$where = sprintf("cycles[%d]", $index);
		$header = nfp_import_cycle($cycle, $where, $account_method, $report);
		if (is_null($header)) continue;

		// a cycle runs to the next first day: an account left alone for years exports one cycle of gaps
		if (count($header["days"]) > NFP_LIMIT_DAYS_PER_CYCLE) {
			$report["warnings"][] = sprintf("[%s] %d days, more than the %d a cycle usually runs. The cycle was read in full.", $where, count($header["days"]), NFP_LIMIT_DAYS_PER_CYCLE);
		}

		$was_under_total = $days_read <= NFP_LIMIT_DAYS_TOTAL;
		$days_read += count($header["days"]);
		if ($was_under_total && $days_read > NFP_LIMIT_DAYS_TOTAL) {
			$report["warnings"][] = sprintf("[file] more than %d days in one file. All of them were read.", NFP_LIMIT_DAYS_TOTAL);
		}

		$plan = array_merge($plan, nfp_import_cycle_days($header, $where, $dates_seen, $report));
	}

	return nfp_import_plan_result($report, $plan, count($cycles), $days_read);
}

function nfp_import_plan_result(array $report, array $plan, int $cycles_read, int $days_read): array {
	return [
		"issues" => $report["issues"],
		"warnings" => $report["warnings"],
		"ignored" => array_values(array_unique($report["ignored"])),
		"mapped" => $report["mapped"],
		"duplicates" => array_values(array_unique($report["duplicates"])),
		"plan" => $plan,
		"cyclesRead" => $cycles_read,
		"daysRead" => $days_read,
	];
}

// An import never changes the account itself: a file must not change its address or its method.
function nfp_import_account_notes(object $file, string $account_method, array &$report): void {
	if (nfp_format_has($file, "userInformation")) {
		$report["ignored"][] = "[file] userInformation: the account's own identity is never changed by an import.";
	}

	$preferences = nfp_format_get($file, "userMethodPreferences");
	if (is_null($preferences)) return;

	$preferred = nfp_format_get($preferences, "preferredMethod");
	$note = "[file] userMethodPreferences: the account's method is only changed from the account settings";
	if (is_string($preferred) && $preferred !== '' && strcasecmp($preferred, $account_method) !== 0) {
		$note .= sprintf(" (the file prefers '%s', this account is set to '%s')", $preferred, $account_method);
	}
	$report["ignored"][] = $note . ".";
}

// The days of a file are between these: nothing older than the floor, nothing after today plus the grace (a client a time zone ahead).
function nfp_import_date_limits(): array {
	return [new DateTime(NFP_DATE_FLOOR), new DateTime('today +' . NFP_FUTURE_GRACE_DAYS . ' day')];
}

// What a cycle needs before its days are read: ["method", "start_date", "days"], or null when it is refused (an issue says why).
function nfp_import_cycle(mixed $cycle, string $where, string $account_method, array &$report): ?array {
	[$floor, $ceiling] = nfp_import_date_limits();

	$method = nfp_format_get($cycle, "method");
	$method = is_string($method) ? trim($method) : '';
	$start_date = nfp_format_get($cycle, "cycleStartDate");
	$start_date = is_string($start_date) ? trim($start_date) : '';

	// the schema has already checked the YYYY-MM-DD shape; this is the calendar check
	$parts = explode('-', $start_date);
	if (count($parts) !== 3 || !checkdate(intval($parts[1]), intval($parts[2]), intval($parts[0]))) {
		$report["issues"][] = sprintf("[%s] cycleStartDate '%s' is not a real calendar date.", $where, $start_date);
		return null;
	}

	$cycle_start = new DateTime($start_date);
	if ($cycle_start < $floor) {
		$report["issues"][] = sprintf("[%s] cycleStartDate %s is before %s.", $where, $start_date, NFP_DATE_FLOOR);
		return null;
	}
	if ($cycle_start > $ceiling) {
		$report["issues"][] = sprintf("[%s] cycleStartDate %s is in the future.", $where, $start_date);
		return null;
	}

	if ($method === '') {
		$report["issues"][] = sprintf("[%s] 'method' is required and must not be empty.", $where);
		return null;
	}
	if (!in_array($method, NFP_METHODS_KNOWN, true)) {
		$report["warnings"][] = sprintf("[%s] method '%s' is not one this app knows; its days were read for the fields every method shares.", $where, $method);
	}
	elseif (!in_array($method, NFP_METHODS_NATIVE, true)) {
		$report["warnings"][] = sprintf("[%s] method '%s' is not recorded by this app; only the fields it shares with %s were kept.", $where, $method, $account_method);
	}
	elseif ($method !== $account_method) {
		$report["warnings"][] = sprintf("[%s] the cycle uses '%s' while this account is set to '%s'. The data was imported; change the method in the account settings to see it.", $where, $method, $account_method);
	}

	foreach (NFP_CYCLE_FIELDS_NOT_STORED as $field => $why) {
		if (nfp_format_has($cycle, $field)) $report["ignored"][] = sprintf("[%s] %s: %s.", $where, $field, $why);
	}
	foreach (nfp_format_object_keys($cycle) as $key) {
		if (!in_array($key, NFP_CYCLE_FIELDS_STRUCTURAL, true) && !isset(NFP_CYCLE_FIELDS_NOT_STORED[$key])) {
			$report["ignored"][] = sprintf("[%s] %s: not a cycle field of schema %s.", $where, $key, NFP_SCHEMA_VERSION);
		}
	}

	$days = nfp_format_get($cycle, "days");
	if (!is_array($days)) {
		$report["issues"][] = sprintf("[%s] 'days' must be an array.", $where);
		return null;
	}

	return ["method" => $method, "start_date" => $start_date, "days" => $days];
}

// The days of a cycle -> the entries to write. The day at index n is the nth from the cycle's start; $dates_seen (date => where) is shared
// by the cycles of the file.
function nfp_import_cycle_days(array $cycle, string $where, array &$dates_seen, array &$report): array {
	[, $ceiling] = nfp_import_date_limits();
	$plan = [];
	$cursor = new DateTime($cycle["start_date"]);

	foreach ($cycle["days"] as $day_index => $nfp_day) {
		$day_date = clone $cursor;
		$date = $day_date->format('Y-m-d');
		$cursor->modify('+1 day');

		if (!nfp_format_is_object($nfp_day)) {
			$report["issues"][] = sprintf("[%s] day %d (%s) must be an object.", $where, $day_index, $date);
			continue;
		}
		if (is_array($nfp_day)) $nfp_day = new stdClass();

		// overlapping cycles put a date in two of them: refused rather than guessed at (gaps count too)
		if (isset($dates_seen[$date])) {
			$report["issues"][] = sprintf("[%s] %s appears twice in the file (also in %s): the cycles overlap.", $where, $date, $dates_seen[$date]);
			$report["duplicates"][] = $date;
			continue;
		}
		$dates_seen[$date] = $where;

		$is_cycle_first_day = ($day_index === 0);
		$mapping = nfp_import_day($nfp_day, $cycle["method"], $date, $report);

		// a day past today is only a problem when it carries something: the export pads a cycle with gaps up to today, and a client a
		// time zone ahead would otherwise make its own file unimportable around midnight
		if ($day_date > $ceiling && ($mapping["content"] || $is_cycle_first_day)) {
			$report["issues"][] = sprintf("[%s] day %d falls on %s, which is in the future.", $where, $day_index, $date);
			continue;
		}

		// the first day of a cycle is written even empty (it marks the cycle); any other empty day is a gap, not written
		if (!$mapping["content"] && !$is_cycle_first_day) continue;

		$fields = day_format_from_json($mapping["day"] + ["cycleFirstDay" => $is_cycle_first_day]);

		// the five note groups packed in fc_score: only here is the packed length known
		if (!is_null($fields["fc_score"]) && mb_strlen($fields["fc_score"]) > DAY_LIMIT_FC_SCORE_CHARS) {
			$report["issues"][] = sprintf("[%s] the FertilityCare notes pack into %d characters; the limit is %d.", $date, mb_strlen($fields["fc_score"]), DAY_LIMIT_FC_SCORE_CHARS);
			continue;
		}

		$plan[] = [
			"date" => $date,
			"cycleStartDate" => $cycle["start_date"],
			"fields" => $fields,
			"sensations" => $mapping["sensations"],
			"observations" => $mapping["observations"],
		];
	}

	return $plan;
}

// One NFP day -> ["day" => the JSON day, "sensations", "observations", "content" => false for a day the file leaves empty]; what is wrong
// or not storable goes to the report. The format reuses keys across methods (codifiedMucusSensation is "0".."10WL" under FertilityCare,
// "Dry|Humid|Wet|Lubricated" under symptothermic_fr): only the FertilityCare reading can be stored, others are reported.
function nfp_import_day(object $nfp_day, string $method, string $date, array &$report): array {
	$day = [];

	nfp_import_day_comment($nfp_day, $date, $day, $report);
	nfp_import_day_not_observed($nfp_day, $date, $day, $report);
	nfp_import_day_stamp($nfp_day, $date, $day, $report);
	nfp_import_day_notes($nfp_day, $method, $date, $day, $report);
	[$sensations, $observations] = nfp_import_day_labels($nfp_day, $date, $report);
	nfp_import_day_flags($nfp_day, $date, $day, $report);
	nfp_import_day_temperature($nfp_day, $date, $day, $report);
	nfp_import_day_unmapped($nfp_day, $date, $report);

	return [
		"day" => $day,
		"sensations" => $sensations,
		"observations" => $observations,
		"content" => !empty($day) || !empty($sensations) || !empty($observations),
	];
}

function nfp_import_day_comment(object $nfp_day, string $date, array &$day, array &$report): void {
	$comments = nfp_format_get($nfp_day, "comments");
	if (!is_array($comments)) return;

	$parts = [];
	foreach ($comments as $comment) {
		if (!is_string($comment)) continue;
		$comment = day_format_clean_text($comment);
		if ($comment !== '') $parts[] = $comment;
	}
	if (empty($parts)) return;

	$joined = implode("\n", $parts);
	if (mb_strlen($joined) > DAY_LIMIT_COMMENT_CHARS) {
		$report["issues"][] = sprintf("[%s] the comments come to %d characters together; the limit is %d.", $date, mb_strlen($joined), DAY_LIMIT_COMMENT_CHARS);
		return;
	}
	$day["comment"] = $joined;
}

// The format calls mucusNotObserved incompatible with any observation; the app stores both side by side, and its export writes them:
// both are kept, and the contradiction is reported.
function nfp_import_day_not_observed(object $nfp_day, string $date, array &$day, array &$report): void {
	if (nfp_format_get($nfp_day, "mucusNotObserved") !== true) return;

	$clash = array_filter(NFP_MUCUS_NOT_OBSERVED_INCOMPATIBLE, fn($field) => nfp_format_has($nfp_day, $field));
	if (!empty($clash)) {
		$report["warnings"][] = sprintf(
			"[%s] mucusNotObserved says nothing was recorded, but the day also carries %s. Both were imported as they are.",
			$date, implode(", ", $clash)
		);
	}
	$day["dayNotObserved"] = true;
}

function nfp_import_day_stamp(object $nfp_day, string $date, array &$day, array &$report): void {
	$colour = nfp_format_get($nfp_day, "stampColor");
	$baby = nfp_format_get($nfp_day, "stampBaby") === true;

	if (is_string($colour) && $colour !== '') {
		$day["stampColor"] = $colour;          // the schema has checked the enum
		// a white stamp is the baby on no colour: without the baby it is no stamp
		if ($colour === 'White' && !$baby) {
			$report["mapped"][] = sprintf("[%s] stampColor 'White' without stampBaby is stored as no stamp.", $date);
		}
	}
	if ($baby) $day["stampBaby"] = true;
}

// The codified note groups and the arrow
function nfp_import_day_notes(object $nfp_day, string $method, string $date, array &$day, array &$report): void {
	if ($method === NFP_METHOD_FERTILITY_CARE) {
		foreach (array_keys(DAY_FORMAT_FC_GROUPS) as $field) {
			$value = nfp_format_get($nfp_day, $field);
			if (!is_string($value) || trim($value) === '') continue;

			$canonical = day_format_fc_canonical($field, $value);
			if (is_null($canonical)) {
				$report["ignored"][] = sprintf("[%s] %s '%s': outside the FertilityCare notation this app stores.", $date, $field, trim($value));
				continue;
			}
			$day[$field] = $canonical;
		}

		$arrow = nfp_format_get($nfp_day, "codifiedArrow");
		if (is_string($arrow) && trim($arrow) !== '') {
			if (in_array($arrow, NFP_ARROWS, true)) $day["codifiedArrow"] = $arrow;
			else $report["ignored"][] = sprintf("[%s] codifiedArrow '%s': not one of %s.", $date, trim($arrow), implode("|", NFP_ARROWS));
		}
		return;
	}

	// bleeding is the one codified field with a meaning outside FertilityCare: a red stamp here
	$bleeding = nfp_format_get($nfp_day, "codifiedBleedingObservation");
	if (is_string($bleeding) && trim($bleeding) !== '') {
		if (!isset($day["stampColor"])) {
			$day["stampColor"] = 'Red';
			$report["mapped"][] = sprintf(
				"[%s] codifiedBleedingObservation '%s' stored as a Red stamp (%s records bleeding as a stamp).",
				$date, trim($bleeding), $method
			);
		}
		else {
			$report["ignored"][] = sprintf("[%s] codifiedBleedingObservation '%s': the day already carries a %s stamp.", $date, trim($bleeding), $day["stampColor"]);
		}
	}
	foreach (["codifiedMucusSensation", "codifiedMucusObservation", "codifiedNumberObservations", "codifiedPainObservations", "codifiedArrow"] as $field) {
		if (nfp_format_has($nfp_day, $field)) {
			$report["ignored"][] = sprintf("[%s] %s: only the FertilityCare reading of this field can be stored, and this cycle is '%s'.", $date, $field, $method);
		}
	}
}

// The free-text sensations and observations of the day: [the sensations, the observations], as names.
function nfp_import_day_labels(object $nfp_day, string $date, array &$report): array {
	$names_by_field = ["freeMucusSensation" => [], "freeMucusObservation" => []];

	foreach (array_keys($names_by_field) as $field) {
		$values = nfp_format_get($nfp_day, $field);
		if (!is_array($values)) continue;

		$names = [];
		foreach ($values as $name) {
			if (!is_string($name)) continue;
			$name = day_format_clean_text($name);
			if ($name === '') continue;
			if (mb_strlen($name) > DAY_LIMIT_DESCRIPTION_CHARS) {
				$report["issues"][] = sprintf(
					"[%s] the %s entry '%s...' is %d characters; the limit is %d.",
					$date, $field, mb_substr($name, 0, 30), mb_strlen($name), DAY_LIMIT_DESCRIPTION_CHARS
				);
				continue;
			}
			$names[] = $name;
		}
		$names = array_values(array_unique($names));

		// nothing caps the labels of a day, so the export can write more than the advisory count: all are imported
		if (count($names) > NFP_LIMIT_DESCRIPTIONS_PER_DAY) {
			$report["warnings"][] = sprintf(
				"[%s] %d %s entries, more than the %d a day usually carries. All of them were imported.",
				$date, count($names), $field, NFP_LIMIT_DESCRIPTIONS_PER_DAY
			);
		}
		$names_by_field[$field] = $names;
	}

	return array_values($names_by_field);
}

// isPeak, pregnancy, the union, the counter
function nfp_import_day_flags(object $nfp_day, string $date, array &$day, array &$report): void {
	foreach (["isPeak", "booleanPregnancyDetected"] as $flag) {
		if (nfp_format_get($nfp_day, $flag) === true) $day[$flag] = true;
	}

	$union = nfp_format_get($nfp_day, "sexUnion");
	if (is_string($union) && trim($union) !== '') {
		$day["sexUnion"] = true;
		if ($union !== 'Union') {
			$report["mapped"][] = sprintf("[%s] sexUnion '%s' stored as a plain union: day_timeline records only whether there was one.", $date, $union);
		}
	}

	$counter = nfp_format_get($nfp_day, "counterStart");
	if (is_int($counter) && $counter > 0) $day["counterStart"] = $counter;       // the schema has bounded it to 0-255
}

function nfp_import_day_temperature(object $nfp_day, string $date, array &$day, array &$report): void {
	$temperature = nfp_format_get($nfp_day, "temperature");

	if (!is_int($temperature) && !is_float($temperature)) {
		if (nfp_format_has($nfp_day, "temperatureTime")) {
			$report["ignored"][] = sprintf("[%s] temperatureTime: there is no temperature on this day to time.", $date);
		}
		return;
	}

	$value = floatval($temperature);
	$printed = rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');

	// outside what the column holds the file is refused; inside it but outside a body's band the reading is kept (the export writes it)
	if ($value < DAY_TEMPERATURE_STORABLE_MIN || $value > DAY_TEMPERATURE_STORABLE_MAX) {
		$report["issues"][] = sprintf(
			"[%s] temperature %s cannot be stored: the column holds %.1f-%.2f C.",
			$date, $printed, DAY_TEMPERATURE_STORABLE_MIN, DAY_TEMPERATURE_STORABLE_MAX
		);
		return;
	}
	if ($value < NFP_TEMPERATURE_MIN || $value > NFP_TEMPERATURE_MAX) {
		$report["warnings"][] = sprintf(
			"[%s] temperature %s is outside the plausible range %.1f-%.1f C. It was imported as it is.",
			$date, $printed, NFP_TEMPERATURE_MIN, NFP_TEMPERATURE_MAX
		);
	}
	$day["temperature"] = round($value, 2);       // decimal(4,2): round here rather than letting MariaDB do it silently

	$time = nfp_format_get($nfp_day, "temperatureTime");
	if (!is_string($time) || trim($time) === '') return;

	// a TIME column runs to 838:59:59, so stored data reaches this: the reading is kept without its hour
	if (!is_null($clock = day_format_time($time))) $day["temperatureTime"] = $clock;
	else {
		$report["warnings"][] = sprintf("[%s] temperatureTime '%s' is not an hh:mm:ss time of day; the temperature was imported without it.", $date, trim($time));
	}
}

// Everything this app cannot hold, and every key the schema does not know
function nfp_import_day_unmapped(object $nfp_day, string $date, array &$report): void {
	foreach (NFP_DAY_FIELDS_NOT_STORED as $field => $why) {
		if (nfp_format_has($nfp_day, $field)) $report["ignored"][] = sprintf("[%s] %s: %s.", $date, $field, $why);
	}
	foreach (nfp_format_object_keys($nfp_day) as $key) {
		if (!in_array($key, NFP_DAY_FIELDS_STORED, true) && !isset(NFP_DAY_FIELDS_NOT_STORED[$key])) {
			$report["ignored"][] = sprintf("[%s] %s: not a day field of schema %s.", $date, $key, NFP_SCHEMA_VERSION);
		}
	}
}

// ---------------------------------------------------------------------------
// Stage 3b: what stage 4 would do, read instead of written (the dry run)
// ---------------------------------------------------------------------------

// The answer of nfp_import_write_plan(), read: the question before an import is "what does this file touch that I already have?". The
// dates the account has a day on (skipped, or with $override replaced; daysAlreadyInAccount lists them either way, to choose), and the
// labels the file uses (new ones would be created; one recorded under the other type keeps its type). Two SELECTs, nothing written.
function nfp_import_preview_plan($db, int $no_user_account, array $plan, bool $override): array {
	$already = [];
	$created = [];
	$overwritten = [];
	$skipped = [];

	if (!empty($plan)) {
		// the plan holds no date twice (stage 3 refuses it): one range query answers every date
		$dates = array_column($plan, "date");
		$existing = array_flip(db_select_day_timeline_dates_frame($db, min($dates), max($dates), $no_user_account));

		foreach ($dates as $date) {
			if (!isset($existing[$date])) {
				$created[] = $date;
				continue;
			}
			$already[] = $date;
			if ($override) $overwritten[] = $date;
			else $skipped[] = $date;
		}
	}

	// the walk of nfp_import_write_plan(): the days it would write, sensations then observations, a name's first use deciding its type
	$recorded = [];
	foreach (db_select_description_name_type($db, $no_user_account) as $description) {
		$recorded[$description["name"]] = intval($description["type"]);
	}

	$descriptions_created = 0;
	$narrowed = [];
	$is_skipped = array_flip($skipped);

	foreach ($plan as $entry) {
		if (isset($is_skipped[$entry["date"]])) continue;
		foreach ([DESCRIPTION_TYPE_SENSATION => $entry["sensations"], DESCRIPTION_TYPE_OBSERVATION => $entry["observations"]] as $type => $names) {
			foreach ($names as $name) {
				if (!isset($recorded[$name])) {
					$recorded[$name] = $type;
					$descriptions_created += 1;
				}
				elseif ($recorded[$name] !== $type) {
					$narrowed[] = nfp_import_narrowed_note($entry["date"], $name, $recorded[$name]);
				}
			}
		}
	}

	return [
		"daysCreated" => $created,
		"daysOverwritten" => $overwritten,
		"daysSkipped" => $skipped,
		"daysAlreadyInAccount" => $already,
		"descriptionsCreated" => $descriptions_created,
		"narrowed" => $narrowed,
	];
}

// ---------------------------------------------------------------------------
// Stage 4: the write
// ---------------------------------------------------------------------------

// A name the file uses under one type that the account records under the other: the label keeps its type. Shared by the dry run and the
// write, which must say the same.
function nfp_import_narrowed_note(string $date, string $name, int $recorded_type): string {
	$type_name = $recorded_type === DESCRIPTION_TYPE_UNDEFINED ? "label" : (DESCRIPTION_TYPE_NAMES[$recorded_type] ?? "label");
	return sprintf(
		"[%s] '%s' is recorded as a %s on this account and stays one: a label carries a single type here.",
		$date, $name, $type_name
	);
}

// The plan in one transaction: a failure anywhere leaves the account untouched. A date that has a day is skipped, or with $override
// replaced whole, descriptions included (a POST /api/v2/day does the same, through data_write_day()). $written_days receives the [no_day,
// date] of each day written and $created_descriptions the id of each description made, for the log.
function nfp_import_write_plan($db, int $no_user_account, array $plan, bool $override, string $last_write_client_UTC, ?array &$written_days = null, ?array &$created_descriptions = null): array {
	$written_days = [];
	$created_descriptions = [];
	return db_transaction($db, function () use ($db, $no_user_account, $plan, $override, $last_write_client_UTC, &$written_days, &$created_descriptions) {
		db_select_user_account_for_update($db, $no_user_account);
		$created = [];
		$overwritten = [];
		$skipped = [];
		$already = [];
		$descriptions_created = 0;
		$known = [];
		$narrowed = [];

		foreach ($plan as $entry) {
			$date = $entry["date"];

			$existing = db_select_day_timeline($db, $date, $no_user_account);
			if (!is_null($existing)) $already[] = $date;

			if (!is_null($existing) && !$override) {
				$skipped[] = $date;
				continue;
			}

			$no_descriptions = [];
			foreach ([DESCRIPTION_TYPE_SENSATION => $entry["sensations"], DESCRIPTION_TYPE_OBSERVATION => $entry["observations"]] as $type => $names) {
				foreach ($names as $name) {
					$description = data_resolve_description($db, $no_user_account, $name, $type, $last_write_client_UTC, $known);
					if ($description["created"]) {
						$descriptions_created += 1;
						$created_descriptions[] = $description["id"];
					}
	
					if ($description["type"] !== $type) $narrowed[] = nfp_import_narrowed_note($date, $name, $description["type"]);
					$no_descriptions[] = $description["id"];
				}
			}

			$no_day = data_write_day($db, $no_user_account, $date, $existing, $entry["fields"], $no_descriptions, $last_write_client_UTC);
			$written_days[] = ["no_day" => $no_day, "date" => $date];

			if (is_null($existing)) $created[] = $date;
			else $overwritten[] = $date;
		}

		// daysAlreadyInAccount: the days of the file the account had, whatever $override did with them
		return [
			"daysCreated" => $created,
			"daysOverwritten" => $overwritten,
			"daysSkipped" => $skipped,
			"daysAlreadyInAccount" => $already,
			"descriptionsCreated" => $descriptions_created,
			"narrowed" => $narrowed,
		];
	});
}

// The `data.import` log line: the counts and the options, and for a real run the bounds of what it wrote (dates, day ids, ids of the
// descriptions made), not a list.
function nfp_import_log_fields(array $report, array $written_days = [], array $created_descriptions = []): array {
	$fields = [
		"dry" => $report["dryRun"],
		"ovr" => $report["override"],
		"rd" => $report["daysRead"],
		"cr" => count($report["daysCreated"]),
		"ow" => count($report["daysOverwritten"]),
		"nds" => $report["descriptionsCreated"],
	];
	if (empty($written_days)) return $fields;

	$dates = array_column($written_days, "date");
	$ids = array_column($written_days, "no_day");
	$fields += ["dt0" => min($dates), "dt1" => max($dates), "day0" => min($ids), "day1" => max($ids)];
	if ($created_descriptions) $fields += ["dsc0" => min($created_descriptions), "dsc1" => max($created_descriptions)];
	return $fields;
}
