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

[$db] = api_start(false);

if ($_SERVER['REQUEST_METHOD'] !== "POST") {
	http_error(405, "method_not_allowed", "Supported method: POST.");
}

$body = http_json_body();
$purpose = $body["purpose"] ?? null;
if (!is_string($purpose) || !in_array($purpose, CAPTCHA_PURPOSES, true)) {
	http_error(400, "invalid_purpose", "'purpose' must be one of: " . implode(", ", CAPTCHA_PURPOSES) . ".");
}

$email = account_email_normalise($body["email"] ?? null);
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
	http_error(400, "invalid_email", "Provided email address is not valid.");
}

if (!captcha_ready()) {
	http_error(503, "captcha_unavailable", "The captcha is not available.");
}

// the same answer, from the same reads, whether the address has an account or not
$challenge = captcha_issue($db, $purpose, $email, sec_client_ip());
log_event("auth.captcha_issued");

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
http_data(200, $challenge);
