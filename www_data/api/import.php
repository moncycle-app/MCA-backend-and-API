<?php
/* moncycle.app
**
** licence Creative Commons CC BY-NC-SA
**
** https://www.moncycle.app
** https://github.com/moncycle-app/backend-api-web-app
*/

// An NFP file into the account, through the four stages of lib/nfp_import.php: the account gets the whole file or nothing. ?dryRun=true
// answers what the write would do (the days the account already has, the new labels) to decide on ?override (replace those days, or by
// default leave them), and a refused dry run answers everything the checks found: a diagnosis is the point of asking for one.

require_once __DIR__ . "/../config.php";
require_once __DIR__ . "/../lib/api.php";
require_once __DIR__ . "/../lib/input.php";
require_once __DIR__ . "/../lib/nfp_import.php";

[$db, $user_account] = api_start();

if (http_method() !== "POST") http_exit_method_not_allowed(["POST"]);
input_exit_unless_path_fits(input_path(), 0);

$issues = [];
$query = input_query(["dryRun", "override", "lastWriteClientUtc"], $issues);
$dry_run = input_query_bool($query, "dryRun", $issues);
$override = input_query_bool($query, "override", $issues);
$last_write_client_UTC = input_last_write($query, $issues, "query.");
input_exit_if_issues($issues);

http_require_json_content_type();
$content_length = isset($_SERVER['CONTENT_LENGTH']) ? intval($_SERVER['CONTENT_LENGTH']) : null;
$parsed = nfp_import_parse(file_get_contents('php://input'), $content_length);

if (!$parsed["ok"]) {
	$status = $parsed["code"] === "file_too_large" ? 413 : 400;
	http_error($status, $parsed["code"], $parsed["message"], $parsed["details"]);
}

$nfp_file = $parsed["file"];
$warnings = $parsed["warnings"];

$schema_errors = nfp_import_schema_errors($nfp_file, $warnings);
if (!empty($schema_errors)) {
	http_error(400, "invalid_nfp_structure", "The file does not match the NFP schema.", ["issues" => $schema_errors]);
}

$checked = nfp_import_build_plan($nfp_file, $user_account, $warnings);

if (!empty($checked["issues"])) {
	$details = [
		"issues" => array_slice($checked["issues"], 0, 50),
		"issueCount" => count($checked["issues"]),
	];
	// a dry run asked what is wrong: the dates the file holds twice (the usual reason, which splitting a cycle fixes), the warnings
	// and the field reports
	if ($dry_run) {
		$details["duplicatedDaysInFile"] = $checked["duplicates"];
		$details["warnings"] = $checked["warnings"];
		$details["mappedFields"] = $checked["mapped"];
		$details["ignoredFields"] = $checked["ignored"];
		$details["cyclesRead"] = $checked["cyclesRead"];
		$details["daysRead"] = $checked["daysRead"];
	}
	http_error(400, "inconsistent_nfp_data", "The file is valid JSON but its contents are inconsistent.", $details);
}

$report = [
	"dryRun" => $dry_run,
	"override" => $override,
	"schemaVersion" => nfp_format_get($nfp_file, "schemaVersion"),
	"sourceApp" => nfp_format_get(nfp_format_get($nfp_file, "fileInformation"), "sourceApp"),
	"cyclesRead" => $checked["cyclesRead"],
	"daysRead" => $checked["daysRead"],
	"daysToWrite" => count($checked["plan"]),
	"warnings" => $checked["warnings"],
	"mappedFields" => $checked["mapped"],
	"ignoredFields" => $checked["ignored"],
	"duplicatedDaysInFile" => $checked["duplicates"],   // empty here, and in every answer
];

if ($dry_run) {
	$previewed = nfp_import_preview_plan($db, intval($user_account["no_user_account"]), $checked["plan"], $override);

	$report["mappedFields"] = array_merge($report["mappedFields"], $previewed["narrowed"]);
	unset($previewed["narrowed"]);

	$report = array_merge($report, $previewed);
	log_event("data.import", nfp_import_log_fields($report));
	http_data(200, $report);
}

data_reactivate_account($db, $user_account);

$written = nfp_import_write_plan($db, intval($user_account["no_user_account"]), $checked["plan"], $override, $last_write_client_UTC, $written_days, $created_descriptions);

// a label already recorded under the other type: the writer's narrowing joins the ones found while checking
$report["mappedFields"] = array_merge($report["mappedFields"], $written["narrowed"]);
unset($written["narrowed"]);

$report = array_merge($report, $written);
$report["daysWritten"] = count($written["daysCreated"]) + count($written["daysOverwritten"]);

log_event("data.import", nfp_import_log_fields($report, $written_days, $created_descriptions));
http_data(200, $report);
