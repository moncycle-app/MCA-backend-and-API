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

// Every word of the PDF charts, in Italian (one file per ACCOUNT_LANGUAGES code; read by res_t("pdf", ...) in lib/res.php). A string has {name} marks
// the code fills, and may take another string of this file with {@key}. A heading is written as a word: the chart puts it in capitals.
return [
	"title" => "Tabella moncycle.app del {date}",
	"anonymous" => "(anonimo)",
	"page" => "pagina {page} di {count}",
	// the days of the week from Sunday, one letter each
	"week_days" => ["D", "L", "M", "M", "G", "V", "S"],
	"days.one" => "{n} giorno",
	"days.other" => "{n} giorni",
	"cycles.one" => "{n} ciclo",
	"cycles.other" => "{n} cicli",
	"mucus.subtitle" => "Tabella di {days}, {cycles}, dal {start} al {end}",
	"mucus.subtitle_anonymous" => "Tabella di {days}, {cycles}",
	// the line under the name, in pieces: "Ciclo 2 di 5" (only when there are several), "{days}", the dates (not when anonymous), the peak
	"mucus.cycle" => "Ciclo {n} di {count}",
	"mucus.cycle_dates" => "dal {start} al {end}",
	"mucus.cycle_peak" => "picco al giorno {day}",
	"mucus.col.date" => "Data",
	"mucus.col.day" => "Giorno",
	"mucus.col.stamp" => "Timbro",
	"mucus.col.peak" => "Picco",
	"mucus.col.union" => "Unione",
	"mucus.col.sensations" => "Sensazioni",
	"mucus.col.observations" => "Osservazioni",
	"mucus.col.comment" => "Commento",
	"mucus.col.temperature" => "Temp.",
	"legend.not_observed" => "giorno non osservato",
	"legend.not_filled" => "giorno non compilato",
	"legend.paused" => "osservazioni in pausa",
	"fc.title" => "- osservazioni dal {start} al {end} - documento creato il {today} - pagina {page} di {count} - ",
	"fc.title_anonymous" => "{@anonymous} - documento creato il {today} - pagina {page} di {count} - ",
	"fc.row.stamp" => "Timbro",
	"fc.row.peak" => "Picco",
	"fc.row.date" => "Data",
	"fc.row.bleeding" => "Sanguinamento",
	"fc.row.mucus" => "Muco",
	"fc.row.other" => "Altre info",
	"fc.row.temperature" => "Temperatura",
	"fc.row.comment" => "Commento",
	"fc.peak" => "PICCO",
	"fc.pause" => "PAUSA",
	"fc.union" => "R",
	"fc.at" => "alle",
	"fc.stamp.red" => "R",
	"fc.stamp.green" => "V",
	"fc.stamp.yellow" => "G",
	"fc.stamp.white" => "B",
	"fc.stamp.baby" => "BB",
];
