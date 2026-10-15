<?php
/* moncycle.app
**
** licence Creative Commons CC BY-NC-SA
**
** https://www.moncycle.app
** https://github.com/moncycle-app/backend-api-web-app
*/

declare(strict_types=1);

require_once __DIR__ . "/../config.php";
require_once __DIR__ . "/../lib/api.php";
require_once __DIR__ . "/../lib/day_format.php";
require_once __DIR__ . "/../lib/input.php";
require_once __DIR__ . "/../lib/mail.php";
require_once __DIR__ . "/../lib/sec_captcha.php";
require_once __DIR__ . "/../lib/user_account.php";

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
$email = input_email($body, "email", $issues, false);
$password = input_secret($body, "password", $issues, false);
$issue = is_null($password) ? null : sec_password_issue($password, "password");
if (!is_null($issue)) $issues[] = $issue;
$birth_year = user_account_birth_year($body, $issues);
$nickname = day_format_body_text($body, "nickname", ACCOUNT_LIMIT_NICKNAME_CHARS, $issues);
$discovered_comment = day_format_body_text($body, "discoveredComment", ACCOUNT_LIMIT_REGISTER_COMMENT_CHARS, $issues);
$nfp_method = user_account_method_id_from_json($body, $issues);
$research = input_bool_field($body, "okForResearch", $issues);
if (input_bool_field($body, "termsAccepted", $issues) === false) {
	$issues[] = input_issue("termsAccepted", "terms_not_accepted", "'termsAccepted' must be true: an account is not created unless its owner accepts the terms of service (their address is 'termsOfServiceUrl' in GET /api/server_info).");
}
input_check_strings($body, ["captcha", "captchaToken"], $issues);
input_exit_if_issues($issues);

$attempt = sec_captcha_exit_unless_attempt_allowed($db, "register");
$registration = user_account_register($db, [
	"nickname" => $nickname, "email" => $email, "password" => $password, "birth_year" => $birth_year,
	"nfp_method" => $nfp_method, "comment" => $discovered_comment, "research" => $research === true,
], $body["captchaToken"] ?? null, $body["captcha"] ?? "", sec_client_ip());
sec_captcha_exit_unless_ok($db, $attempt, $registration["captcha"], "register");

// The same answer whether the address was new or had an account; the mail is not: a link to prove the address, or to choose a new password.
$account = $registration["account"];
$sent = !is_null($account) && !is_null($registration["token"])
	&& mail_send_link($registration["created"] ? "verify_email" : "account_exists", $account["login_email"], $account["name"], $registration["token"]);

http_data(202, ["sent" => $sent]);
