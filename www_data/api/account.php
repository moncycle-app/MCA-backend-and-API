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
require_once __DIR__ . "/../lib/user_account.php";
require_once __DIR__ . "/../lib/data.php";
require_once __DIR__ . "/../lib/input.php";
require_once __DIR__ . "/../lib/sec_token.php";

[$db, $user_account] = api_start();

$path = input_path();
input_exit_unless_path_fits($path, 1);
$method = http_method();
$issues = [];

// DELETION OF THE ACCOUNT: POST /account/deletion, with the password
if ($path === ["deletion"]) {
	if ($method !== "POST") http_exit_method_not_allowed(["POST"]);

	input_query([], $issues);
	$body = input_body(["password"], $issues);
	$password = input_secret($body, "password", $issues);
	input_exit_if_issues($issues);

	if (!user_account_password_matches($db, $user_account, $password)) {
		log_event("account.refused", ["act" => "delete", "err" => "invalid_password"]);
		http_error(401, "invalid_password", "Incorrect password.");
	}

	data_delete_account($db, $user_account, "user_request");
	sec_token_clear_cookie();

	http_no_content();
}

if ($path !== []) http_exit_not_found();

// THE ACCOUNT
if ($method === "GET") {
	input_query([], $issues);
	input_check_no_body($issues);
	input_exit_if_issues($issues);

	$account = user_account_to_json($user_account);
	// a client that keeps the account sends back the ETag it was given and is told when nothing changed
	$etag = 'W/"' . md5(json_encode($account)) . '"';
	header("ETag: $etag");
	if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? "") === $etag) {
		http_response_code(304);
		exit;
	}
	http_data(200, $account);
}

// UPDATE OF THE ACCOUNT, partial (what is not sent is kept): its settings, and what wants the password again (loginEmail, newPassword, notificationEmails)
if ($method === "POST") {
	input_query([], $issues);
	$body = input_body(ACCOUNT_UPDATE_FIELDS, $issues, [], ACCOUNT_READ_ONLY_FIELDS);

	$outcome = user_account_update($db, $user_account, $body, $issues);
	if (!isset($outcome["data"])) http_error($outcome["status"], $outcome["code"], $outcome["message"]);

	http_data($outcome["status"], $outcome["data"], $outcome["meta"]);
}

http_exit_method_not_allowed(["GET", "POST"]);
