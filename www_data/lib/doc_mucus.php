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
// with the name and its cycle on the left, the app (its name and logo, a link) on the right; it ends with the legend and what the whole export
// covers. A line: date | day of the cycle | stamp | [peak and counter] | [union] | sensations |
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
	$days = doc_mucus_without_paused_days($days);
	$first_date = new DateTime($days[0]["date"]);
	$last_date = new DateTime(end($days)["date"]);
	if ($anonymous) $name = doc_get_initials($name) . " " . res_t("pdf", $language, "anonymous");

	$pdf = new DocPdf('P', 'A4', res_t("pdf", $language, "title", ["date" => date_human($first_date, $date_pattern)]), $name);
	$pdf->SetMargins(DOC_PDF_MARGIN, DOC_PDF_MARGIN);

	$rows = doc_mucus_rows($days);
	$cycles = doc_mucus_cycle_summaries($rows);
	$cycle_count = count(array_filter($cycles, fn($cycle) => !is_null($cycle["n"])));

	// what the whole export covers, at the foot of every page: nothing when it is one cycle, which the head of the page already tells
	$summary = "";
	if (count($cycles) > 1 || $cycle_count === 0) {
		$days_text = res_t("pdf", $language, count($days) > 1 ? "days.other" : "days.one", ["n" => count($days)]);
		$cycles_text = res_t("pdf", $language, $cycle_count > 1 ? "cycles.other" : "cycles.one", ["n" => $cycle_count]);
		$summary = $anonymous
			? res_t("pdf", $language, "mucus.subtitle_anonymous", ["days" => $days_text, "cycles" => $cycles_text])
			: res_t("pdf", $language, "mucus.subtitle", ["days" => $days_text, "cycles" => $cycles_text, "start" => date_human($first_date, $date_pattern), "end" => date_human($last_date, $date_pattern)]);
	}

	// one layout per cycle (a cycle starts a page): its columns are the ones its days use. The temperature scale is the export's.
	$readings = array_filter(array_column($rows, "temperature"), fn($t) => !is_null($t));
	$temperature = user_account_tracks_temperature($nfp_method) && !empty($readings);
	$scale = $temperature ? doc_mucus_temperature_scale($readings) : null;
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
		$page = count($pages) > 1 ? res_t("pdf", $language, "page", ["page" => $page_index + 1, "count" => count($pages)]) : "";
		doc_mucus_draw_title($pdf, $name, doc_mucus_cycle_pieces($cycles[$rows[$first]["group"]], $cycle_count, $language, $anonymous, $date_pattern), $page);
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
		doc_mucus_draw_footer($pdf, $legend, $summary);
	}

	return $pdf;
}

// A pause ends the cycle and the next one starts at its own first day: the days between are not part of any cycle, so the chart leaves them out
// (the pause day itself stays, as the last of its cycle).
function doc_mucus_without_paused_days(array $days): array {
	$paused = false;
	return array_values(array_filter($days, function ($day) use (&$paused) {
		if (!empty($day["cycleFirstDay"])) $paused = false;
		if ($paused) return false;
		$paused = !empty($day["observationPaused"]);
		return true;
	}));
}

// What each day shows. The peak (+1..+3 after it) and the counter (+1..+n) run over calendar days, gaps included, and start over at
// every cycle start. A day's "marks" are its boxes, side by side: [[box colour, text colour], text], a null text being the peak's triangle. "items" are the pieces a text is cut between: the keywords of a description list (each but the last with its comma),
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

		$marks = [];
		if ($day["isPeak"]) {
			$since_peak = 0;
			$marks[] = [DOC_MUCUS_PEAK_COLORS, null];
		}
		elseif (!is_null($since_peak) && $since_peak < 3) $marks[] = [DOC_MUCUS_PEAK_COLORS, "+" . ++$since_peak];
		else $since_peak = null;

		if (!empty($day["counterStart"])) $counter = [0, $day["counterStart"]];
		if (!is_null($counter) && $counter[0] < $counter[1]) $marks[] = [DOC_MUCUS_COUNTER_COLORS, "+" . ++$counter[0]];
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
			"marks" => $marks ?: null,
			"union" => $day["sexUnion"] ? ["heart"] : null,
			"items" => $items,
			"temperature" => $day["temperature"],
			"temperature_label" => is_null($day["temperature"]) ? '' : number_format($day["temperature"], 2, '.', '') . "°",
			"temperature_time" => doc_time_hhmm($day["temperatureTime"]),
		];
	}

	return $rows;
}

// The curve's scale: [lowest, highest, step], the ends on the grid of the step (DOC_MUCUS_CURVE_STEPS) and at least one step apart.
function doc_mucus_temperature_scale(array $readings): array {
	foreach (DOC_MUCUS_CURVE_STEPS as $step) {
		$low = floor(round(min($readings) / $step, 6)) * $step;
		$high = ceil(round(max($readings) / $step, 6)) * $step;
		if ($high - $low < $step / 2) [$low, $high] = [$low - $step, $high + $step];
		if (round(($high - $low) / $step) <= DOC_MUCUS_CURVE_MAX_LINES) break;
	}
	return [$low, $high, $step];
}

// The x of a temperature on the curve column.
function doc_mucus_curve_x(array $layout, float $temperature): float {
	$curve = $layout["columns"]["curve"];
	$share = ($temperature - $layout["temperature_min"]) / ($layout["temperature_max"] - $layout["temperature_min"]);
	return $curve["x"] + DOC_MUCUS_CURVE_PAD + $share * ($curve["w"] - 2 * DOC_MUCUS_CURVE_PAD);
}

// The temperatures of the curve's grid lines, lowest first.
function doc_mucus_curve_grid(array $layout): array {
	$count = (int) round(($layout["temperature_max"] - $layout["temperature_min"]) / $layout["temperature_step"]);
	return array_map(fn(int $i): float => $layout["temperature_min"] + $i * $layout["temperature_step"], range(0, $count));
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

// What each cycle of the chart is, by group of doc_mucus_cycles(): "n" (its number, null for the days before the first cycle start), the first and
// last date, "days" (a pause ends the cycle: its last day is the paused one) and "peak" (the cycle day of its last peak, or null).
function doc_mucus_cycle_summaries(array $rows): array {
	$summaries = [];
	$number = 0;
	foreach (doc_mucus_cycles($rows) as $group => $indexes) {
		$first = $rows[$indexes[0]]["day"];
		$summary = ["n" => $first["cycleFirstDay"] ? ++$number : null, "start" => $first["date"], "end" => $first["date"], "days" => 0, "peak" => null];
		$ended = false;
		foreach ($indexes as $index) {
			$day = $rows[$index]["day"];
			if ($ended) break;
			$summary["end"] = $day["date"];
			$summary["days"]++;
			if ($day["isPeak"]) $summary["peak"] = $day["cycleDay"];
			$ended = !empty($day["observationPaused"]);
		}
		$summaries[$group] = $summary;
	}
	return $summaries;
}

// The line under the name, in pieces: the cycle of the page, its number among the export's when there are several, its length, its dates, its
// peak. The first piece is drawn in a chip. No piece for the days before the first cycle start.
function doc_mucus_cycle_pieces(array $cycle, int $count, string $language, bool $anonymous, string $date_pattern): array {
	if (is_null($cycle["n"])) return [];
	$pieces = [];
	if ($count > 1) $pieces[] = res_t("pdf", $language, "mucus.cycle", ["n" => $cycle["n"], "count" => $count]);
	$pieces[] = res_t("pdf", $language, $cycle["days"] > 1 ? "days.other" : "days.one", ["n" => $cycle["days"]]);
	if (!$anonymous) {
		$pieces[] = res_t("pdf", $language, "mucus.cycle_dates", ["start" => date_human(new DateTime($cycle["start"]), $date_pattern), "end" => date_human(new DateTime($cycle["end"]), $date_pattern)]);
	}
	if (!is_null($cycle["peak"])) $pieces[] = res_t("pdf", $language, "mucus.cycle_peak", ["day" => $cycle["peak"]]);
	return $pieces;
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
		$w = $key === "marks"
			? max($min_w, doc_mucus_marks_width(max(array_map('count', array_filter(array_column($rows, "marks"))))))
			: max($min_w, $pdf->GetStringWidth($label) + 2 * $margin);
		$columns[$key] = ["label" => $label, "w" => $w, "align" => 'C'];
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

		[$layout["temperature_min"], $layout["temperature_max"], $layout["temperature_step"]] = $scale;
	}

	$x = DOC_PDF_MARGIN;
	foreach ($columns as $key => $column) {
		$layout["columns"][$key] = $column + ["x" => $x];
		$x += $column["w"];
	}

	return $layout;
}

// The width of a column that holds up to $boxes peak / counter boxes side by side.
function doc_mucus_marks_width(int $boxes): float {
	return $boxes * DOC_MUCUS_MARK_W + ($boxes + 1) * DOC_MUCUS_MARK_GAP;
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

// The top of a page, two lines that the logo spans on the right. First line: the name, and "mon<b>cycle</b>.app" on its baseline; second
// line: the cycle's pieces (the first in a chip, the others after it, joined) and the page number under the app's name. The app's name and
// logo are a link: the chart is shared with people who do not know the app. $name is UTF-8; $page is "" on a chart of one page.
function doc_mucus_draw_title(DocPdf $pdf, string $name, array $pieces, string $page): void {
	$left = DOC_PDF_MARGIN;
	$logo_x = $pdf->GetPageWidth() - DOC_PDF_MARGIN - DOC_MUCUS_LOGO_SIZE;
	$right = $logo_x - DOC_MUCUS_TITLE_GAP;
	$name_baseline = DOC_PDF_MARGIN + DOC_MUCUS_NAME_BASELINE;
	$cycle_baseline = DOC_PDF_MARGIN + DOC_MUCUS_CYCLE_BASELINE;

	// the app
	$runs = [["mucus.link", "mon", 0], ["mucus.link_bold", "cycle", 0], ["mucus.link", ".app", 0]];
	$link_w = $pdf->RunsWidth($runs);
	$pdf->TextOnBaseline($right - $link_w, $name_baseline, $runs);
	$pdf->Logo($logo_x, DOC_PDF_MARGIN + DOC_MUCUS_LOGO_TOP, DOC_MUCUS_LOGO_SIZE);
	$pdf->Link($right - $link_w, DOC_PDF_MARGIN, $link_w + DOC_MUCUS_TITLE_GAP + DOC_MUCUS_LOGO_SIZE, DOC_MUCUS_LOGO_TOP + DOC_MUCUS_LOGO_SIZE, DOC_APP_URL);

	$pdf->UseStyle('mucus.page');
	$page_w = $pdf->GetStringWidth($page);
	if ($page !== "") $pdf->Text($right - $page_w, DOC_PDF_MARGIN + DOC_MUCUS_PAGE_BASELINE, $page);

	$pdf->UseStyle('mucus.title');
	$pdf->Text($left, $name_baseline, $pdf->FitText($name, $right - $link_w - DOC_MUCUS_TITLE_GAP - $left));

	if (empty($pieces)) return;
	$room = $right - $page_w - DOC_MUCUS_TITLE_GAP - $left;

	$pdf->UseStyle('mucus.chip');
	$chip_text = $pdf->FitText(array_shift($pieces), $room - 2 * DOC_MUCUS_CHIP_PAD);
	$chip_w = $pdf->GetStringWidth($chip_text) + 2 * DOC_MUCUS_CHIP_PAD;
	$pdf->SetFillColor(...DOC_MUCUS_CHIP_FILL);
	$pdf->RoundedRect($left, $cycle_baseline - DOC_MUCUS_CHIP_RISE, $chip_w, DOC_MUCUS_CHIP_H, DOC_MUCUS_CHIP_H * DOC_MUCUS_CHIP_RADIUS);
	$pdf->Text($left + DOC_MUCUS_CHIP_PAD, $cycle_baseline, $chip_text);

	if (empty($pieces)) return;
	$x = $left + $chip_w + DOC_MUCUS_CHIP_PAD;
	$pdf->UseStyle('mucus.cycle');
	$pdf->Text($x, $cycle_baseline, $pdf->FitText(implode(DOC_MUCUS_CYCLE_JOINER, $pieces), $left + $room - $x));
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

	// the values of the curve's grid lines, every one or every few so that they do not touch
	if ($layout["temperature"]) {
		$curve = $layout["columns"]["curve"];
		$grid = doc_mucus_curve_grid($layout);
		$decimals = $layout["temperature_step"] < 1 ? 1 : 0;
		$labels = array_map(fn(float $t): string => number_format($t, $decimals, '.', ''), $grid);
		$pdf->UseStyle('mucus.scale');
		$room = doc_mucus_curve_x($layout, $grid[1]) - doc_mucus_curve_x($layout, $grid[0]);
		$every = (int) ceil((max(array_map([$pdf, 'GetStringWidth'], $labels)) + 1) / $room);
		foreach ($grid as $index => $t) {
			if ($index % $every !== 0) continue;
			$w = $pdf->GetStringWidth($labels[$index]);
			$x = min(max(doc_mucus_curve_x($layout, $t) - $w / 2, $curve["x"]), $curve["x"] + $curve["w"] - $w);
			$pdf->SetXY($x, $y);
			$pdf->Cell($w, DOC_MUCUS_HEAD_H, $labels[$index], 0, 0, 'C');
		}
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

	if (isset($columns["marks"]) && !is_null($row["marks"])) {
		$column = $columns["marks"];
		$x = $column["x"] + ($column["w"] - doc_mucus_marks_width(count($row["marks"])) + 2 * DOC_MUCUS_MARK_GAP) / 2;
		foreach ($row["marks"] as [$colors, $text]) {
			doc_mucus_draw_mark($pdf, $colors, $text, $x, $middle - DOC_MUCUS_MARK_H / 2);
			$x += DOC_MUCUS_MARK_W + DOC_MUCUS_MARK_GAP;
		}
	}
	if (isset($columns["union"]) && !is_null($row["union"])) {
		$pdf->Heart($columns["union"]["x"] + $columns["union"]["w"] / 2, $middle, 3.2, DOC_MUCUS_UNION_COLOR);
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

	// method 1: the curve's grid on every day, so that it runs down the page unbroken
	if ($layout["temperature"]) {
		$pdf->SetDrawColor(...DOC_MUCUS_GRID_COLOR);
		$pdf->SetLineWidth(DOC_MUCUS_BORDER_W);
		foreach (doc_mucus_curve_grid($layout) as $t) {
			$x = doc_mucus_curve_x($layout, $t);
			$pdf->Line($x, $y, $x, $y + $h);
		}
	}

	// the reading and its time, then its dot on the curve, joined to the day before's; a day without one breaks the curve. The day
	// before's dot is drawn again over the line, so that both ends of it are clean.
	if ($layout["temperature"] && !is_null($row["temperature"])) {
		$runs = [['mucus.temperature', $row["temperature_label"], 0]];
		if ($row["temperature_time"] !== '') $runs[] = ['mucus.temperature_time', $row["temperature_time"], 1];
		$pdf->TextRuns($columns["temperature"]["x"] + $margin, $middle - DOC_MUCUS_LINE_H / 2, DOC_MUCUS_LINE_H, $runs);

		$point = [doc_mucus_curve_x($layout, $row["temperature"]), $middle];
		if (!is_null($previous_point)) {
			$pdf->SetDrawColor(...DOC_MUCUS_TEMPERATURE_COLOR);
			$pdf->SetLineWidth(DOC_MUCUS_CURVE_LINE_W);
			$pdf->Line($previous_point[0], $previous_point[1], $point[0], $point[1]);
			doc_mucus_draw_dot($pdf, $previous_point);
		}
		doc_mucus_draw_dot($pdf, $point);
		$previous_point = $point;
	}
	else $previous_point = null;

	if (PDF_CERVICAL_MUCUS_BORDERS) doc_mucus_draw_rule($pdf, $layout, $y + $h);
}

// A reading's dot on the curve, in a white ring.
function doc_mucus_draw_dot(DocPdf $pdf, array $point): void {
	$pdf->SetFillColor(255, 255, 255);
	$pdf->Circle($point[0], $point[1], DOC_MUCUS_DOT_R + DOC_MUCUS_DOT_RING);
	$pdf->SetFillColor(...DOC_MUCUS_TEMPERATURE_COLOR);
	$pdf->Circle($point[0], $point[1], DOC_MUCUS_DOT_R);
}

// A peak or counter box: a rounded rectangle, with its text or, when $text is null, the peak's triangle, in $colors (box, text).
function doc_mucus_draw_mark(DocPdf $pdf, array $colors, ?string $text, float $x, float $y): void {
	[$fill, $ink] = $colors;
	$pdf->SetFillColor(...$fill);
	$pdf->RoundedRect($x, $y, DOC_MUCUS_MARK_W, DOC_MUCUS_MARK_H, DOC_MUCUS_MARK_H * DOC_MUCUS_MARK_RADIUS);
	if (is_null($text)) {
		$pdf->Triangle($x + DOC_MUCUS_MARK_W / 2, $y + DOC_MUCUS_MARK_H / 2, DOC_MUCUS_MARK_H * 0.6, $ink);
		return;
	}
	$pdf->UseStyle('mucus.mark', $ink);
	$pdf->SetXY($x, $y);
	$pdf->Cell(DOC_MUCUS_MARK_W, DOC_MUCUS_MARK_H, $text, 0, 0, 'C');
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

	// a chip: the pause is two rounded bars on a tinted, outlined square, not a text
	if ($kind === "paused") {
		$pdf->SetDrawColor(...DOC_MUCUS_PAUSE_OUTLINE);
		$pdf->SetLineWidth(0.15);
		$pdf->RoundedRect($x, $y, $size, $size, $size * DOC_MUCUS_STAMP_RADIUS, 'D');
		[$bar_w, $bar_h, $bar_gap] = array_map(fn($share) => $share * $size, DOC_MUCUS_PAUSE_BAR);
		$pdf->SetFillColor(...$glyph_color);
		$left = $x + ($size - 2 * $bar_w - $bar_gap) / 2;
		foreach ([$left, $left + $bar_w + $bar_gap] as $bar_x) $pdf->RoundedRect($bar_x, $y + ($size - $bar_h) / 2, $bar_w, $bar_h, $bar_w / 2);
		return;
	}
	if ($glyph !== '') {
		$pdf->UseStyle('mucus.stamp', $glyph_color);
		$pdf->SetFontSize($size * 1.45);
		$pdf->SetXY($x, $y);
		$pdf->Cell($size, $size, $glyph, 0, 0, 'C');
	}
	if ($baby) $pdf->Baby($x + $size * 0.1, $y + $size * 0.1, $size * 0.8, $fill, DOC_MUCUS_BABY_INK[$kind] ?? null);
}

// The bottom of the page: on the left what the stamps that are not self-explanatory mean, on the right what the whole export covers ($summary,
// may be "").
function doc_mucus_draw_footer(DocPdf $pdf, array $legend, string $summary): void {
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

	if ($summary === "") return;
	$right = $pdf->GetPageWidth() - DOC_PDF_MARGIN;
	$pdf->UseStyle('mucus.legend');
	$summary = $pdf->FitText($summary, $right - $x);
	$pdf->TextRuns($right - $pdf->GetStringWidth($summary), $y, $size, [['mucus.legend', $summary, 0]]);
}
