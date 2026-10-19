<?php
/* moncycle.app
**
** licence Creative Commons CC BY-NC-SA
**
** https://www.moncycle.app
** https://github.com/moncycle-app/backend-api-web-app
*/

declare(strict_types=1);

// The start of every JSON endpoint, and so the lib files every endpoint can count on.

require_once __DIR__ . "/db_ops.php";
require_once __DIR__ . "/http.php";
require_once __DIR__ . "/log.php";
require_once __DIR__ . "/sec.php";
require_once __DIR__ . "/sec_token.php";
require_once __DIR__ . "/server.php";

// The log of the request, a JSON answer, and an exception nobody catches logged and answered 500 "unexpected_error" with a message that
// tells nothing. All that GET /api/v2/server_info does, as it answers in maintenance.
function api_start_open(): void {
	log_start(sec_client_ip());
	set_exception_handler(function (\Throwable $e) {
		log_exception($e);
		http_error(500, "unexpected_error", "Unexpected error.");
	});
	header('Content-Type: application/json');
}

// api_start_open(), then the 503 of maintenance, the 403 of a write from a foreign page (before anything is read), the database checked
// to be at APP_VERSION, and the caller: [$db, $user_account]. With $login_required nobody logged in is a 401; without it a visitor's
// $user_account is null. A read-only token gets a 403 on a method that is not safe, unless $read_only_ok (the endpoint decides itself).
function api_start(bool $login_required = true, bool $read_only_ok = false): array {
	api_start_open();
	http_exit_if_maintenance();
	sec_exit_if_cross_origin();
	$db = db_ops_open();
	server_exit_if_schema_mismatch($db);
	$user_account = sec_token_authenticate($db);
	if ($login_required) {
		sec_exit_if_logged_out($user_account);
		if (!$read_only_ok && !in_array($_SERVER['REQUEST_METHOD'] ?? '', HTTP_SAFE_METHODS, true)) sec_token_exit_if_read_only($user_account);
	}
	return [$db, $user_account];
}
