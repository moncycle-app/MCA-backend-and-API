<?php
/* moncycle.app
**
** Copyright (C) 2021-2026 Tech 4 Fertility, aka. T4F (https://tech4fertility.org)
** SPDX-License-Identifier: AGPL-3.0-or-later
**
** This program is free software: you can redistribute it and/or modify it under
** the terms of the GNU Affero General Public License as published by the Free
** Software Foundation, either version 3 of the License, or (at your option) any
** later version. It is distributed WITHOUT ANY WARRANTY; see the licence for details.
**
** https://www.moncycle.app
** https://github.com/moncycle-app/backend-api-web-app
*/

declare(strict_types=1);

require_once __DIR__ . "/../../config.php";
require_once __DIR__ . "/../../lib/db_ops.php";
require_once __DIR__ . "/../../lib/db_querie.php";
require_once __DIR__ . "/../../lib/http.php";
require_once __DIR__ . "/../../lib/log.php";

header('Content-Type: application/json');

// in maintenance a 503, the database left alone (the migrations run then)
http_exit_if_maintenance();

try {
	$db = db_ops_open();
	if (boolval(db_querie_count_user_accounts($db))) http_data(200, ["status" => "oookkk"]);
	http_error(503, "database_unreachable", "Database query did not return the expected result.");
} catch (\Throwable $e) {
	log_exception($e);
	http_error(503, "database_unreachable", "Database is not reachable.");
}
