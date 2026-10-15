<?php
/* moncycle.app
**
** licence Creative Commons CC BY-NC-SA
**
** https://www.moncycle.app
** https://github.com/moncycle-app/backend-api-web-app
*/

// What every JSON endpoint starts with, and so the lib files every endpoint can count on.
// An endpoint that needs more (doc_export.php, nfp_import.php, mail.php...) requires them itself.

require_once __DIR__ . "/db.php";
require_once __DIR__ . "/http.php";
require_once __DIR__ . "/log.php";
require_once __DIR__ . "/sec.php";
require_once __DIR__ . "/sec_token.php";
require_once __DIR__ . "/server.php";

// What every JSON endpoint begins with, and the only thing the ones that answer in maintenance (GET /api/server_info) do: starts the log of the
// request, answers in JSON, and has an exception nobody catches logged and answered with a 500 "unexpected_error", whose message tells nothing.
function api_start_open(): void {
	log_start(sec_client_ip());
	set_exception_handler(function (\Throwable $e) {
		log_exception($e);
		http_error(500, "unexpected_error", "Unexpected error.");
	});
	header('Content-Type: application/json');
}

// Starts the request: api_start_open(), then a 503 in maintenance, a 403 for a write from a page that is not ours (before anything is read),
// the database opened and checked to be the one this code is written for (503 schema_mismatch, server_exit_if_schema_mismatch()), and who is
// calling found: [$db, $user_account]. With $login_required the request ends here with a 401 when nobody is logged in; without it
// $user_account is null for a visitor. A read-only token (POST /api/token, "scope") gets a 403 on any method that is not safe
// (HTTP_SAFE_METHODS), unless the endpoint says $read_only_ok: it then decides itself what such a token may do (api/token.php: end its own session).
function api_start(bool $login_required = true, bool $read_only_ok = false): array {
	api_start_open();
	http_exit_if_maintenance();
	sec_exit_if_cross_origin();
	$db = db_open();
	server_exit_if_schema_mismatch($db);
	$user_account = sec_token_authenticate($db);
	if ($login_required) {
		sec_exit_if_logged_out($user_account);
		if (!$read_only_ok && !in_array($_SERVER['REQUEST_METHOD'] ?? '', HTTP_SAFE_METHODS, true)) sec_token_exit_if_read_only($user_account);
	}
	return [$db, $user_account];
}
