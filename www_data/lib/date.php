<?php
/* moncycle.app
**
** licence Creative Commons CC BY-NC-SA
**
** https://www.moncycle.app
** https://github.com/moncycle-app/backend-api-web-app
*/

function date_human(DateTimeInterface $date, string $sep = '/'): string {
	return $date->format('d' . $sep . 'm' . $sep . 'y');
}

function date_human_week_day(DateTimeInterface $date, array $week_days): string {
	return $week_days[intval($date->format('w'))] . $date->format(' d/m');
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
