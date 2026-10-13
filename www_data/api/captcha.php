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
require_once "../lib/captcha.php";
require_once "../lib/input.php";

[$db] = api_start(false);

if (http_method() !== "POST") http_exit_method_not_allowed(["POST"]);
input_exit_unless_path_fits(input_path(), 0);

$issues = [];
input_query([], $issues);
$body = input_body(["purpose", "email"], $issues);

if (input_require($body, "purpose", $issues)) {
	if (!is_string($body["purpose"])) input_wrong_type($issues, "purpose", $body["purpose"], "a string (" . implode(", ", CAPTCHA_PURPOSES) . ")");
	elseif (!in_array($body["purpose"], CAPTCHA_PURPOSES, true)) $issues[] = input_issue("purpose", "invalid_value", "'purpose' must be one of: " . implode(", ", CAPTCHA_PURPOSES) . ".");
}
$email = null;
if (input_require($body, "email", $issues)) {
	if (!is_string($body["email"])) input_wrong_type($issues, "email", $body["email"], "a string (an e-mail address)");
	else {
		$email = account_email_normalise($body["email"]);
		if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $issues[] = input_issue("email", "invalid_email", "'email' is not a valid e-mail address.");
	}
}
input_exit_if_issues($issues);

if (!captcha_ready()) {
	http_error(503, "captcha_unavailable", "The captcha is not available.");
}

$challenge = captcha_issue($db, $body["purpose"], $email, sec_client_ip());
log_event("auth.captcha_issued");

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
http_data(200, $challenge);
