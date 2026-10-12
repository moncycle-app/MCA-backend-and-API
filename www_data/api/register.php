<?php
/* moncycle.app
**
** licence Creative Commons CC BY-NC-SA
**
** https://www.moncycle.app
** https://github.com/moncycle-app/backend-api-web-app
*/

require_once "../config.php";
require_once "../lib/api.php";
require_once "../lib/account.php";
require_once "../lib/day_format.php";
require_once "../lib/mail.php";

[$db, $user_account] = api_start(false);

$body = http_json_body();
// the address is stored as it is looked up: trimmed, in lower case (anything but a string is refused below)
if (is_string($body["email"] ?? null)) $body["email"] = account_email_normalise($body["email"]);

// someone on a demo account is no one who has an account: they can sign up
if (!is_null($user_account) && !sec_session_is_demo($user_account)) {
	http_error(409, "already_authenticated", "Account already logged in.", ["userId" => $user_account["no_user_account"]]);
}

if (!REGISTRATION_ENABLED) {
	log_event("account.refused", ["act" => "register", "err" => "registration_disabled"]);
	http_error(403, "registration_disabled", "Account creation has been disabled.");
}

if (!isset($body["firstName"]) || !isset($body["email"]) || !isset($body["birthYear"]) || !isset($body["password"])) {
	http_error(400, "missing_fields", "'firstName', 'email', 'password' and 'birthYear' are required.");
}

if (!filter_var($body["email"], FILTER_VALIDATE_EMAIL)) {
	http_error(400, "invalid_email", "Provided email address is not valid.");
}

// Everything that can be wrong with the fields is said before anything is looked up: no answer depends on whether the address has an account.
if (!is_string($body["password"])) {
	http_error(400, "invalid_parameter", "'password' must be a string.");
}
$problem = sec_password_problem($body["password"]);
if (!is_null($problem)) {
	http_error(422, $problem[0], $problem[1]);
}

if (!account_birth_year_valid(intval($body["birthYear"]))) {
	http_error(400, "invalid_birth_year", "Birth year is not realistic.");
}

[$first_name, $problem] = day_format_text($body["firstName"], "firstName", ACCOUNT_LIMIT_NAME_CHARS);
if (!is_null($problem)) {
	http_error(400, "invalid_first_name", $problem);
}

$discovered_comment = null;
if (isset($body["discoveredComment"])) {
	[$discovered_comment, $problem] = day_format_text($body["discoveredComment"], "discoveredComment", ACCOUNT_LIMIT_REGISTER_COMMENT_CHARS);
	if (!is_null($problem)) {
		http_error(400, "invalid_discovered_comment", $problem);
	}
}

// the attempt is written before the captcha is looked at, and given back if it passes: only the failures count
$attempt = sec_exit_unless_attempt_allowed($db, "register");
$registration = account_register($db, [
	"first_name" => $first_name, "email" => $body["email"], "password" => $body["password"], "birth_year" => intval($body["birthYear"]),
	"nfp_method" => account_method_id_from_json($body), "comment" => $discovered_comment, "research" => boolval($body["okForResearch"] ?? false),
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
