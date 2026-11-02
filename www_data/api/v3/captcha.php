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
require_once __DIR__ . "/../../lib/sec_captcha.php";

[$db] = api_start(false);

if (http_method() !== "POST") http_exit_method_not_allowed(["POST"]);
input_exit_unless_path_fits(input_path(), 0);

$issues = [];
input_query([], $issues);
$body = input_body(["purpose", "email"], $issues);
$purpose = input_choice($body, "purpose", CAPTCHA_PURPOSES, $issues);
$email = input_email($body, "email", $issues);
input_exit_if_issues($issues);

if (!sec_captcha_ready()) http_error(503, "captcha_unavailable", "The captcha is not available.");

$challenge = sec_captcha_issue($db, $purpose, $email, sec_client_ip());
log_event("auth.captcha_issued");

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
http_data(200, $challenge);
