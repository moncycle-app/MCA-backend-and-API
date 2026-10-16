<?php
/* moncycle.app
**
** licence Creative Commons CC BY-NC-SA
**
** https://www.moncycle.app
** https://github.com/moncycle-app/backend-api-web-app
*/

declare(strict_types=1);

// The account: its method, the sign-up and the password recovery, GET and POST /api/v2/account.

require_once __DIR__ . "/day_format.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/email_token.php";
require_once __DIR__ . "/http.php";
require_once __DIR__ . "/input.php";
require_once __DIR__ . "/log.php";
require_once __DIR__ . "/mail.php";
require_once __DIR__ . "/sec.php";
require_once __DIR__ . "/sec_captcha.php";
require_once __DIR__ . "/sec_token.php";

// ---------------------------------------------------------------------------
// nfp_method: one 1-4 int in the DB, a method name and a temperature flag everywhere else (NFP_METHOD_BY_ID)
// ---------------------------------------------------------------------------

// Anything unknown reads as cervical mucus self-observation.
function user_account_method_name(int $nfp_method): string {
	return NFP_METHOD_BY_ID[$nfp_method][0] ?? NFP_METHOD_CERVICAL_MUCUS;
}

function user_account_tracks_temperature(int $nfp_method): bool {
	return NFP_METHOD_BY_ID[$nfp_method][1] ?? false;
}

// The id a body's "method" and "temperatureTracking" ask for; one not sent is $default_id's (cervical mucus self-observation without temperature for a new
// account, the account's own for a change). Null when a field is wrong (an issue).
function user_account_method_id_from_json(array $body, array &$issues, int $default_id = 2): ?int {
	[$method, $temperature_tracking] = NFP_METHOD_BY_ID[$default_id];
	$before = count($issues);

	if (array_key_exists("method", $body)) {
		if (!is_string($body["method"])) input_wrong_type($issues, "method", $body["method"], "a string (" . implode(" or ", NFP_METHODS_NATIVE) . ")");
		elseif (!in_array($body["method"], NFP_METHODS_NATIVE, true)) $issues[] = input_issue("method", "invalid_value", "'method' must be " . implode(" or ", NFP_METHODS_NATIVE) . ".");
		else $method = $body["method"];
	}
	$flag = input_bool_field($body, "temperatureTracking", $issues);
	if (!is_null($flag)) $temperature_tracking = $flag;

	return count($issues) > $before ? null : array_search([$method, $temperature_tracking], NFP_METHOD_BY_ID, true);
}

// "birthYear": this year or up to ACCOUNT_BIRTH_YEAR_MAX_AGE years back (the statistics average it; user_account.age is a smallint).
// Null when absent or wrong (an issue).
function user_account_birth_year(array $body, array &$issues): ?int {
	if (!array_key_exists("birthYear", $body)) return null;
	$year = $body["birthYear"];
	$this_year = intval(date("Y"));
	if (!is_int($year)) input_wrong_type($issues, "birthYear", $year, "a whole number (a year)");
	elseif ($year < $this_year - ACCOUNT_BIRTH_YEAR_MAX_AGE || $year > $this_year) {
		$issues[] = input_issue("birthYear", "out_of_range", "'birthYear' must be a year from " . ($this_year - ACCOUNT_BIRTH_YEAR_MAX_AGE) . " to " . date("Y") . ".");
	}
	else return $year;
	return null;
}

// The session's row holds no hash: it is read again.
function user_account_password_matches($db, array $user_account, mixed $password): bool {
	if (!is_string($password) || $password === "") return false;
	$hash = db_select_user_account_by_email($db, $user_account["login_email"])["password"] ?? null;
	return is_string($hash) && password_verify($password, $hash);
}

// The notification addresses as the column keeps them: lower case, no duplicate, ACCOUNT_NOTIFICATION_EMAILS_SEPARATOR between two;
// "" clears the list, an empty item is skipped. Null when it is no such list: not a string, an invalid address, more than
// ACCOUNT_NOTIFICATION_EMAILS_MAX, or more than the column holds.
function user_account_notification_emails_parse(mixed $value): ?string {
	if (!is_string($value)) return null;

	$list = [];
	foreach (explode(ACCOUNT_NOTIFICATION_EMAILS_SEPARATOR, $value) as $part) {
		$email = input_email_normalise($part);
		if ($email === "") continue;
		if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return null;
		if (!in_array($email, $list, true)) $list[] = $email;
	}
	if (count($list) > ACCOUNT_NOTIFICATION_EMAILS_MAX) return null;

	$joined = implode(ACCOUNT_NOTIFICATION_EMAILS_SEPARATOR, $list);
	return strlen($joined) <= ACCOUNT_LIMIT_NOTIFICATION_EMAILS_CHARS ? $joined : null;
}

// ---------------------------------------------------------------------------
// POST /api/v2/register, POST /api/v2/recover_password
// ---------------------------------------------------------------------------

// A new address gets an account that cannot be used until the address is proved, and the link that proves it. An address that has an
// account (proved or not) is left exactly as it is, and its owner gets a link to choose a new password: nothing a stranger types reaches
// an account, and the answer is the same either way. $new: nickname, email, password, birth_year, nfp_method, comment, research.
// Answers ["captcha" => verdict, "account" => [no_user_account, name, login_email] or null, "token" => for the mail or null,
// "created" => bool]; the mail is the caller's, once this has committed. The captcha is checked first (a refused one costs no bcrypt),
// and again under the lock of an existing account: of requests that come together with one captcha, one mail.
function user_account_register($db, array $new, mixed $captcha_token, mixed $captcha_answer, string $ip): array {
	$verdict = sec_captcha_verify($db, "register", $new["email"], $captcha_token, $captcha_answer, $ip);
	if ($verdict !== "ok") return ["captcha" => $verdict, "account" => null, "token" => null, "created" => false];

	$password_hash = sec_hash($new["password"]);   // an address that has an account costs the same time

	// An insert that finds the address taken keeps a share lock on the key until its transaction ends; two such requests that then
	// lock the row wait for each other and MariaDB kills one (a deadlock: a 500). So that transaction ends with nothing written and the
	// request goes round once more, as one for an address that has an account, which locks the row by its id.
	for ($round = 0; $round < 2; $round++) {
		// read before the transaction: its plain reads would show the rows as they were at its first one
		$existing = db_select_user_account_by_email($db, $new["email"]);

		$registered = db_transaction($db, function () use ($db, $new, $password_hash, $captcha_token, $captcha_answer, $ip, $existing) {
			if (is_null($existing)) {
				$no_user_account = db_insert_user_account($db, $new["nickname"], $new["nfp_method"], $new["birth_year"], $new["email"], $password_hash, $new["comment"], $new["research"]);
				if (is_null($no_user_account)) return null;   // taken since the read

				log_context(["uid" => intval($no_user_account)]);
				log_event("account.registered", ["nfp" => $new["nfp_method"]]);
				return [
					"captcha" => "ok", "created" => true, "account" => ["no_user_account" => $no_user_account, "name" => $new["nickname"], "login_email" => $new["email"]],
					"token" => email_token_issue($db, intval($no_user_account), EMAIL_TOKEN_PURPOSE_ACCOUNT_CREATION),
				];
			}

			db_select_user_account_for_update($db, $existing["no_user_account"]);
			$verdict = sec_captcha_verify($db, "register", $new["email"], $captcha_token, $captcha_answer, $ip);
			if ($verdict !== "ok") return ["captcha" => $verdict, "account" => $existing, "token" => null, "created" => false];

			log_context(["uid" => intval($existing["no_user_account"])]);
			log_event("account.refused", ["act" => "register", "err" => "account_exists"]);
			return [
				"captcha" => "ok", "created" => false, "account" => $existing,
				"token" => email_token_issue($db, intval($existing["no_user_account"]), EMAIL_TOKEN_PURPOSE_RESET_PASSWORD),
			];
		});
		if (!is_null($registered)) return $registered;
	}

	return ["captcha" => "ok", "account" => null, "token" => null, "created" => false];   // taken and erased in turn: nothing to say
}

// The link to choose a new password for the account at $email, if the captcha passes; the password stays until the link is used.
// Answers ["captcha" => verdict, "account" => row or null, "token" => for the mail or null]. The captcha is tied to the password and
// the pending link and checked under the account's lock, so of requests that come together with one captcha, one mail. An address with
// no account is only checked: the same verdict, nothing to tell from outside.
function user_account_recover_password($db, string $email, mixed $token, mixed $answer, string $ip): array {
	$account = db_select_user_account_by_email($db, $email);
	if (is_null($account)) return ["captcha" => sec_captcha_verify($db, "recover", $email, $token, $answer, $ip), "account" => null, "token" => null];

	return db_transaction($db, function () use ($db, $email, $token, $answer, $ip, $account) {
		db_select_user_account_for_update($db, $account["no_user_account"]);
		$verdict = sec_captcha_verify($db, "recover", $email, $token, $answer, $ip);
		if ($verdict !== "ok") return ["captcha" => $verdict, "account" => $account, "token" => null];
		return ["captcha" => $verdict, "account" => $account, "token" => email_token_issue($db, intval($account["no_user_account"]), EMAIL_TOKEN_PURPOSE_RESET_PASSWORD)];
	});
}

// ---------------------------------------------------------------------------
// GET /api/v2/account, POST /api/v2/account
// ---------------------------------------------------------------------------

// The settings of a POST /api/v2/account body that need no password: [the values db_update_user_account_settings() takes, the JSON names
// of those that change]. A field absent keeps its value.
function user_account_check_settings(array $account, array $body, array &$issues): array {
	$new = [
		"name" => $account["name_user_account"], "notification_emails" => $account["notification_emails"], "nfp_method" => $account["nfp_method"],
		"age" => $account["age"], "sponsor" => $account["sponsor"], "timeline_asc" => $account["timeline_asc"], "research" => $account["research"],
		"auto_mail_export" => $account["auto_mail_export"],
	];
	$changed = [];

	$nickname = day_format_body_text($body, "nickname", ACCOUNT_LIMIT_NICKNAME_CHARS, $issues);
	if ($nickname === "") $issues[] = input_issue("nickname", "invalid_value", "'nickname' must not be empty.");
	elseif (!is_null($nickname)) [$new["name"], $changed[]] = [$nickname, "nickname"];

	if (array_key_exists("method", $body) || array_key_exists("temperatureTracking", $body)) {
		$nfp_method = user_account_method_id_from_json($body, $issues, intval($account["nfp_method"]));
		if (!is_null($nfp_method)) [$new["nfp_method"], $changed[]] = [$nfp_method, "method"];
	}

	$birth_year = user_account_birth_year($body, $issues);
	if (!is_null($birth_year)) [$new["age"], $changed[]] = [$birth_year, "birthYear"];

	foreach (["timelineAscending" => "timeline_asc", "research" => "research", "sponsor" => "sponsor", "autoMailExport" => "auto_mail_export"] as $json => $column) {
		$flag = input_bool_field($body, $json, $issues);
		if (is_null($flag)) continue;
		$new[$column] = $flag ? 1 : 0;
		$changed[] = $json;
	}

	return [$new, $changed];
}

// The whole of POST /api/v2/account, partial. $issues are the ones the endpoint found (unknown names); every problem of a value is added, and
// one 400 tells them all. Three changes want the current password ("password"), because a session must not be able to hand the account
// or its cycles to someone else: "newPassword" (with "logoutOtherDevices" to close the other sessions), "notificationEmails" and
// "loginEmail" (which also wants the TOTP "code" when the account has one). The first two are made at once; the third sends a link to the
// NEW address and happens when it is used. Everything is checked before anything is written, so a refused request changes nothing.
// Answers ["status" => 200, "data" => user_account_to_json(), "meta" => ["fieldsUpdated", "loggedOutDevices"?, "loginEmailLinkSent"?]]
// or ["status" => 4xx, "code", "message"].
function user_account_update($db, array $user_account, array $body, array $issues = []): array {
	$refuse = fn(int $status, string $code, string $message) => ["status" => $status, "code" => $code, "message" => $message];
	$no_user_account = intval($user_account["no_user_account"]);

	[$new, $changed] = user_account_check_settings($user_account, $body, $issues);

	$new_notification_emails = null;
	if (array_key_exists("notificationEmails", $body)) {
		$separator = ACCOUNT_NOTIFICATION_EMAILS_SEPARATOR;
		if (!is_string($body["notificationEmails"])) input_wrong_type($issues, "notificationEmails", $body["notificationEmails"], "a string of addresses separated by '$separator' (or an empty string)");
		else {
			$parsed = user_account_notification_emails_parse($body["notificationEmails"]);
			if (is_null($parsed)) {
				$issues[] = input_issue("notificationEmails", "invalid_value", "'notificationEmails' must be at most " . ACCOUNT_NOTIFICATION_EMAILS_MAX . " valid e-mail addresses separated by '$separator', "
					. ACCOUNT_LIMIT_NOTIFICATION_EMAILS_CHARS . " characters in all (or an empty string to clear the list).");
			}
			elseif ($parsed !== (user_account_notification_emails_parse($user_account["notification_emails"] ?? "") ?? "")) $new_notification_emails = $parsed;
		}
	}

	$new_login_email = input_email($body, "loginEmail", $issues, false);
	if (!is_null($new_login_email) && $new_login_email === $user_account["login_email"]) {
		$issues[] = input_issue("loginEmail", "email_unchanged", "'loginEmail' is already the login address of the account.");
		$new_login_email = null;
	}

	$new_password = input_secret($body, "newPassword", $issues, false);
	$issue = is_null($new_password) ? null : sec_password_issue($new_password, "newPassword");
	if (!is_null($issue)) {
		$issues[] = $issue;
		$new_password = null;
	}

	$logout_other_devices = input_bool_field($body, "logoutOtherDevices", $issues);
	if ($logout_other_devices === true && !array_key_exists("newPassword", $body)) {
		$issues[] = input_issue("logoutOtherDevices", "invalid_value", "'logoutOtherDevices' closes the other sessions when the password changes: send it with 'newPassword'.");
	}

	$password = input_secret($body, "password", $issues, false);
	$sensitive = !is_null($new_notification_emails) || !is_null($new_login_email) || !is_null($new_password);
	if ($sensitive) {
		// a demo account's password is public: its password and addresses are no one's to change
		if (sec_session_is_demo($user_account)) return $refuse(403, "demo_account", "Not available on a demo account.");
		if (is_null($password) && !array_key_exists("password", $body)) {
			$issues[] = input_issue("password", "password_required", "'password' (the current password) is required to change 'loginEmail', 'newPassword' or 'notificationEmails'.");
		}
	}

	$totp_active = $user_account["totp_state"] == TOTP_STATE_ACTIVE;
	if (!is_null($new_login_email) && $totp_active && array_key_exists("code", $body) && !is_string($body["code"]) && !is_int($body["code"])) {
		input_wrong_type($issues, "code", $body["code"], "a string of digits (the one-time code)");
	}

	$last_write_client_UTC = input_last_write($body, $issues);
	input_exit_if_issues($issues);

	if ($sensitive) {
		sec_token_reauth_exit_if_locked($user_account);
		if (!user_account_password_matches($db, $user_account, $password)) {
			sec_token_reauth_failed($db, $user_account);
			log_event("account.refused", ["act" => "account", "err" => "invalid_password"]);
			return $refuse(401, "invalid_password", "Incorrect password.");
		}
		if (!is_null($new_login_email) && $totp_active && !sec_totp_code_valid($user_account, $body["code"] ?? "")) {
			$given = is_scalar($body["code"] ?? null) && strlen((string) $body["code"]) > 0;
			if ($given) sec_token_reauth_failed($db, $user_account);   // a code asked for and not sent is no guess
			log_event("account.refused", ["act" => "account", "err" => $given ? "totp_invalid" : "totp_required"]);
			return $given ? $refuse(401, "totp_invalid", "The TOTP code is incorrect.") : $refuse(401, "totp_required", "A TOTP code is required.");
		}
		sec_token_reauth_passed($db, $user_account);
		if (!is_null($new_password) && user_account_password_matches($db, $user_account, $new_password)) {
			input_exit_if_issues([input_issue("newPassword", "password_unchanged", "'newPassword' is identical to the current password.")]);
		}
	}

	// the settings and the password together, then the mails, then the link
	if (!is_null($new_notification_emails)) {
		$new["notification_emails"] = $new_notification_emails;
		$changed[] = "notificationEmails";
	}
	$closed_sessions = db_transaction($db, function () use ($db, $no_user_account, $user_account, $new, $changed, $last_write_client_UTC, $new_password, $logout_other_devices) {
		if (!empty($changed)) db_update_user_account_settings($db, $no_user_account, $new, $last_write_client_UTC);
		if (is_null($new_password)) return null;

		db_update_password($db, sec_hash($new_password), $no_user_account);
		// a reset link still in the mailbox is no use any more; a pending change of address is left alone
		db_update_user_account_email_token_clear($db, $no_user_account, EMAIL_TOKEN_PURPOSE_RESET_PASSWORD);
		return $logout_other_devices === true ? db_delete_auth_tokens_other($db, $no_user_account, $user_account["no_auth_token"]) : [];
	});
	$logged_out_devices = is_null($closed_sessions) ? null : count($closed_sessions);

	if (!empty($closed_sessions)) log_context(["rev" => $closed_sessions]);
	if (!empty($changed)) log_event("account.settings_changed", ["fld" => $changed]);
	if (!is_null($new_password)) log_event("account.password_changed", ["out" => $logged_out_devices]);
	if (!is_null($new_notification_emails)) mail_send_notification_emails_changed($user_account["login_email"], $new_notification_emails);

	$meta = ["fieldsUpdated" => is_null($new_password) ? $changed : array_merge($changed, ["password"])];
	if (!is_null($new_password)) $meta["loggedOutDevices"] = $logged_out_devices;
	if (!is_null($new_login_email)) $meta["loginEmailLinkSent"] = email_token_request_email_change($db, $user_account, $new_login_email);

	// the account as it is now: the row the request started from, with what was written
	$after = array_merge($user_account, $new, ["name_user_account" => $new["name"]]);
	if (!empty($changed)) $after["last_write_client_UTC"] = $last_write_client_UTC;
	return ["status" => 200, "data" => user_account_to_json($after, db_select_day_timeline_oldest_date($db, $user_account["no_user_account"])), "meta" => $meta];
}

// The account as a client starts from it ($user_account: the row of sec_token_authenticate()). Nothing secret. The cycles are in the
// sync, the news banner in GET /api/v2/server_info.
function user_account_to_json(array $user_account, ?string $oldest_day): array {
	$nfp_method = intval($user_account["nfp_method"]);
	return [
		"userId" => intval($user_account["no_user_account"]),
		"loginEmail" => $user_account["login_email"],
		"notificationEmails" => $user_account["notification_emails"],
		"method" => user_account_method_name($nfp_method),
		"temperatureTracking" => user_account_tracks_temperature($nfp_method),
		"birthYear" => $user_account["age"],
		"nickname" => $user_account["name_user_account"],
		"inscriptionDate" => http_iso8601($user_account["inscription_date"]),
		"oldestDay" => $oldest_day,
		"sponsor" => boolval($user_account["sponsor"]),
		"research" => boolval($user_account["research"]),
		"timelineAscending" => boolval($user_account["timeline_asc"]),
		"autoMailExport" => boolval($user_account["auto_mail_export"]),
		"totpState" => sec_totp_state_name($user_account["totp_state"]),
		"lastWriteClientUtc" => http_iso8601($user_account["last_write_client_UTC"]),
	];
}
