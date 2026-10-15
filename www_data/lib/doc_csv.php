<?php
/* moncycle.app
**
** licence Creative Commons CC BY-NC-SA
**
** https://www.moncycle.app
** https://github.com/moncycle-app/backend-api-web-app
*/

declare(strict_types=1);

// The CSV of a period, and the raw data export of an account (api/v2/all_of_my_data_plz.php). The most compatible shape for text editors,
// Excel (FR and EN), Google Sheets and LibreOffice: UTF-8 with a BOM, CSV_SEP between fields, RFC 4180 quoting, CRLF line ends, and as
// many fields on every row as in the header.

require_once __DIR__ . "/data.php";
require_once __DIR__ . "/day_format.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/doc.php";
require_once __DIR__ . "/user_account.php";

// Named after the NFP fields, or the API's day where NFP has none (date, cycleDay, cycleFirstDay).
function doc_csv_columns(int $nfp_method): array {
	$temperature = user_account_tracks_temperature($nfp_method) ? ["temperature", "temperatureTime"] : [];

	if (user_account_method_name($nfp_method) === NFP_METHOD_FERTILITY_CARE) {
		return ["date", "cycleDay", "cycleFirstDay", "mucusNotObserved", "stampColor", "stampBaby",
			"codifiedBleedingObservation", "codifiedMucusSensation", "codifiedMucusObservation",
			"codifiedNumberObservations", "codifiedPainObservations", "codifiedArrow",
			...$temperature, "isPeak", "sexUnion", "booleanPregnancyDetected", "comments"];
	}

	return ["date", "cycleDay", "cycleFirstDay", "mucusNotObserved", "stampColor", "stampBaby",
		"freeMucusSensation", "freeMucusObservation",
		...$temperature, "isPeak", "counterStart", "sexUnion", "booleanPregnancyDetected", "comments"];
}

function doc_csv_cycle($out, array $days, int $nfp_method): void {
	$columns = doc_csv_columns($nfp_method);
	fwrite($out, "\xEF\xBB\xBF");
	doc_csv_row($out, $columns);
	foreach ($days as $day) {
		$day = doc_display_day($day);
		doc_csv_row($out, array_map(fn($column) => doc_csv_value($day, $column), $columns));
	}
}

// The empty escape character is RFC 4180 (a quote is escaped by doubling it), and PHP 8.4 deprecates relying on the default one.
function doc_csv_row($out, array $fields): void {
	fputcsv($out, $fields, CSV_SEP, '"', '', "\r\n");
}

// A stored table for the raw data export: the column names, each followed by the separator, the rows, a blank line; nothing for no row.
// The empty escape character, as doc_csv_row(): with PHP's default "\\" a value holding \" would leave its cell open for a reader that
// follows RFC 4180, and what follows would start a cell of its own, a formula.
function doc_csv_dump($out, array $rows): void {
	if (empty($rows)) return;
	foreach (array_keys($rows[0]) as $column) fputs($out, $column . CSV_SEP);
	fputs($out, PHP_EOL);
	foreach ($rows as $row) fputcsv($out, array_map(fn($cell) => is_string($cell) ? doc_csv_free_text($cell) : $cell, $row), CSV_SEP, '"', '');
	fputs($out, PHP_EOL);
}

// Every day of the account as stored, with its sensation and observation descriptions as two columns after the stamp.
function doc_csv_raw_days($db, int $no_user_account): array {
	$days = db_select_all_day_timeline($db, $no_user_account);
	$descriptions = data_descriptions_by_day($db, $days, $no_user_account);

	$rows = [];
	foreach ($days as $day) {
		$names = [DESCRIPTION_TYPE_SENSATION => [], DESCRIPTION_TYPE_OBSERVATION => []];
		foreach ($descriptions[$day["no_day"]] ?? [] as $description) $names[intval($description["type"])][] = $description["name"];

		$row = [];
		foreach ($day as $column => $value) {
			$row[$column] = $value;
			if ($column !== "stamp") continue;
			$row["sensation"] = implode(DOC_CSV_LIST_JOINER, $names[DESCRIPTION_TYPE_SENSATION]);
			$row["observation"] = implode(DOC_CSV_LIST_JOINER, $names[DESCRIPTION_TYPE_OBSERVATION]);
		}
		$rows[] = $row;
	}
	return $rows;
}

// Booleans "1" or empty, lists joined with DOC_CSV_LIST_JOINER, ISO dates, HH:MM times, and a decimal comma for the temperature when
// the separator is ";" (the French spreadsheets, where "," cannot separate). A gap only has its date and its day of the cycle.
function doc_csv_value(array $day, string $column): string {
	if ($day["isGap"] && $column !== "date" && $column !== "cycleDay") return '';

	$value = $day[$column] ?? null;
	if (is_null($value)) return '';   // not empty(): "0" is a FertilityCare code (dryness)

	return match ($column) {
		"freeMucusSensation", "freeMucusObservation" =>
			doc_csv_free_text(implode(DOC_CSV_LIST_JOINER, array_map('day_format_clean_text', $value))),
		"comments" => doc_csv_free_text(day_format_clean_text($value)),
		"temperature" => number_format($value, 2, CSV_SEP === ';' ? ',' : '.', ''),
		"temperatureTime" => doc_time_hhmm($value),
		default => is_bool($value) ? ($value ? '1' : '') : (string) $value,
	};
}

// A spreadsheet runs a cell that starts with one of these as a formula: free text that does gets a leading apostrophe (OWASP's advice
// against CSV injection), which some readers show.
function doc_csv_free_text(string $text): string {
	if ($text !== '' && in_array($text[0], ['=', '+', '-', '@', "\t", "\r"], true)) return "'" . $text;
	return $text;
}
