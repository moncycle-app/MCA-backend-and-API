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
require_once __DIR__ . "/../lib/input.php";
require_once __DIR__ . "/../lib/server.php";

// the one call that works in maintenance (it says so), with the version of the contract and the toggles a client needs before it signs in;
// it opens no database
api_start_open();

if (http_method() !== "GET") http_exit_method_not_allowed(["GET"]);
$issues = [];
input_query([], $issues);
input_check_no_body($issues);
input_exit_if_issues($issues);
input_exit_unless_path_fits(input_path(), 0);

http_data(200, server_info());
