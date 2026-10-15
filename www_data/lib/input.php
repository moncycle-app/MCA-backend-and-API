<?php
/* moncycle.app
**
** licence Creative Commons CC BY-NC-SA
**
** https://www.moncycle.app
** https://github.com/moncycle-app/backend-api-web-app
*/

declare(strict_types=1);

// What a request may say. Every endpoint reads its path, its query string and its body through here and collects the problems as
// issues {"field", "code", "message"} (codes: INPUT_ISSUE_CODES), so that one 400 `validation_failed` tells the client everything that
// is wrong at once. A message names the field, the type that came and what is expected; it never holds the value sent (a password, a
// code, the text of a day). `field` is `stampColor`, `freeMucusSensation[2]`, `query.from` or `path.date`.

require_once __DIR__ . "/date.php";
require_once __DIR__ . "/http.php";

// ---------------------------------------------------------------------------
// The issues
// ---------------------------------------------------------------------------

function input_issue(string $field, string $code, string $message): array {
	return ["field" => $field, "code" => in_array($code, INPUT_ISSUE_CODES, true) ? $code : "invalid_value", "message" => $message];
}

function input_exit_if_issues(array $issues): void {
	if (empty($issues)) return;
	$count = count($issues);
	$message = $count === 1
		? "The request is not valid: " . $issues[0]["message"]
		: "The request is not valid: $count problems (see details.issues). First: " . $issues[0]["message"];
	http_error(400, "validation_failed", $message, ["issues" => $issues]);
}

// "'name' must be a string, got array."
function input_wrong_type(array &$issues, string $field, mixed $value, string $expected): void {
	$type = match (true) {
		is_null($value) => "null",
		is_bool($value) => "boolean",
		is_int($value) => "whole number",
		is_float($value) => "decimal number",
		is_string($value) => "string",
		default => array_is_list($value) ? "array" : "object",
	};
	$issues[] = input_issue($field, "wrong_type", "'" . preg_replace('/^(query|path)\./', '', $field) . "' must be $expected, got $type.");
}

// ---------------------------------------------------------------------------
// The names a request may use
// ---------------------------------------------------------------------------

// An unknown name, with the known one it is a typo of ("stampColour" -> "stampColor") when one is close enough. A long name is cut:
// it is only there to be recognised.
function input_unknown_issue(string $prefix, string $name, array $known): array {
	$closest = null;
	$best_distance = PHP_INT_MAX;
	foreach ($known as $candidate) {
		$distance = levenshtein(strtolower(substr($name, 0, 60)), strtolower($candidate));
		if ($distance < $best_distance) [$closest, $best_distance] = [$candidate, $distance];
	}
	if (!($best_distance <= 2 || ($best_distance <= 3 && strlen($name) >= 8))) $closest = null;

	$shown = mb_strlen($name) > 64 ? mb_substr($name, 0, 64) . "..." : $name;
	$what = $prefix === "query." ? "parameter" : "field";
	if (is_null($closest)) $hint = empty($known) ? "This endpoint takes no $what." : "Known " . $what . "s: " . implode(", ", $known) . ".";
	else $hint = "Did you mean '$closest'?";
	return input_issue($prefix . $shown, "unknown_field", "Unknown $what '$shown'. $hint");
}

// Issues for the names of $given that are not in $known, and for the read-only ones the server writes. $prefix is "query." for a query.
function input_check_names(array $given, array $known, array &$issues, array $read_only = [], string $prefix = ""): void {
	foreach (array_keys($given) as $name) {
		$name = (string) $name;
		if (in_array($name, $read_only, true)) $issues[] = input_issue($prefix . $name, "read_only_field", "'$name' is read-only: the server sets it. Remove it from the request.");
		elseif (!in_array($name, $known, true)) $issues[] = input_unknown_issue($prefix, $name, $known);
	}
}

// ---------------------------------------------------------------------------
// The body
// ---------------------------------------------------------------------------

// The JSON body, its names checked. $ignored are accepted and dropped (the output-only fields of a day sent back as it was read),
// $read_only refused with their own code. A field set to null is a field not sent: it is dropped too.
function input_body(array $known, array &$issues, array $ignored = [], array $read_only = []): array {
	$body = http_json_body();
	input_check_names($body, array_merge($known, $ignored), $issues, $read_only);
	return array_filter(array_diff_key($body, array_flip($ignored)), fn($value) => !is_null($value));
}

function input_check_no_body(array &$issues): void {
	if (http_body_present()) $issues[] = input_issue("body", "body_not_allowed", "This request takes no body.");
}

// A request that takes neither a query string nor a body: the 400 for either, and for the issues already found.
function input_exit_unless_none(array $issues = []): void {
	input_query([], $issues);
	input_check_no_body($issues);
	input_exit_if_issues($issues);
}

// true, false, or null when absent (or wrong: an issue).
function input_bool_field(array $body, string $field, array &$issues): ?bool {
	if (!array_key_exists($field, $body)) return null;
	if (is_bool($body[$field])) return $body[$field];
	input_wrong_type($issues, $field, $body[$field], "true or false");
	return null;
}

function input_require(array $body, string $field, array &$issues): bool {
	if (array_key_exists($field, $body) && !is_null($body[$field])) return true;
	$issues[] = input_issue($field, "missing_field", "'$field' is required.");
	return false;
}

// Optional fields that must be strings when sent (a captcha and its token).
function input_check_strings(array $body, array $fields, array &$issues): void {
	foreach ($fields as $field) {
		if (array_key_exists($field, $body) && !is_string($body[$field])) input_wrong_type($issues, $field, $body[$field], "a string");
	}
}

// One of $values, or null when absent (an issue when $required) or wrong (an issue).
function input_choice(array $body, string $field, array $values, array &$issues, bool $required = true): ?string {
	if ($required ? !input_require($body, $field, $issues) : !array_key_exists($field, $body)) return null;
	$list = implode(", ", $values);
	if (!is_string($body[$field])) input_wrong_type($issues, $field, $body[$field], "a string ($list)");
	elseif (!in_array($body[$field], $values, true)) $issues[] = input_issue($field, "invalid_value", "'$field' must be one of: $list.");
	else return $body[$field];
	return null;
}

// An address as it is stored and looked up: trimmed and in lower case, so that Alice@X.test is alice@x.test ("" when it is no string).
// FILTER_VALIDATE_EMAIL refuses non-ASCII, so strtolower never meets a multibyte character.
function input_email_normalise(mixed $email): string {
	return is_string($email) ? strtolower(trim($email)) : "";
}

// A valid address, normalised; null when absent (an issue when $required) or wrong (an issue).
function input_email(array $body, string $field, array &$issues, bool $required = true): ?string {
	if ($required ? !input_require($body, $field, $issues) : !array_key_exists($field, $body)) return null;
	if (!is_string($body[$field])) {
		input_wrong_type($issues, $field, $body[$field], "a string (an e-mail address)");
		return null;
	}
	$email = input_email_normalise($body[$field]);
	if (filter_var($email, FILTER_VALIDATE_EMAIL)) return $email;
	$issues[] = input_issue($field, "invalid_email", "'$field' is not a valid e-mail address.");
	return null;
}

// A one-time code: digits as a string or a number, returned as a string; null when absent (an issue when $required) or wrong.
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

// A non-empty string (a password, a token); null when absent (an issue when $required) or wrong (an issue).
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

// An RFC 3339 timestamp with a zone, as the UTC "Y-m-d H:i:s" the app stores; null when absent (or wrong: an issue).
function input_timestamp(mixed $value, string $field, array &$issues): ?string {
	if (is_null($value)) return null;
	if (!is_string($value)) {
		input_wrong_type($issues, $field, $value, "a timestamp string such as 2026-03-10T15:30:45Z");
		return null;
	}
	$utc = date_parse_rfc3339($value);
	if (is_null($utc)) {
		$issues[] = input_issue($field, "invalid_timestamp", "'" . preg_replace('/^(query|path)\./', '', $field) . "' must be an RFC 3339 timestamp with a zone, such as 2026-03-10T15:30:45Z or 2026-03-10T17:30:45+02:00"
			. (str_starts_with($field, "query.") ? " (in a URL, write the '+' of an offset as %2B)." : "."));
	}
	return $utc;
}

// The client's own moment of a write ("lastWriteClientUtc", of the body or with $prefix "query." of the query), or the server's clock when
// it sent none or one a TIMESTAMP column cannot store: a client whose clock is wrong must still be able to write.
function input_last_write(array $values, array &$issues, string $prefix = ""): string {
	$utc = input_timestamp($values["lastWriteClientUtc"] ?? null, $prefix . "lastWriteClientUtc", $issues);
	return $utc && $utc >= TIMESTAMP_STORABLE_MIN && $utc <= TIMESTAMP_STORABLE_MAX ? $utc : date('Y-m-d H:i:s');
}

// ---------------------------------------------------------------------------
// The query string
// ---------------------------------------------------------------------------

// [name => string]. An unknown name is an issue, and so is a value that is not a plain string (`?from[]=2026-01-01`).
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

// true, false, 1 or 0; $default when absent (or wrong: an issue).
function input_query_bool(array $query, string $name, array &$issues, bool $default = false): bool {
	if (!array_key_exists($name, $query)) return $default;
	$value = ["true" => true, "1" => true, "false" => false, "0" => false][$query[$name]] ?? null;
	if (!is_null($value)) return $value;
	$issues[] = input_issue("query.$name", "invalid_value", "'$name' must be true, false, 1 or 0.");
	return $default;
}

function input_query_date(array $query, string $name, array &$issues): ?string {
	if (!array_key_exists($name, $query)) return null;
	$date = date_parse_ymd($query[$name]);
	if (is_null($date)) $issues[] = input_issue("query.$name", "invalid_date", "'$name' must be a real date in YYYY-MM-DD format.");
	return $date;
}

// ---------------------------------------------------------------------------
// The path after the endpoint's name
// ---------------------------------------------------------------------------

// /api/day/2026-03-10 -> ["2026-03-10"], empty segments dropped.
function input_path(): array {
	$info = $_SERVER['PATH_INFO'] ?? "";
	return array_values(array_filter(explode("/", is_string($info) ? $info : ""), fn($segment) => $segment !== ""));
}

// More segments than the endpoint reads: a URL that does not exist.
function input_exit_unless_path_fits(array $path, int $max): void {
	if (count($path) > $max) http_exit_not_found();
}

function input_path_date(string $segment, array &$issues): ?string {
	$date = date_parse_ymd($segment);
	if (is_null($date)) $issues[] = input_issue("path.date", "invalid_date", "The date in the URL must be a real date in YYYY-MM-DD format.");
	return $date;
}

// A row number: digits only, from 1, within an int column.
function input_path_id(string $segment, array &$issues): ?int {
	if (preg_match('/\A[1-9][0-9]{0,9}\z/', $segment) && intval($segment) <= 2147483647) return intval($segment);
	$issues[] = input_issue("path.id", "invalid_value", "The id in the URL must be a whole number from 1.");
	return null;
}
