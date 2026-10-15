<?php
/* moncycle.app
**
** licence Creative Commons CC BY-NC-SA
**
** https://www.moncycle.app
** https://github.com/moncycle-app/backend-api-web-app
*/

require_once __DIR__ . "/../config.php";
require_once __DIR__ . "/../lib/account.php";
require_once __DIR__ . "/../lib/api.php";
require_once __DIR__ . "/../lib/input.php";
require_once __DIR__ . "/../lib/mail.php";

[$db] = api_start(false);

if (http_method() !== "POST") http_exit_method_not_allowed(["POST"]);
input_exit_unless_path_fits(input_path(), 0);

$issues = [];
input_query([], $issues);
$body = input_body(["email", "captcha", "captchaToken"], $issues);

$email = null;
if (input_require($body, "email", $issues)) {
	if (!is_string($body["email"])) input_wrong_type($issues, "email", $body["email"], "a string (an e-mail address)");
	else {
		$email = account_email_normalise($body["email"]);
		if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $issues[] = input_issue("email", "invalid_email", "'email' is not a valid e-mail address.");
	}
}
foreach (["captcha", "captchaToken"] as $field) {
	if (array_key_exists($field, $body) && !is_string($body[$field])) input_wrong_type($issues, $field, $body[$field], "a string");
}
input_exit_if_issues($issues);

$attempt = sec_exit_unless_attempt_allowed($db, "recover_password");
$recovery = account_recover_password($db, $email, $body["captchaToken"] ?? null, $body["captcha"] ?? "", sec_client_ip());
sec_exit_unless_captcha_ok($db, $attempt, $recovery["captcha"], "recover_password");

// The answer is the same whether or not the account exists, and takes as long, so that nobody
// can use this to find out who has one. Logged before the sleep: a worker killed meanwhile must not lose it.
// The link is committed: the mail goes now, and only now. The password is not touched until the link is used.
if (!is_null($recovery["account"])) {
	log_context(["uid" => intval($recovery["account"]["no_user_account"])]);
	mail_send_reset_password($recovery["account"]["name"], $email, $recovery["token"]);
	sleep(rand(1, 4));
}
else {
	log_event("account.password_reset_unknown");
	sleep(rand(1, 5));
}

http_data(200, ["sent" => true]);
