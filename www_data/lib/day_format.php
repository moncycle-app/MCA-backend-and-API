<?php
/* moncycle.app
**
** licence Creative Commons CC BY-NC-SA
**
** https://www.moncycle.app
** https://github.com/moncycle-app/backend-api-web-app
*/

declare(strict_types=1);

// A day in its shapes: the row of day_timeline with its packed columns (stamp, fc_score, fc_arrow), and the JSON day of the API on the
// vocabulary of the NFP format (stampColor, isPeak, codifiedArrow...), which the NFP export and import also go through. The only file
// that knows the packed encoding, and the rules a day must follow to be stored.

require_once __DIR__ . "/http.php";
require_once __DIR__ . "/input.php";

// ---------------------------------------------------------------------------
// fc_score: the five FertilityCare note groups (DAY_FORMAT_FC_GROUPS) packed in one string, one nullable string per group in the API.
// They are joined with a space: decoding only looks for known codes, and the space stops two groups from spelling a third code (a
// sensation ending "...WL" before a pain "AP..." would read "LAP").
// ---------------------------------------------------------------------------

function day_format_fc_score_decode(?string $fc_score): array {
	$rest = strtoupper(trim((string) $fc_score));

	// a leading "L" (not the start of "LAP") is the spotting of the bleeding group
	$spotting = str_starts_with($rest, 'L') && !str_starts_with($rest, 'LAP');
	if ($spotting) $rest = substr($rest, 1);

	$found = [];
	foreach (DAY_FORMAT_FC_PARSE_ORDER as $code) {
		if (!str_contains($rest, $code)) continue;
		$found[$code] = true;
		$rest = str_replace($code, '', $rest);
	}

	$decoded = [];
	foreach (DAY_FORMAT_FC_GROUPS as $field => $codes) {
		$value = implode('', array_filter($codes, fn($code) => isset($found[$code])));
		if ($field === 'codifiedBleedingObservation' && $spotting) $value .= 'L';
		$decoded[$field] = $value !== '' ? $value : null;
	}
	return $decoded;
}

function day_format_fc_score_encode(array $codified): string {
	$bleeding_codes = DAY_FORMAT_FC_GROUPS['codifiedBleedingObservation'];
	$bleeding = trim((string) ($codified['codifiedBleedingObservation'] ?? ''));

	// Decoding shows the spotting as a trailing "L" of the bleeding; stored, it is a leading "L" of the whole. A trailing "L" is the
	// spotting only when what is left is empty or a bleeding code: "VL" (very light) is a code of its own.
	$spotting = false;
	if ($bleeding !== '' && !in_array($bleeding, $bleeding_codes, true) && str_ends_with($bleeding, 'L')) {
		$without = substr($bleeding, 0, -1);
		if ($without === '' || in_array($without, $bleeding_codes, true)) {
			$spotting = true;
			$bleeding = $without;
		}
	}

	$parts = $bleeding !== '' ? [$bleeding] : [];
	foreach (array_slice(array_keys(DAY_FORMAT_FC_GROUPS), 1) as $field) {
		$value = trim((string) ($codified[$field] ?? ''));
		if ($value !== '') $parts[] = $value;
	}

	$joined = implode(' ', $parts);
	if ($spotting) return 'L' . ($joined !== '' ? ' ' . $joined : '');
	// another group's value that starts with "L" and comes first: the leading space keeps it from reading as the spotting
	if (str_starts_with($joined, 'L') && !str_starts_with($joined, 'LAP')) $joined = ' ' . $joined;
	return $joined;
}

// A note value as the codes of its group (several in a row: "CK"), or null when it is outside them: fc_score is read back by looking for
// codes, so an unknown one would corrupt the day. The NFP spelling of three codes is accepted (NFP_FC_CODE_ALIASES). Longest code first,
// so that "10DL" is not "10" and junk.
function day_format_fc_canonical(string $field, string $value): ?string {
	$codes = DAY_FORMAT_FC_GROUPS[$field] ?? [];
	if ($field === 'codifiedBleedingObservation') $codes[] = 'L';   // the spotting, as decoding shows it
	usort($codes, fn($a, $b) => strlen($b) <=> strlen($a));

	$rest = strtoupper(trim($value));
	if ($rest === '') return null;
	$rest = NFP_FC_CODE_ALIASES[$rest] ?? $rest;

	$canonical = '';
	while ($rest !== '') {
		$code = current(array_filter($codes, fn($code) => str_starts_with($rest, $code)));
		if ($code === false) return null;
		$canonical .= $code;
		$rest = substr($rest, strlen($code));
	}
	return $canonical;
}

// ---------------------------------------------------------------------------
// What a stored value has to follow
// ---------------------------------------------------------------------------

// Control characters out (they make the CSV unreadable), line breaks and tabs kept (a comment has them). Invalid UTF-8 gives "".
function day_format_clean_text(string $text): string {
	$text = preg_replace('/[^\P{C}\n\t]+/u', '', str_replace(["\r\n", "\r"], "\n", $text));
	return is_null($text) ? '' : trim($text);
}

// "hh:mm" or "hh:mm:ss" as "hh:mm:ss", null when it is no time of day.
function day_format_time(string $time): ?string {
	if (!preg_match('/^(\d{2}):(\d{2})(?::(\d{2}))?$/', trim($time), $m)) return null;
	$seconds = intval($m[3] ?? 0);
	return intval($m[1]) <= 23 && intval($m[2]) <= 59 && $seconds <= 59 ? sprintf("%s:%s:%02d", $m[1], $m[2], $seconds) : null;
}

// A client's text for a varchar($max_chars), cleaned; null when it is no string or too long (an issue).
function day_format_text(mixed $value, string $field, int $max_chars, array &$issues): ?string {
	if (!is_string($value)) {
		input_wrong_type($issues, $field, $value, "a string");
		return null;
	}
	$text = day_format_clean_text($value);
	$length = mb_strlen($text);
	if ($length <= $max_chars) return $text;
	$issues[] = input_issue($field, "too_long", "'$field' is $length characters; the limit is $max_chars.");
	return null;
}

// $body[$field] as day_format_text(); null when absent.
function day_format_body_text(array $body, string $field, int $max_chars, array &$issues): ?string {
	return array_key_exists($field, $body) ? day_format_text($body[$field], $field, $max_chars, $issues) : null;
}

// Does the field carry a value? "0" is a real FertilityCare code (dryness), which empty() would miss; a list counts, uncast.
function day_format_filled(array $day, string $field): bool {
	return isset($day[$field]) && (!is_scalar($day[$field]) || (string) $day[$field] !== '');
}

// ---------------------------------------------------------------------------
// A day sent by a client (POST /api/v2/day/{date})
// ---------------------------------------------------------------------------

// The day checked against what can be stored and cleaned for day_format_from_json(), its problems added to $issues. A day is its full
// state: a field left out is empty, not a problem. The types are JSON's: a flag is true or false, a count a whole number.
function day_format_validate(array $day, array &$issues): array {
	foreach (['cycleFirstDay', 'dayNotObserved', 'stampBaby', 'isPeak', 'sexUnion', 'booleanPregnancyDetected'] as $field) {
		if (array_key_exists($field, $day) && !is_bool($day[$field])) input_wrong_type($issues, $field, $day[$field], "true or false");
	}

	foreach (['stampColor' => NFP_STAMP_COLORS, 'codifiedArrow' => NFP_ARROWS] as $field => $vocabulary) {
		if (!array_key_exists($field, $day)) continue;
		if (!is_string($day[$field])) input_wrong_type($issues, $field, $day[$field], "a string (" . implode(", ", $vocabulary) . ") or null");
		elseif (!in_array($day[$field], $vocabulary, true)) $issues[] = input_issue($field, "invalid_value", "'$field' must be one of " . implode(", ", $vocabulary) . ", or null.");
	}

	$codes_ok = true;
	foreach (array_keys(DAY_FORMAT_FC_GROUPS) as $field) {
		if (!array_key_exists($field, $day)) continue;
		if (!is_string($day[$field])) {
			input_wrong_type($issues, $field, $day[$field], "a string (FertilityCare codes) or null");
			$codes_ok = false;
		}
		elseif (day_format_filled($day, $field)) {
			$canonical = day_format_fc_canonical($field, $day[$field]);
			if (is_null($canonical)) {
				$issues[] = input_issue($field, "invalid_value", "'$field' holds a code outside the FertilityCare notation. The codes of this group are: " . implode(", ", DAY_FORMAT_FC_GROUPS[$field]) . ".");
				$codes_ok = false;
			}
			else $day[$field] = $canonical;
		}
	}
	if ($codes_ok) {
		$packed = mb_strlen(day_format_fc_score_encode($day));
		if ($packed > DAY_LIMIT_FC_SCORE_CHARS) $issues[] = input_issue("codifiedBleedingObservation", "too_long", "The FertilityCare notes take $packed characters packed; the limit is " . DAY_LIMIT_FC_SCORE_CHARS . ".");
	}

	if (array_key_exists('comment', $day)) $day['comment'] = day_format_text($day['comment'], 'comment', DAY_LIMIT_COMMENT_CHARS, $issues);

	foreach (['freeMucusSensation', 'freeMucusObservation', 'freeOther'] as $field) {
		if (!array_key_exists($field, $day)) continue;
		if (!is_array($day[$field]) || !array_is_list($day[$field])) {
			input_wrong_type($issues, $field, $day[$field], "a list of strings");
			continue;
		}
		$names = [];
		foreach ($day[$field] as $position => $name) {
			$name = day_format_text($name, "{$field}[$position]", DAY_LIMIT_DESCRIPTION_CHARS, $issues);
			if (!is_null($name) && $name !== '') $names[] = $name;
		}
		$day[$field] = array_values(array_unique($names));
	}

	if (array_key_exists('temperature', $day)) {
		if (!is_int($day['temperature']) && !is_float($day['temperature'])) input_wrong_type($issues, 'temperature', $day['temperature'], "a number");
		elseif ($day['temperature'] < DAY_TEMPERATURE_STORABLE_MIN || $day['temperature'] > DAY_TEMPERATURE_STORABLE_MAX) {
			$issues[] = input_issue('temperature', "out_of_range", "'temperature' must be a number from " . DAY_TEMPERATURE_STORABLE_MIN . " to " . DAY_TEMPERATURE_STORABLE_MAX . " (0 means none).");
		}
	}
	if (array_key_exists('temperatureTime', $day)) {
		if (!is_string($day['temperatureTime'])) input_wrong_type($issues, 'temperatureTime', $day['temperatureTime'], "a time of day string, hh:mm or hh:mm:ss");
		elseif ($day['temperatureTime'] === '') $day['temperatureTime'] = null;
		else {
			$time = day_format_time($day['temperatureTime']);
			if (is_null($time)) $issues[] = input_issue('temperatureTime', "invalid_value", "'temperatureTime' must be a time of day, hh:mm or hh:mm:ss.");
			else $day['temperatureTime'] = $time;
		}
	}

	if (array_key_exists('counterStart', $day)) {
		if (!is_int($day['counterStart'])) input_wrong_type($issues, 'counterStart', $day['counterStart'], "a whole number");
		elseif ($day['counterStart'] < 0 || $day['counterStart'] > DAY_LIMIT_COUNTER_START) {
			$issues[] = input_issue('counterStart', "out_of_range", "'counterStart' must be a whole number from 0 to " . DAY_LIMIT_COUNTER_START . ".");
		}
	}

	return $day;
}

// ---------------------------------------------------------------------------
// The whole day: data_day() (lib/data.php) <-> the JSON day
// ---------------------------------------------------------------------------

function day_format_to_json(array $day): array {
	// the stamp is "" | R | G | Y, then "BB" with the baby; White has no letter: the baby on no colour
	$stamp = $day['stamp'] ?? '';
	$color = null;
	foreach (DAY_STAMP_COLOR_CODES as $name => $code) {
		if (str_contains($stamp, $code)) {
			$color = $name;
			break;
		}
	}
	$baby = str_contains($stamp, 'BB');

	$descriptions = [DESCRIPTION_TYPE_SENSATION => [], DESCRIPTION_TYPE_OBSERVATION => [], DESCRIPTION_TYPE_UNDEFINED => []];
	foreach ($day['description'] ?? [] as $description) $descriptions[intval($description['type'])][] = $description['name'];

	return array_merge([
		'date' => $day['date_obs'],
		'cycleStartDate' => $day['cycle'] ?? null,
		'cycleDay' => isset($day['pos']) ? intval($day['pos']) : null,
		'cycleFirstDay' => boolval($day['cycle_1st_day'] ?? false),
		'dayNotObserved' => boolval($day['day_not_observed'] ?? false),
		'stampColor' => $color ?? ($baby ? 'White' : null),
		'stampBaby' => $baby,
		'isPeak' => boolval($day['is_peak'] ?? false),
		'counterStart' => !empty($day['counter_start']) ? intval($day['counter_start']) : null,
		'sexUnion' => boolval($day['union_sex'] ?? false),
		'booleanPregnancyDetected' => boolval($day['pregnancy'] ?? false),
		'freeMucusSensation' => $descriptions[DESCRIPTION_TYPE_SENSATION],
		'freeMucusObservation' => $descriptions[DESCRIPTION_TYPE_OBSERVATION],
		'freeOther' => $descriptions[DESCRIPTION_TYPE_UNDEFINED],
		'temperature' => !empty($day['temperature']) ? floatval($day['temperature']) : null,
		'temperatureTime' => $day['time_temp_taken'] ?? null,
		'codifiedArrow' => array_search($day['fc_arrow'] ?? null, DAY_ARROW_GLYPHS, true) ?: null,
		'comment' => $day['comment'] ?? '',
		'lastWriteClientUtc' => http_iso8601($day['last_write_client_UTC'] ?? null),
		'lastWriteDb' => http_iso8601($day['last_write_db'] ?? null),
	], day_format_fc_score_decode($day['fc_score'] ?? null));
}

// The stamp as stored: a colour letter, then "BB" with the baby (White and no colour have no letter).
function day_format_stamp_encode(?string $color, bool $baby): string {
	return (DAY_STAMP_COLOR_CODES[$color] ?? '') . ($baby ? 'BB' : '');
}

// The fields db_update_day_timeline() takes. A day is its full state, not a patch: a field left out is written empty. The descriptions
// are names, resolved to ids by lib/data.php.
function day_format_from_json(array $json): array {
	$fc_score = day_format_fc_score_encode($json);
	$has_temperature = isset($json['temperature']) && floatval($json['temperature']) > 0;

	return [
		'stamp' => day_format_stamp_encode($json['stampColor'] ?? null, boolval($json['stampBaby'] ?? false)),
		'fc_score' => $fc_score !== '' ? $fc_score : null,
		'fc_arrow' => DAY_ARROW_GLYPHS[$json['codifiedArrow'] ?? ''] ?? null,
		'temp' => $has_temperature ? floatval($json['temperature']) : null,
		'htemp' => $has_temperature && !empty($json['temperatureTime']) ? trim($json['temperatureTime']) : null,
		'is_peak' => boolval($json['isPeak'] ?? false),
		'union_sex' => boolval($json['sexUnion'] ?? false),
		'cycle_1st_day' => boolval($json['cycleFirstDay'] ?? false),
		'day_not_observed' => boolval($json['dayNotObserved'] ?? false),
		'pregnancy' => boolval($json['booleanPregnancyDetected'] ?? false),
		'comment' => $json['comment'] ?? null,
		'counter_start' => isset($json['counterStart']) && intval($json['counterStart']) > 0 ? intval($json['counterStart']) : null,
	];
}
