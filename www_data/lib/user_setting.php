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

declare(strict_types=1);

// The settings of an account that it does not need to exist: the table user_setting (constants.php, "USER SETTINGS"). One row per
// account and name, a number in value_int and a text in value_str; no row means the default. Three kinds live there: the optional
// settings of the app (USER_SETTINGS, checked here before they reach the table), the two-factor state (written by api/v3/totp.php
// only) and the keys that apps of third parties keep for their user (customSettings, under USER_SETTING_CUSTOM_PREFIX).

require_once __DIR__ . "/db_ops.php";
require_once __DIR__ . "/db_querie.php";
require_once __DIR__ . "/input.php";

// ---------------------------------------------------------------------------
// Reading
// ---------------------------------------------------------------------------

// Every row of the account: name => ["int" => ?int, "str" => ?string]. Two-factor secrets included: it never reaches a client as is.
function user_setting_load($db, int $no_user_account): array {
	$rows = [];
	foreach (db_querie_select_user_settings($db, $no_user_account) as $row) {
		$rows[$row["name"]] = ["int" => is_null($row["value_int"]) ? null : intval($row["value_int"]), "str" => $row["value_str"]];
	}
	return $rows;
}

// What the setting is when no row says: the date format follows the language of the account.
function user_setting_default(string $json_name, string $language): bool|string {
	if ($json_name === "dateFormat") return USER_SETTING_DATE_FORMAT_BY_LANGUAGE[$language] ?? USER_SETTING_DATE_FORMAT_BY_LANGUAGE[ACCOUNT_DEFAULT_LANGUAGE];
	return USER_SETTINGS[$json_name][2];
}

// The value the app uses: the row's, or the default when there is none (or when the row holds what the setting no longer takes).
function user_setting_value(array $rows, string $json_name, string $language): bool|string {
	[$name, $type, , $codes] = USER_SETTINGS[$json_name];
	$row = $rows[$name] ?? null;
	if (is_null($row)) return user_setting_default($json_name, $language);

	if ($type === USER_SETTING_TYPE_BOOL) return is_null($row["int"]) ? user_setting_default($json_name, $language) : $row["int"] === 1;
	if ($type === USER_SETTING_TYPE_CHOICE) return in_array($row["str"], $codes, true) ? $row["str"] : user_setting_default($json_name, $language);
	return $row["str"] ?? user_setting_default($json_name, $language);
}

// The optional settings as GET /api/v3/account tells them: each with the value in force, whether the account set it, and the default.
function user_setting_to_json(array $rows, string $language): array {
	$json = [];
	foreach (USER_SETTINGS as $json_name => [$name]) {
		$json[$json_name] = [
			"value" => user_setting_value($rows, $json_name, $language),
			"isSet" => isset($rows[$name]),
			"default" => user_setting_default($json_name, $language),
		];
	}
	return $json;
}

// The keys of third parties: key => int or string, in the order of the keys.
function user_setting_custom_to_json(array $rows): array {
	$custom = [];
	foreach ($rows as $name => $row) {
		if (!str_starts_with($name, USER_SETTING_CUSTOM_PREFIX)) continue;
		$custom[substr($name, strlen(USER_SETTING_CUSTOM_PREFIX))] = $row["int"] ?? $row["str"] ?? "";
	}
	ksort($custom);
	return $custom;
}

// How a server-made document shows what is stored: the order of day and month of the compact date (the PDF) and the temperature unit
// of the account.
function user_setting_display(array $rows, string $language): array {
	return [
		"date_order" => user_setting_value($rows, "dateOrder", $language),
		"temperature_unit" => user_setting_value($rows, "temperatureUnit", $language),
	];
}

// ---------------------------------------------------------------------------
// Checking what a client sends
// ---------------------------------------------------------------------------

// The optional settings of a POST body, except the kinds another function checks (an address list wants the password). A value is
// checked at once; null puts the setting back to its default. Answers ["write" => [name => ["int", "str"]], "reset" => [name],
// "changed" => [json names]]: only what differs from the rows given, so that a setting sent as it already is writes nothing.
function user_setting_check_body(array $body, array $rows, string $language, array &$issues): array {
	$check = ["write" => [], "reset" => [], "changed" => []];

	foreach (USER_SETTINGS as $json_name => [$name, $type, , $codes]) {
		if ($type === USER_SETTING_TYPE_EMAILS || !array_key_exists($json_name, $body)) continue;
		$sent = $body[$json_name];

		if (is_null($sent)) {
			if (isset($rows[$name])) [$check["reset"][], $check["changed"][]] = [$name, $json_name];
			continue;
		}

		$write = null;
		if ($type === USER_SETTING_TYPE_BOOL) {
			if (is_bool($sent)) $write = ["int" => $sent ? 1 : 0, "str" => null];
			else input_wrong_type($issues, $json_name, $sent, "true or false");
		}
		elseif (!is_string($sent)) input_wrong_type($issues, $json_name, $sent, "a string (" . implode(", ", $codes) . ")");
		elseif (!in_array($sent, $codes, true)) $issues[] = input_issue($json_name, "invalid_value", "'$json_name' must be one of: " . implode(", ", $codes) . ".");
		else $write = ["int" => null, "str" => $sent];

		if (is_null($write) || ($rows[$name] ?? null) === $write) continue;
		[$check["write"][$name], $check["changed"][]] = [$write, $json_name];
	}

	return $check;
}

// "customSettings" of a POST body: key => int, string, or null (the key is deleted). Same answer as user_setting_check_body(), the
// names being the keys behind USER_SETTING_CUSTOM_PREFIX and "changed" the keys as sent, prefixed with "customSettings.".
function user_setting_check_custom(mixed $sent, array $rows, array &$issues): array {
	$check = ["write" => [], "reset" => [], "changed" => []];
	if (is_null($sent)) return $check;
	if (!is_array($sent) || (!empty($sent) && array_is_list($sent))) {
		input_wrong_type($issues, "customSettings", $sent, "an object (key: a whole number or a text, or null to delete the key)");
		return $check;
	}

	$existing = count(array_filter(array_keys($rows), fn($name) => str_starts_with($name, USER_SETTING_CUSTOM_PREFIX)));
	foreach ($sent as $key => $value) {
		$key = (string) $key;
		$field = "customSettings.$key";
		$name = USER_SETTING_CUSTOM_PREFIX . $key;

		if (preg_match(USER_SETTING_CUSTOM_KEY_REGEX, $key) !== 1) {
			$issues[] = input_issue($field, "invalid_value", "A key of 'customSettings' is 1 to " . USER_SETTING_CUSTOM_KEY_MAX_CHARS . " characters: lower case letters, digits, '_', '.' and '-', starting with a letter or a digit.");
			continue;
		}
		if (is_null($value)) {
			if (isset($rows[$name])) [$check["reset"][], $check["changed"][]] = [$name, $field];
			continue;
		}

		if (is_int($value)) {
			if ($value < USER_SETTING_INT_MIN || $value > USER_SETTING_INT_MAX) {
				$issues[] = input_issue($field, "out_of_range", "'$field' must be from " . USER_SETTING_INT_MIN . " to " . USER_SETTING_INT_MAX . ".");
				continue;
			}
			$write = ["int" => $value, "str" => null];
		}
		elseif (is_string($value)) {
			if (mb_strlen($value) > USER_SETTING_CUSTOM_VALUE_MAX_CHARS) {
				$issues[] = input_issue($field, "too_long", "'$field' must be at most " . USER_SETTING_CUSTOM_VALUE_MAX_CHARS . " characters.");
				continue;
			}
			$write = ["int" => null, "str" => $value];
		}
		else {
			input_wrong_type($issues, $field, $value, "a whole number or a text");
			continue;
		}

		if (($rows[$name] ?? null) === $write) continue;
		[$check["write"][$name], $check["changed"][]] = [$write, $field];
	}

	$created = count(array_filter(array_keys($check["write"]), fn($name) => !isset($rows[$name])));
	if ($existing + $created - count($check["reset"]) > USER_SETTING_CUSTOM_MAX) {
		$issues[] = input_issue("customSettings", "too_many", "An account keeps at most " . USER_SETTING_CUSTOM_MAX . " keys in 'customSettings'.");
	}
	return $check;
}

// ---------------------------------------------------------------------------
// Writing
// ---------------------------------------------------------------------------

// What the checks answered, in the caller's transaction (the account is locked there).
function user_setting_save($db, int $no_user_account, array $check): void {
	foreach ($check["write"] as $name => $value) db_querie_upsert_user_setting($db, $no_user_account, $name, $value["int"], $value["str"]);
	foreach ($check["reset"] as $name) db_querie_delete_user_setting($db, $no_user_account, $name);
}

function user_setting_set_totp($db, int $no_user_account, int $state, ?string $secret): void {
	db_ops_transaction($db, function () use ($db, $no_user_account, $state, $secret) {
		db_querie_upsert_user_setting($db, $no_user_account, USER_SETTING_TOTP_STATE, $state, null);
		if (is_null($secret)) db_querie_delete_user_setting($db, $no_user_account, USER_SETTING_TOTP_SECRET);
		else db_querie_upsert_user_setting($db, $no_user_account, USER_SETTING_TOTP_SECRET, null, $secret);
	});
}
