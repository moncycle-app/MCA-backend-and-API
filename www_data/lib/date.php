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

// A date in a PHP date() pattern: 31/12/26 by default (the cycle mail's dates), or a file name's.
function date_human(DateTimeInterface $date, string $pattern = 'd/m/y'): string {
	return $date->format($pattern);
}

// The compact date: the weekday's initial, the day and the month on 3 letters, in capitals, in the order the account chose ("M 3 NOV",
// "T NOV 3"); the year on two digits follows when it is not the current one ("M 3 NOV 25", "T NOV 3, 25"). $words are the words of the
// language (res_words("pdf")): "week_days" from Sunday and "months_short". No weekday initial when $week_day is false.
function date_compact(DateTimeInterface $date, array $words, string $order, bool $week_day = true): string {
	$day = $date->format('j');
	$month = mb_strtoupper($words["months_short"][intval($date->format('n')) - 1]);
	$text = $order === "MONTH_FIRST" ? "$month $day" : "$day $month";
	if ($date->format('Y') !== date('Y')) $text .= ($order === "MONTH_FIRST" ? ", " : " ") . $date->format('y');
	return $week_day ? $words["week_days"][intval($date->format('w'))] . " " . $text : $text;
}

function date_next_day(string $date): string {
	return (new DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d');
}

// The position of $date in the cycle that began on $cycle_start: 1 on its first day.
function date_cycle_day(string $cycle_start, string $date): int {
	return (new DateTimeImmutable($cycle_start))->diff(new DateTimeImmutable($date))->days + 1;
}

// "YYYY-MM-DD", spaces around allowed, and a real calendar date; null otherwise.
function date_parse_ymd(mixed $value): ?string {
	if (!is_string($value) || !preg_match('/^\s*(\d{4})-(\d{2})-(\d{2})\s*$/', $value, $m)) return null;
	return checkdate(intval($m[2]), intval($m[3]), intval($m[1])) ? trim($value) : null;
}

// An RFC 3339 timestamp ("2026-03-10T15:30:45Z", fractional seconds allowed, "Z" or "+02:00") as the UTC "YYYY-MM-DD hh:mm:ss" the
// app stores; null when it is not one. The zone is required: a time without one is no moment.
function date_parse_rfc3339(mixed $value): ?string {
	if (!is_string($value) || !preg_match('/\A(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})(?:\.\d{1,9})?(Z|[+-]\d{2}:\d{2})\z/', $value, $m)) return null;

	$moment = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $m[1] . ($m[2] === "Z" ? "+00:00" : $m[2]));
	$errors = DateTimeImmutable::getLastErrors();
	if ($moment === false || ($errors && ($errors["warning_count"] > 0 || $errors["error_count"] > 0))) return null;
	return $moment->setTimezone(new DateTimeZone("UTC"))->format('Y-m-d H:i:s');
}
