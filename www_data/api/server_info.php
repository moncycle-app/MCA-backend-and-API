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
require_once "../lib/input.php";
require_once "../lib/server.php";

// the one call that works in maintenance, with the version of the contract and of the build, and the toggles a client needs before it signs in
api_start_open();

if (http_method() !== "GET") http_exit_method_not_allowed(["GET"]);
$issues = [];
input_query([], $issues);
input_check_no_body($issues);
input_exit_if_issues($issues);
input_exit_unless_path_fits(input_path(), 0);

if (MAINTENANCE_MODE) http_data(200, server_info(null));

try {
	$db = db_open();
}
catch (\Throwable $e) {
	log_exception($e);
	http_error(503, "database_unreachable", "Database is not reachable.");
}
http_data(200, server_info($db));
