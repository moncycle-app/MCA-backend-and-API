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
require_once "../lib/email_token.php";

// The link of a mail, used: it proves an address, or sets a new password, or changes the primary address. It needs no session (the
// person may open the mail on another device), and the token is the whole proof. The page of the link posts the token it finds in its
// fragment (js/verify_email.js).
[$db] = api_start(false);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
	http_error(405, "method_not_allowed", "Use POST.");
}

$outcome = email_token_complete($db, http_json_body());
if (!isset($outcome["data"])) http_error($outcome["status"], $outcome["code"], $outcome["message"]);

http_data($outcome["status"], $outcome["data"]);
