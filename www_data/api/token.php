<?php
/* moncycle.app
**
** licence Creative Commons CC BY-NC-SA
**
** https://www.moncycle.app
** https://github.com/moncycle-app/backend-api-web-app
*/

require_once __DIR__ . "/../config.php";
require_once __DIR__ . "/../lib/user_account.php";
require_once __DIR__ . "/../lib/api.php";
require_once __DIR__ . "/../lib/input.php";
require_once __DIR__ . "/../lib/mail.php";
require_once __DIR__ . "/../lib/sec_token.php";

[$db, $user_account] = api_start(false, true);

$path = input_path();
input_exit_unless_path_fits($path, 1);
$method = http_method();
$issues = [];

// /token/{prefix} exists for the revocation only
if ($path !== [] && $method !== 'DELETE') http_exit_method_not_allowed(["DELETE"]);

// SIGN IN: POST /token
if ($method === 'POST') {
	input_query([], $issues);
	$body = input_body(["email", "password", "name", "scope", "expiresInDays", "cookie", "captcha", "captchaToken", "code"], $issues);

	if (input_require($body, "email", $issues)) {
		if (!is_string($body["email"])) input_wrong_type($issues, "email", $body["email"], "a string (an e-mail address)");
		else {
			$body["email"] = user_account_email_normalise($body["email"]);
			if (!filter_var($body["email"], FILTER_VALIDATE_EMAIL)) $issues[] = input_issue("email", "invalid_email", "'email' is not a valid e-mail address.");
		}
	}
	input_secret($body, "password", $issues);
	foreach (["captcha", "captchaToken"] as $field) {
		if (array_key_exists($field, $body) && !is_string($body[$field])) input_wrong_type($issues, $field, $body[$field], "a string");
	}
	input_code($body, $issues, false);
	$options = sec_token_parse_options($body, $issues);
	input_exit_if_issues($issues);

	$login = sec_token_login($db, $body, $options);
	if (!isset($login["data"])) http_error($login["status"], $login["code"], $login["message"], $login["meta"]);

	$token = sec_token_find($db, intval($login["data"]["userId"]), $login["data"]["prefix"]) ?? [];

	if (!in_array(intval($login["data"]["userId"]), ACCOUNT_DEMO_IDS, true)) {
		$account = db_select_user_account($db, intval($login["data"]["userId"]));
		$expires_at = is_null($options["expires_in_days"]) ? null : date_parse_rfc3339($token["expiresAt"] ?? null);
		if (!is_null($account)) mail_send_new_login($account, $options["name"], $login["data"]["prefix"], AUTH_TOKEN_SCOPE_NAMES[$options["scope"]], sec_token_user_agent(), $expires_at);
	}

	http_data($login["status"], array_merge($login["data"], $token));
}

// THE LIST OF THE ACCOUNT'S TOKENS: GET /token
if ($method === 'GET') {
	input_query([], $issues);
	input_check_no_body($issues);
	input_exit_if_issues($issues);

	sec_exit_if_logged_out($user_account);
	sec_token_exit_if_demo($user_account);

	$tokens = sec_token_list($db, $user_account);
	log_note(["n" => count($tokens)]);
	http_data(200, $tokens);
}

// LOG OUT, OR REVOKE: DELETE /token (this session), DELETE /token/{prefix} (one token), DELETE /token?others=true (all the other ones)
if ($method === 'DELETE') {
	$query = input_query(["others"], $issues);
	input_check_no_body($issues);

	$prefix = $path[0] ?? null;
	if (!is_null($prefix) && !sec_token_prefix_is_valid($prefix)) {
		$issues[] = input_issue("path.prefix", "invalid_value", "The token in the URL must be the first " . AUTH_TOKEN_PREFIX_CHARS . " characters of the token (letters and digits), as GET /token shows them.");
	}
	$others = false;
	if (array_key_exists("others", $query)) {
		$others = input_query_bool($query, "others", $issues);
		if (!$others) $issues[] = input_issue("query.others", "invalid_value", "'others' can only be true: it revokes every token but this one. Leave it out to log out.");
		elseif (!is_null($prefix)) $issues[] = input_issue("query.others", "invalid_value", "Name one token in the URL or send 'others=true', not both.");
	}
	input_exit_if_issues($issues);

	if (is_null($prefix) && !$others) {
		if (!is_null($user_account)) {
			db_delete_auth_token($db, $user_account["no_auth_token"], $user_account["no_user_account"]);
			log_event("auth.logout");
		}
		sec_token_clear_cookie();
		http_no_content();
	}

	sec_exit_if_logged_out($user_account);
	$revoked = sec_token_revoke($db, $user_account, $prefix);
	log_context(["rev" => $revoked ?: null]);
	log_event("auth.token_revoked", ["out" => count($revoked)]);
	http_data(200, ["revoked" => count($revoked)]);
}

http_exit_method_not_allowed(["POST", "GET", "DELETE"]);
