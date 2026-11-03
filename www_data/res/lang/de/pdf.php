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

// Every word of the PDF charts, in German (one file per ACCOUNT_LANGUAGES code; read by res_t("pdf", ...) in lib/res.php). A string has {name} marks
// the code fills, and may take another string of this file with {@key}. A heading is written as a word: the chart puts it in capitals.
return [
	"title" => "moncycle.app-Tabelle vom {date}",
	"anonymous" => "(anonym)",
	"page" => "Seite {page} von {count}",
	// the days of the week from Sunday, one letter each
	"week_days" => ["S", "M", "D", "M", "D", "F", "S"],
	"days.one" => "{n} Tag",
	"days.other" => "{n} Tage",
	"cycles.one" => "{n} Zyklus",
	"cycles.other" => "{n} Zyklen",
	"mucus.subtitle" => "Tabelle von {days}, {cycles}, vom {start} bis zum {end}",
	"mucus.subtitle_anonymous" => "Tabelle von {days}, {cycles}",
	// the line under the name, in pieces: "Zyklus 2 von 5" (only when there are several), "{days}", the dates (not when anonymous), the peak
	"mucus.cycle" => "Zyklus {n} von {count}",
	"mucus.cycle_dates" => "vom {start} bis zum {end}",
	"mucus.cycle_peak" => "Höhepunkt an Tag {day}",
	"mucus.col.date" => "Datum",
	"mucus.col.day" => "Tag",
	"mucus.col.stamp" => "Stempel",
	"mucus.col.peak" => "Gipfel",
	"mucus.col.union" => "Verkehr",
	"mucus.col.sensations" => "Empfindungen",
	"mucus.col.observations" => "Beobachtungen",
	"mucus.col.comment" => "Kommentar",
	"mucus.col.temperature" => "Temp.",
	"legend.not_observed" => "Tag nicht beobachtet",
	"legend.not_filled" => "Tag nicht ausgefüllt",
	"legend.paused" => "Beobachtungen pausiert",
	"fc.title" => "- Beobachtungen vom {start} bis zum {end} - Dokument erstellt am {today} - Seite {page} von {count} - ",
	"fc.title_anonymous" => "{@anonymous} - Dokument erstellt am {today} - Seite {page} von {count} - ",
	"fc.row.stamp" => "Stempel",
	"fc.row.peak" => "Gipfel",
	"fc.row.date" => "Datum",
	"fc.row.bleeding" => "Blutung",
	"fc.row.mucus" => "Schleim",
	"fc.row.other" => "Weitere Infos",
	"fc.row.temperature" => "Temperatur",
	"fc.row.comment" => "Kommentar",
	"fc.peak" => "GIPFEL",
	"fc.pause" => "PAUSE",
	"fc.union" => "V",
	"fc.at" => "um",
	"fc.stamp.red" => "R",
	"fc.stamp.green" => "G",
	"fc.stamp.yellow" => "Y",
	"fc.stamp.white" => "W",
	"fc.stamp.baby" => "B",
];
