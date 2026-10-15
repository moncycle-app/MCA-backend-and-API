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
require_once __DIR__ . "/../lib/input.php";
require_once __DIR__ . "/../lib/mail.php";
require_once __DIR__ . "/../lib/sec_captcha.php";
require_once __DIR__ . "/../lib/user_account.php";

[$db] = api_start(false);

if (http_method() !== "POST") http_exit_method_not_allowed(["POST"]);
input_exit_unless_path_fits(input_path(), 0);

$issues = [];
input_query([], $issues);
$body = input_body(["email", "captcha", "captchaToken"], $issues);
$email = input_email($body, "email", $issues);
input_check_strings($body, ["captcha", "captchaToken"], $issues);
input_exit_if_issues($issues);

$attempt = sec_captcha_exit_unless_attempt_allowed($db, "recover_password");
$recovery = user_account_recover_password($db, $email, $body["captchaToken"] ?? null, $body["captcha"] ?? "", sec_client_ip());
sec_captcha_exit_unless_ok($db, $attempt, $recovery["captcha"], "recover_password");

// The same answer, after as long, whether or not the account exists: nobody can use this to find out who has one. Logged before the
// sleep, so that a worker killed meanwhile does not lose it. The link is committed: the mail goes now.
if (!is_null($recovery["account"])) {
	log_context(["uid" => intval($recovery["account"]["no_user_account"])]);
	mail_send_link("reset_password", $email, $recovery["account"]["name"], $recovery["token"]);
	sleep(rand(1, 4));
}
else {
	log_event("account.password_reset_unknown");
	sleep(rand(1, 5));
}

http_data(200, ["sent" => true]);
