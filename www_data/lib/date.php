<?php
/* moncycle.app
**
** licence Creative Commons CC BY-NC-SA
**
** https://www.moncycle.app
** https://github.com/moncycle-app/backend-api-web-app
*/

function date_human($date, $sep='/') {
	return date_format($date, 'd' . $sep . 'm' . $sep . 'y');
}

function date_human_week_day($date, $week_days, $sep='/') {
	return $week_days[intval(date_format($date, 'w'))] . date_format($date, ' d' . $sep . 'm');
}

// "YYYY-MM-DD" (spaces around are allowed) -> the date itself, or null when it is not a real calendar date.
function date_parse_ymd(mixed $value): ?string {
	if (!is_string($value) || !preg_match('/^\s*(\d{4})-(\d{2})-(\d{2})\s*$/', $value, $m)) return null;
	return checkdate(intval($m[2]), intval($m[3]), intval($m[1])) ? trim($value) : null;
}

// An RFC 3339 timestamp as a client writes it ("2026-03-10T15:30:45Z", fractional seconds allowed, "Z" or a numeric offset "+02:00") ->
// the same moment in UTC as the app stores it, "YYYY-MM-DD hh:mm:ss"; null when it is not one: a zone is required (a time without one
// has no moment), the bare "YYYY-MM-DD hh:mm:ss" form is not accepted, nor is a date that does not exist.
function date_parse_rfc3339(mixed $value): ?string {
	if (!is_string($value) || !preg_match('/\A(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})(?:\.\d{1,9})?(Z|[+-]\d{2}:\d{2})\z/', $value, $m)) return null;

	$moment = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $m[1] . ($m[2] === "Z" ? "+00:00" : $m[2]));
	$errors = DateTimeImmutable::getLastErrors();
	if ($moment === false || ($errors && ($errors["warning_count"] > 0 || $errors["error_count"] > 0))) return null;
	return $moment->setTimezone(new DateTimeZone("UTC"))->format('Y-m-d H:i:s');
}
