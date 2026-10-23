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

require_once __DIR__ . "/../../config.php";
require_once __DIR__ . "/../../lib/api.php";
require_once __DIR__ . "/../../lib/data.php";
require_once __DIR__ . "/../../lib/day_format.php";
require_once __DIR__ . "/../../lib/input.php";

[$db, $user_account] = api_start();
$no_user_account = $user_account["no_user_account"];

// /description lists and creates; /description/{id} renames, retypes and deletes
$path = input_path();
input_exit_unless_path_fits($path, 1);
$method = http_method();
$allowed = $path === [] ? ["GET", "POST"] : ["POST", "DELETE"];
if (!in_array($method, $allowed, true)) http_exit_method_not_allowed($allowed);

$issues = [];
$id = $path === [] ? null : input_path_id($path[0], $issues);

// LISTING ALL DESCRIPTIONS
if ($method === "GET") {
	input_exit_unless_none($issues);

	$descriptions = array_map('data_description_to_json', db_querie_select_description_with_count($db, $no_user_account));
	log_note(["n" => count($descriptions)]);
	http_data(200, $descriptions);
}

// DELETION OF A DESCRIPTION
elseif ($method === "DELETE") {
	input_exit_unless_none($issues);

	if (is_null(db_querie_select_description($db, $id, $no_user_account))) {
		http_error(404, "not_found", "No description of this account has this id.");
	}

	data_delete_description($db, $no_user_account, $id);

	http_no_content();
}

// CREATION (POST /description) OR MODIFICATION (POST /description/{id}, partial: what is not sent is kept) OF A DESCRIPTION
else {
	input_query([], $issues);
	$body = input_body(["name", "type", "lastWriteClientUtc"], $issues);
	$creates = $path === [];

	$name = array_key_exists("name", $body) ? day_format_description_text($body["name"], "name", DAY_LIMIT_DESCRIPTION_CHARS, $issues) : null;
	if ($name === "") $issues[] = input_issue("name", "invalid_value", "'name' must not be empty.");
	elseif ($creates) input_require($body, "name", $issues);

	$type_name = input_choice($body, "type", DESCRIPTION_TYPE_NAMES, $issues, $creates);
	$type = is_null($type_name) ? null : array_search($type_name, DESCRIPTION_TYPE_NAMES, true);
	if (!$creates && !array_key_exists("name", $body) && !array_key_exists("type", $body)) {
		$issues[] = input_issue("body", "missing_field", "Send the 'name' or the 'type' to change (or both).");
	}

	$last_write_client_UTC = input_last_write($body, $issues);
	input_exit_if_issues($issues);

	$saved = data_save_description($db, $no_user_account, $id, $name, $type, $last_write_client_UTC);
	if ($saved["outcome"] === "not_found") http_error(404, "not_found", "No description of this account has this id.");
	if ($saved["outcome"] === "duplicate_name") http_error(409, "duplicate_name", "A description of this account already has this name.");

	$description = $saved["description"];
	if ($creates) http_location("description/" . $description["no_description"]);
	http_data($creates ? 201 : 200, [
		"id" => $description["no_description"],
		"name" => $description["name"],
		"type" => DESCRIPTION_TYPE_NAMES[$description["type"]],
		"lastWriteClientUtc" => http_iso8601($last_write_client_UTC),
	]);
}
