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

// Every word of the PDF charts, in French (one file per ACCOUNT_LANGUAGES code; read by res_t("pdf", ...) in lib/res.php). A string has {name} marks
// the code fills, and may take another string of this file with {@key}. A heading is written as a word: the chart puts it in capitals.
return [
	"title" => "Tableau moncycle.app du {date}",
	"anonymous" => "(anonyme)",
	"page" => "page {page} sur {count}",
	// the days of the week from Sunday, one letter each
	"week_days" => ["D", "L", "M", "M", "J", "V", "S"],
	// the months on 3 letters (shown in capitals), for the compact date
	"months_short" => ["jan", "fév", "mar", "avr", "mai", "jun", "jul", "aoû", "sep", "oct", "nov", "déc"],
	"days.one" => "{n} jour",
	"days.other" => "{n} jours",
	"cycles.one" => "{n} cycle",
	"cycles.other" => "{n} cycles",
	"mucus.subtitle" => "Tableau de {days}, {cycles}, du {start} au {end}",
	"mucus.subtitle_anonymous" => "Tableau de {days}, {cycles}",
	// the line under the name, in pieces: "Cycle 2 sur 5" (only when there are several), "{days}", the dates (not when anonymous), the peak
	"mucus.cycle" => "Cycle {n} sur {count}",
	"mucus.cycle_dates" => "du {start} au {end}",
	"mucus.cycle_peak" => "pic au jour {day}",
	"mucus.col.date" => "Date",
	"mucus.col.day" => "Jour",
	"mucus.col.stamp" => "Tampon",
	"mucus.col.peak" => "Pic",
	"mucus.col.union" => "Union",
	"mucus.col.sensations" => "Sensations",
	"mucus.col.observations" => "Observations",
	"mucus.col.comment" => "Commentaire",
	"mucus.col.temperature" => "Temp.",
	"legend.not_observed" => "jour non observé",
	"legend.not_filled" => "jour non renseigné",
	"legend.paused" => "pause des observations",
	// the line under the name: what the export covers, and the day it was made
	"fc.observations" => "Observations du {start} au {end}",
	"fc.created" => "document créé le {today}",
	"fc.row.stamp" => "Tampon",
	"fc.row.peak" => "Peak",
	"fc.row.date" => "Date",
	"fc.row.bleeding" => "Saignement",
	"fc.row.mucus" => "Glaire",
	"fc.row.other" => "Autre info",
	"fc.row.temperature" => "Température",
	"fc.row.comment" => "Commentaire",
	"fc.peak" => "PIC",
	"fc.pause" => "PAUSE",
	"fc.intercourse" => "I",
	"fc.at" => "à",
	"fc.stamp.red" => "R",
	"fc.stamp.green" => "V",
	"fc.stamp.yellow" => "J",
	"fc.stamp.white" => "B",
	"fc.stamp.baby" => "BB",
];
