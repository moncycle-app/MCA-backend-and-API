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
require_once "../lib/data.php";

[$db, $user_account] = api_start();

$body = http_json_body();

// DELETION OF THE ACCOUNT
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {

	if (!is_string($body["password"] ?? null) || empty($body["password"])) {
		http_error(400, "missing_password", "'password' is required to confirm account deletion.");
	}

	if (!account_password_matches($db, $user_account, $body["password"])) {
		log_event("account.refused", ["act" => "delete", "err" => "invalid_password"]);
		http_error(401, "invalid_password", "Incorrect password.");
	}

	data_delete_account($db, $user_account, "user_request");
	sec_clear_token_cookie();

	http_no_content();
}

// UPDATE OF THE ACCOUNT: its settings, and what wants the password again (loginEmail, newPassword, notificationEmails)
$outcome = account_update($db, $user_account, $body);
if (!isset($outcome["data"])) http_error($outcome["status"], $outcome["code"], $outcome["message"]);

http_data($outcome["status"], $outcome["data"]);
