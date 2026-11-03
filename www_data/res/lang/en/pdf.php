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

// Every word of the PDF charts, in English (one file per ACCOUNT_LANGUAGES code; read by res_t("pdf", ...) in lib/res.php). A string has {name} marks
// the code fills, and may take another string of this file with {@key}. A heading is written as a word: the chart puts it in capitals.
return [
	"title" => "moncycle.app chart of {date}",
	"anonymous" => "(anonymous)",
	"page" => "page {page} of {count}",
	// the days of the week from Sunday, one letter each
	"week_days" => ["S", "M", "T", "W", "T", "F", "S"],
	"days.one" => "{n} day",
	"days.other" => "{n} days",
	"cycles.one" => "{n} cycle",
	"cycles.other" => "{n} cycles",
	"mucus.subtitle" => "Chart of {days}, {cycles}, from {start} to {end}",
	"mucus.subtitle_anonymous" => "Chart of {days}, {cycles}",
	// the line under the name, in pieces: "Cycle 2 of 5" (only when there are several), "{days}", the dates (not when anonymous), the peak
	"mucus.cycle" => "Cycle {n} of {count}",
	"mucus.cycle_dates" => "from {start} to {end}",
	"mucus.cycle_peak" => "peak on day {day}",
	"mucus.col.date" => "Date",
	"mucus.col.day" => "Day",
	"mucus.col.stamp" => "Stamp",
	"mucus.col.peak" => "Peak",
	"mucus.col.union" => "Union",
	"mucus.col.sensations" => "Sensations",
	"mucus.col.observations" => "Observations",
	"mucus.col.comment" => "Comment",
	"mucus.col.temperature" => "Temp.",
	"legend.not_observed" => "day not observed",
	"legend.not_filled" => "day not filled in",
	"legend.paused" => "observations paused",
	// the line under the name: what the export covers, and the day it was made
	"fc.observations" => "Observations from {start} to {end}",
	"fc.created" => "document created on {today}",
	"fc.row.stamp" => "Stamp",
	"fc.row.peak" => "Peak",
	"fc.row.date" => "Date",
	"fc.row.bleeding" => "Bleeding",
	"fc.row.mucus" => "Mucus",
	"fc.row.other" => "Other info",
	"fc.row.temperature" => "Temperature",
	"fc.row.comment" => "Comment",
	"fc.peak" => "PEAK",
	"fc.pause" => "PAUSE",
	"fc.union" => "I",
	"fc.at" => "at",
	"fc.stamp.red" => "R",
	"fc.stamp.green" => "G",
	"fc.stamp.yellow" => "Y",
	"fc.stamp.white" => "W",
	"fc.stamp.baby" => "B",
];
