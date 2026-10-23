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
require_once __DIR__ . "/../../lib/db_querie.php";
require_once __DIR__ . "/../../lib/input.php";
require_once __DIR__ . "/../../lib/sec.php";
require_once __DIR__ . "/../../lib/sec_token.php";
require_once __DIR__ . "/../../lib/user_account.php";

[$db, $user_account] = api_start();

$path = input_path();
input_exit_unless_path_fits($path, 1);
$method = http_method();
$issues = [];

// DELETION OF THE ACCOUNT: POST /account/deletion, with the password, and the TOTP code when two-factor is on
if ($path === ["deletion"]) {
	if ($method !== "POST") http_exit_method_not_allowed(["POST"]);

	input_query([], $issues);
	$body = input_body(["password", "code"], $issues);
	$password = input_password($body, "password", $issues);
	$totp_active = $user_account["totp_state"] == TOTP_STATE_ACTIVE;
	$code = $totp_active ? input_code($body, $issues, false) : null;
	input_exit_if_issues($issues);

	sec_token_reauth_exit_if_locked($user_account);
	if (is_null(user_account_password_check($db, $user_account, $password))) {
		sec_token_reauth_failed($db, $user_account);
		log_event("account.refused", ["act" => "delete", "err" => "invalid_password"]);
		http_error(401, "invalid_password", "Incorrect password.");
	}
	if ($totp_active && !sec_totp_code_valid($user_account, $code)) {
		if (!is_null($code)) sec_token_reauth_failed($db, $user_account);   // a code asked for and not sent is no guess
		log_event("account.refused", ["act" => "delete", "err" => is_null($code) ? "totp_required" : "totp_invalid"]);
		if (is_null($code)) http_error(401, "totp_required", "A TOTP code is required.");
		http_error(401, "totp_invalid", "The TOTP code is incorrect.");
	}

	data_delete_account($db, $user_account, "user_request");
	sec_token_clear_cookie();

	http_no_content();
}

if ($path !== []) http_exit_not_found();

// THE ACCOUNT
if ($method === "GET") {
	input_exit_unless_none();

	$account = user_account_to_json($user_account, db_querie_select_day_timeline_oldest_date($db, $user_account["no_user_account"]));
	// a client that keeps the account sends back the ETag it was given and is told when nothing changed
	$etag = 'W/"' . md5((string) json_encode($account)) . '"';
	header("ETag: $etag");
	if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? "") === $etag) {
		http_response_code(304);
		exit;
	}
	http_data(200, $account);
}

// UPDATE OF THE ACCOUNT, partial (what is not sent is kept): its settings, and what wants the password again (loginEmail, newPassword, notificationEmails)
if ($method === "POST") {
	input_query([], $issues);
	$body = input_body(ACCOUNT_UPDATE_FIELDS, $issues, [], ACCOUNT_READ_ONLY_FIELDS);

	$outcome = user_account_update($db, $user_account, $body, $issues);
	if (!isset($outcome["data"])) http_error($outcome["status"], $outcome["code"], $outcome["message"]);

	http_data($outcome["status"], $outcome["data"], $outcome["meta"]);
}

http_exit_method_not_allowed(["GET", "POST"]);
