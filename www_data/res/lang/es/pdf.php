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

// Every word of the PDF charts, in Spanish (one file per ACCOUNT_LANGUAGES code; read by res_t("pdf", ...) in lib/res.php). A string has {name} marks
// the code fills, and may take another string of this file with {@key}. A heading is written as a word: the chart puts it in capitals.
return [
	"title" => "Tabla moncycle.app del {date}",
	"anonymous" => "(anónimo)",
	"page" => "página {page} de {count}",
	// the days of the week from Sunday, one letter each
	"week_days" => ["D", "L", "M", "X", "J", "V", "S"],
	// the months on 3 letters (shown in capitals), for the compact date
	"months_short" => ["ene", "feb", "mar", "abr", "may", "jun", "jul", "ago", "sep", "oct", "nov", "dic"],
	"days.one" => "{n} día",
	"days.other" => "{n} días",
	"cycles.one" => "{n} ciclo",
	"cycles.other" => "{n} ciclos",
	"mucus.subtitle" => "Tabla de {days}, {cycles}, del {start} al {end}",
	"mucus.subtitle_anonymous" => "Tabla de {days}, {cycles}",
	// the line under the name, in pieces: "Ciclo 2 de 5" (only when there are several), "{days}", the dates (not when anonymous), the peak
	"mucus.cycle" => "Ciclo {n} de {count}",
	"mucus.cycle_dates" => "del {start} al {end}",
	"mucus.cycle_peak" => "pico el día {day}",
	"mucus.col.date" => "Fecha",
	"mucus.col.day" => "Día",
	"mucus.col.stamp" => "Sello",
	"mucus.col.peak" => "Pico",
	"mucus.col.union" => "Unión",
	"mucus.col.sensations" => "Sensaciones",
	"mucus.col.observations" => "Observaciones",
	"mucus.col.comment" => "Comentario",
	"mucus.col.temperature" => "Temp.",
	"legend.not_observed" => "día no observado",
	"legend.not_filled" => "día no rellenado",
	"legend.paused" => "pausa de las observaciones",
	// the line under the name: what the export covers, and the day it was made
	"fc.observations" => "Observaciones del {start} al {end}",
	"fc.created" => "documento creado el {today}",
	"fc.row.stamp" => "Sello",
	"fc.row.peak" => "Pico",
	"fc.row.date" => "Fecha",
	"fc.row.bleeding" => "Sangrado",
	"fc.row.mucus" => "Moco",
	"fc.row.other" => "Otra info",
	"fc.row.temperature" => "Temperatura",
	"fc.row.comment" => "Comentario",
	"fc.peak" => "PICO",
	"fc.pause" => "PAUSA",
	"fc.intercourse" => "I",
	"fc.at" => "a las",
	"fc.stamp.red" => "R",
	"fc.stamp.green" => "V",
	"fc.stamp.yellow" => "A",
	"fc.stamp.white" => "B",
	"fc.stamp.baby" => "BB",
];
