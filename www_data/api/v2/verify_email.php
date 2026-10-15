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
require_once __DIR__ . "/../../lib/email_token.php";
require_once __DIR__ . "/../../lib/input.php";

// The link of a mail, used (lib/email_token.php). No session: the mail may be opened on another device, and the token is the whole proof.
[$db] = api_start(false);

if (http_method() !== 'POST') http_exit_method_not_allowed(["POST"]);
input_exit_unless_path_fits(input_path(), 0);

// A link is a secret of 256 bits that nobody guesses; what the limit stops is an anonymous client making the server work. Every request
// is written against the address first (the pool of the sign-in), and given back when the link worked.
$attempt = sec_attempt_reserve($db, sec_client_ip());
if (is_null($attempt)) {
	log_event("account.refused", ["act" => "verify_email", "err" => "rate_limited"]);
	http_error(429, "rate_limited", "Too many attempts from this network, please try again later.");
}

$issues = [];
input_query([], $issues);
$body = input_body(["purpose", "token", "newPassword"], $issues);

$outcome = email_token_complete($db, $body, $issues);
if (!isset($outcome["data"])) http_error($outcome["status"], $outcome["code"], $outcome["message"]);
sec_attempt_release($db, $attempt);

http_data($outcome["status"], $outcome["data"]);
