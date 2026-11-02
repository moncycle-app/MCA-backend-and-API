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
require_once __DIR__ . "/../../lib/api.php";
require_once __DIR__ . "/../../lib/data.php";
require_once __DIR__ . "/../../lib/input.php";

[$db, $user_account] = api_start();

if (http_method() !== "GET") http_exit_method_not_allowed(["GET"]);
input_exit_unless_path_fits(input_path(), 0);

$issues = [];
$query = input_query(["since"], $issues);
input_check_no_body($issues);

// absent or empty: from the start (a client's first sync)
$from_timestamp = SYNC_FROM_START;
if (trim($query["since"] ?? "") !== "") $from_timestamp = input_timestamp(trim($query["since"]), "query.since", $issues) ?? SYNC_FROM_START;
input_exit_if_issues($issues);

$sync = data_sync($db, $user_account, $from_timestamp);
log_note(["n" => count($sync["days"]), "nds" => count($sync["descriptions"]), "full" => $from_timestamp === SYNC_FROM_START]);
http_data(200, $sync);
