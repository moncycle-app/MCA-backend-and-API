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

// Every word of the PDF charts, in Portuguese (one file per ACCOUNT_LANGUAGES code; read by res_t("pdf", ...) in lib/res.php). A string has {name} marks
// the code fills, and may take another string of this file with {@key}. A heading is written as a word: the chart puts it in capitals.
return [
	"title" => "Tabela moncycle.app de {date}",
	"anonymous" => "(anónimo)",
	"page" => "página {page} de {count}",
	// the days of the week from Sunday, one letter each
	"week_days" => ["D", "S", "T", "Q", "Q", "S", "S"],
	// the months on 3 letters (shown in capitals), for the compact date
	"months_short" => ["jan", "fev", "mar", "abr", "mai", "jun", "jul", "ago", "set", "out", "nov", "dez"],
	"days.one" => "{n} dia",
	"days.other" => "{n} dias",
	"cycles.one" => "{n} ciclo",
	"cycles.other" => "{n} ciclos",
	"mucus.subtitle" => "Tabela de {days}, {cycles}, de {start} a {end}",
	"mucus.subtitle_anonymous" => "Tabela de {days}, {cycles}",
	// the line under the name, in pieces: "Ciclo 2 de 5" (only when there are several), "{days}", the dates (not when anonymous), the peak
	"mucus.cycle" => "Ciclo {n} de {count}",
	"mucus.cycle_dates" => "de {start} a {end}",
	"mucus.cycle_peak" => "pico no dia {day}",
	"mucus.col.date" => "Data",
	"mucus.col.day" => "Dia",
	"mucus.col.stamp" => "Selo",
	"mucus.col.peak" => "Pico",
	"mucus.col.union" => "União",
	"mucus.col.sensations" => "Sensações",
	"mucus.col.observations" => "Observações",
	"mucus.col.comment" => "Comentário",
	"mucus.col.temperature" => "Temp.",
	"legend.not_observed" => "dia não observado",
	"legend.not_filled" => "dia não preenchido",
	"legend.paused" => "observações em pausa",
	// the line under the name: what the export covers, and the day it was made
	"fc.observations" => "Observações de {start} a {end}",
	"fc.created" => "documento criado em {today}",
	"fc.row.stamp" => "Selo",
	"fc.row.peak" => "Pico",
	"fc.row.date" => "Data",
	"fc.row.bleeding" => "Sangramento",
	"fc.row.mucus" => "Muco",
	"fc.row.other" => "Outra info",
	"fc.row.temperature" => "Temperatura",
	"fc.row.comment" => "Comentário",
	"fc.peak" => "PICO",
	"fc.pause" => "PAUSA",
	"fc.intercourse" => "I",
	"fc.at" => "às",
	"fc.stamp.red" => "Vm",
	"fc.stamp.green" => "Vd",
	"fc.stamp.yellow" => "Am",
	"fc.stamp.white" => "Br",
	"fc.stamp.baby" => "BB",
];
