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

// What the server says of itself (GET /api/v2/server_info), and the check that the database is at the version of this code. The version of
// the app (APP_VERSION) is also the schema's: the row `schema_version` of key_value must say it. The contract's version is the v2 of the URL.

require_once __DIR__ . "/sec_captcha.php";
require_once __DIR__ . "/sec.php";
require_once __DIR__ . "/db_querie.php";
require_once __DIR__ . "/http.php";
require_once __DIR__ . "/upgrade.php";

// A 503 when the database is not at the schema version of APP_VERSION, after the maintenance exit (the migrations run in maintenance).
function server_exit_if_schema_mismatch($db): void {
	$value = db_querie_select_key_value($db, SCHEMA_VERSION_KEY);
	$version = $value === false || is_null($value) ? null : intval($value);
	$needed = upgrade_schema_version();
	if ($version === $needed) return;
	header('Content-Type: application/json');
	http_error(503, "schema_mismatch", "The database is not at the version this server needs (it has " . ($version ?? "none") . ", the server needs " . $needed . "). Run the migrations, then start the server again.");
}

// The address of the news file (NEWS_URL): https, or http on this machine for a dev server. Anything else is no news.
function server_news_url(): ?string {
	return preg_match('#^(https://|http://(localhost|127\.0\.0\.1)(:[0-9]+)?/)#', NEWS_URL) === 1 ? NEWS_URL : null;
}

// What a client needs before it signs in, from the settings alone: no database, so it answers in maintenance too. The version told is the
// app's (it changes with every release, so a client can tell the server was updated); it promises nothing about the contract.
function server_info(): array {
	return [
		"app" => APP_NAME,
		"appVersion" => APP_VERSION,
		"maintenanceMode" => MAINTENANCE_MODE,
		"registrationEnabled" => REGISTRATION_ENABLED,
		"demoEnabled" => DEMO_ENABLED,
		"captchaAvailable" => sec_captcha_ready(),
		"newsUrl" => server_news_url(),
		"passwordPolicyRegex" => sec_password_policy_regex(),
		"termsOfServiceUrl" => str_starts_with(TERMS_OF_SERVICE_URL, "https://") ? TERMS_OF_SERVICE_URL : null,
		"limits" => [
			"passwordMaxChars" => PASSWORD_MAX_CHARS,
			"accountNicknameMaxChars" => ACCOUNT_LIMIT_NICKNAME_CHARS,
			"registerCommentMaxChars" => ACCOUNT_LIMIT_REGISTER_COMMENT_CHARS,
			"notificationEmailsMax" => ACCOUNT_NOTIFICATION_EMAILS_MAX,
			"birthYearMaxAge" => ACCOUNT_BIRTH_YEAR_MAX_AGE,
			"tokenNameMaxChars" => AUTH_TOKEN_LIMIT_NAME_CHARS,
			"tokenExpiresInDaysMin" => AUTH_TOKEN_EXPIRES_DAYS_MIN,
			"tokenExpiresInDaysMax" => AUTH_TOKEN_EXPIRES_DAYS_MAX,
			"dayCommentMaxChars" => DAY_LIMIT_COMMENT_CHARS,
			"descriptionNameMaxChars" => DAY_LIMIT_DESCRIPTION_CHARS,
			"counterStartMax" => DAY_LIMIT_COUNTER_START,
			"temperatureMin" => DAY_TEMPERATURE_STORABLE_MIN,
			"temperatureMax" => DAY_TEMPERATURE_STORABLE_MAX,
			"queryDaysMax" => DAY_LIMIT_QUERY_DAYS,
			"requestBodyMaxBytes" => REQUEST_BODY_MAX_BYTES,
		],
	];
}
