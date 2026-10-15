<?php
/* moncycle.app
**
** licence Creative Commons CC BY-NC-SA
**
** https://www.moncycle.app
** https://github.com/moncycle-app/backend-api-web-app
*/

declare(strict_types=1);

// The front door of the period exports (api/export.php, script/cron.php): the days of a period, and the chart of a method.

require_once __DIR__ . "/data.php";
require_once __DIR__ . "/date.php";
require_once __DIR__ . "/day_format.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/doc_bill.php";
require_once __DIR__ . "/doc_fc.php";
require_once __DIR__ . "/user_account.php";

// The days of [$start_date, $end_date], oldest first, each day_format_to_json()'s with the NFP names of two fields (mucusNotObserved,
// comments: one string) and isGap false. A date with no row between two recorded days is a gap: the same keys, empty, isGap true;
// gaps never run past today. The period is exactly the one asked for (not widened to a cycle start, unlike the NFP export): cycleDay
// counts from the latest cycleFirstDay inside it, and is null before the first one. Two queries, whatever the length.
function doc_export_days($db, string $start_date, string $end_date, array $user_account): array {
	$no_user_account = intval($user_account["no_user_account"]);
	$rows = db_select_day_timelines_range($db, $start_date, date_next_day($end_date), $no_user_account);
	$descriptions = data_descriptions_by_day($db, $rows, $no_user_account);

	$today = date('Y-m-d');
	$cycle_start = null;
	$previous_date = null;
	$days = [];
	foreach ($rows as $row) {
		$date = $row["date_obs"];
		for ($gap = is_null($previous_date) ? $date : date_next_day($previous_date); $gap < $date && $gap <= $today; $gap = date_next_day($gap)) {
			$days[] = array_merge(doc_export_day(data_day(["date_obs" => $gap], $cycle_start)), ["isGap" => true]);
		}
		if (!empty($row["cycle_1st_day"])) $cycle_start = $date;
		$days[] = doc_export_day(data_day($row, $cycle_start, $descriptions[$row["no_day"]] ?? []));
		$previous_date = $date;
	}
	return $days;
}

// The rows an export read (its gaps are none), for its log.
function doc_export_rows_read(array $days): int {
	return count(array_filter($days, fn($day) => empty($day["isGap"])));
}

function doc_export_day(array $day): array {
	$day = day_format_to_json($day);
	$day["mucusNotObserved"] = $day["dayNotObserved"];
	$day["comments"] = $day["comment"];
	unset($day["dayNotObserved"], $day["comment"], $day["lastWriteClientUtc"], $day["lastWriteDb"]);   // sync metadata, not content
	$day["isGap"] = false;
	return $day;
}

function doc_export_pdf(array $days, int $nfp_method, string $name, bool $anonymous = false): DocPdf {
	if (user_account_method_name($nfp_method) === NFP_METHOD_FERTILITY_CARE) return doc_fc_pdf($days, $nfp_method, $name, $anonymous);
	return doc_bill_pdf($days, $nfp_method, $name, $anonymous);
}
