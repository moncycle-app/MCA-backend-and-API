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

// The cervical mucus chart (nfp_method 1 and 2): a table, one line per day, A4 portrait, in mm, in the language of the account. A page starts
// with the logo, the name and what the chart covers. A line: date | day of the cycle | stamp | [peak] | [counter] | [union] | sensations |
// observations | comment, and method 1 ends with the temperature and its curve; the columns in brackets only exist when a day uses them, and
// so do the three text columns.
//
// The room is spent on content. A line is as tall as its tallest text; a text is cut between its keywords (a keyword stays whole on a
// line), and the widths of the text columns are searched over the whole export so that the lines of all the days add up to the least height
// (doc_mucus_text_widths()): a column with long keywords is wide where the others are narrow, and a comment column nobody writes in does
// not exist. Every line is measured before anything is drawn, so the pages are known up front: a cycle starts a page, and one that does
// not fit carries on to the next under the same headers. PDF_CERVICAL_MUCUS_BORDERS (config) swaps the lines between days for stripes.

require_once __DIR__ . "/user_account.php";
require_once __DIR__ . "/date.php";
require_once __DIR__ . "/doc.php";
require_once __DIR__ . "/doc_pdf.php";
require_once __DIR__ . "/res.php";

function doc_mucus_pdf(array $days, int $nfp_method, string $name, string $language, bool $anonymous = false, string $date_pattern = 'd/m/y'): DocPdf {
	$first_date = new DateTime($days[0]["date"]);
	$last_date = new DateTime(end($days)["date"]);
	if ($anonymous) $name = doc_get_initials($name) . " " . res_t("pdf", $language, "anonymous");

	$days_text = res_t("pdf", $language, count($days) > 1 ? "days.other" : "days.one", ["n" => count($days)]);
	$subtitle = $anonymous
		? res_t("pdf", $language, "mucus.subtitle_anonymous", ["days" => $days_text])
		: res_t("pdf", $language, "mucus.subtitle", ["days" => $days_text, "start" => date_human($first_date, $date_pattern), "end" => date_human($last_date, $date_pattern)]);

	$pdf = new DocPdf('P', 'A4', res_t("pdf", $language, "title", ["date" => date_human($first_date, $date_pattern)]), $name);
	$pdf->SetMargins(DOC_PDF_MARGIN, DOC_PDF_MARGIN);

	$rows = doc_mucus_rows($days);

	// one layout per cycle (a cycle starts a page): its columns are the ones its days use. The temperature scale is the export's.
	$readings = array_filter(array_column($rows, "temperature"), fn($t) => !is_null($t));
	$temperature = user_account_tracks_temperature($nfp_method) && !empty($readings);
	$scale = null;
	if ($temperature) {
		$scale = [min($readings), max($readings)];
		// one reading, or all the same: a scale around it
		if ($scale[0] == $scale[1]) $scale = [$scale[0] - 0.5, $scale[1] + 0.5];
	}
	$layouts = [];
	foreach (doc_mucus_cycles($rows) as $group => $indexes) {
		$layout = doc_mucus_layout($pdf, array_intersect_key($rows, array_flip($indexes)), $scale, $language);
		$layout["date_pattern"] = $date_pattern;
		$layout["week_days"] = res_words("pdf", $language)["week_days"];
		$layouts[$group] = $layout;
		foreach ($indexes as $index) $rows[$index]["group"] = $group;
	}
	doc_mucus_fit_rows($pdf, $rows, $layouts);
	$pages = doc_mucus_paginate($pdf, $rows);

	foreach ($pages as $page_index => [$first, $count]) {
		$pdf->AddPage();
		$layout = $layouts[$rows[$first]["group"]];
		doc_mucus_draw_title($pdf, $name, $subtitle, res_t("pdf", $language, "page", ["page" => $page_index + 1, "count" => count($pages)]));
		doc_mucus_draw_head($pdf, $layout);

		$previous_point = null;   // the last point drawn on the temperature curve
		$y = DOC_MUCUS_TABLE_TOP + DOC_MUCUS_HEAD_H;
		for ($index = $first; $index < $first + $count; $index++) {
			doc_mucus_draw_row($pdf, $rows[$index], $layout, $y, $anonymous, ($index - $first) % 2 === 1, $previous_point);
			$y += $rows[$index]["height"];
		}

		// the stamps of this page that need explaining
		$stamps = array_column(array_slice($rows, $first, $count), "stamp");
		$legend = [];
		if (in_array("unknown", $stamps, true)) $legend["unknown"] = res_t("pdf", $language, "legend.not_observed");
		if (in_array("missing", $stamps, true)) $legend["missing"] = res_t("pdf", $language, "legend.not_filled");
		if (in_array("paused", $stamps, true)) $legend["paused"] = res_t("pdf", $language, "legend.paused");
		doc_mucus_draw_legend($pdf, $legend);
	}

	return $pdf;
}

// What each day shows. The peak (+1..+3 after it) and the counter (+1..+n) run over calendar days, gaps included, and start over at
// every cycle start. "items" are the pieces a text is cut between: the keywords of a description list (each but the last with its comma),
// the words of a comment.
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
			$peak = ["triangle"];
		}
		elseif (!is_null($since_peak) && $since_peak < 3) $peak = ["text", 'mucus.peak_offset', "+" . ++$since_peak];
		else $since_peak = null;

		if (!empty($day["counterStart"])) $counter = [0, $day["counterStart"]];
		$count = null;
		if (!is_null($counter) && $counter[0] < $counter[1]) $count = ["text", 'mucus.counter', "+" . ++$counter[0]];
		else $counter = null;

		$state = doc_day_stamp_state($day);
		$stamp = DOC_MUCUS_STATE_STAMPS[$state] ?? $day["stampColor"];

		$items = [];
		foreach (["freeMucusSensation", "freeMucusObservation"] as $field) {
			$keywords = array_values(array_filter(array_map('doc_one_line', $day[$field]), fn($keyword) => $keyword !== ''));
			foreach ($keywords as $index => $keyword) $keywords[$index] = $keyword . ($index < count($keywords) - 1 ? "," : "");
			$items[$field] = $keywords;
		}
		$comment = doc_one_line($day["comments"]);
		$items["comments"] = $comment === '' ? [] : explode(" ", $comment);

		$rows[] = [
			"day" => $day,
			"stamp" => $stamp,
			"baby" => $day["stampBaby"] && !is_null($day["stampColor"]),
			"peak" => $peak,
			"counter" => $count,
			"union" => $day["sexUnion"] ? ["heart"] : null,
			"items" => $items,
			"temperature" => $day["temperature"],
			"temperature_label" => is_null($day["temperature"]) ? '' : number_format($day["temperature"], 2, '.', '') . "°",
			"temperature_time" => doc_time_hhmm($day["temperatureTime"]),
		];
	}

	return $rows;
}

// The row indexes of each cycle: a cycle starts at its cycleFirstDay (days before the first one are a group of their own).
function doc_mucus_cycles(array $rows): array {
	$groups = [];
	$group = -1;
	foreach ($rows as $index => $row) {
		if ($group < 0 || $row["day"]["cycleFirstDay"]) $group++;
		$groups[$group][] = $index;
	}
	return $groups;
}

// The columns of a cycle, so that its days line up: which there are, their x and width. $scale: [lowest, highest] temperature of the export,
// or null when no curve is drawn.
function doc_mucus_layout(DocPdf $pdf, array $rows, ?array $scale, string $language): array {
	$margin = $pdf->GetCellMargin();
	$width = $pdf->GetPageWidth() - 2 * DOC_PDF_MARGIN;
	$temperature = !is_null($scale);

	$columns = [];
	$pdf->UseStyle('mucus.head');
	foreach (DOC_MUCUS_FIXED_COLUMNS as $key => [$label_key, $min_w]) {
		if (in_array($key, DOC_MUCUS_MARKERS, true) && empty(array_filter(array_column($rows, $key)))) continue;
		$label = mb_strtoupper(res_t("pdf", $language, $label_key));
		$columns[$key] = ["label" => $label, "w" => max($min_w, $pdf->GetStringWidth($label) + 2 * $margin), "align" => 'C'];
	}

	$right_w = $temperature ? DOC_MUCUS_TEMPERATURE_W + DOC_MUCUS_CURVE_W : 0;
	$text_room = $width - array_sum(array_column($columns, "w")) - $right_w;

	foreach (doc_mucus_text_widths($pdf, $rows, $text_room, $language) as $field => $w) {
		$columns[$field] = ["label" => mb_strtoupper(res_t("pdf", $language, DOC_MUCUS_TEXT_COLUMNS[$field][0])), "w" => $w, "align" => 'L'];
	}

	$layout = ["columns" => [], "temperature" => $temperature, "width" => $width];

	if ($temperature) {
		$columns["temperature"] = ["label" => mb_strtoupper(res_t("pdf", $language, "mucus.col.temperature")), "w" => DOC_MUCUS_TEMPERATURE_W, "align" => 'L'];
		$columns["curve"] = ["label" => "", "w" => DOC_MUCUS_CURVE_W, "align" => 'L'];

		[$layout["temperature_min"], $layout["temperature_max"]] = $scale;
	}

	$x = DOC_PDF_MARGIN;
	foreach ($columns as $key => $column) {
		$layout["columns"][$key] = $column + ["x" => $x];
		$x += $column["w"];
	}

	return $layout;
}

// The height of a day's line that takes $lines lines of text.
function doc_mucus_row_height(int $lines): float {
	return max(DOC_MUCUS_MIN_ROW_H, 2 * DOC_MUCUS_PAD + $lines * DOC_MUCUS_LINE_H);
}

// The width of each text column that a day uses, field => mm, filling $room. The sensations and the observations are searched: every width
// from their least to what keeps their longest list on one line is tried, and the pair that makes the days' lines add up to the least
// height wins (the narrower pair when several do); the comment takes what is left, and with no comment column the two share what is left.
// A text a day does not have asks for no line.
function doc_mucus_text_widths(DocPdf $pdf, array $rows, float $room, string $language): array {
	$margin2 = 2 * $pdf->GetCellMargin();

	// the columns a day uses, with the width of each of their pieces, and the least width of each
	$fields = [];
	$pieces = [];
	$space = [];
	$min_w = [];
	$natural_w = [];
	foreach (DOC_MUCUS_TEXT_COLUMNS as $field => [$label_key, $style]) {
		if (empty(array_filter(array_column(array_column($rows, "items"), $field)))) continue;
		$fields[] = $field;
		$pdf->UseStyle($style);
		$space[$field] = $pdf->TextWidth(" ");
		$natural_w[$field] = 0.0;
		foreach ($rows as $index => $row) {
			$pieces[$field][$index] = array_map(fn($item) => $pdf->TextWidth($item), $row["items"][$field]);
			$natural_w[$field] = max($natural_w[$field], array_sum($pieces[$field][$index]) + $space[$field] * max(0, count($pieces[$field][$index]) - 1));
		}
		$pdf->UseStyle('mucus.head');
		$label_w = $pdf->GetStringWidth(mb_strtoupper(res_t("pdf", $language, $label_key))) + $margin2;
		$min_w[$field] = max($label_w, $field === "comments" ? DOC_MUCUS_COMMENT_MIN_W : DOC_MUCUS_TEXT_MIN_W);
		$natural_w[$field] += $margin2;
	}

	$with_comment = in_array("comments", $fields, true);
	$searched = array_values(array_filter($fields, fn($field) => $field !== "comments"));
	// nothing to write in the descriptions: the comment column takes the table's width, empty if need be
	if (empty($searched)) return ["comments" => $room];

	// what each searched column is tried at: its least width up to the one that keeps its longest text on a line (the room left by the
	// others' least at most)
	$candidates = [];
	foreach ($searched as $field) {
		$most = $room - array_sum($min_w) + $min_w[$field];
		$top = max($min_w[$field], min($natural_w[$field], $most));
		$candidates[$field] = [];
		for ($w = $min_w[$field]; $w < $top; $w += DOC_MUCUS_WIDTH_STEP) $candidates[$field][] = $w;
		$candidates[$field][] = $top;
	}

	// the days that could ask for more than a line, at the narrowest: the others cost one line whatever the widths
	$count_lines = fn(string $field, int $index, float $w): int => empty($pieces[$field][$index]) ? 0
		: min(DOC_MUCUS_MAX_LINES, DocPdf::CountLines($pieces[$field][$index], $space[$field], $w - $margin2));
	$dynamic = [];
	foreach (array_keys($rows) as $index) {
		foreach ($fields as $field) {
			if ($count_lines($field, $index, $field === "comments" ? $min_w[$field] : $candidates[$field][0]) > 1) {
				$dynamic[] = $index;
				break;
			}
		}
	}

	$height = array_map('doc_mucus_row_height', range(0, DOC_MUCUS_MAX_LINES));
	$lines = [];   // field => width position => day => lines
	foreach ($searched as $field) {
		foreach ($candidates[$field] as $position => $w) {
			foreach ($dynamic as $index) $lines[$field][$position][$index] = $count_lines($field, $index, $w);
		}
	}

	$best = null;
	$comment_lines = [];   // comment width in quarters of mm => day => lines
	foreach (doc_mucus_combinations(array_map(fn($field) => array_keys($candidates[$field]), $searched)) as $combination) {
		$widths = [];
		foreach ($searched as $dimension => $field) $widths[$field] = $candidates[$field][$combination[$dimension]];
		$rest = $room - array_sum($widths);
		if ($rest < ($with_comment ? $min_w["comments"] : 0)) continue;

		$comment = [];
		if ($with_comment) {
			$key = intval(round($rest * 4));
			if (!isset($comment_lines[$key])) {
				$comment_lines[$key] = [];
				foreach ($dynamic as $index) $comment_lines[$key][$index] = $count_lines("comments", $index, $rest);
			}
			$comment = $comment_lines[$key];
		}

		$cost = 0.0;
		foreach ($dynamic as $index) {
			$most = max(1, $comment[$index] ?? 1);
			foreach ($searched as $dimension => $field) $most = max($most, $lines[$field][$combination[$dimension]][$index]);
			$cost += $height[$most];
		}
		$total = array_sum($widths);
		if (is_null($best) || $cost < $best["cost"] - 1e-9 || (abs($cost - $best["cost"]) <= 1e-9 && $total < $best["total"])) {
			$best = ["cost" => $cost, "total" => $total, "widths" => $widths];
		}
	}

	// the room is too small for the least widths: they share it
	if (is_null($best)) {
		$widths = [];
		foreach ($searched as $field) $widths[$field] = $min_w[$field];
		$scale = min(1.0, $room / array_sum($widths));
		$best = ["widths" => array_map(fn($w) => $w * $scale, $widths)];
	}

	$widths = $best["widths"];
	$rest = max(0.0, $room - array_sum($widths));
	if ($with_comment) $widths["comments"] = $rest;
	else foreach ($widths as $field => $w) $widths[$field] = $w + $rest / count($widths);

	return $widths;
}

// Every way to pick one value of each list of $lists: [[a0, b0], [a0, b1], ...]. A search over the widths of two columns: a few thousand at most.
function doc_mucus_combinations(array $lists): array {
	$combinations = [[]];
	foreach ($lists as $list) {
		$next = [];
		foreach ($combinations as $combination) {
			foreach ($list as $value) $next[] = [...$combination, $value];
		}
		$combinations = $next;
	}
	return $combinations;
}

// Each day's texts cut into lines for their column, and the day's height. In place: a long export has thousands of days, and a copy is
// memory it needs.
function doc_mucus_fit_rows(DocPdf $pdf, array &$rows, array $layouts): void {
	$margin2 = 2 * $pdf->GetCellMargin();
	foreach ($rows as &$row) {
		$most = 1;
		$row["lines"] = [];
		$layout = $layouts[$row["group"]];
		foreach (DOC_MUCUS_TEXT_COLUMNS as $field => [$label_key, $style]) {
			if (!isset($layout["columns"][$field])) continue;
			$pdf->UseStyle($style);
			$row["lines"][$field] = $pdf->PackLines($row["items"][$field], $layout["columns"][$field]["w"] - $margin2, DOC_MUCUS_MAX_LINES);
			$most = max($most, count($row["lines"][$field]));
		}
		unset($row["items"]);
		$row["height"] = doc_mucus_row_height($most);
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

// The top of a page: the logo, the name and what the chart covers, the link to the app and the page number. $name is UTF-8.
function doc_mucus_draw_title(DocPdf $pdf, string $name, string $subtitle, string $page): void {
	$left = DOC_PDF_MARGIN + DOC_MUCUS_LOGO_SIZE + 3;
	$side_w = 32;
	$text_w = $pdf->GetPageWidth() - DOC_PDF_MARGIN - $left;

	$pdf->Logo(DOC_PDF_MARGIN, DOC_PDF_MARGIN, DOC_MUCUS_LOGO_SIZE);

	$pdf->SetXY($left, DOC_PDF_MARGIN);
	$pdf->UseStyle('mucus.title');
	$pdf->Cell($text_w - $side_w, 6.5, $pdf->FitText($name, $text_w - $side_w), 0, 0, 'L');
	$pdf->UseStyle('mucus.link');
	$pdf->Cell($side_w, 6.5, "MONCYCLE.APP", 0, 1, 'R', false, "https://www.moncycle.app");

	$pdf->SetX($left);
	$pdf->UseStyle('mucus.subtitle');
	$pdf->Cell($text_w - $side_w, 4.5, $pdf->FitText($subtitle, $text_w - $side_w), 0, 0, 'L');
	$pdf->UseStyle('mucus.page');
	$pdf->Cell($side_w, 4.5, $page, 0, 1, 'R');
}

// A rule across the table at $y.
function doc_mucus_draw_rule(DocPdf $pdf, array $layout, float $y, ?array $color = null, float $weight = DOC_MUCUS_BORDER_W): void {
	$pdf->SetDrawColor(...($color ?? DOC_MUCUS_BORDER_COLOR));
	$pdf->SetLineWidth($weight);
	$pdf->Line(DOC_PDF_MARGIN, $y, DOC_PDF_MARGIN + $layout["width"], $y);
}

// The column headers, on a light band over a darker rule. The curve's header gives its scale.
function doc_mucus_draw_head(DocPdf $pdf, array $layout): void {
	$y = DOC_MUCUS_TABLE_TOP;

	$pdf->SetFillColor(...DOC_MUCUS_HEAD_FILL);
	$pdf->Rect(DOC_PDF_MARGIN, $y, $layout["width"], DOC_MUCUS_HEAD_H, 'F');

	foreach ($layout["columns"] as $column) {
		$pdf->UseStyle('mucus.head');
		$pdf->SetXY($column["x"], $y);
		$pdf->Cell($column["w"], DOC_MUCUS_HEAD_H, $column["label"], 0, 0, $column["align"]);
	}

	if ($layout["temperature"]) {
		$curve = $layout["columns"]["curve"];
		$min = number_format($layout["temperature_min"], 2, '.', '') . "°";
		$max = number_format($layout["temperature_max"], 2, '.', '') . "°";
		$pdf->UseStyle('mucus.scale');
		$pdf->SetXY($curve["x"], $y);
		$pdf->Cell($curve["w"], DOC_MUCUS_HEAD_H, $min, 0, 0, 'L');
		$pdf->SetXY($curve["x"], $y);
		$pdf->Cell($curve["w"], DOC_MUCUS_HEAD_H, $max, 0, 0, 'R');
	}

	doc_mucus_draw_rule($pdf, $layout, $y + DOC_MUCUS_HEAD_H, DOC_MUCUS_HEAD_RULE_COLOR, 0.25);
}

function doc_mucus_draw_row(DocPdf $pdf, array $row, array $layout, float $y, bool $anonymous, bool $striped, ?array &$previous_point): void {
	$day = $row["day"];
	$columns = $layout["columns"];
	$h = $row["height"];
	$middle = $y + $h / 2;
	$margin = $pdf->GetCellMargin();

	// without lines, every other day is shaded to keep the eye on its line
	if ($striped && !PDF_CERVICAL_MUCUS_BORDERS) {
		$pdf->SetFillColor(...DOC_MUCUS_STRIPE_FILL);
		$pdf->Rect(DOC_PDF_MARGIN, $y, $layout["width"], $h, 'F');
	}

	// the date and the day of the cycle, bold on a Sunday
	$date = new DateTime($day["date"]);
	$week_day = intval($date->format('w'));
	$date_label = $layout["week_days"][$week_day] . ($anonymous ? "" : " " . date_human($date, $layout["date_pattern"]));
	$bold = $week_day === 0;
	$pdf->UseStyle($bold ? 'mucus.date.sunday' : 'mucus.date');
	$pdf->SetXY($columns["date"]["x"], $y);
	$pdf->Cell($columns["date"]["w"], $h, $date_label, 0, 0, 'C');
	$pdf->UseStyle($bold ? 'mucus.day_number.bold' : 'mucus.day_number');
	$pdf->SetXY($columns["day"]["x"], $y);
	$pdf->Cell($columns["day"]["w"], $h, (string) ($day["cycleDay"] ?? "?"), 0, 0, 'C');

	doc_mucus_draw_stamp($pdf, $row["stamp"], $row["baby"],
		$columns["stamp"]["x"] + ($columns["stamp"]["w"] - DOC_MUCUS_STAMP_SIZE) / 2, $middle - DOC_MUCUS_STAMP_SIZE / 2, DOC_MUCUS_STAMP_SIZE);

	foreach (DOC_MUCUS_MARKERS as $kind) {
		if (!isset($columns[$kind]) || is_null($row[$kind])) continue;
		$center = $columns[$kind]["x"] + $columns[$kind]["w"] / 2;
		if ($row[$kind][0] === "heart") $pdf->Heart($center, $middle, 3.2, DOC_MUCUS_UNION_COLOR);
		elseif ($row[$kind][0] === "triangle") $pdf->Triangle($center, $middle, 3.2, DOC_MUCUS_PEAK_COLOR);
		else {
			[, $style, $text] = $row[$kind];
			$pdf->UseStyle($style);
			$pdf->SetXY($columns[$kind]["x"], $y);
			$pdf->Cell($columns[$kind]["w"], $h, $text, 0, 0, 'C');
		}
	}

	// the lines of each text, as a block in the middle of the day's line
	foreach ($row["lines"] as $field => $lines) {
		$pdf->UseStyle(DOC_MUCUS_TEXT_COLUMNS[$field][1]);
		$line_y = $middle - count($lines) * DOC_MUCUS_LINE_H / 2;
		foreach ($lines as $line) {
			$pdf->SetXY($columns[$field]["x"], $line_y);
			$pdf->Cell($columns[$field]["w"], DOC_MUCUS_LINE_H, $line, 0, 0, 'L');
			$line_y += DOC_MUCUS_LINE_H;
		}
	}

	// method 1: the reading and its time, then its point on the curve, joined to the day before's; a day without one breaks the curve
	if ($layout["temperature"] && !is_null($row["temperature"])) {
		$runs = [['mucus.temperature', $row["temperature_label"], 0]];
		if ($row["temperature_time"] !== '') $runs[] = ['mucus.temperature_time', $row["temperature_time"], 1];
		$pdf->TextRuns($columns["temperature"]["x"] + $margin, $middle - DOC_MUCUS_LINE_H / 2, DOC_MUCUS_LINE_H, $runs);

		$curve = $columns["curve"];
		$scale = ($row["temperature"] - $layout["temperature_min"]) / ($layout["temperature_max"] - $layout["temperature_min"]);
		$point = [$curve["x"] + DOC_MUCUS_CURVE_PAD + $scale * ($curve["w"] - 2 * DOC_MUCUS_CURVE_PAD), $middle];
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

	if (PDF_CERVICAL_MUCUS_BORDERS) doc_mucus_draw_rule($pdf, $layout, $y + $h);
}

// The stamp: a square of the day's colour, with the baby on it. A day not observed is a grey "?", one nothing was entered on a grey "!", a
// pause of the observations a pink "II".
function doc_mucus_draw_stamp(DocPdf $pdf, ?string $kind, bool $baby, float $x, float $y, float $size): void {
	if (is_null($kind)) return;

	$glyph = '';
	if (isset(DOC_MUCUS_SPECIAL_STAMPS[$kind])) [$fill, $glyph, $glyph_color] = DOC_MUCUS_SPECIAL_STAMPS[$kind];
	else $fill = DOC_MUCUS_STAMP_COLORS[$kind];

	$pdf->SetFillColor(...$fill);
	$pdf->RoundedRect($x, $y, $size, $size, $size * DOC_MUCUS_STAMP_RADIUS);
	// a white stamp would float without an outline
	if ($kind === "White") {
		$pdf->SetDrawColor(...DOC_MUCUS_STAMP_OUTLINE);
		$pdf->SetLineWidth(0.15);
		$pdf->RoundedRect($x, $y, $size, $size, $size * DOC_MUCUS_STAMP_RADIUS, 'D');
	}

	if ($glyph !== '') {
		$pdf->UseStyle('mucus.stamp', $glyph_color);
		$pdf->SetFontSize($size * 1.45);
		$pdf->SetXY($x, $y);
		$pdf->Cell($size, $size, $glyph, 0, 0, 'C');
	}
	if ($baby) $pdf->Baby($x + $size * 0.1, $y + $size * 0.1, $size * 0.8, $fill);
}

// What the stamps that are not self-explanatory mean, along the bottom of the page.
function doc_mucus_draw_legend(DocPdf $pdf, array $legend): void {
	if (empty($legend)) return;

	$size = 3.4;
	$y = $pdf->GetPageHeight() - DOC_PDF_MARGIN - $size;
	$x = DOC_PDF_MARGIN;
	foreach ($legend as $kind => $text) {
		doc_mucus_draw_stamp($pdf, $kind, false, $x, $y, $size);
		$pdf->UseStyle('mucus.legend');
		$width = $pdf->GetStringWidth($text) + 2 * $pdf->GetCellMargin();
		$pdf->SetXY($x + $size + 1, $y);
		$pdf->Cell($width, $size, $text, 0, 0, 'L');
		$x += $size + 1 + $width + 4;
	}
}
