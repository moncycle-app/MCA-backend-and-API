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

// What both charts are drawn with: DocPdf, a tFPDF (FPDF with UTF-8) with their shared helpers. The text is UTF-8 all the way: the fonts
// are TrueType, embedded as a subset (only the glyphs a chart uses), so that any language of the app, and what a user typed, can be drawn.
// Noto Sans (res/fonts) for the mucus chart; DejaVu Sans Mono, which ships with tFPDF, for the FertilityCare grid, which has arrows.

require_once __DIR__ . "/../vendor/autoload.php";   // DocPdf extends tFPDF as this file loads

class DocPdf extends tFPDF {

	private array $width_cache = [];

	// $title and $author are UTF-8.
	public function __construct(string $orientation, string $size, string $title, string $author) {
		parent::__construct($orientation, 'mm', $size);
		$this->SetAutoPageBreak(false);   // the rows are measured first and the breaks decided from that, never in a MultiCell
		$this->SetTitle($title, true);
		$this->SetAuthor($author, true);
		$this->SetCreator("moncycle.app", true);
		foreach (DOC_PDF_FONTS as [$family, $style, $file]) $this->AddFont($family, $style, $file, true);
	}

	public function UseStyle(string $style, ?array $color = null): void {
		[$family, $font_style, $size, $style_color] = DOC_PDF_STYLES[$style];
		$this->SetFont($family, $font_style, $size);
		$this->SetTextColor(...($color ?? $style_color));
	}

	// the padding Cell() leaves before left-aligned text
	public function GetCellMargin(): float {
		return $this->cMargin;
	}

	// Runs of differently styled text side by side, on the baseline Cell() would use at ($x, $y) in the first run's style, without the
	// cell margin. $runs: [[style, text, space before it in mm], ...].
	public function TextRuns(float $x, float $y, float $h, array $runs): void {
		$this->UseStyle($runs[0][0]);
		$this->TextOnBaseline($x, $y + .5 * $h + .3 * $this->FontSize, $runs);
	}

	// The same runs on a given baseline: texts of different sizes that must sit on one line.
	public function TextOnBaseline(float $x, float $baseline, array $runs): void {
		foreach ($runs as [$style, $text, $gap]) {
			$this->UseStyle($style);
			$x += $gap;
			$this->Text($x, $baseline, $text);
			$x += $this->GetStringWidth($text);
		}
	}

	// The width of those runs, gaps included.
	public function RunsWidth(array $runs): float {
		$w = 0.0;
		foreach ($runs as [$style, $text, $gap]) {
			$this->UseStyle($style);
			$w += $gap + $this->GetStringWidth($text);
		}
		return $w;
	}

	// The width of $text in the current font, measured once: a chart measures the same keywords thousands of times.
	public function TextWidth(string $text): float {
		return $this->width_cache[$this->FontFamily . $this->FontStyle . $this->FontSizePt][$text] ??= $this->GetStringWidth($text);
	}

	// $txt cut short with an ellipsis when it is wider than Cell($w, ...) holds, in the current font.
	public function FitText(string $txt, float $w): string {
		$room = $w - 2 * $this->cMargin;
		if ($this->GetStringWidth($txt) <= $room) return $txt;

		$txt = mb_substr($txt, 0, $this->FittingChars($txt, $room, "…"));
		return rtrim($txt) . "…";
	}

	// How many characters of $txt, followed by $tail, fit in $room mm in the current font.
	private function FittingChars(string $txt, float $room, string $tail = ''): int {
		$low = 0;
		$high = mb_strlen($txt);
		while ($low < $high) {
			$mid = intdiv($low + $high + 1, 2);
			if ($this->GetStringWidth(mb_substr($txt, 0, $mid) . $tail) <= $room) $low = $mid;
			else $high = $mid - 1;
		}
		return $low;
	}

	// How many lines $widths (the widths of items that stay whole, such as keywords) take, laid side by side with $space between them in
	// lines of $room mm: the greedy fill of PackLines(). An item wider than a line by itself counts as the lines it would be broken in.
	public static function CountLines(array $widths, float $space, float $room): int {
		$lines = 1;
		$used = 0.0;
		foreach ($widths as $index => $width) {
			if ($width > $room) {
				$extra = intval(ceil($width / $room));
				$lines += ($index > 0 && $used > 0 ? 1 : 0) + $extra - 1;
				$used = fmod($width, $room);
				continue;
			}
			if ($used > 0 && $used + $space + $width > $room) {
				$lines++;
				$used = $width;
			}
			else $used += ($used > 0 ? $space : 0) + $width;
		}
		return $lines;
	}

	// $items (keywords, or the words of a comment) laid out in lines of at most $room mm in the current font, joined by a space; a line
	// breaks between two items, never inside one unless it is wider than a line. More than $max lines: the last one ends with an ellipsis.
	public function PackLines(array $items, float $room, int $max): array {
		$space = $this->TextWidth(" ");
		$lines = [];
		$line = '';
		$used = 0.0;
		foreach ($items as $item) {
			$width = $this->TextWidth($item);
			if ($width > $room) {
				if ($line !== '') $lines[] = $line;
				while (mb_strlen($item) > 0 && $this->GetStringWidth($item) > $room) {
					$cut = max(1, $this->FittingChars($item, $room));
					$lines[] = mb_substr($item, 0, $cut);
					$item = mb_substr($item, $cut);
				}
				[$line, $used] = [$item, $item === '' ? 0.0 : $this->TextWidth($item)];
				continue;
			}
			if ($line !== '' && $used + $space + $width > $room) {
				$lines[] = $line;
				[$line, $used] = [$item, $width];
			}
			else [$line, $used] = [$line === '' ? $item : $line . " " . $item, $used + ($line === '' ? 0 : $space) + $width];
		}
		if ($line !== '') $lines[] = $line;

		if (count($lines) > $max) {
			$lines = array_slice($lines, 0, $max);
			$lines[$max - 1] = rtrim(mb_substr($lines[$max - 1], 0, $this->FittingChars($lines[$max - 1], $room, "…"))) . "…";
		}
		return $lines;
	}

	// A filled polygon, [[x, y], ...] in mm, in the current fill colour.
	public function Polygon(array $points): void {
		$path = '';
		foreach ($points as $index => [$x, $y]) $path .= sprintf('%.2F %.2F %s ', $x * $this->k, ($this->h - $y) * $this->k, $index === 0 ? 'm' : 'l');
		$this->_out($path . 'h f');
	}

	// A heart, $size mm wide, centred on ($x, $y).
	public function Heart(float $x, float $y, float $size, array $color): void {
		$this->SetFillColor(...$color);
		$points = [];
		for ($i = 0; $i < 40; $i++) {
			$t = 2 * M_PI * $i / 40;
			$points[] = [
				$x + $size / 2 * pow(sin($t), 3),
				$y - $size / 32 * (13 * cos($t) - 5 * cos(2 * $t) - 2 * cos(3 * $t) - cos(4 * $t)) + $size * 0.05,
			];
		}
		$this->Polygon($points);
	}

	// A triangle pointing up, $size mm wide, centred on ($x, $y).
	public function Triangle(float $x, float $y, float $size, array $color): void {
		$this->SetFillColor(...$color);
		$this->Polygon([[$x, $y - $size * 0.45], [$x + $size / 2, $y + $size * 0.4], [$x - $size / 2, $y + $size * 0.4]]);
	}

	// The outline of a rectangle with rounded corners, as a PDF path (no painting operator).
	private function RoundedPath(float $x, float $y, float $w, float $h, float $r): string {
		$k = $this->k;
		$c = $r * 0.5523;   // control points of a quarter circle
		$p = fn(float $px, float $py): string => sprintf('%.2F %.2F', $px * $k, ($this->h - $py) * $k);
		return $p($x + $r, $y) . ' m ' . $p($x + $w - $r, $y) . ' l '
			. $p($x + $w - $r + $c, $y) . ' ' . $p($x + $w, $y + $r - $c) . ' ' . $p($x + $w, $y + $r) . ' c '
			. $p($x + $w, $y + $h - $r) . ' l '
			. $p($x + $w, $y + $h - $r + $c) . ' ' . $p($x + $w - $r + $c, $y + $h) . ' ' . $p($x + $w - $r, $y + $h) . ' c '
			. $p($x + $r, $y + $h) . ' l '
			. $p($x + $r - $c, $y + $h) . ' ' . $p($x, $y + $h - $r + $c) . ' ' . $p($x, $y + $h - $r) . ' c '
			. $p($x, $y + $r) . ' l '
			. $p($x, $y + $r - $c) . ' ' . $p($x + $r - $c, $y) . ' ' . $p($x + $r, $y) . ' c h';
	}

	// A rectangle with rounded corners of radius $r; $style: 'F' fill, 'D' outline, 'FD' both (the colours already set).
	public function RoundedRect(float $x, float $y, float $w, float $h, float $r, string $style = 'F'): void {
		$this->_out($this->RoundedPath($x, $y, $w, $h, $r) . ' ' . ['F' => 'f', 'D' => 'S', 'FD' => 'B'][$style]);
	}

	// A filled circle in the current fill colour.
	public function Circle(float $cx, float $cy, float $r): void {
		$this->RoundedRect($cx - $r, $cy - $r, 2 * $r, 2 * $r, $r);
	}

	// The baby of a stamp, in a $size mm square at ($x, $y): a round face with ears and a curl of hair. On a coloured stamp ($background: r, g, b)
	// the face is white, outlined, and its eyes and smile are drawn in $ink (r, g, b; the stamp's colour when null); on a white one, where white
	// alone would not show, the face is very light grey with a mid grey outline and mid grey eyes, smile and curl. It is drawn inside its own
	// graphics state (q ... Q), so that the caller's colours and line width are the same after it.
	public function Baby(float $x, float $y, float $size, array $background, ?array $ink = null): void {
		$on_white = min($background) > 230;
		$ink = $on_white ? [140, 140, 140] : ($ink ?? $background);
		$face = $on_white ? [240, 240, 240] : [255, 255, 255];
		$color = fn(array $rgb): string => vsprintf('%.3F %.3F %.3F rg %1$.3F %2$.3F %3$.3F RG', array_map(fn(int $c): float => $c / 255, $rgb));
		// a point of the icon, (0, 0) its top left corner and (1, 1) its bottom right
		$at = fn(float $u, float $v): string => sprintf('%.2F %.2F', ($x + $u * $size) * $this->k, ($this->h - $y - $v * $size) * $this->k);
		// a disc; 'f' fills it, 'B' fills it and strokes its outline
		$dot = fn(float $u, float $v, float $r, string $paint = 'f'): string
			=> $this->RoundedPath($x + ($u - $r) * $size, $y + ($v - $r) * $size, 2 * $r * $size, 2 * $r * $size, $r * $size) . ' ' . $paint;
		$width = fn(float $w): string => sprintf('%.2F w', $w * $size * $this->k);
		$curl = $at(0.5, 0.23) . ' m ' . $at(0.46, 0.13) . ' ' . $at(0.53, 0.05) . ' ' . $at(0.61, 0.07) . ' c '
			. $at(0.68, 0.09) . ' ' . $at(0.67, 0.18) . ' ' . $at(0.6, 0.17) . ' c S';

		$this->_out(implode("\n", [
			'q 1 J 1 j', $color([255, 255, 255]),
			$on_white ? '' : $width(0.075) . ' ' . $curl,        // white on a coloured stamp
			$width(0.05), $color($ink), vsprintf('%.3F %.3F %.3F rg', array_map(fn(int $c): float => $c / 255, $face)),   // $face fill, $ink outline
			// the ears go first: the face covers their inner half, outline included
			$dot(0.16, 0.6, 0.09, 'B'), $dot(0.84, 0.6, 0.09, 'B'),   // the ears
			$dot(0.5, 0.57, 0.35, 'B'),                          // the face
			$on_white ? $width(0.075) . ' ' . $curl : '',        // dark grey on a white stamp
			$color($ink),
			$dot(0.375, 0.55, 0.05), $dot(0.625, 0.55, 0.05),    // the eyes
			$width(0.06),                                        // the smile
			$at(0.39, 0.69) . ' m ' . $at(0.45, 0.76) . ' ' . $at(0.55, 0.76) . ' ' . $at(0.61, 0.69) . ' c S',
			'Q',
		]));
	}

	// The logo of the app: its four squares (red, yellow / green, white), $size mm wide, with rounded corners. The white one gets an outline,
	// as the page is white.
	public function Logo(float $x, float $y, float $size): void {
		$half = $size / 2;
		$path = $this->RoundedPath($x, $y, $size, $size, $size * DOC_LOGO_RADIUS);
		$this->_out('q ' . $path . ' W n');   // the squares are cut to the rounded shape
		foreach (DOC_LOGO_SQUARES as $index => $color) {
			$this->SetFillColor(...$color);
			$this->Rect($x + ($index % 2) * $half, $y + intdiv($index, 2) * $half, $half, $half, 'F');
		}
		$this->_out('Q');
		$this->_out($this->FillColor);   // the colour the page had before the cut
		$this->SetDrawColor(...DOC_LOGO_OUTLINE);
		$this->SetLineWidth(0.15);
		$this->_out($path . ' S');
	}
}
