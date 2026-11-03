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

// The FertilityCare chart (nfp_method 3 and 4): a grid, A3 landscape, in mm. A row per cycle, DOC_FC_DAYS_PER_ROW days wide (a longer
// cycle carries on in the next row); a day is a column of cells: stamp, baby, peak, date, bleeding, mucus, other (arrow, pain, union),
// [temperature,] comment. That grid was set with FertilityCare: its rows and cells stay as they are. The page title is the mucus chart's, and so
// are the stamps' colours and the baby. The words are in the language of the account.

require_once __DIR__ . "/user_account.php";
require_once __DIR__ . "/date.php";
require_once __DIR__ . "/doc.php";
require_once __DIR__ . "/doc_pdf.php";
require_once __DIR__ . "/res.php";

function doc_fc_pdf(array $days, int $nfp_method, string $name, string $language, bool $anonymous = false, string $date_pattern = 'd/m/y'): DocPdf {
	$days = doc_days_without_paused($days);
	$with_temperature = user_account_tracks_temperature($nfp_method);
	if ($anonymous) $name = doc_get_initials($name) . " " . res_t("pdf", $language, "anonymous");

	$pdf = new DocPdf('L', 'A3', res_t("pdf", $language, "title", ["date" => date_human(new DateTime($days[0]["date"]), $date_pattern)]), $name);
	$pdf->SetMargins(DOC_PDF_MARGIN, DOC_PDF_MARGIN);
	$grid = doc_fc_layout($pdf, $with_temperature, $anonymous, $language);
	$grid["date_pattern"] = $date_pattern;

	// built once, then paginated: the page count cannot disagree with what is drawn
	$lines_per_page = $with_temperature ? DOC_FC_LINES_PER_PAGE_TEMPERATURE : DOC_FC_LINES_PER_PAGE;
	$pages = array_chunk(doc_fc_grid_rows($days, $language), $lines_per_page);
	$pieces = doc_fc_title_pieces($days, $language, $anonymous, $date_pattern);

	foreach ($pages as $page_index => $page_rows) {
		$pdf->AddPage();
		$page = count($pages) > 1 ? res_t("pdf", $language, "page", ["page" => $page_index + 1, "count" => count($pages)]) : "";
		$title_end = $pdf->PageTitle($name, $pieces, $page);
		doc_fc_draw_legend($pdf, doc_fc_page_states($page_rows), $title_end, $language);

		$y = doc_fc_draw_day_numbers($pdf, $grid);
		for ($line = 0; $line < $lines_per_page; $line++) {
			$y = doc_fc_draw_row($pdf, $page_rows[$line] ?? [], $grid, $y);
		}
		doc_fc_draw_bar($pdf, $grid, $y);
	}

	return $pdf;
}

// The line under the name, in pieces: what the export covers (in the chip; its length only when anonymous), its days and cycles, and the day
// it was made.
function doc_fc_title_pieces(array $days, string $language, bool $anonymous, string $date_pattern): array {
	$cycles = count(array_filter($days, fn($day) => !empty($day["cycleFirstDay"])));
	$days_text = res_t("pdf", $language, count($days) > 1 ? "days.other" : "days.one", ["n" => count($days)]);
	$pieces = $anonymous ? [$days_text] : [
		res_t("pdf", $language, "fc.observations", [
			"start" => date_human(new DateTime($days[0]["date"]), $date_pattern), "end" => date_human(new DateTime(end($days)["date"]), $date_pattern),
		]),
		$days_text,
	];
	if ($cycles > 0) $pieces[] = res_t("pdf", $language, $cycles > 1 ? "cycles.other" : "cycles.one", ["n" => $cycles]);
	$pieces[] = res_t("pdf", $language, "fc.created", ["today" => date_human(new DateTime(), $date_pattern)]);
	return $pieces;
}

// The states of doc_day_stamp_state() the days of a page show, in the order of DOC_FC_LEGEND.
function doc_fc_page_states(array $page_rows): array {
	$states = [];
	foreach ($page_rows as $row) {
		foreach ($row as $cell) $states[doc_day_stamp_state($cell["day"]) ?? ""] = true;
	}
	return array_keys(array_intersect_key(DOC_FC_LEGEND, $states));
}

// What the stamps of $states mean, on the second line of the page title, ending at $end: each a chip as its cell looks (its glyph, or
// "PAUSE"), then its meaning.
function doc_fc_draw_legend(DocPdf $pdf, array $states, float $end, string $language): void {
	$baseline = DOC_PDF_MARGIN + DOC_PDF_TITLE_LINE_BASELINE;
	$top = $baseline - DOC_PDF_TITLE_CHIP_RISE;
	$h = DOC_PDF_TITLE_CHIP_H;
	$x = $end - DOC_FC_LEGEND_GAP;
	foreach (array_reverse($states) as $state) {
		[$glyph, $fill, $color] = DOC_FC_STATE_STAMPS[$state];
		if ($state === "paused") $glyph = res_t("pdf", $language, "fc.pause");

		$text = res_t("pdf", $language, DOC_FC_LEGEND[$state]);
		$pdf->UseStyle('title.line');
		$x -= $pdf->GetStringWidth($text);
		$pdf->Text($x, $baseline, $text);

		$pdf->UseStyle($state === "paused" ? 'fc.baby' : 'fc.legend.glyph', $color);
		$chip_w = max($h, $pdf->GetStringWidth($glyph) + 2 * DOC_PDF_TITLE_CHIP_PAD);
		$x -= DOC_PDF_TITLE_CHIP_PAD + $chip_w;
		$pdf->SetFillColor(...$fill);
		$pdf->RoundedRect($x, $top, $chip_w, $h, $h * DOC_PDF_TITLE_CHIP_RADIUS);
		$pdf->SetXY($x, $top);
		$pdf->Cell($chip_w, $h, $glyph, 0, 0, 'C');
		$x -= DOC_FC_LEGEND_GAP;
	}
}

// The measures of the grid, and the legend column (row => [label, height]).
function doc_fc_layout(DocPdf $pdf, bool $with_temperature, bool $anonymous, string $language): array {
	$legend = [];
	foreach (DOC_FC_ROW_LABELS as $row => $label_key) {
		if ($row === "temperature" && !$with_temperature) continue;
		$legend[$row] = [$label_key === "" ? "" : mb_strtoupper(res_t("pdf", $language, $label_key)), $row === "baby" ? DOC_FC_STAMP_H : DOC_FC_LINE_H];
	}

	return [
		"cell_w" => ($pdf->GetPageWidth() - DOC_FC_FIRST_COL_W - 2 * DOC_PDF_MARGIN) / DOC_FC_DAYS_PER_ROW,
		"line_h" => DOC_FC_LINE_H,
		"stamp_h" => DOC_FC_STAMP_H,
		"color_coef" => DOC_FC_COLOR_COEF,
		"temperature" => $with_temperature,
		"anonymous" => $anonymous,
		"language" => $language,
		"left" => DOC_PDF_MARGIN,
		"top" => DOC_FC_TOP,
		"first_col_w" => DOC_FC_FIRST_COL_W,
		"full_w" => $pdf->GetPageWidth() - 2 * DOC_PDF_MARGIN,
		"separator_h" => DOC_FC_SEPARATOR_H,
		"row_h" => DOC_FC_LINE_H * ($with_temperature ? 8 : 7) + DOC_FC_STAMP_H,
		"legend" => $legend,
	];
}

// the day numbers across the top, on a light band; returns where the first row of the grid starts
function doc_fc_draw_day_numbers(DocPdf $pdf, array $grid): float {
	$x = $grid["left"] + $grid["first_col_w"];
	$pdf->SetFillColor(...DOC_FC_HEAD_FILL);
	$pdf->Rect($x, $grid["top"], $grid["full_w"] - $grid["first_col_w"], $grid["line_h"], 'F');
	$pdf->UseStyle('fc.day_numbers');
	for ($day = 1; $day <= DOC_FC_DAYS_PER_ROW; $day++) {
		$pdf->SetXY($x + $grid["cell_w"] * ($day - 1), $grid["top"]);
		$pdf->Cell($grid["cell_w"], $grid["line_h"], (string) $day, 0, 0, 'C');
	}
	doc_fc_draw_columns($pdf, $grid, $grid["top"], $grid["line_h"], false);
	$pdf->SetDrawColor(...DOC_FC_SEPARATOR_COLOR);
	$pdf->Line($x, $grid["top"], $grid["left"] + $grid["full_w"], $grid["top"]);   // the top of the grid, as its sides
	return $grid["top"] + $grid["line_h"];
}

// the bar between two rows of the grid, and under the last
function doc_fc_draw_bar(DocPdf $pdf, array $grid, float $y): void {
	$pdf->SetFillColor(...DOC_FC_SEPARATOR_COLOR);
	$pdf->Rect($grid["left"], $y, $grid["full_w"], $grid["separator_h"], 'F');
}

// The vertical lines of the grid from $y, $h high: between the days, and the outline (with the legend's edge when $legend).
function doc_fc_draw_columns(DocPdf $pdf, array $grid, float $y, float $h, bool $legend): void {
	$x = $grid["left"] + $grid["first_col_w"];
	$pdf->SetLineWidth(DOC_FC_LINE_W);
	$pdf->SetDrawColor(...DOC_FC_COLUMN_COLOR);
	for ($day = 1; $day < DOC_FC_DAYS_PER_ROW; $day++) $pdf->Line($x + $grid["cell_w"] * $day, $y, $x + $grid["cell_w"] * $day, $y + $h);
	$pdf->SetDrawColor(...DOC_FC_SEPARATOR_COLOR);
	foreach ($legend ? [$grid["left"], $x, $grid["left"] + $grid["full_w"]] : [$x, $grid["left"] + $grid["full_w"]] as $line_x) {
		$pdf->Line($line_x, $y, $line_x, $y + $h);
	}
}

// One row of the grid: the bar above it, the legend, the cells of its days ($cells: none for an empty row), the lines between them.
// Returns the y under it.
function doc_fc_draw_row(DocPdf $pdf, array $cells, array $grid, float $y): float {
	doc_fc_draw_bar($pdf, $grid, $y);
	$y += $grid["separator_h"];

	// the legend column, on a light band
	$pdf->SetFillColor(...DOC_FC_LEGEND_FILL);
	$pdf->Rect($grid["left"], $y, $grid["first_col_w"], $grid["row_h"], 'F');
	$pdf->SetLineWidth(DOC_FC_LINE_W);
	$pdf->SetDrawColor(...DOC_FC_LINE_COLOR);
	$legend_y = $y;
	foreach ($grid["legend"] as $row => [$text, $height]) {
		$pdf->UseStyle('fc.legend');
		$pdf->ShrinkToFit($text, $grid["first_col_w"]);   // a long word in some languages
		$pdf->SetXY($grid["left"], $legend_y);
		$pdf->Cell($grid["first_col_w"], $height, $text, $row === "comment" ? "" : "B", 0, 'R');
		$legend_y += $height;
	}

	$x = $grid["left"] + $grid["first_col_w"];
	for ($day = 0; $day < DOC_FC_DAYS_PER_ROW; $day++) {
		doc_fc_draw_cell($pdf, $cells[$day] ?? null, $grid, $x + $grid["cell_w"] * $day, $y);
	}

	doc_fc_draw_columns($pdf, $grid, $y, $grid["row_h"], true);
	return $y + $grid["row_h"];
}

// The rows of the grid: a new one at every cycle start and when one is full. A cell is ["day", "peak" => text]; the peak count runs to
// the end of its cycle (on into the next row), gaps counted as the calendar days they are.
function doc_fc_grid_rows(array $days, string $language): array {
	$rows = [];
	$row = [];
	$since_peak = null;

	foreach ($days as $day) {
		$day = doc_display_day($day);

		if (!empty($row) && ($day["cycleFirstDay"] || count($row) >= DOC_FC_DAYS_PER_ROW)) {
			$rows[] = $row;
			$row = [];
		}
		if ($day["cycleFirstDay"]) $since_peak = null;

		$peak = '';
		if ($day["isPeak"]) {
			$since_peak = 0;
			$peak = res_t("pdf", $language, "fc.peak");
		}
		elseif (!is_null($since_peak)) $peak = (string) ++$since_peak;

		$row[] = ["day" => $day, "peak" => $peak];
	}

	if (!empty($row)) $rows[] = $row;
	return $rows;
}

// What a day's stamp cell says, its colour and the colour of its text: [text, [r, g, b], [r, g, b]]. A day not observed (???), a day not
// filled in (!!!) and a pause are the mucus chart's light blue and pink; the baby adds its letters before the colour's and lightens the stamp.
// $day is null for the padding after a cycle's last day.
function doc_fc_stamp(?array $day, string $language): array {
	$none = ["", DOC_FC_STAMP_NONE, DOC_PDF_STYLES['fc.stamp'][3]];
	if (is_null($day)) return $none;

	$state = doc_day_stamp_state($day);
	if (!is_null($state)) return DOC_FC_STATE_STAMPS[$state];
	if (is_null($day["stampColor"])) return $none;

	[$letter_key, $text_color] = DOC_FC_STAMPS[$day["stampColor"]];
	$fill = DOC_MUCUS_STAMP_COLORS[$day["stampColor"]];
	if (!$day["stampBaby"]) return [res_t("pdf", $language, $letter_key), $fill, $text_color];
	$tint = array_map(fn(int $c): int => (int) round($c + (255 - $c) * DOC_FC_BABY_TINT), $fill);
	return [res_t("pdf", $language, "fc.stamp.baby") . res_t("pdf", $language, $letter_key), $tint, $text_color];
}

// One day's column of cells, or an empty one when $cell is null (padding).
function doc_fc_draw_cell(DocPdf $pdf, ?array $cell, array $grid, float $x, float $y): void {
	$day = $cell["day"] ?? null;
	$w = $grid["cell_w"];
	$line_h = $grid["line_h"];
	$stamp_h = $grid["stamp_h"];
	$coef = $grid["color_coef"];
	$paused = !empty($day["observationPaused"]);
	$text_color = $paused ? DOC_PAUSE_COLOR : DOC_PDF_STYLES['fc.cell'][3];

	// the stamp, the baby and the peak, in the stamp's colour
	[$symbol, [$r, $g, $b], $stamp_text] = doc_fc_stamp($day, $grid["language"]);
	$pdf->SetDrawColor($r / $coef, $g / $coef, $b / $coef);
	$pdf->SetFillColor($r, $g, $b);
	$pdf->UseStyle('fc.stamp', $stamp_text);
	$pdf->SetXY($x, $y);
	$pdf->Cell($w, $line_h, $symbol, 'B', 0, 'C', true);

	$pdf->UseStyle('fc.baby', $text_color);
	$pdf->SetXY($x, $y + $line_h);
	$pdf->Cell($w, $stamp_h, $paused ? res_t("pdf", $grid["language"], "fc.pause") : "", 'B', 0, 'C', true);
	$image_size = min($w, $stamp_h) * DOC_FC_BABY_SHARE;
	// a paused or unobserved day shows its state, not the stamp it may still hold
	if (!empty($day["stampBaby"]) && !is_null($day["stampColor"]) && is_null(doc_day_stamp_state($day))) {
		$pdf->Baby($x + ($w - $image_size) / 2, $y + $line_h + ($stamp_h - $image_size) / 2, $image_size, [$r, $g, $b], DOC_MUCUS_BABY_INK[$day["stampColor"]] ?? null);
	}

	$pdf->UseStyle('fc.peak', $stamp_text);
	$pdf->SetXY($x, $y + $line_h + $stamp_h);
	$pdf->Cell($w, $line_h, $cell["peak"] ?? '', 'B', 0, 'C', true);

	$pdf->SetDrawColor(...DOC_FC_LINE_COLOR);
	$row_y = fn(int $index) => $y + $line_h * $index + $stamp_h;

	// the date
	$date_text = '';
	$date_style = 'fc.date';
	if (!is_null($day)) {
		$date = new DateTime($day["date"]);
		$week_day = intval($date->format('w'));
		$week_days = res_words("pdf", $grid["language"])["week_days"];
		$date_text = $grid["anonymous"] ? $week_days[$week_day] : date_human_week_day($date, $week_days, $grid["date_pattern"]);
		if ($week_day === 0) $date_style = 'fc.date.sunday';
	}
	$pdf->UseStyle($date_style, $paused ? $text_color : null);
	$pdf->SetXY($x, $row_y(2));
	$pdf->Cell($w, $line_h, $date_text, 'B', 0, 'C');

	// bleeding, then mucus: codifiedMucusSensation, codifiedMucusObservation and the recurrence
	// count, "X" written "*"
	$pdf->UseStyle('fc.cell', $text_color);
	$pdf->SetXY($x, $row_y(3));
	$pdf->Cell($w, $line_h, $day["codifiedBleedingObservation"] ?? '', 'B', 0, 'C');
	$mucus = ($day["codifiedMucusSensation"] ?? '') . ($day["codifiedMucusObservation"] ?? '')
		. str_ireplace('X', '*', $day["codifiedNumberObservations"] ?? '');
	$pdf->SetXY($x, $row_y(4));
	$pdf->Cell($w, $line_h, $mucus, 'B', 0, 'C');

	// other info: the arrow, the pain codes and the union (`fc.union`, a letter per language)
	$pain = $day["codifiedPainObservations"] ?? '';
	$other = $pain . ($pain !== '' ? ' ' : '') . (!empty($day["sexUnion"]) ? res_t("pdf", $grid["language"], "fc.union") : '');
	$arrow = DOC_FC_ARROWS[$day["codifiedArrow"] ?? ''] ?? '';
	$pdf->SetXY($x, $row_y(5));
	if ($other !== '' && $arrow !== '') {
		$pdf->UseStyle('fc.arrow', $text_color);
		$pdf->Cell($w / 4, $line_h, $arrow, 'B', 0, 'R');
		$pdf->UseStyle('fc.cell', $text_color);
		$pdf->Cell($w / 4 * 3, $line_h, $other, 'B', 0, 'L');
	}
	elseif ($arrow !== '') {
		$pdf->UseStyle('fc.arrow', $text_color);
		$pdf->Cell($w, $line_h, $arrow, 'B', 0, 'C');
	}
	else $pdf->Cell($w, $line_h, $other, 'B', 0, 'C');

	if ($grid["temperature"]) {
		$temperature = '';
		if (!is_null($day["temperature"] ?? null)) {
			$temperature = number_format($day["temperature"], 2, '.', '');
			$time = doc_time_hhmm($day["temperatureTime"]);
			if ($time !== '') $temperature .= " " . res_t("pdf", $grid["language"], "fc.at") . " " . $time;
		}
		$pdf->UseStyle('fc.temperature', $text_color);
		$pdf->ShrinkToFit($temperature, $w);
		$pdf->SetXY($x, $row_y(6));
		$pdf->Cell($w, $line_h, $temperature, 'B', 0, 'C');
	}

	if (!is_null($day)) {
		$comment = doc_one_line($day["comments"]);
		doc_fc_draw_comment($pdf, $comment, $x, $row_y($grid["temperature"] ? 7 : 6), $w, $line_h, $text_color);
	}
}

// A comment in a cell a few millimetres wide, as large as it fits: one line at 6pt, one at 3pt, three at 3pt, else five at 3pt cut
// with an ellipsis.
function doc_fc_draw_comment(DocPdf $pdf, string $comment, float $x, float $y, float $w, float $h, array $color): void {
	if ($comment === '') return;
	$room = $w - 2 * $pdf->GetCellMargin();
	$pdf->SetXY($x, $y);

	$pdf->UseStyle('fc.cell', $color);
	if ($pdf->GetStringWidth($comment) <= $room) {
		$pdf->Cell($w, $h, $comment, 0, 0, 'L');
		return;
	}

	$pdf->UseStyle('fc.comment.small', $color);
	if ($pdf->GetStringWidth($comment) <= $room) {
		$pdf->Cell($w, $h, $comment, 0, 0, 'L');
		return;
	}

	$lines = $pdf->PackLines(explode(" ", $comment), $room, 5);
	$line_h = count($lines) <= 3 ? $h / 3 : $h / 5;
	foreach ($lines as $line) {
		$pdf->SetXY($x, $y);
		$pdf->Cell($w, $line_h, $line, 0, 0, 'L');
		$y += $line_h;
	}
}
