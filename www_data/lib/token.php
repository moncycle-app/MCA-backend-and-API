<?php
/* moncycle.app
**
** licence Creative Commons CC BY-NC-SA
**
** https://www.moncycle.app
** https://github.com/moncycle-app/backend-api-web-app
*/

require_once __DIR__ . "/day_format.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/http.php";
require_once __DIR__ . "/sec.php";

// What the account's tokens look like to the account page and to API clients: a list, a revocation, and what a client may ask of a
// new token (POST /api/token). A token is a session (the browser's, in a cookie) or a custom token (a script's, in a header): the
// table and the endpoint do not tell them apart, the name does.

// The options of a new token, from the body of POST /api/token: [name, scope, expires_in_days, cookie] as sec_auth_success() takes
// them. A body field that is not what it must be ends the request with a 400, before anything is read from the database. "name"
// is the client's own text (a browser says "WEB-APP <domain>"), cleaned like any text a client stores; "expiresInDays" is a whole
// number of days, absent for the rule of the cron (40 days unused, or a year old).
function token_parse_options(array $body): array {
	$name = AUTH_TOKEN_DEFAULT_NAME;
	if (isset($body["name"])) {
		[$text, $problem] = day_format_text($body["name"], "name", AUTH_TOKEN_LIMIT_NAME_CHARS);
		if (!is_null($problem)) http_error(400, "invalid_parameter", $problem);
		// one line: a name is a label in a list (day_format_clean_text() keeps line breaks and tabs, which a comment may have)
		$text = trim(preg_replace('/\s+/u', ' ', $text));
		if ($text !== "") $name = $text;
	}

	$scope = AUTH_TOKEN_SCOPE_READ_WRITE;
	if (isset($body["scope"])) {
		$scope = is_string($body["scope"]) ? array_search($body["scope"], AUTH_TOKEN_SCOPE_NAMES, true) : false;
		if ($scope === false) http_error(400, "invalid_parameter", "'scope' must be " . implode(" or ", array_map(fn($n) => "'$n'", AUTH_TOKEN_SCOPE_NAMES)) . ".");
	}

	$expires_in_days = $body["expiresInDays"] ?? null;
	if (!is_null($expires_in_days) && (!is_int($expires_in_days) || $expires_in_days < AUTH_TOKEN_EXPIRES_DAYS_MIN || $expires_in_days > AUTH_TOKEN_EXPIRES_DAYS_MAX)) {
		http_error(400, "invalid_parameter", "'expiresInDays' must be a whole number from " . AUTH_TOKEN_EXPIRES_DAYS_MIN . " to " . AUTH_TOKEN_EXPIRES_DAYS_MAX . ".");
	}

	if (isset($body["cookie"]) && !is_bool($body["cookie"])) http_error(400, "invalid_parameter", "'cookie' must be true or false.");

	return ["name" => $name, "scope" => $scope, "expires_in_days" => $expires_in_days, "cookie" => ($body["cookie"] ?? false) === true];
}

// An address cut to its network, as the token list shows it: the first AUTH_TOKEN_IP_PREFIX_V4 bits of an IPv4 address ("a.b.c.0"),
// the first AUTH_TOKEN_IP_PREFIX_V6 of an IPv6 one ("2001:db8:1234::"). An IPv4-mapped IPv6 address is read as the IPv4 one.
// null when there is none or it is not an address. The database keeps the whole address.
function token_ip_network(?string $ip): ?string {
	$packed = is_null($ip) ? false : inet_pton($ip);
	if ($packed === false) return null;
	if (strlen($packed) === 16 && str_starts_with($packed, str_repeat("\0", 10) . "\xff\xff")) $packed = substr($packed, 12);

	$bits = strlen($packed) === 4 ? AUTH_TOKEN_IP_PREFIX_V4 : AUTH_TOKEN_IP_PREFIX_V6;
	$whole_bytes = intdiv($bits, 8);
	$network = substr($packed, 0, $whole_bytes);
	if ($bits % 8 !== 0) $network .= chr(ord($packed[$whole_bytes]) & ((0xFF << (8 - $bits % 8)) & 0xFF));
	return inet_ntop(str_pad($network, strlen($packed), "\0"));
}

// When a token dies, "Y-m-d H:i:s" UTC: its expiry date when it has one, else the year after its creation (a token with none is
// also erased after AUTH_TOKEN_IDLE_DAYS without use: that is not a date), null for a token the operator made to last (expire 0).
function token_expires_at(array $row): ?string {
	if (!is_null($row["date_expire"])) return $row["date_expire"];
	if (intval($row["expire"]) === 0) return null;
	return gmdate("Y-m-d H:i:s", strtotime($row["date_creation"] . " UTC") + AUTH_TOKEN_DEFAULT_LIFETIME_DAYS * 86400);
}

// One row of db_select_auth_tokens_listing() in the JSON API's shape. The hash is not in it, and the token itself is kept nowhere:
// it was shown once, when it was made. The addresses are cut (token_ip_network()).
function token_to_json(array $row, int $current_no_auth_token): array {
	return [
		"id" => intval($row["no_auth_token"]),
		"name" => $row["name"],
		"scope" => AUTH_TOKEN_SCOPE_NAMES[intval($row["scope"])] ?? AUTH_TOKEN_SCOPE_NAMES[AUTH_TOKEN_SCOPE_READ_WRITE],
		"current" => intval($row["no_auth_token"]) === $current_no_auth_token,
		"createdAt" => http_iso8601($row["date_creation"]),
		"createdUserAgent" => $row["user_agent"],
		"createdIp" => token_ip_network($row["ip_address"]),
		"lastUsedAt" => http_iso8601($row["date_use"]),
		"lastUsedUserAgent" => $row["user_agent_use"],
		"lastUsedIp" => token_ip_network($row["ip_address_use"]),
		"expiresAt" => http_iso8601(token_expires_at($row)),
	];
}

// The account's tokens, the newest first, the caller's own marked "current".
function token_list($db, array $user_account): array {
	$current = intval($user_account["no_auth_token"]);
	return array_map(fn($row) => token_to_json($row, $current), db_select_auth_tokens_listing($db, $user_account["no_user_account"]));
}

// The JSON of one token of the account (the one just made, so the caller's own), null when it is not there.
function token_find($db, int $no_user_account, int $no_auth_token): ?array {
	foreach (db_select_auth_tokens_listing($db, $no_user_account) as $row) {
		if (intval($row["no_auth_token"]) === $no_auth_token) return token_to_json($row, $no_auth_token);
	}
	return null;
}

// The demo accounts are shared by every visitor (their password is on the sign-in page): their tokens are the visitors' sessions, and
// neither the list nor the revocation of the others' belongs to anyone. Ends the request with a 403.
function token_exit_if_demo(array $user_account): void {
	if (sec_session_is_demo($user_account)) http_error(403, "demo_account", "Not available on a demo account.");
}

// DELETE /api/token with a body: {"id": N} revokes that token, {"all": true} every token but the caller's own. Answers how many
// were revoked. A token of another account is "not found", as one that does not exist. A read-only token may end its own session
// and nothing else. The caller's own token going (by its id) is a logout: the cookie goes with it.
function token_revoke($db, array $user_account, array $body): int {
	$no_user_account = intval($user_account["no_user_account"]);
	$current = intval($user_account["no_auth_token"]);
	$has_id = array_key_exists("id", $body);
	$has_all = array_key_exists("all", $body);

	if ($has_id === $has_all) http_error(400, "invalid_parameter", "Send either 'id' (a token) or 'all': true (every other token).");
	if ($has_all && $body["all"] !== true) http_error(400, "invalid_parameter", "'all' must be true.");
	if ($has_id && !is_int($body["id"])) http_error(400, "invalid_parameter", "'id' must be a whole number.");

	if ($has_all) {
		sec_exit_if_token_read_only($user_account);
		token_exit_if_demo($user_account);
		return db_delete_auth_tokens_other($db, $no_user_account, $current);
	}

	if ($body["id"] !== $current) {
		sec_exit_if_token_read_only($user_account);
		token_exit_if_demo($user_account);
	}
	if (db_delete_auth_token($db, $body["id"], $no_user_account) === 0) http_error(404, "not_found", "'id' does not match a token of this account.");
	if ($body["id"] === $current) sec_clear_token_cookie();
	return 1;
}
