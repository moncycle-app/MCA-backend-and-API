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
require_once __DIR__ . "/../../lib/input.php";
require_once __DIR__ . "/../../lib/server.php";

// the one call that works in maintenance (it says so), with the version of the contract and the toggles a client needs before it signs in;
// it opens no database
api_start_open();

if (http_method() !== "GET") http_exit_method_not_allowed(["GET"]);
input_exit_unless_none();
input_exit_unless_path_fits(input_path(), 0);

http_data(200, server_info());
