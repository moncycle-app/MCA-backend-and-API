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

// Every word of the PDF charts, in Polish (one file per ACCOUNT_LANGUAGES code; read by res_t("pdf", ...) in lib/res.php). A string has {name} marks
// the code fills, and may take another string of this file with {@key}. A heading is written as a word: the chart puts it in capitals.
return [
	"title" => "Tabela moncycle.app z dnia {date}",
	"anonymous" => "(anonimowo)",
	"page" => "strona {page} z {count}",
	// the days of the week from Sunday, one letter each
	"week_days" => ["N", "P", "W", "Ś", "C", "P", "S"],
	// the months on 3 letters (shown in capitals), for the compact date
	"months_short" => ["sty", "lut", "mar", "kwi", "maj", "cze", "lip", "sie", "wrz", "paź", "lis", "gru"],
	"days.one" => "{n} dzień",
	"days.other" => "{n} dni",
	"cycles.one" => "{n} cykl",
	"cycles.other" => "{n} cykli",
	"mucus.subtitle" => "Tabela: {days}, {cycles}, od {start} do {end}",
	"mucus.subtitle_anonymous" => "Tabela: {days}, {cycles}",
	// the line under the name, in pieces: "Cykl 2 z 5" (only when there are several), "{days}", the dates (not when anonymous), the peak
	"mucus.cycle" => "Cykl {n} z {count}",
	"mucus.cycle_dates" => "od {start} do {end}",
	"mucus.cycle_peak" => "szczyt w dniu {day}",
	"mucus.col.date" => "Data",
	"mucus.col.day" => "Dzień",
	"mucus.col.stamp" => "Znacznik",
	"mucus.col.peak" => "Szczyt",
	"mucus.col.union" => "Zbliżenie",
	"mucus.col.sensations" => "Odczucia",
	"mucus.col.observations" => "Obserwacje",
	"mucus.col.comment" => "Komentarz",
	"mucus.col.temperature" => "Temp.",
	"legend.not_observed" => "dzień bez obserwacji",
	"legend.not_filled" => "dzień niewypełniony",
	"legend.paused" => "obserwacje wstrzymane",
	// the line under the name: what the export covers, and the day it was made
	"fc.observations" => "Obserwacje od {start} do {end}",
	"fc.created" => "dokument utworzony {today}",
	"fc.row.stamp" => "Znacznik",
	"fc.row.peak" => "Szczyt",
	"fc.row.date" => "Data",
	"fc.row.bleeding" => "Krwawienie",
	"fc.row.mucus" => "Śluz",
	"fc.row.other" => "Inne",
	"fc.row.temperature" => "Temperatura",
	"fc.row.comment" => "Komentarz",
	"fc.peak" => "SZCZYT",
	"fc.pause" => "PAUZA",
	"fc.intercourse" => "I",
	"fc.at" => "o",
	"fc.stamp.red" => "R",
	"fc.stamp.green" => "G",
	"fc.stamp.yellow" => "Y",
	"fc.stamp.white" => "W",
	"fc.stamp.baby" => "B",
];
