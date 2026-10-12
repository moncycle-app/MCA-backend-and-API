<?php
/* moncycle.app
**
** licence Creative Commons CC BY-NC-SA
**
** https://www.moncycle.app
** https://github.com/moncycle-app/backend-api-web-app
*/

require_once "../config.php";
require_once "../lib/account.php";
require_once "../lib/api.php";
require_once "../lib/mail.php";
require_once "../lib/token.php";

// POST is the sign-in and needs no token; GET needs one; DELETE ends the caller's own session whoever it is (a dead token or none
// included: the cookie must still go) or revokes others. A read-only token reaches the DELETE: token_revoke() decides what it may.
[$db, $user_account] = api_start(false, true);

// SIGN IN: credentials for a token (a browser's session with "cookie": true, or a custom token)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

	$body = http_json_body();
	// the address is looked up as it is stored: trimmed, in lower case (sec_login reads it from the body)
	if (is_string($body["email"] ?? null)) $body["email"] = account_email_normalise($body["email"]);
	$options = token_parse_options($body);

	$login = sec_login($db, $body, $options);
	if (!isset($login["data"])) http_error($login["status"], $login["code"], $login["message"], $login["meta"]);

	$token = token_find($db, intval($login["data"]["userId"]), $login["data"]["prefix"]) ?? [];

	// every login is told to its owner, the demo accounts' visitors (their password is public) excepted
	if (!in_array(intval($login["data"]["userId"]), ACCOUNT_DEMO_IDS, true)) {
		$account = db_select_user_account($db, intval($login["data"]["userId"]));
		$expires_at = is_null($options["expires_in_days"]) ? null : http_from_iso8601($token["expiresAt"] ?? null);
		if (!is_null($account)) mail_send_new_login($account, $options["name"], $login["data"]["prefix"], AUTH_TOKEN_SCOPE_NAMES[$options["scope"]], sec_user_agent(), $expires_at);
	}

	http_data($login["status"], array_merge($login["data"], $token));
}

// THE ACCOUNT'S TOKENS
if (in_array($_SERVER['REQUEST_METHOD'], ['GET', 'HEAD'], true)) {

	sec_exit_if_logged_out($user_account);
	token_exit_if_demo($user_account);

	$tokens = token_list($db, $user_account);
	log_note(["n" => count($tokens)]);
	http_data(200, ["tokens" => $tokens]);
}

// LOG OUT, OR REVOKE
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {

	// no body: the caller's own session ends. Idempotent: a token that is already gone, or none, is a logout all the same, and
	// the cookie (HttpOnly, only the server can clear it) goes in every case
	if (trim(file_get_contents('php://input')) === "") {
		if (!is_null($user_account)) {
			db_delete_auth_token($db, $user_account["no_auth_token"], $user_account["no_user_account"]);
			log_event("auth.logout");
		}
		sec_clear_token_cookie();
		http_no_content();
	}

	// a body: {"prefix": "abcde"} one token, {"all": true} every other one
	sec_exit_if_logged_out($user_account);
	$revoked = token_revoke($db, $user_account, http_json_body());
	log_event("auth.token_revoked", ["out" => $revoked]);
	http_data(200, ["revoked" => $revoked]);
}

http_error(405, "method_not_allowed", "Use POST (sign in), GET (list) or DELETE (log out, revoke).");
