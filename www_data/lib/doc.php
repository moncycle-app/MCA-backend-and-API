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

// The human-readable exports of a period: a CSV, and a chart (one line per day for cervical mucus self-observation, a grid for FertilityCare). One reading
// feeds all three, doc_export_days() (lib/doc_export.php): one entry per calendar day in the JSON day shape with the NFP names, gaps
// included, nothing hidden. What a file shows of a day is the renderers' business, and the rule they share is here. The NFP export
// (lib/nfp_export.php) is apart on purpose: a data file widened to whole cycles that writes every stored value.

// The day as every export shows it. On a mucusNotObserved day the mucus fields are empty (DOC_MUCUS_FIELDS), even when old values are
// stored; the rest (union, counter, peak, temperature, pain, arrow, pause, comment) shows as usual. The descriptions with no type
// yet (freeOther) have no column of their own: they follow the comment.
function doc_display_day(array $day): array {
	if (!empty($day["mucusNotObserved"])) {
		foreach (DOC_MUCUS_FIELDS as $field) {
			if (!array_key_exists($field, $day)) continue;
			$day[$field] = match (true) {
				is_array($day[$field]) => [],
				is_bool($day[$field]) => false,
				default => null,
			};
		}
	}

	$other = implode(", ", $day["freeOther"] ?? []);
	if ($other !== '') {
		$comment = (string) ($day["comments"] ?? '');
		$day["comments"] = $comment === '' ? $other : $comment . DOC_COMMENT_OTHER_JOINER . $other;
	}
	$day["freeOther"] = [];
	return $day;
}

// What a day's stamp says when it has no colour of its own: "paused" (observations paused), "not_observed" (the user marked the day not
// observed: "?") or "not_filled" (nothing was entered: no row for the date, or a row that holds nothing: "!"); null otherwise. $day is
// doc_display_day()'s.
function doc_day_stamp_state(array $day): ?string {
	if (!empty($day["observationPaused"])) return "paused";
	if (!empty($day["mucusNotObserved"])) return "not_observed";
	if (!empty($day["isGap"]) || doc_day_is_blank($day)) return "not_filled";
	return null;
}

// A stored day with nothing on it: no stamp, description, comment, temperature, marker or FertilityCare code.
function doc_day_is_blank(array $day): bool {
	foreach (DOC_DAY_CONTENT_FIELDS as $field) {
		if (!empty($day[$field])) return false;
	}
	return trim((string) ($day["comments"] ?? '')) === '';
}

// Free text on one line: line breaks and runs of blanks become one space.
function doc_one_line(?string $text): string {
	$text = $text ?? '';
	return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
}

// "07:00:00" -> "07:00". A TIME column can run past 24h ("838:59:59"): kept as it is.
function doc_time_hhmm(?string $time): string {
	if (is_null($time) || !preg_match('/^\s*(\d+):(\d{2})/', $time, $m)) return '';
	return $m[1] . ':' . $m[2];
}

function doc_get_initials(string $name): string {
	$initials = '';
	foreach (preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) $initials .= mb_strtoupper(mb_substr($word, 0, 1));
	return $initials;
}

// The temperatures of the days (stored in Celsius) in the unit the account chose.
function doc_temperature_unit(array $days, string $unit): array {
	if ($unit !== "F") return $days;
	return array_map(function ($day) {
		if (!is_null($day["temperature"] ?? null)) $day["temperature"] = round($day["temperature"] * 9 / 5 + 32, 2);
		return $day;
	}, $days);
}
