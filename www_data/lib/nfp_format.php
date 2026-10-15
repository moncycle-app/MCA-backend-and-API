<?php
/* MONCYCLE.APP
**
** licence Creative Commons CC BY-NC-SA
**
** https://www.moncycle.app
** https://github.com/moncycle-app/backend-api-web-app
*/

// The NFP interchange format: its JSON Schema, its reading helpers, and the older variants of the format brought to the one shape the
// rest of the code reads. Its constants are the NFP FILE FORMAT section of constants.php. How the format maps onto this app's storage is
// lib/nfp_export.php and lib/nfp_import.php; nothing here touches the DB or the request.
//
// The reference of schema 1.0 is the format's structure document. A field marked _customValuesAllowed accepts values outside its enum,
// so only the closed vocabularies are enforced (stampColor, sexUnion, Yes/No/Undecided...); a _methodValidation null ("not part of
// that method") is advisory: reported, never a reason to refuse a file.

// The schema, for the canonical shape (nfp_format_normalize() first): structure and types only, the rest is lib/nfp_import.php, which
// can name the cycle and the day. No maxItems on cycles, days or the freeMucus lists, and no hh:mm:ss pattern on temperatureTime: this
// app's own export can exceed them (a cycle padded with years of gaps; a TIME column runs to 838:59:59), and refusing here would deny
// stage 3 the chance to report them as warnings.

const NFP_FILE_SCHEMA = <<<'JSON'
{
	"type": "object",
	"required": ["schemaVersion", "fileInformation", "cycles"],
	"properties": {
		"schemaVersion": {
			"description": "Version of the schema this file claims to follow.",
			"type": "string",
			"pattern": "^[0-9]+\\.[0-9]+$"
		},
		"fileInformation": {
			"type": "object",
			"required": ["sourceApp", "sourceAppVersion", "fileCreationTimestamp"],
			"properties": {
				"sourceApp": {"type": "string", "minLength": 1, "maxLength": 255},
				"sourceAppVersion": {"type": "string", "minLength": 1, "maxLength": 64},
				"fileCreationTimestamp": {
					"description": "ISO 8601 with timezone; a bare 'YYYY-MM-DD hh:mm:ss' is also accepted.",
					"type": "string",
					"minLength": 1,
					"maxLength": 64
				}
			}
		},
		"userInformation": {
			"type": "object",
			"properties": {
				"identifier": {"type": "string", "maxLength": 255},
				"email": {"type": "string", "maxLength": 255},
				"firstName": {"type": "string", "maxLength": 255},
				"lastName": {"type": "string", "maxLength": 255},
				"birthDate": {"type": "string", "maxLength": 32},
				"comment": {"type": "string", "maxLength": 1024}
			}
		},
		"userMethodPreferences": {
			"type": "object",
			"properties": {
				"preferredMethod": {"type": "string", "maxLength": 64},
				"twoTenthShift": {"type": "boolean"},
				"oestrogenicalHollow": {"type": "boolean"},
				"irregularitiesSensibility": {"type": "boolean"}
			}
		},
		"cycles": {
			"type": "array",
			"minItems": 1,
			"items": {
				"type": "object",
				"required": ["method", "cycleStartDate", "days"],
				"properties": {
					"method": {"type": "string", "minLength": 1, "maxLength": 64},
					"cycleStartDate": {"type": "string", "pattern": "^\\d{4}-\\d{2}-\\d{2}$"},
					"comment": {"type": "string", "maxLength": 1024},
					"cycleParticularCases": {
						"type": "array",
						"maxItems": 8,
						"items": {"type": "string", "enum": ["FirstEyedCycle", "PostPill", "Pregnancy", "PostPartum"]}
					},
					"currentCyclePlannedPregnancy": {"type": "string", "enum": ["Yes", "No", "Undecided"]},
					"nextMonthsPlannedPregnancy": {"type": "string", "enum": ["Yes", "No", "Undecided"]},
					"endOfCycleFollowUp": {"type": "string", "enum": ["Pregnancy", "Menopause", "Disease", "Other"]},
					"temperatureUsualTime": {"type": "string", "pattern": "^\\d{2}:\\d{2}:\\d{2}$"},
					"temperatureCaptureType": {"type": "string", "maxLength": 64},
					"temperatureThermometerType": {"type": "string", "maxLength": 64},
					"days": {
						"type": "array",
						"items": {
							"type": "object",
							"properties": {
								"comments": {"type": "array", "maxItems": 32, "items": {"type": "string", "maxLength": 256}},
								"codifiedBleedingObservation": {"type": "string", "maxLength": 32},
								"nonUsualBleeding": {"type": "boolean"},
								"codifiedMucusSensation": {"type": "string", "maxLength": 32},
								"freeMucusSensation": {"type": "array", "items": {"type": "string", "maxLength": 256}},
								"codifiedMucusObservation": {"type": "string", "maxLength": 32},
								"mucusNotObserved": {"type": "boolean"},
								"freeMucusObservation": {"type": "array", "items": {"type": "string", "maxLength": 256}},
								"codifiedArrow": {"type": "string", "maxLength": 16},
								"codifiedNumberObservations": {"type": "string", "maxLength": 16},
								"codifiedPainObservations": {"type": "string", "maxLength": 16},
								"booleanCervixMucus": {"type": "boolean"},
								"booleanCervixAperture": {"type": "boolean"},
								"codifiedCervixConsistency": {"type": "string", "maxLength": 32},
								"codifiedCervixHeight": {"type": "string", "maxLength": 32},
								"temperature": {"type": "number"},
								"codifiedTemperatureParasitic": {"type": "string", "maxLength": 64},
								"temperatureTime": {"type": "string", "maxLength": 32},
								"temperatureCaptureOffset": {"type": "integer", "minimum": -12, "maximum": 12},
								"booleanPregnancyDetected": {"type": "boolean"},
								"isPeak": {"type": "boolean"},
								"stampColor": {"type": "string", "enum": ["Green", "Red", "Yellow", "White"]},
								"stampBaby": {"type": "boolean"},
								"codifiedEvents": {"type": "array", "maxItems": 32, "items": {"type": "string", "maxLength": 128}},
								"babyFeedCount": {"type": "integer", "minimum": 0, "maximum": 99},
								"codifiedManualFertility": {"type": "string", "maxLength": 64},
								"counterStart": {"type": "integer", "minimum": 0, "maximum": 255},
								"sexUnion": {"type": "string", "enum": ["Union", "ReservedUnion", "LastReportedUnion"]}
							}
						}
					}
				}
			}
		}
	}
}
JSON;

// ---------------------------------------------------------------------------
// Reading a decoded file. A null is a field not set: the format marks almost everything optional, and most serialisers write "not
// set" as null.
// ---------------------------------------------------------------------------

function nfp_format_get(mixed $holder, string $key): mixed {
	if (!is_object($holder) || !property_exists($holder, $key)) return null;
	return $holder->$key;
}

function nfp_format_has(mixed $holder, string $key): bool {
	return !is_null(nfp_format_get($holder, $key));
}

// The keys of an object (to report the ones the schema does not know); none for anything else.
function nfp_format_object_keys(mixed $holder): array {
	if (!is_object($holder)) return [];
	return array_keys(get_object_vars($holder));
}

// A stdClass, or the [] json_decode() gives for "{}" when decoding associatively.
function nfp_format_is_object(mixed $value): bool {
	return is_object($value) || (is_array($value) && empty($value));
}

// An int, or a float with nothing after the point (JSON's 2.0), small enough to be a note.
function nfp_format_is_whole_number(mixed $value): bool {
	return (is_int($value) || is_float($value)) && $value == floor($value) && abs($value) < 1000000;
}

// ---------------------------------------------------------------------------
// The variants of schema 1.0 -> the canonical shape. Users hold files of three: the structure document's (nested fileInformation and
// userInformation, "preferredMethod", per-day "comments"); an early draft (flat "source_app" / "source_app_version" /
// "file_creation_timestamp", a positional "userInformation", "preferedMethod", per-day "comment" and "dayNotObserved"); and the draft
// with freeMucus* as comma-joined strings and sexUnion as a boolean. Values are only moved and re-typed, never validated: what cannot be
// made sense of is left for the schema to refuse with a clear message.
// ---------------------------------------------------------------------------

function nfp_format_normalize(object $raw): object {
	$file = clone $raw;

	// flat source_app/* -> fileInformation{}
	$information = nfp_format_get($file, "fileInformation");
	if (!nfp_format_is_object($information)) $information = new stdClass();
	else $information = clone $information;

	$legacy_file_keys = [
		"source_app" => "sourceApp",
		"source_app_version" => "sourceAppVersion",
		"file_creation_timestamp" => "fileCreationTimestamp",
	];
	foreach ($legacy_file_keys as $legacy => $canonical) {
		if (!nfp_format_has($information, $canonical) && nfp_format_has($file, $legacy)) {
			$information->$canonical = $file->$legacy;
		}
		unset($file->$legacy);
	}
	// the early draft's bare "YYYY-MM-DD hh:mm:ss" -> ISO 8601
	$timestamp = nfp_format_get($information, "fileCreationTimestamp");
	if (is_string($timestamp) && preg_match('/^\s*(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2}:\d{2})\s*$/', $timestamp, $m)) {
		$information->fileCreationTimestamp = $m[1] . 'T' . $m[2] . date('P');
	}
	$file->fileInformation = $information;

	// userInformation as [identifier, email, firstName, lastName, birthDate] -> object
	$user = nfp_format_get($file, "userInformation");
	if (is_array($user)) {
		$positional = ["identifier", "email", "firstName", "lastName", "birthDate"];
		$rebuilt = new stdClass();
		foreach (array_values($user) as $index => $value) {
			if (!isset($positional[$index]) || is_null($value)) continue;
			if (is_scalar($value)) $rebuilt->{$positional[$index]} = (string) $value;
		}
		$file->userInformation = $rebuilt;
	}

	// "preferedMethod" (one r) -> "preferredMethod"
	$preferences = nfp_format_get($file, "userMethodPreferences");
	if (is_object($preferences)) {
		$preferences = clone $preferences;
		if (!nfp_format_has($preferences, "preferredMethod") && nfp_format_has($preferences, "preferedMethod")) {
			$preferences->preferredMethod = $preferences->preferedMethod;
		}
		unset($preferences->preferedMethod);
		$file->userMethodPreferences = $preferences;
	}

	$cycles = nfp_format_get($file, "cycles");
	if (is_array($cycles)) {
		$file->cycles = array_map('nfp_format_normalize_cycle', $cycles);
	}

	return $file;
}

// A word of a closed vocabulary with the casing the format spells it ("red" -> "Red"); anything
// else, trimmed, is left for the schema to refuse.
function nfp_format_spelled(string $value, array $vocabulary): string {
	$trimmed = trim($value);
	foreach ($vocabulary as $known) {
		if (strcasecmp($trimmed, $known) === 0) return $known;
	}
	return $trimmed;
}

function nfp_format_normalize_cycle(mixed $raw_cycle): mixed {
	if (!is_object($raw_cycle)) return $raw_cycle;
	$cycle = clone $raw_cycle;

	// the method name is matched case-insensitively everywhere else, so settle its casing once
	$method = nfp_format_get($cycle, "method");
	if (is_string($method)) $cycle->method = nfp_format_spelled($method, NFP_METHODS_KNOWN);

	$start_date = nfp_format_get($cycle, "cycleStartDate");
	if (is_string($start_date)) $cycle->cycleStartDate = trim($start_date);

	$days = nfp_format_get($cycle, "days");
	if (is_array($days)) $cycle->days = array_map('nfp_format_normalize_day', $days);

	return $cycle;
}

function nfp_format_normalize_day(mixed $raw_day): mixed {
	if (is_array($raw_day) && empty($raw_day)) return new stdClass();   // "{}" decoded associatively
	if (!is_object($raw_day)) return $raw_day;
	$day = clone $raw_day;

	// "dayNotObserved" -> "mucusNotObserved"
	if (!nfp_format_has($day, "mucusNotObserved") && nfp_format_has($day, "dayNotObserved")) {
		$day->mucusNotObserved = $day->dayNotObserved;
	}
	unset($day->dayNotObserved);

	// a single "comment" string -> the "comments" array
	if (!nfp_format_has($day, "comments") && nfp_format_has($day, "comment")) {
		$comment = $day->comment;
		if (is_scalar($comment) && trim((string) $comment) !== '') $day->comments = [(string) $comment];
	}
	unset($day->comment);

	// comma-joined strings
	foreach (["freeMucusSensation", "freeMucusObservation"] as $field) {
		$value = nfp_format_get($day, $field);
		if (is_string($value)) {
			$parts = array_filter(array_map('trim', explode(",", $value)), fn($part) => $part !== '');
			$day->$field = array_values($parts);
		}
	}

	// a boolean union
	$union = nfp_format_get($day, "sexUnion");
	if (is_bool($union)) {
		if ($union) $day->sexUnion = "Union";
		else unset($day->sexUnion);
	}
	elseif (is_string($union)) $day->sexUnion = nfp_format_spelled($union, NFP_SEX_UNIONS);

	// the early day schema typed these as the strings "true"/"false"
	foreach (["mucusNotObserved", "stampBaby", "isPeak", "booleanPregnancyDetected",
	          "nonUsualBleeding", "booleanCervixMucus", "booleanCervixAperture"] as $field) {
		$value = nfp_format_get($day, $field);
		if (!is_string($value)) continue;
		$trimmed = strtolower(trim($value));
		if ($trimmed === "true") $day->$field = true;
		elseif ($trimmed === "false") $day->$field = false;
	}

	// ... and counterStart as a string
	$counter = nfp_format_get($day, "counterStart");
	if (is_string($counter) && preg_match('/^\s*\d{1,3}\s*$/', $counter)) $day->counterStart = intval($counter);

	// Two FertilityCare notes that read as numbers, and that some apps write as numbers: the mucus
	// sensation (0, 2, 4 ... 10) as is, and the number of observations as 1, 2, 3 for "x1", "x2", "x3".
	// A number that is no code of the note ("x7") is left to be reported as ignored, like any other.
	$sensation = nfp_format_get($day, "codifiedMucusSensation");
	if (nfp_format_is_whole_number($sensation)) $day->codifiedMucusSensation = (string) intval($sensation);
	$observations = nfp_format_get($day, "codifiedNumberObservations");
	if (nfp_format_is_whole_number($observations)) {
		$observations = intval($observations);
		$day->codifiedNumberObservations = in_array($observations, [1, 2, 3], true) ? "x" . $observations : (string) $observations;
	}
	elseif (is_string($observations) && preg_match('/^\s*([1-3])\s*$/', $observations, $m)) $day->codifiedNumberObservations = "x" . $m[1];

	// a temperature sent as "36.45"
	$temperature = nfp_format_get($day, "temperature");
	if (is_string($temperature) && is_numeric(trim($temperature))) $day->temperature = floatval(trim($temperature));

	// stampColor / codifiedArrow / enum-ish strings: settle casing, trim
	$colour = nfp_format_get($day, "stampColor");
	if (is_string($colour)) $day->stampColor = nfp_format_spelled($colour, NFP_STAMP_COLORS);
	$arrow = nfp_format_get($day, "codifiedArrow");
	if (is_string($arrow)) $day->codifiedArrow = nfp_format_spelled($arrow, NFP_ARROWS);

	$time = nfp_format_get($day, "temperatureTime");
	if (is_string($time)) $day->temperatureTime = trim($time);

	return $day;
}

// A null is a field not set: it leaves the file before the schema sees it, or a file that writes every unset field as null would be
// refused on all of them. After nfp_format_normalize(), for which a null holds a place in the draft's positional userInformation.
// $found counts the nulls dropped by field, list indexes left out ("cycles[].days[].temperature": one line per field, not per day). A
// null in a list of days is a day with nothing on it (its place is its date); in any other list it goes.

function nfp_format_drop_nulls(mixed $node, string $path, array &$found): mixed {
	if (is_object($node)) {
		$kept = new stdClass();
		foreach (get_object_vars($node) as $key => $value) {
			$here = $path === '' ? (string) $key : $path . '.' . $key;
			if (is_null($value)) $found[$here] = ($found[$here] ?? 0) + 1;
			else $kept->$key = nfp_format_drop_nulls($value, $here, $found);
		}
		return $kept;
	}
	if (is_array($node)) {
		$item_path = $path . '[]';
		$kept = [];
		foreach ($node as $item) {
			if (!is_null($item)) $kept[] = nfp_format_drop_nulls($item, $item_path, $found);
			else {
				$found[$item_path] = ($found[$item_path] ?? 0) + 1;
				if ($path === 'cycles[].days') $kept[] = new stdClass();
			}
		}
		return $kept;
	}
	return $node;
}
