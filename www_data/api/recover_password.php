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

[$db] = api_start(false);

$body = http_json_body();
$email = account_email_normalise($body["email"] ?? null);

if (empty($email)) {
	http_error(400, "missing_email", "'email' is required.");
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
	http_error(400, "invalid_email", "Provided email address is not valid.");
}

// the attempt is written before the captcha is looked at, and given back if it passes: only the failures count
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
