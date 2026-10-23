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

// The cervical mucus chart (nfp_method 1 and 2): a table, one line per day, A4 portrait, in mm. A line: date | day of the cycle | stamp |
// [peak] | [counter] | [union] | sensations | observations | comment, and method 1 ends with the temperature and its curve; the columns
// in brackets only exist when a day uses them. A line is as tall as its tallest text (cut with an ellipsis past DOC_MUCUS_MAX_LINES).
// Every line is measured before anything is drawn, so the pages are known up front: a cycle starts a page, and one that does not fit
// carries on to the next under the same headers. PDF_CERVICAL_MUCUS_BORDERS (config) turns the table's lines off.

require_once __DIR__ . "/user_account.php";
require_once __DIR__ . "/date.php";
require_once __DIR__ . "/doc.php";
require_once __DIR__ . "/doc_pdf.php";

function doc_mucus_pdf(array $days, int $nfp_method, string $name, bool $anonymous = false): DocPdf {
	$first_date = new DateTime($days[0]["date"]);
	$last_date = new DateTime(end($days)["date"]);
	if ($anonymous) $name = doc_get_initials($name) . " (anonyme)";

	$subtitle = sprintf("Tableau de %d %s", count($days), count($days) > 1 ? "jours" : "jour");
	if (!$anonymous) $subtitle .= sprintf(" du %s au %s", date_human($first_date), date_human($last_date));

	$pdf = new DocPdf('P', 'A4', 'MONCYCLE.APP tableau du ' . date_human($first_date), $name);
	$pdf->SetMargins(DOC_PDF_MARGIN, DOC_PDF_MARGIN);

	$rows = doc_mucus_rows($days);
	$layout = doc_mucus_layout($pdf, $rows, user_account_tracks_temperature($nfp_method));
	doc_mucus_fit_rows($pdf, $rows, $layout);
	$pages = doc_mucus_paginate($pdf, $rows);

	foreach ($pages as $page_index => [$first, $count]) {
		$pdf->AddPage();
		doc_mucus_draw_title($pdf, doc_pdf_text($name), $subtitle, $page_index + 1, count($pages));
		doc_mucus_draw_head($pdf, $layout);

		$previous_point = null;   // the last point drawn on the temperature curve
		$y = DOC_MUCUS_TABLE_TOP + DOC_MUCUS_HEAD_H;
		for ($index = $first; $index < $first + $count; $index++) {
			doc_mucus_draw_row($pdf, $rows[$index], $layout, $y, $anonymous, ($index - $first) % 2 === 1, $previous_point);
			$y += $rows[$index]["height"];
		}
		doc_mucus_draw_edges($pdf, $layout, DOC_MUCUS_TABLE_TOP, $y);

		// the stamps of this page that need explaining
		$stamps = array_column(array_slice($rows, $first, $count), "stamp");
		$legend = [];
		if (in_array("unknown", $stamps, true)) $legend["unknown"] = "jour non observé ou non renseigné";
		if (in_array("pregnancy", $stamps, true)) $legend["pregnancy"] = "grossesse";
		doc_mucus_draw_legend($pdf, $legend);
	}

	return $pdf;
}

// What each day shows. The peak (+1..+3 after it) and the counter (+1..+n) run over calendar days, gaps included, and start over at
// every cycle start. Texts are windows-1252.
function doc_mucus_rows(array $days): array {
	$rows = [];
	$since_peak = null;    // days since this cycle's last peak
	$counter = null;       // [days counted so far, days to count]

	foreach ($days as $day) {
		$day = doc_display_day($day);

		if ($day["cycleFirstDay"]) {
			$since_peak = null;
			$counter = null;
		}

		$peak = null;
		if ($day["isPeak"]) {
			$since_peak = 0;
			$peak = ['mucus.peak', chr(115)];     // a triangle in ZapfDingbats
		}
		elseif (!is_null($since_peak) && $since_peak < 3) $peak = ['mucus.peak_offset', "+" . ++$since_peak];
		else $since_peak = null;

		if (!empty($day["counterStart"])) $counter = [0, $day["counterStart"]];
		$count = null;
		if (!is_null($counter) && $counter[0] < $counter[1]) $count = ['mucus.counter', "+" . ++$counter[0]];
		else $counter = null;

		// a day not observed and a day never filled in show the same stamp
		$stamp = null;
		if ($day["booleanPregnancyDetected"]) $stamp = "pregnancy";
		elseif ($day["isGap"] || $day["mucusNotObserved"]) $stamp = "unknown";
		elseif (!is_null($day["stampColor"])) $stamp = $day["stampColor"];

		// each description list on one line, comma joined
		$texts = [];
		foreach (["freeMucusSensation", "freeMucusObservation"] as $field) {
			$texts[$field] = doc_pdf_text(doc_one_line(implode(", ", $day[$field])));
		}
		$texts["comments"] = doc_pdf_text(doc_one_line($day["comments"]));

		$temperature_time = doc_time_hhmm($day["temperatureTime"]);

		$rows[] = [
			"day" => $day,
			"stamp" => $stamp,
			"baby" => $day["stampBaby"] && !is_null($day["stampColor"]),
			"peak" => $peak,
			"counter" => $count,
			"union" => $day["sexUnion"] ? ['mucus.union', chr(164)] : null,   // a heart in ZapfDingbats
			"texts" => $texts,
			"temperature" => $day["temperature"],
			"temperature_label" => is_null($day["temperature"]) ? '' : doc_pdf_text(number_format($day["temperature"], 2, '.', '') . "°"),
			"temperature_time" => str_replace(':', 'h', $temperature_time),
		];
	}

	return $rows;
}

// The columns of the whole export, so that the days line up: which there are, their x and width, and the temperature scale ($temperature:
// the method tracks it). The description columns are as wide as most of their values, within DOC_MUCUS_TEXT_MIN_W and _MAX_W, and give
// way to keep the comment DOC_MUCUS_COMMENT_WANTED_W wide; the comment takes the rest.
function doc_mucus_layout(DocPdf $pdf, array $rows, bool $temperature): array {
	$margin = $pdf->GetCellMargin();
	$width = $pdf->GetPageWidth() - 2 * DOC_PDF_MARGIN;

	// method 1 draws the curve only when the export has a reading to put on it
	$readings = array_filter(array_column($rows, "temperature"), fn($t) => !is_null($t));
	$temperature = $temperature && !empty($readings);

	$columns = [];
	foreach (DOC_MUCUS_FIXED_COLUMNS as $key => [$label, $w]) {
		if (in_array($key, DOC_MUCUS_MARKERS, true) && empty(array_filter(array_column($rows, $key)))) continue;
		$columns[$key] = ["label" => $label, "w" => $w, "align" => 'C'];
	}

	$right_w = $temperature ? DOC_MUCUS_TEMPERATURE_W + DOC_MUCUS_CURVE_W : 0;
	$text_room = $width - array_sum(array_column($columns, "w")) - $right_w;

	$text_w = [];
	$text_min_w = [];
	foreach (DOC_MUCUS_TEXT_COLUMNS as $field => [$label, $style]) {
		if ($field === "comments") continue;

		// the width most values fit in on one line: a single long list wraps
		$pdf->UseStyle($style);
		$widths = [];
		foreach ($rows as $row) {
			if ($row["texts"][$field] !== '') $widths[] = $pdf->GetStringWidth($row["texts"][$field]);
		}
		sort($widths);
		$typical = empty($widths) ? 0.0 : $widths[intval(ceil(DOC_MUCUS_TEXT_TYPICAL * count($widths))) - 1];

		// and never narrower than its own header
		$pdf->UseStyle('mucus.head');
		$text_min_w[$field] = max(DOC_MUCUS_TEXT_MIN_W, $pdf->GetStringWidth($label) + 2 * $margin);
		$text_w[$field] = min(DOC_MUCUS_TEXT_MAX_W, max($text_min_w[$field], $typical + 2 * $margin));
	}

	$budget = $text_room - DOC_MUCUS_COMMENT_WANTED_W;
	if (array_sum($text_w) > $budget) {
		$scale = $budget / array_sum($text_w);
		foreach ($text_w as $field => $w) $text_w[$field] = max($text_min_w[$field], $w * $scale);
	}
	$text_w["comments"] = $text_room - array_sum($text_w);

	foreach ($text_w as $field => $w) {
		$columns[$field] = ["label" => DOC_MUCUS_TEXT_COLUMNS[$field][0], "w" => $w, "align" => 'L'];
	}

	$layout = ["columns" => [], "temperature" => $temperature, "width" => $width];

	if ($temperature) {
		$columns["temperature"] = ["label" => "TEMP.", "w" => DOC_MUCUS_TEMPERATURE_W, "align" => 'L'];
		$columns["curve"] = ["label" => "", "w" => DOC_MUCUS_CURVE_W, "align" => 'L'];

		$layout["temperature_min"] = min($readings);
		$layout["temperature_max"] = max($readings);
		// one reading, or all the same: a scale around it
		if ($layout["temperature_max"] == $layout["temperature_min"]) {
			$layout["temperature_min"] -= 0.5;
			$layout["temperature_max"] += 0.5;
		}
	}

	$x = DOC_PDF_MARGIN;
	foreach ($columns as $key => $column) {
		$layout["columns"][$key] = $column + ["x" => $x];
		$x += $column["w"];
	}

	return $layout;
}

// Each day's texts cut to their column (with an ellipsis), and the day's height, measured in the style they are drawn in. In place: a long
// export has thousands of days, and a copy is memory it needs.
function doc_mucus_fit_rows(DocPdf $pdf, array &$rows, array $layout): void {
	foreach ($rows as &$row) {
		$lines = 1;
		foreach (DOC_MUCUS_TEXT_COLUMNS as $field => [$label, $style]) {
			$pdf->UseStyle($style);
			[$row["texts"][$field], $text_lines] = $pdf->FitLines($layout["columns"][$field]["w"], $row["texts"][$field], DOC_MUCUS_MAX_LINES);
			$lines = max($lines, $text_lines);
		}
		$row["height"] = max(DOC_MUCUS_MIN_ROW_H, 2 * DOC_MUCUS_PAD + $lines * DOC_MUCUS_LINE_H);
	}
}

// The pages as [first day, number of days]: a cycle starts one, and so does a day that would run past the bottom (one day always fits).
function doc_mucus_paginate(DocPdf $pdf, array $rows): array {
	$limit = $pdf->GetPageHeight() - DOC_MUCUS_BOTTOM;
	$pages = [];
	$first = 0;
	$y = DOC_MUCUS_TABLE_TOP + DOC_MUCUS_HEAD_H;

	foreach ($rows as $index => $row) {
		if ($index > $first && ($row["day"]["cycleFirstDay"] || $y + $row["height"] > $limit)) {
			$pages[] = [$first, $index - $first];
			$first = $index;
			$y = DOC_MUCUS_TABLE_TOP + DOC_MUCUS_HEAD_H;
		}
		$y += $row["height"];
	}

	$pages[] = [$first, count($rows) - $first];
	return $pages;
}

// The name, the link to the app, what the chart covers and the page number. $name is windows-1252.
function doc_mucus_draw_title(DocPdf $pdf, string $name, string $subtitle, int $page, int $page_count): void {
	$width = $pdf->GetPageWidth() - 2 * DOC_PDF_MARGIN;
	$side_w = 32;

	$pdf->SetXY(DOC_PDF_MARGIN, DOC_PDF_MARGIN);
	$pdf->UseStyle('mucus.title');
	$pdf->Cell($width - $side_w, 6.5, $pdf->FitText($name, $width - $side_w), 0, 0, 'L');
	$pdf->UseStyle('mucus.link');
	$pdf->Cell($side_w, 6.5, "MONCYCLE.APP", 0, 1, 'R', false, "https://www.moncycle.app");

	$pdf->UseStyle('mucus.subtitle');
	$pdf->Cell($width - $side_w, 4, $pdf->FitText($subtitle, $width - $side_w), 0, 0, 'L');
	$pdf->Cell($side_w, 4, sprintf("page %d sur %d", $page, $page_count), 0, 1, 'R');
}

// With borders: a rule across the table at $y (under every band of cells, and over the first)...
function doc_mucus_draw_rule(DocPdf $pdf, array $layout, float $y): void {
	if (!PDF_CERVICAL_MUCUS_BORDERS) return;

	$pdf->SetDrawColor(...DOC_MUCUS_BORDER_COLOR);
	$pdf->SetLineWidth(DOC_MUCUS_BORDER_W);
	$pdf->Line(DOC_PDF_MARGIN, $y, DOC_PDF_MARGIN + $layout["width"], $y);
}

// ... and the edges of the columns, once for the whole page.
function doc_mucus_draw_edges(DocPdf $pdf, array $layout, float $top, float $bottom): void {
	if (!PDF_CERVICAL_MUCUS_BORDERS) return;

	$pdf->SetDrawColor(...DOC_MUCUS_BORDER_COLOR);
	$pdf->SetLineWidth(DOC_MUCUS_BORDER_W);
	foreach ($layout["columns"] as $column) $pdf->Line($column["x"], $top, $column["x"], $bottom);
	$pdf->Line(DOC_PDF_MARGIN + $layout["width"], $top, DOC_PDF_MARGIN + $layout["width"], $bottom);
}

// The column headers, on a light band. The curve's header gives its scale.
function doc_mucus_draw_head(DocPdf $pdf, array $layout): void {
	$y = DOC_MUCUS_TABLE_TOP;

	$pdf->SetFillColor(...DOC_MUCUS_HEAD_FILL);
	$pdf->Rect(DOC_PDF_MARGIN, $y, $layout["width"], DOC_MUCUS_HEAD_H, 'F');

	foreach ($layout["columns"] as $column) {
		$pdf->UseStyle('mucus.head');
		$pdf->SetXY($column["x"], $y);
		$pdf->Cell($column["w"], DOC_MUCUS_HEAD_H, doc_pdf_text($column["label"]), 0, 0, $column["align"]);
	}

	if ($layout["temperature"]) {
		$curve = $layout["columns"]["curve"];
		$min = doc_pdf_text(number_format($layout["temperature_min"], 2, '.', '') . "°");
		$max = doc_pdf_text(number_format($layout["temperature_max"], 2, '.', '') . "°");
		$pdf->UseStyle('mucus.scale');
		$pdf->SetXY($curve["x"], $y);
		$pdf->Cell($curve["w"], DOC_MUCUS_HEAD_H, $min, 0, 0, 'L');
		$pdf->SetXY($curve["x"], $y);
		$pdf->Cell($curve["w"], DOC_MUCUS_HEAD_H, $max, 0, 0, 'R');
	}

	doc_mucus_draw_rule($pdf, $layout, $y);
	doc_mucus_draw_rule($pdf, $layout, $y + DOC_MUCUS_HEAD_H);
}

function doc_mucus_draw_row(DocPdf $pdf, array $row, array $layout, float $y, bool $anonymous, bool $striped, ?array &$previous_point): void {
	$day = $row["day"];
	$columns = $layout["columns"];
	$h = $row["height"];
	$text_y = $y + DOC_MUCUS_PAD;
	$margin = $pdf->GetCellMargin();

	// without lines, every other day is shaded to keep the eye on its line
	if ($striped && !PDF_CERVICAL_MUCUS_BORDERS) {
		$pdf->SetFillColor(...DOC_MUCUS_STRIPE_FILL);
		$pdf->Rect(DOC_PDF_MARGIN, $y, $layout["width"], $h, 'F');
	}

	// the date and the day of the cycle, white on black on a cycle's first day
	$date = new DateTime($day["date"]);
	$week_day = intval($date->format('w'));
	$date_label = $anonymous ? DOC_WEEK_DAYS[$week_day] : date_human_week_day($date, DOC_WEEK_DAYS);
	$date_style = $week_day === 0 ? 'mucus.date.sunday' : 'mucus.date';
	$text_color = null;
	if ($day["cycleFirstDay"]) {
		$pdf->SetFillColor(0, 0, 0);
		$pdf->Rect($columns["date"]["x"], $y, $columns["date"]["w"] + $columns["day"]["w"], $h, 'F');
		$text_color = [255, 255, 255];
	}
	$pdf->UseStyle($date_style, $text_color);
	$pdf->SetXY($columns["date"]["x"], $text_y);
	$pdf->Cell($columns["date"]["w"], DOC_MUCUS_LINE_H, $date_label, 0, 0, 'C');
	$pdf->UseStyle('mucus.day_number', $text_color);
	$pdf->SetXY($columns["day"]["x"], $text_y);
	$pdf->Cell($columns["day"]["w"], DOC_MUCUS_LINE_H, (string) ($day["cycleDay"] ?? "?"), 0, 0, 'C');

	doc_mucus_draw_stamp($pdf, $row["stamp"], $row["baby"],
		$columns["stamp"]["x"] + ($columns["stamp"]["w"] - DOC_MUCUS_STAMP_SIZE) / 2,
		$y + (DOC_MUCUS_MIN_ROW_H - DOC_MUCUS_STAMP_SIZE) / 2, DOC_MUCUS_STAMP_SIZE);

	foreach (DOC_MUCUS_MARKERS as $kind) {
		if (!isset($columns[$kind]) || is_null($row[$kind])) continue;
		[$style, $text] = $row[$kind];
		$pdf->UseStyle($style);
		$pdf->SetXY($columns[$kind]["x"], $text_y);
		$pdf->Cell($columns[$kind]["w"], DOC_MUCUS_LINE_H, $text, 0, 0, 'C');
	}

	foreach ($row["texts"] as $field => $text) {
		if ($text === '') continue;
		$pdf->UseStyle(DOC_MUCUS_TEXT_COLUMNS[$field][1]);
		$pdf->SetXY($columns[$field]["x"], $text_y);
		$pdf->MultiCell($columns[$field]["w"], DOC_MUCUS_LINE_H, $text, 0, 'L');
	}

	// method 1: the reading and its time, then its point on the curve, joined to the day before's; a day without one breaks the curve
	if ($layout["temperature"] && !is_null($row["temperature"])) {
		$runs = [['mucus.temperature', $row["temperature_label"], 0]];
		if ($row["temperature_time"] !== '') $runs[] = ['mucus.temperature_time', $row["temperature_time"], 1];
		$pdf->TextRuns($columns["temperature"]["x"] + $margin, $y + DOC_MUCUS_PAD, DOC_MUCUS_LINE_H, $runs);

		$curve = $columns["curve"];
		$scale = ($row["temperature"] - $layout["temperature_min"]) / ($layout["temperature_max"] - $layout["temperature_min"]);
		$point = [
			$curve["x"] + DOC_MUCUS_CURVE_PAD + $scale * ($curve["w"] - 2 * DOC_MUCUS_CURVE_PAD),
			$y + DOC_MUCUS_MIN_ROW_H / 2,
		];
		if (!is_null($previous_point)) {
			$pdf->SetDrawColor(...DOC_MUCUS_TEMPERATURE_COLOR);
			$pdf->SetLineWidth(0.25);
			$pdf->Line($previous_point[0], $previous_point[1], $point[0], $point[1]);
		}
		$pdf->SetFillColor(...DOC_MUCUS_TEMPERATURE_COLOR);
		$pdf->Rect($point[0] - 0.6, $point[1] - 0.6, 1.2, 1.2, 'F');
		$previous_point = $point;
	}
	else $previous_point = null;

	doc_mucus_draw_rule($pdf, $layout, $y + $h);
}

// The stamp: a square of the day's colour, with the baby on it. Not observed or not filled in is a grey "?", a pregnancy a pink "G".
function doc_mucus_draw_stamp(DocPdf $pdf, ?string $kind, bool $baby, float $x, float $y, float $size): void {
	if (is_null($kind)) return;

	$glyph = '';
	if (isset(DOC_MUCUS_SPECIAL_STAMPS[$kind])) [$fill, $glyph, $glyph_color] = DOC_MUCUS_SPECIAL_STAMPS[$kind];
	else $fill = DOC_MUCUS_STAMP_COLORS[$kind];

	$pdf->SetFillColor(...$fill);
	$pdf->Rect($x, $y, $size, $size, 'F');
	// a baby on white would float without an outline
	if ($kind === "White" && PDF_CERVICAL_MUCUS_BORDERS) {
		$pdf->SetDrawColor(...DOC_MUCUS_BORDER_COLOR);
		$pdf->SetLineWidth(DOC_MUCUS_BORDER_W);
		$pdf->Rect($x, $y, $size, $size, 'D');
	}

	if ($glyph !== '') {
		$pdf->UseStyle('mucus.stamp', $glyph_color);
		$pdf->SetFontSize($size * 1.45);
		$pdf->SetXY($x, $y);
		$pdf->Cell($size, $size, $glyph, 0, 0, 'C');
	}
	if ($baby) $pdf->Image(DOC_BABY_IMAGE, $x + 0.35, $y + 0.35, $size - 0.7, $size - 0.7);
}

// What the stamps that are not self-explanatory mean, along the bottom of the page.
function doc_mucus_draw_legend(DocPdf $pdf, array $legend): void {
	if (empty($legend)) return;

	$size = 3.4;
	$y = $pdf->GetPageHeight() - DOC_PDF_MARGIN - $size;
	$x = DOC_PDF_MARGIN;
	foreach ($legend as $kind => $text) {
		doc_mucus_draw_stamp($pdf, $kind, false, $x, $y, $size);
		$text = doc_pdf_text($text);
		$pdf->UseStyle('mucus.legend');
		$pdf->SetXY($x + $size + 1, $y);
		$pdf->Cell($pdf->GetStringWidth($text) + 2 * $pdf->GetCellMargin(), $size, $text, 0, 0, 'L');
		$x += $size + 1 + $pdf->GetStringWidth($text) + 2 * $pdf->GetCellMargin() + 4;
	}
}
