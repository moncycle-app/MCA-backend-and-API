<?php
/* moncycle.app
**
** licence Creative Commons CC BY-NC-SA
**
** https://www.moncycle.app
** https://github.com/moncycle-app/backend-api-web-app
*/

require_once __DIR__ . "/../config.php";
require_once __DIR__ . "/../lib/api.php";
require_once __DIR__ . "/../lib/account.php";
require_once __DIR__ . "/../lib/day_format.php";
require_once __DIR__ . "/../lib/input.php";
require_once __DIR__ . "/../lib/mail.php";

[$db, $user_account] = api_start(false);

if (http_method() !== "POST") http_exit_method_not_allowed(["POST"]);
input_exit_unless_path_fits(input_path(), 0);

$issues = [];
input_query([], $issues);
$body = input_body(["nickname", "email", "password", "birthYear", "method", "temperatureTracking", "discoveredComment", "okForResearch", "termsAccepted", "captcha", "captchaToken"], $issues);

if (!is_null($user_account) && !sec_session_is_demo($user_account)) {
	http_error(409, "already_authenticated", "Account already logged in.", ["userId" => $user_account["no_user_account"]]);
}

if (!REGISTRATION_ENABLED) {
	log_event("account.refused", ["act" => "register", "err" => "registration_disabled"]);
	http_error(403, "registration_disabled", "Account creation has been disabled.");
}

foreach (["nickname", "email", "password", "birthYear", "termsAccepted"] as $field) input_require($body, $field, $issues);

if (array_key_exists("email", $body)) {
	if (!is_string($body["email"])) input_wrong_type($issues, "email", $body["email"], "a string (an e-mail address)");
	else {
		$body["email"] = account_email_normalise($body["email"]);
		if (!filter_var($body["email"], FILTER_VALIDATE_EMAIL)) $issues[] = input_issue("email", "invalid_email", "'email' is not a valid e-mail address.");
	}
}

$password = input_secret($body, "password", $issues, false);
if (!is_null($password)) {
	$issue = sec_password_issue($password, "password");
	if (!is_null($issue)) $issues[] = $issue;
}

if (array_key_exists("birthYear", $body)) {
	if (!is_int($body["birthYear"])) input_wrong_type($issues, "birthYear", $body["birthYear"], "a whole number (a year)");
	elseif (!account_birth_year_valid($body["birthYear"])) {
		$issues[] = input_issue("birthYear", "out_of_range", "'birthYear' must be a year from " . (intval(date("Y")) - ACCOUNT_BIRTH_YEAR_MAX_AGE) . " to " . date("Y") . ".");
	}
}

$nickname = day_format_body_text($body, "nickname", ACCOUNT_LIMIT_NICKNAME_CHARS, $issues);
$discovered_comment = day_format_body_text($body, "discoveredComment", ACCOUNT_LIMIT_REGISTER_COMMENT_CHARS, $issues);
$nfp_method = account_method_id_from_json($body, $issues);
$research = input_bool_field($body, "okForResearch", $issues);
if (input_bool_field($body, "termsAccepted", $issues) === false) {
	$issues[] = input_issue("termsAccepted", "terms_not_accepted", "'termsAccepted' must be true: an account is not created unless its owner accepts the terms of service (their address is 'termsOfServiceUrl' in GET /api/server_info).");
}
foreach (["captcha", "captchaToken"] as $field) {
	if (array_key_exists($field, $body) && !is_string($body[$field])) input_wrong_type($issues, $field, $body[$field], "a string");
}
input_exit_if_issues($issues);

$attempt = sec_exit_unless_attempt_allowed($db, "register");
$registration = account_register($db, [
	"nickname" => $nickname, "email" => $body["email"], "password" => $password, "birth_year" => $body["birthYear"],
	"nfp_method" => $nfp_method, "comment" => $discovered_comment, "research" => $research === true,
], $body["captchaToken"] ?? null, $body["captcha"] ?? "", sec_client_ip());
sec_exit_unless_captcha_ok($db, $attempt, $registration["captcha"], "register");

// The answer is the same whether the address was new or already had an account (the mail is not: a link to confirm the address, or a
// link to choose a new password). The account is the same one in both cases, and a new one cannot be used until its address is proved.
$account = $registration["account"];
$sent = false;
if (!is_null($account) && !is_null($registration["token"])) {
	$sent = $registration["created"]
		? mail_send_verify_email($account["name"], $account["login_email"], $registration["token"])
		: mail_send_account_exists($account["name"], $account["login_email"], $registration["token"]);
}

http_data(202, ["sent" => $sent]);
