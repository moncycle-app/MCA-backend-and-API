<?php
/* moncycle.app
**
** licence Creative Commons CC BY-NC-SA
**
** https://www.moncycle.app
** https://github.com/moncycle-app/backend-api-web-app
*/

declare(strict_types=1);

require_once __DIR__ . "/../../config.php";
require_once __DIR__ . "/../../lib/api.php";
require_once __DIR__ . "/../../lib/input.php";
require_once __DIR__ . "/../../lib/sec_token.php";
require_once __DIR__ . "/../../lib/user_account.php";

[$db, $user_account] = api_start();

// POST /totp starts the set-up, POST /totp/confirm turns it on with the password and a code, POST /totp/disable turns it off with a code.
// The password is asked where the second factor takes effect: a session that only started the set-up cannot finish it, and so cannot
// shut the owner out of the next sign-in.
$path = input_path();
input_exit_unless_path_fits($path, 1);
$step = $path[0] ?? "";
if (!in_array($step, ["", "confirm", "disable"], true)) http_exit_not_found();
if (http_method() !== 'POST') http_exit_method_not_allowed(["POST"]);

$is_active = $user_account["totp_state"] == TOTP_STATE_ACTIVE;
$issues = [];
input_query([], $issues);

// START
if ($step === "") {
	if (http_body_present()) input_body([], $issues);   // optional, without any field
	input_exit_if_issues($issues);
	if ($is_active) http_error(409, "totp_already_enabled", "Two-factor authentication is already enabled.");

	$setup = sec_totp_begin($db, $user_account);
	log_event("account.totp_setup_started");
	http_data(200, $setup);
}

$enable = $step === "confirm";
$body = input_body($enable ? ["code", "password"] : ["code"], $issues);
$code = input_code($body, $issues);
$password = $enable ? input_password($body, "password", $issues) : null;
input_exit_if_issues($issues);

// CONFIRM or DISABLE. A wrong password or code counts against the account like a failed sign-in (lib/sec_token.php).
if ($enable && $is_active) http_error(409, "totp_already_enabled", "Two-factor authentication is already enabled.");
if (!$enable && !$is_active) http_error(409, "totp_not_enabled", "Two-factor authentication is not enabled.");
sec_token_reauth_exit_if_locked($user_account);
$hash = $enable ? user_account_password_check($db, $user_account, $password) : null;
if ($enable && is_null($hash)) {
	sec_token_reauth_failed($db, $user_account);
	log_event("account.refused", ["act" => "totp_enable", "err" => "invalid_password"]);
	http_error(401, "invalid_password", "Incorrect password.");
}
if (!sec_totp_code_valid($user_account, $code)) {
	sec_token_reauth_failed($db, $user_account);
	log_event("account.refused", ["act" => $enable ? "totp_enable" : "totp_disable", "err" => "totp_invalid"]);
	http_error(401, "totp_invalid", "Entered TOTP code is not valid.");
}
sec_token_reauth_passed($db, $user_account);
if ($enable) sec_password_upgrade($db, intval($user_account["no_user_account"]), $hash, $password);

$state = $enable ? TOTP_STATE_ACTIVE : TOTP_STATE_DISABLED;
db_querie_update_user_account_totp($db, $user_account["no_user_account"], $state, $enable ? $user_account["totp_secret"] : null);
if ($enable) log_event("account.totp_enabled");
else log_event("account.totp_disabled");
http_data(200, ["totpState" => sec_totp_state_name($state)]);
