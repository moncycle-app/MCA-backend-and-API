<?php
/* moncycle.app
**
** licence Creative Commons CC BY-NC-SA
**
** https://www.moncycle.app
** https://github.com/moncycle-app/backend-api-web-app
*/

/*
** Imports an NFP file into the logged-in account.
**
** The four stages of lib/nfp_import.php run in order and nothing is written until all the
** checking ones have passed, so the account is either updated with the whole file or not
** touched at all:
**
**   1. nfp_import_parse()        size, encoding, JSON, schema version
**   2. nfp_import_schema_errors() structure and types
**   3. nfp_import_build_plan()   calendar, ranges, lengths, cycle overlap -> the write plan
**   4. nfp_import_write_plan()   inside one transaction
**
** ?dryRun=true answers for stage 4 without running it: nfp_import_preview_plan() reads the account
** and reports what the write would have done -- which days of the file the account already
** holds, and which free-text labels are new -- so the user can see what is at stake, and in
** particular decide on 'override', before anything is written. A dry run that is refused also
** answers with everything the checks found, not only the first thing that rejects the file,
** because a diagnosis is the whole point of asking for one.
**
** ?override=<true|false> decides what happens to a date the account already has data on: false (the
** default) leaves it alone and reports it, true replaces it.
*/

require_once "../config.php";
require_once "../lib/api.php";
require_once "../lib/input.php";
require_once "../lib/nfp_import.php";

[$db, $user_account] = api_start();

if (http_method() !== "POST") http_exit_method_not_allowed(["POST"]);
input_exit_unless_path_fits(input_path(), 0);

// ---------------------------------------------------------------------------
// Parameters
// ---------------------------------------------------------------------------

$issues = [];
$query = input_query(["dryRun", "override", "lastWriteClientUtc"], $issues);
$dry_run = input_query_bool($query, "dryRun", $issues);
$override = input_query_bool($query, "override", $issues);
$last_write_client_UTC = http_client_timestamp(input_timestamp($query["lastWriteClientUtc"] ?? null, "query.lastWriteClientUtc", $issues));
input_exit_if_issues($issues);

// ---------------------------------------------------------------------------
// Stage 1 -- the body
// ---------------------------------------------------------------------------

http_require_json_content_type();
$content_length = isset($_SERVER['CONTENT_LENGTH']) ? intval($_SERVER['CONTENT_LENGTH']) : null;
$parsed = nfp_import_parse(file_get_contents('php://input'), $content_length);

if (!$parsed["ok"]) {
	$status = $parsed["code"] === "file_too_large" ? 413 : 400;
	http_error($status, $parsed["code"], $parsed["message"], $parsed["details"]);
}

$nfp_file = $parsed["file"];
$warnings = $parsed["warnings"];

// ---------------------------------------------------------------------------
// Stage 2 -- structure and types
// ---------------------------------------------------------------------------

$schema_errors = nfp_import_schema_errors($nfp_file, $warnings);
if (!empty($schema_errors)) {
	http_error(400, "invalid_nfp_structure", "The file does not match the NFP schema.", ["issues" => $schema_errors]);
}

// ---------------------------------------------------------------------------
// Stage 3 -- semantics and consistency
// ---------------------------------------------------------------------------

$checked = nfp_import_build_plan($nfp_file, $user_account, $warnings);

if (!empty($checked["issues"])) {
	$details = [
		"issues" => array_slice($checked["issues"], 0, 50),
		"issueCount" => count($checked["issues"]),
	];
	// On a dry run the caller asked what is wrong with the file, so the answer carries
	// everything stage 3 found: the dates the file holds twice as a list of their own (the
	// commonest reason a file is refused, and the one a user can act on by splitting a cycle),
	// and the warnings and field reports, which a bare list of issues would hide.
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
	// always empty at this point -- a file holding a date twice does not get here -- and
	// reported anyway so the field exists in every answer this endpoint gives
	"duplicatedDaysInFile" => $checked["duplicates"],
];

if ($dry_run) {
	$previewed = nfp_import_preview_plan($db, intval($user_account["no_user_account"]), $checked["plan"], $override);

	// same merge as the real run below, because the preview answers in the writer's shape
	$report["mappedFields"] = array_merge($report["mappedFields"], $previewed["narrowed"]);
	unset($previewed["narrowed"]);

	$report = array_merge($report, $previewed);
	log_event("data.import", nfp_import_log_fields($report));
	http_data(200, $report);
}

// ---------------------------------------------------------------------------
// Stage 4 -- the write
// ---------------------------------------------------------------------------

// an import counts as activity, same as a POST to /api/day
data_reactivate_account($db, $user_account);

$written = nfp_import_write_plan($db, intval($user_account["no_user_account"]), $checked["plan"], $override, $last_write_client_UTC, $written_days);

// the writer finds one kind of narrowing of its own (a label already recorded under the other
// type), so its list joins the ones found while checking
$report["mappedFields"] = array_merge($report["mappedFields"], $written["narrowed"]);
unset($written["narrowed"]);

$report = array_merge($report, $written);
$report["daysWritten"] = count($written["daysCreated"]) + count($written["daysOverwritten"]);

log_event("data.import", nfp_import_log_fields($report, $written_days));
http_data(200, $report);
