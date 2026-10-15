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
require_once __DIR__ . "/../lib/data.php";
require_once __DIR__ . "/../lib/input.php";

[$db] = api_start(false);

if (http_method() !== "GET") http_exit_method_not_allowed(["GET"]);
$issues = [];
input_query([], $issues);
input_check_no_body($issues);
input_exit_if_issues($issues);
input_exit_unless_path_fits(input_path(), 0);

// the numbers the cron keeps (counted now when it has not run yet)
$stats = data_public_stats($db);

db_update_increment_key_value($db, "pub_visit_monthly");
db_update_increment_key_value($db, "pub_visit_weekly");
db_update_increment_key_value($db, "pub_visit_daily");

http_data(200, $stats);
