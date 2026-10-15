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

$issues = [];
input_query([], $issues);
$body = input_body(["purpose", "token", "newPassword"], $issues);

$outcome = email_token_complete($db, $body, $issues);
if (!isset($outcome["data"])) http_error($outcome["status"], $outcome["code"], $outcome["message"]);

http_data($outcome["status"], $outcome["data"]);
