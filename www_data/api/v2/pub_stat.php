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
require_once __DIR__ . "/../../lib/data.php";
require_once __DIR__ . "/../../lib/input.php";

[$db] = api_start(false);

if (http_method() !== "GET") http_exit_method_not_allowed(["GET"]);
input_exit_unless_none();
input_exit_unless_path_fits(input_path(), 0);

// the numbers the cron keeps (counted now when it has not run yet)
$stats = data_public_stats($db);

http_data(200, $stats);
