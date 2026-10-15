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
require_once __DIR__ . "/../lib/email_token.php";
require_once __DIR__ . "/../lib/input.php";

// The link of a mail, used: it proves an address, or sets a new password, or changes the primary address. It needs no session (the
// person may open the mail on another device), and the token is the whole proof. The page of the link posts the token it finds in its
// fragment (js/verify_email.js).
[$db] = api_start(false);

if (http_method() !== 'POST') http_exit_method_not_allowed(["POST"]);
input_exit_unless_path_fits(input_path(), 0);

$issues = [];
input_query([], $issues);
$body = input_body(["purpose", "token", "newPassword"], $issues);

$outcome = email_token_complete($db, $body, $issues);
if (!isset($outcome["data"])) http_error($outcome["status"], $outcome["code"], $outcome["message"]);

http_data($outcome["status"], $outcome["data"]);
