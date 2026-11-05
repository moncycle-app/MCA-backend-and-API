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

// A day in its shapes: the row of day_timeline (the stamp packed, the FertilityCare notation in its fc_* columns), and the JSON day of the
// API on the vocabulary of the NFP format (stampColor, isPeak, codifiedArrow...), which the NFP export and import also go through. The only
// file that knows the columns of a day, and the rules a day must follow to be stored.

require_once __DIR__ . "/http.php";
require_once __DIR__ . "/input.php";

// ---------------------------------------------------------------------------
// The FertilityCare notation
// ---------------------------------------------------------------------------

// The code as the notation writes it, whatever its case; null when it is not one of the field's codes.
function day_format_fc_code(string $field, string $value): ?string {
	$code = strtoupper(trim($value));
	return in_array($code, DAY_FORMAT_FC_CODES[$field], true) ? $code : null;
}

// The letters in the order they are stored, each once. Null when one is not a letter of the notation.
function day_format_fc_mucus_letters(array $letters): ?array {
	$letters = array_map(fn($letter) => is_string($letter) ? strtoupper(trim($letter)) : null, $letters);
	if (!empty(array_diff($letters, DAY_FORMAT_FC_MUCUS_LETTERS))) return null;
	return array_values(array_intersect(DAY_FORMAT_FC_MUCUS_LETTERS, $letters));
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

// A description's name as it is stored: cleaned, trimmed and in lower case, so that "Humide " and "humide" are one description.
function day_format_description_name(string $name): string {
	return mb_strtolower(day_format_clean_text($name));
}

// A client's description name for a varchar($max_chars): day_format_description_name() then the checks of day_format_text().
function day_format_description_text(mixed $value, string $field, int $max_chars, array &$issues): ?string {
	return day_format_text(is_string($value) ? day_format_description_name($value) : $value, $field, $max_chars, $issues);
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
// A day sent by a client (POST /api/v3/day/{date})
// ---------------------------------------------------------------------------

// The day checked against what can be stored and cleaned for day_format_from_json(), its problems added to $issues. A day is its full
// state: a field left out is empty, not a problem. The types are JSON's: a flag is true or false, a count a whole number.
function day_format_validate(array $day, array &$issues): array {
	foreach (['cycleFirstDay', 'dayNotObserved', 'stampBaby', 'isPeak', 'sexUnion', 'observationPaused', 'codifiedBleedingBrown'] as $field) {
		if (array_key_exists($field, $day) && !is_bool($day[$field])) input_wrong_type($issues, $field, $day[$field], "true or false");
	}

	foreach (['stampColor' => NFP_STAMP_COLORS, 'codifiedArrow' => NFP_ARROWS] as $field => $vocabulary) {
		if (!array_key_exists($field, $day)) continue;
		if (!is_string($day[$field])) input_wrong_type($issues, $field, $day[$field], "a string (" . implode(", ", $vocabulary) . ") or null");
		elseif (!in_array($day[$field], $vocabulary, true)) $issues[] = input_issue($field, "invalid_value", "'$field' must be one of " . implode(", ", $vocabulary) . ", or null.");
	}

	foreach (DAY_FORMAT_FC_CODES as $field => $codes) {
		if (!array_key_exists($field, $day)) continue;
		if (!is_string($day[$field])) input_wrong_type($issues, $field, $day[$field], "a string (one FertilityCare code) or null");
		elseif (day_format_filled($day, $field)) {
			$code = day_format_fc_code($field, $day[$field]);
			if (is_null($code)) $issues[] = input_issue($field, "invalid_value", "'$field' must be one of " . implode(", ", $codes) . ", or null.");
			else $day[$field] = $code;
		}
	}

	if (array_key_exists('codifiedMucusObservation', $day)) {
		$letters = $day['codifiedMucusObservation'];
		if (!is_array($letters) || !array_is_list($letters)) input_wrong_type($issues, 'codifiedMucusObservation', $letters, "a list of strings");
		else {
			$letters = day_format_fc_mucus_letters($letters);
			if (is_null($letters)) $issues[] = input_issue('codifiedMucusObservation', "invalid_value", "'codifiedMucusObservation' is a list of " . implode(", ", DAY_FORMAT_FC_MUCUS_LETTERS) . ".");
			else $day['codifiedMucusObservation'] = $letters;
		}
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
			$name = day_format_description_text($name, "{$field}[$position]", DAY_LIMIT_DESCRIPTION_CHARS, $issues);
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

	$fc_codes = [];
	foreach (DAY_FORMAT_FC_COLUMNS as $field => $column) $fc_codes[$field] = day_format_filled($day, $column) ? $day[$column] : null;
	$mucus_letters = $day['fc_mucus_observation'] ?? '';   // a SET column reads as "C,K"

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
		'observationPaused' => boolval($day['observation_paused'] ?? false),
		'freeMucusSensation' => $descriptions[DESCRIPTION_TYPE_SENSATION],
		'freeMucusObservation' => $descriptions[DESCRIPTION_TYPE_OBSERVATION],
		'freeOther' => $descriptions[DESCRIPTION_TYPE_UNDEFINED],
		'temperature' => !empty($day['temperature']) ? floatval($day['temperature']) : null,
		'temperatureTime' => $day['time_temp_taken'] ?? null,
		'codifiedArrow' => array_search($day['fc_arrow'] ?? null, DAY_ARROW_GLYPHS, true) ?: null,
		'comment' => $day['comment'] ?? '',
		'lastWriteClientUtc' => http_iso8601($day['last_write_client_UTC'] ?? null),
		'lastWriteDb' => http_iso8601($day['last_write_db'] ?? null),
		'codifiedBleedingBrown' => boolval($day['fc_bleeding_brown'] ?? false),
		'codifiedMucusObservation' => $mucus_letters !== '' ? explode(',', $mucus_letters) : [],
	], $fc_codes);
}

// The stamp as stored: a colour letter, then "BB" with the baby (White and no colour have no letter).
function day_format_stamp_encode(?string $color, bool $baby): string {
	return (DAY_STAMP_COLOR_CODES[$color ?? ''] ?? '') . ($baby ? 'BB' : '');
}

// The fields db_querie_update_day_timeline() takes. A day is its full state, not a patch: a field left out is written empty. The descriptions
// are names, resolved to ids by lib/data.php.
function day_format_from_json(array $json): array {
	$fc_codes = [];
	foreach (DAY_FORMAT_FC_COLUMNS as $field => $column) $fc_codes[$column] = day_format_filled($json, $field) ? $json[$field] : null;
	$mucus_letters = implode(',', $json['codifiedMucusObservation'] ?? []);
	$has_temperature = isset($json['temperature']) && floatval($json['temperature']) > 0;

	return $fc_codes + [
		'stamp' => day_format_stamp_encode($json['stampColor'] ?? null, boolval($json['stampBaby'] ?? false)),
		'fc_bleeding_brown' => boolval($json['codifiedBleedingBrown'] ?? false),
		'fc_mucus_observation' => $mucus_letters !== '' ? $mucus_letters : null,
		'fc_arrow' => DAY_ARROW_GLYPHS[$json['codifiedArrow'] ?? ''] ?? null,
		'temp' => $has_temperature ? floatval($json['temperature']) : null,
		'htemp' => $has_temperature && !empty($json['temperatureTime']) ? trim($json['temperatureTime']) : null,
		'is_peak' => boolval($json['isPeak'] ?? false),
		'union_sex' => boolval($json['sexUnion'] ?? false),
		'cycle_1st_day' => boolval($json['cycleFirstDay'] ?? false),
		'day_not_observed' => boolval($json['dayNotObserved'] ?? false),
		'observation_paused' => boolval($json['observationPaused'] ?? false),
		'comment' => $json['comment'] ?? null,
		'counter_start' => isset($json['counterStart']) && intval($json['counterStart']) > 0 ? intval($json['counterStart']) : null,
	];
}
