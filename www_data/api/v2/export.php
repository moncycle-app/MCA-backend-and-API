<?php
/* moncycle.app
**
** licence Creative Commons CC BY-NC-SA
**
** https://www.moncycle.app
** https://github.com/moncycle-app/backend-api-web-app
*/

declare(strict_types=1);

require_once __DIR__ . "/../../config.php";
require_once __DIR__ . "/../../lib/api.php";
require_once __DIR__ . "/../../lib/doc_csv.php";
require_once __DIR__ . "/../../lib/doc_export.php";
require_once __DIR__ . "/../../lib/input.php";
require_once __DIR__ . "/../../lib/nfp_export.php";

// GET /export/{format}: a file. Its failures are the JSON envelope like every other endpoint's.
[$db, $user_account] = api_start();

if (http_method() !== "GET") http_exit_method_not_allowed(["GET"]);

// THE FORMAT, THE PERIOD AND THE OPTIONS
$path = input_path();
input_exit_unless_path_fits($path, 1);
if ($path === []) http_exit_not_found("Say the format in the URL: /export/" . implode(", /export/", EXPORT_TYPES) . ".");

$issues = [];
$type = $path[0];
if (!in_array($type, EXPORT_TYPES, true)) {
	$issues[] = input_issue("path.format", "invalid_value", "The format in the URL must be one of: " . implode(", ", EXPORT_TYPES) . ".");
}

$query = input_query(["from", "to", "anonymous", "inline"], $issues);
input_check_no_body($issues);
foreach (["from", "to"] as $name) {
	if (!isset($query[$name])) $issues[] = input_issue("query.$name", "missing_field", "'$name' is required: the first and the last date of the period, both included, YYYY-MM-DD.");
}
$start_date = input_query_date($query, "from", $issues);
$end_date = input_query_date($query, "to", $issues);
if (!is_null($start_date) && !is_null($end_date) && $start_date > $end_date) $issues[] = input_issue("query.from", "invalid_range", "'from' must not be after 'to'.");

// 'anonymous' applies to pdf and nfp, 'inline' (shown in the browser) to nfp; another format accepts and ignores them
$anonymous = input_query_bool($query, "anonymous", $issues);
$inline = input_query_bool($query, "inline", $issues) && $type === EXPORT_TYPE_NFP;
input_exit_if_issues($issues);

$nfp_method = intval($user_account["nfp_method"]);

// csv and pdf read exactly the period; the nfp export reads its own, widened back to the cycle start
if ($type === EXPORT_TYPE_NFP) {
	$days = [];
	$has_data = !empty(db_querie_select_day_timeline_dates_frame($db, $start_date, $end_date, $user_account["no_user_account"]));
}
else {
	$days = doc_export_days($db, $start_date, $end_date, $user_account);
	$has_data = !empty($days);
}

if (!$has_data) {
	http_error(404, "no_data_in_period", "There is no observation between 'from' and 'to'.");
}

// the NFP file is made before the log line, for the rows it read
$rows_read = null;
$nfp_json = $type === EXPORT_TYPE_NFP ? nfp_export_json($db, $start_date, $end_date, $user_account, $anonymous, $rows_read) : null;

// only the length of the period and the number of days read are logged, not the dates
log_event("export.file", [
	"fmt" => $type, "anon" => $anonymous, "per" => (new DateTime($start_date))->diff(new DateTime($end_date))->days + 1,
	"n" => $rows_read ?? doc_export_rows_read($days),
]);

$filename = 'moncycle_app_' . date_human(new DateTime($start_date), '_');

if ($type === EXPORT_TYPE_CSV) {
	header("Content-Type: text/csv; charset=utf-8");
	header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
	$out = fopen('php://output', 'w');
	doc_csv_cycle($out, $days, $nfp_method);
	fclose($out);
}

elseif ($type === EXPORT_TYPE_PDF) {
	$pdf = doc_export_pdf($days, $nfp_method, $user_account["name_user_account"], $anonymous);
	// 'D' sends the Content-Type and an attachment Content-Disposition itself
	$pdf->Output('D', $filename . '.pdf', true);
}

else {
	header('Content-Type: application/json');
	if (!$inline) header('Content-Disposition: attachment; filename="' . $filename . '.nfp"');
	print($nfp_json);
}
