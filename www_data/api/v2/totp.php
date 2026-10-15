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

[$db, $user_account] = api_start();

// POST /totp starts the set-up, POST /totp/confirm turns it on with a code, POST /totp/disable turns it off with a code
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

$body = input_body(["code"], $issues);
$code = input_code($body, $issues);
input_exit_if_issues($issues);

// CONFIRM or DISABLE
$enable = $step === "confirm";
if ($enable && $is_active) http_error(409, "totp_already_enabled", "Two-factor authentication is already enabled.");
if (!$enable && !$is_active) http_error(409, "totp_not_enabled", "Two-factor authentication is not enabled.");
if (!sec_totp_code_valid($user_account, $code)) {
	log_event("account.refused", ["act" => $enable ? "totp_enable" : "totp_disable", "err" => "totp_invalid"]);
	http_error(401, "totp_invalid", "Entered TOTP code is not valid.");
}

$state = $enable ? TOTP_STATE_ACTIVE : TOTP_STATE_DISABLED;
db_update_user_account_totp($db, $user_account["no_user_account"], $state, $enable ? $user_account["totp_secret"] : null);
if ($enable) log_event("account.totp_enabled");
else log_event("account.totp_disabled");
http_data(200, ["totpState" => sec_totp_state_name($state)]);
