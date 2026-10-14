<?php
/* moncycle.app
**
** licence Creative Commons CC BY-NC-SA
**
** https://www.moncycle.app
** https://github.com/moncycle-app/backend-api-web-app
*/

// What a request is allowed to say, and how a client is told when it says it wrong. Every endpoint reads its path, its query string and its
// body through here, and ends with one answer for any problem: 400 `validation_failed`, whose `details.issues` is a list of
// {"field", "code", "message"} (the codes are INPUT_ISSUE_CODES, constants.php). `field` is where the problem is: `stampColor`,
// `freeMucusSensation[2]`, `query.from`, `path.date`.
//
// An issue is collected, not thrown: the checks of an endpoint all run, and input_exit_if_issues() tells the client everything that is
// wrong at once. A message names the field, the type that came ("got array") and what is expected; it never holds the value that was
// sent (a password, a code, the text of a day).

require_once __DIR__ . "/date.php";
require_once __DIR__ . "/http.php";

// ---------------------------------------------------------------------------
// The issues
// ---------------------------------------------------------------------------

function input_issue(string $field, string $code, string $message): array {
	return ["field" => $field, "code" => in_array($code, INPUT_ISSUE_CODES, true) ? $code : "invalid_value", "message" => $message];
}

// Ends the request with the 400 when there is any issue.
function input_exit_if_issues(array $issues): void {
	if (empty($issues)) return;
	$count = count($issues);
	$message = $count === 1
		? "The request is not valid: " . $issues[0]["message"]
		: "The request is not valid: $count problems (see details.issues). First: " . $issues[0]["message"];
	http_error(400, "validation_failed", $message, ["issues" => $issues]);
}

// The type of a decoded JSON value as a client thinks of it.
function input_type_name(mixed $value): string {
	if (is_null($value)) return "null";
	if (is_bool($value)) return "boolean";
	if (is_int($value)) return "whole number";
	if (is_float($value)) return "decimal number";
	if (is_string($value)) return "string";
	return array_is_list($value) ? "array" : "object";
}

// "'name' must be a string, got array."
function input_wrong_type(array &$issues, string $field, mixed $value, string $expected): void {
	$issues[] = input_issue($field, "wrong_type", "'" . input_label($field) . "' must be $expected, got " . input_type_name($value) . ".");
}

// A field as a message names it: `query.from` is the parameter "from", `stampColor` stays as it is.
function input_label(string $field): string {
	return preg_replace('/^(query|path)\./', '', $field);
}

// ---------------------------------------------------------------------------
// The names a request may use
// ---------------------------------------------------------------------------

// The known name closest to a name that is none, when it is close enough to be a typo ("stampColour" -> "stampColor"); null otherwise.
function input_closest(string $name, array $known): ?string {
	$best = null;
	$best_distance = PHP_INT_MAX;
	foreach ($known as $candidate) {
		$distance = levenshtein(strtolower(substr($name, 0, 60)), strtolower($candidate));
		if ($distance < $best_distance) [$best, $best_distance] = [$candidate, $distance];
	}
	return $best_distance <= 2 || ($best_distance <= 3 && strlen($name) >= 8) ? $best : null;
}

// The message and the code of a name nobody reads: a name over 64 characters is cut, it is only there to be recognised.
function input_unknown_issue(string $field_prefix, string $name, array $known): array {
	$shown = mb_strlen($name) > 64 ? mb_substr($name, 0, 64) . "..." : $name;
	$closest = input_closest($name, $known);
	$what = $field_prefix === "query." ? "parameter" : "field";
	if (is_null($closest)) $hint = empty($known) ? "This endpoint takes no $what." : "Known " . $what . "s: " . implode(", ", $known) . ".";
	else $hint = "Did you mean '$closest'?";
	$message = "Unknown $what '$shown'. $hint";
	return input_issue($field_prefix . $shown, "unknown_field", $message);
}

// Issues for the names of $given that are not in $known: unknown ones, and read-only ones ($read_only, a subset the server writes). $prefix is
// "" for a body and "query." for a query string.
function input_check_names(array $given, array $known, array &$issues, array $read_only = [], string $prefix = ""): void {
	foreach (array_keys($given) as $name) {
		$name = (string) $name;
		if (in_array($name, $read_only, true)) {
			$issues[] = input_issue($prefix . $name, "read_only_field", "'$name' is read-only: the server sets it. Remove it from the request.");
		}
		elseif (!in_array($name, $known, true)) {
			$issues[] = input_unknown_issue($prefix, $name, $known);
		}
	}
}

// ---------------------------------------------------------------------------
// The body
// ---------------------------------------------------------------------------

// The JSON body of a POST: http_json_body() (415 and invalid_json end the request), then the names checked. $known are the
// names the endpoint reads; $ignored are names it accepts and drops (the output-only fields of a day a client sends back as it read it);
// $read_only are refused with their own code. Returns the body without the ignored names, and without the fields set to null: a field that is
// null is a field that is not sent (a client library that writes its unset options as null says the same as one that leaves them out).
function input_body(array $known, array &$issues, array $ignored = [], array $read_only = []): array {
	$body = http_json_body();
	input_check_names($body, array_merge($known, $ignored), $issues, $read_only);
	return array_filter(array_diff_key($body, array_flip($ignored)), fn($value) => !is_null($value));
}

// A body on a request that takes none (GET, DELETE).
function input_check_no_body(array &$issues): void {
	if (http_body_present()) $issues[] = input_issue("body", "body_not_allowed", "This request takes no body.");
}

// A field that must be true or false; null when absent.
function input_bool_field(array $body, string $field, array &$issues): ?bool {
	if (!array_key_exists($field, $body)) return null;
	if (!is_bool($body[$field])) {
		input_wrong_type($issues, $field, $body[$field], "true or false");
		return null;
	}
	return $body[$field];
}

// A field that must be present: an issue when it is not.
function input_require(array $body, string $field, array &$issues): bool {
	if (array_key_exists($field, $body) && !is_null($body[$field])) return true;
	$issues[] = input_issue($field, "missing_field", "'$field' is required.");
	return false;
}

// A one-time code (TOTP): a string of digits, or a number. As a string, null when absent (an issue when $required) or wrong (an issue).
function input_code(array $body, array &$issues, bool $required = true): ?string {
	if (!array_key_exists("code", $body)) {
		if ($required) $issues[] = input_issue("code", "missing_field", "'code' (the one-time code of the authenticator app) is required.");
		return null;
	}
	if (!is_string($body["code"]) && !is_int($body["code"])) {
		input_wrong_type($issues, "code", $body["code"], "a string of digits (the one-time code)");
		return null;
	}
	if (trim((string) $body["code"]) === "") {
		$issues[] = input_issue("code", "missing_field", "'code' must not be empty.");
		return null;
	}
	return (string) $body["code"];
}

// A secret that must be a non-empty string (a password, a token): the issue never holds its value.
function input_secret(array $body, string $field, array &$issues, bool $required = true): ?string {
	if (!array_key_exists($field, $body) || is_null($body[$field])) {
		if ($required) $issues[] = input_issue($field, "missing_field", "'$field' is required.");
		return null;
	}
	if (!is_string($body[$field])) {
		input_wrong_type($issues, $field, $body[$field], "a string");
		return null;
	}
	if ($body[$field] === "") {
		$issues[] = input_issue($field, "missing_field", "'$field' must not be empty.");
		return null;
	}
	return $body[$field];
}

// A timestamp a client sends, body or query: the same moment in UTC as "Y-m-d H:i:s", null when there is none (absent or null). One that is
// not an RFC 3339 timestamp with a zone is an issue.
function input_timestamp(mixed $value, string $field, array &$issues): ?string {
	if (is_null($value)) return null;
	if (!is_string($value)) {
		input_wrong_type($issues, $field, $value, "a timestamp string such as 2026-03-10T15:30:45Z");
		return null;
	}
	$utc = date_parse_rfc3339($value);
	if (is_null($utc)) {
		$issues[] = input_issue($field, "invalid_timestamp", "'" . input_label($field) . "' must be an RFC 3339 timestamp with a zone, such as 2026-03-10T15:30:45Z or 2026-03-10T17:30:45+02:00"
			. (str_starts_with($field, "query.") ? " (in a URL, write the '+' of an offset as %2B)." : "."));
	}
	return $utc;
}

// ---------------------------------------------------------------------------
// The query string
// ---------------------------------------------------------------------------

// The query string as [name => string]. A name that is not in $known is an issue (`query.<name>`), and so is a value that is not a plain
// string (`?from[]=2026-01-01`).
function input_query(array $known, array &$issues): array {
	$values = [];
	input_check_names($_GET, $known, $issues, [], "query.");
	foreach ($_GET as $name => $value) {
		$name = (string) $name;
		if (!in_array($name, $known, true)) continue;
		if (!is_string($value)) input_wrong_type($issues, "query.$name", $value, "a single value");
		else $values[$name] = $value;
	}
	return $values;
}

// A query value that is a boolean: true, false, 1 or 0. Absent is $default.
function input_query_bool(array $query, string $name, array &$issues, bool $default = false): bool {
	if (!array_key_exists($name, $query)) return $default;
	$value = ["true" => true, "1" => true, "false" => false, "0" => false][$query[$name]] ?? null;
	if (is_null($value)) {
		$issues[] = input_issue("query.$name", "invalid_value", "'$name' must be true, false, 1 or 0.");
		return $default;
	}
	return $value;
}

// A query value that is a calendar date YYYY-MM-DD; null (with an issue) when it is not.
function input_query_date(array $query, string $name, array &$issues): ?string {
	if (!array_key_exists($name, $query)) return null;
	$date = date_parse_ymd($query[$name]);
	if (is_null($date)) $issues[] = input_issue("query.$name", "invalid_date", "'$name' must be a real date in YYYY-MM-DD format.");
	return $date;
}

// ---------------------------------------------------------------------------
// The path after the endpoint's name
// ---------------------------------------------------------------------------

// The segments of the URL after the script (/api/day/2026-03-10 -> ["2026-03-10"]), decoded, empty ones dropped.
function input_path(): array {
	$info = $_SERVER['PATH_INFO'] ?? "";
	return array_values(array_filter(explode("/", is_string($info) ? $info : ""), fn($segment) => $segment !== ""));
}

// A path that has more segments than the endpoint reads is a URL that does not exist.
function input_exit_unless_path_fits(array $path, int $max): void {
	if (count($path) > $max) http_exit_not_found();
}

// A path segment that is a calendar date; null (with an issue) when it is not.
function input_path_date(string $segment, array &$issues): ?string {
	$date = date_parse_ymd($segment);
	if (is_null($date)) $issues[] = input_issue("path.date", "invalid_date", "The date in the URL must be a real date in YYYY-MM-DD format.");
	return $date;
}

// A path segment that is a row number: digits only, from 1. null (with an issue) when it is not.
function input_path_id(string $segment, array &$issues): ?int {
	if (!preg_match('/\A[1-9][0-9]{0,9}\z/', $segment) || intval($segment) > 2147483647) {
		$issues[] = input_issue("path.id", "invalid_value", "The id in the URL must be a whole number from 1.");
		return null;
	}
	return intval($segment);
}
