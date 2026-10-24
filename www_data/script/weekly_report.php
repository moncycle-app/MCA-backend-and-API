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

require_once __DIR__ . "/../config.php";
require_once __DIR__ . "/../lib/db_ops.php";
require_once __DIR__ . "/../lib/weekly_report.php";

// Sends the weekly report of the team to WEEKLY_REPORT_EMAILS now, whatever the day (the cron sends it on Mondays by itself).
//   php weekly_report.php             sends it
//   php weekly_report.php --dry-run   prints its text and who would get it, sends nothing
//   php weekly_report.php --html      prints its HTML (to look at it in a browser), sends nothing
// Command line only: over HTTP a mere visit would mail the team. Anything else on the line is refused.
if (PHP_SAPI !== "cli") {
	http_response_code(404);
	exit;
}
$arguments = array_slice($_SERVER["argv"], 1);
if (array_diff($arguments, ["--dry-run", "--html"]) || count($arguments) > 1) {
	fwrite(STDERR, "usage: php weekly_report.php [--dry-run | --html]" . PHP_EOL);
	exit(2);
}
$dry_run = in_array("--dry-run", $arguments, true);
$html_only = in_array("--html", $arguments, true);

// the database may be down or being restored
if (MAINTENANCE_MODE) {
	fwrite(STDERR, "MAINTENANCE_MODE is on: no report." . PHP_EOL);
	exit(1);
}

$to = weekly_report_recipients();
if ($to === [] && !$html_only) {
	echo "WEEKLY_REPORT_EMAILS holds no address: the weekly report is off." . PHP_EOL;
	exit($dry_run ? 0 : 1);
}

$report = weekly_report_build(db_ops_open());
if ($html_only) {
	echo $report[1];
	exit(0);
}
if ($dry_run) {
	echo "[dry run] the weekly report would be sent to " . implode(", ", $to) . PHP_EOL . "Subject: {$report[0]}" . PHP_EOL . PHP_EOL . $report[2] . PHP_EOL;
	exit(0);
}
$sent = weekly_report_send($to, $report);
echo ($sent ? "weekly report sent to " : "COULD NOT send the weekly report to ") . count($to) . " addresses" . PHP_EOL;
exit($sent ? 0 : 1);
