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
require_once "../lib/email_token.php";

// ASKS TO CHANGE THE PRIMARY ADDRESS (the one that signs in and gets the password links). The change happens when the link sent to the
// NEW address is used (POST /api/email_token). The primary address is what every recovery rests on, so a session alone cannot ask:
// the password confirms, and the TOTP code too when the account has one.
[$db, $user_account] = api_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
	http_error(405, "method_not_allowed", "Use POST.");
}

// the demo accounts are shared: their address is not for a visitor to change
if (sec_session_is_demo($user_account)) {
	http_error(403, "demo_account", "Not available on a demo account.");
}

$body = http_json_body();
$new_email = account_email_normalise($body["newEmail"] ?? null);

if ($new_email === "") {
	http_error(400, "missing_fields", "'newEmail' and 'password' are required.");
}
if (!filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
	http_error(400, "invalid_email", "Provided email address is not valid.");
}
if (!is_string($body["password"] ?? null) || $body["password"] === "") {
	http_error(400, "missing_password", "'password' is required to change the email address.");
}
if ($new_email === $user_account["email1"]) {
	http_error(400, "email_unchanged", "This is already the email address of the account.");
}

if (!account_password_matches($db, $user_account, $body["password"])) {
	log_event("account.refused", ["act" => "email_change", "err" => "invalid_password"]);
	http_error(401, "invalid_password", "Incorrect password.");
}

if ($user_account["totp_state"] == TOTP_STATE_ACTIVE && !sec_totp_code_valid($user_account, $body["code"] ?? "")) {
	$given = isset($body["code"]) && strlen((string) (is_scalar($body["code"]) ? $body["code"] : "")) > 0;
	log_event("account.refused", ["act" => "email_change", "err" => $given ? "totp_invalid" : "totp_required"]);
	http_error(401, $given ? "totp_invalid" : "totp_required", $given ? "The TOTP code is incorrect." : "A TOTP code is required.");
}

// The answer does not say whether the address was free: an address that has an account gets a mail saying so, not a link.
http_data(202, ["sent" => email_token_request_email_change($db, $user_account, $new_email)]);
