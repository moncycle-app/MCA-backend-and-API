<?php
/* moncycle.app
**
** licence Creative Commons CC BY-NC-SA
**
** https://www.moncycle.app
** https://github.com/moncycle-app/backend-api-web-app
*/

// The user_account row as the code and the JSON API see it: its method, and what a
// POST /api/account body changes.

require_once __DIR__ . "/captcha.php";
require_once __DIR__ . "/day_format.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/email_token.php";
require_once __DIR__ . "/http.php";
require_once __DIR__ . "/log.php";
require_once __DIR__ . "/mail.php";
require_once __DIR__ . "/sec.php";

// ---------------------------------------------------------------------------
// nfp_method: one 1-4 int in the DB, a method name plus a temperature flag everywhere else
// (NFP_METHOD_BY_ID, constants.php).
// ---------------------------------------------------------------------------

// The method of the account as the NFP format names it. Anything unknown reads as Billings.
function account_method_name(int $nfp_method): string {
	return NFP_METHOD_BY_ID[$nfp_method][0] ?? NFP_METHOD_BILLINGS;
}

function account_tracks_temperature(int $nfp_method): bool {
	return NFP_METHOD_BY_ID[$nfp_method][1] ?? false;
}

// The id of a method name (any case) and temperature flag, as the API takes them. Anything
// that is not FertilityCare is Billings, as before the two fields were split.
function account_method_id(string $method, bool $temperature_tracking): int {
	$method = strtolower(trim($method)) === strtolower(NFP_METHOD_FERTILITY_CARE) ? NFP_METHOD_FERTILITY_CARE : NFP_METHOD_BILLINGS;
	return array_search([$method, $temperature_tracking], NFP_METHOD_BY_ID, true);
}

// The id a body's "method" and "temperatureTracking" ask for; Billings without temperature when absent.
function account_method_id_from_json(array $body): int {
	$method = $body["method"] ?? NFP_METHOD_BILLINGS;
	return account_method_id(is_string($method) ? $method : NFP_METHOD_BILLINGS, boolval($body["temperatureTracking"] ?? false));
}

// ---------------------------------------------------------------------------
// The addresses and the password of the account
// ---------------------------------------------------------------------------

// An email address as it is stored and looked up: trimmed and in lower case, so that Alice@X.test
// and alice@x.test are one account. "" when it is not a string (a client can send a list or a number).
// FILTER_VALIDATE_EMAIL refuses non-ASCII, so strtolower never meets a multibyte character.
function account_email_normalise(mixed $email): string {
	return is_string($email) ? strtolower(trim($email)) : "";
}

// Is this the account's password? The row sec_auth_token() reads holds no hash, so it is read again.
function account_password_matches($db, array $user_account, mixed $password): bool {
	if (!is_string($password) || $password === "") return false;
	$full_account = db_select_user_account_by_email($db, $user_account["login_email"]) ?? [];
	return isset($full_account["password"]) && password_verify($password, $full_account["password"]);
}

// ---------------------------------------------------------------------------
// POST /api/register, POST /api/recover_password
// ---------------------------------------------------------------------------

// Signs an address up: a new account that cannot be used until the address is proved, and the link that proves it. $new holds the
// checked fields: first_name, email, password, birth_year, nfp_method, comment, research. An address that already has an account
// (proved or not) is left exactly as it is, whatever the request says: its owner gets a link to choose a new password instead, so
// nothing a stranger types can reach an account, and the answer is the same either way.
// Answers ["captcha" => the verdict of captcha_verify(), "account" => ["no_user_account", "name", "login_email"] or null, "token" => the
// token for the mail or null, "created" => whether a new account was made]. The mail is the caller's, and goes after this returns.
// The captcha is checked first (a refused one costs no hash), and again under the lock of an account that already exists: of requests
// that arrive together with one token the first makes the link and the others find the account moved, one mail per captcha.
function account_register($db, array $new, mixed $captcha_token, mixed $captcha_answer, string $ip): array {
	$verdict = captcha_verify($db, "register", $new["email"], $captcha_token, $captcha_answer, $ip);
	if ($verdict !== "ok") return ["captcha" => $verdict, "account" => null, "token" => null, "created" => false];

	// an address that has an account costs the same as one that has not
	$password_hash = sec_hash($new["password"]);

	// An insert that finds the address taken (a second registration of it sent at the same time as the first) keeps a share lock on
	// the key until its transaction ends: two such requests that then asked for the row would each wait for the other's lock, and
	// MariaDB would kill one (a deadlock, a 500). So that transaction ends there, with nothing written, and the request goes round
	// once more as a request for an address that has an account, which holds the row by its id.
	for ($round = 0; $round < 2; $round++) {
		// read before the transaction begins, like account_recover_password(): a transaction's plain reads show the rows as they were
		// at its first one, and the captcha is checked again under the lock, on what the account holds by then
		$existing = db_select_user_account_by_email($db, $new["email"]);

		$registered = db_transaction($db, function () use ($db, $new, $password_hash, $captcha_token, $captcha_answer, $ip, $existing) {
			if (is_null($existing)) {
				$no_user_account = db_insert_user_account(
					$db, $new["first_name"], $new["nfp_method"], $new["birth_year"], $new["email"], $password_hash, $new["comment"], $new["research"]
				);
				if (is_null($no_user_account)) return null;   // taken since the read

				log_context(["uid" => intval($no_user_account)]);
				log_event("account.registered", ["nfp" => $new["nfp_method"]]);
				return [
					"captcha" => "ok", "created" => true, "account" => ["no_user_account" => $no_user_account, "name" => $new["first_name"], "login_email" => $new["email"]],
					"token" => email_token_issue($db, intval($no_user_account), EMAIL_TOKEN_PURPOSE_ACCOUNT_CREATION),
				];
			}

			db_select_user_account_for_update($db, $existing["no_user_account"]);
			$verdict = captcha_verify($db, "register", $new["email"], $captcha_token, $captcha_answer, $ip);
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

// Makes the link that lets the owner of the address at $email choose a new password, if the captcha of the request (purpose
// "recover") is good. Nothing is changed but the account's slot: the password stays what it is until the link is used. Answers
// ["captcha" => the verdict of captcha_verify(), "account" => the row or null, "token" => the token for the mail, or null when no
// link was made]. The mail is the caller's, and goes after this returns: the link is committed by then.
// The captcha is tied to the password and the pending link. The account is held from the check to the new link, and the check is
// made on what it holds now, so of requests that arrive together with one token the first makes a link and the others no longer
// match: one mail per token. An address with no account is only checked (the same verdict, and no way to tell from the outside).
function account_recover_password($db, string $email, mixed $token, mixed $answer, string $ip): array {
	$account = db_select_user_account_by_email($db, $email);
	if (is_null($account)) {
		return ["captcha" => captcha_verify($db, "recover", $email, $token, $answer, $ip), "account" => null, "token" => null];
	}

	return db_transaction($db, function () use ($db, $email, $token, $answer, $ip, $account) {
		db_select_user_account_for_update($db, $account["no_user_account"]);
		$verdict = captcha_verify($db, "recover", $email, $token, $answer, $ip);
		if ($verdict !== "ok") return ["captcha" => $verdict, "account" => $account, "token" => null];

		return ["captcha" => $verdict, "account" => $account, "token" => email_token_issue($db, intval($account["no_user_account"]), EMAIL_TOKEN_PURPOSE_RESET_PASSWORD)];
	});
}

// ---------------------------------------------------------------------------
// POST /api/account
// ---------------------------------------------------------------------------

// A birth year a person can give: this year, or up to ACCOUNT_BIRTH_YEAR_MAX_AGE years back. The
// statistics average it, and user_account.age is a smallint.
function account_birth_year_valid(int $year): bool {
	$this_year = intval(date("Y"));
	return $year >= $this_year - ACCOUNT_BIRTH_YEAR_MAX_AGE && $year <= $this_year;
}

// A list of addresses as the column keeps it: lower case, no space, no duplicate, ACCOUNT_NOTIFICATION_EMAILS_SEPARATOR between two. "" is no
// address (it clears the list). null when the value is no list of valid addresses: not a string, an address that is not one, more than
// ACCOUNT_NOTIFICATION_EMAILS_MAX of them, or more than the column holds. An empty item ("a@x.org;;b@y.org", a trailing ";") is skipped.
function account_notification_emails_parse(mixed $value): ?string {
	if (!is_string($value)) return null;

	$list = [];
	foreach (explode(ACCOUNT_NOTIFICATION_EMAILS_SEPARATOR, $value) as $part) {
		$email = account_email_normalise($part);
		if ($email === "") continue;
		if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return null;
		if (!in_array($email, $list, true)) $list[] = $email;
	}
	if (count($list) > ACCOUNT_NOTIFICATION_EMAILS_MAX) return null;

	$joined = implode(ACCOUNT_NOTIFICATION_EMAILS_SEPARATOR, $list);
	return strlen($joined) <= ACCOUNT_LIMIT_NOTIFICATION_EMAILS_CHARS ? $joined : null;
}

// What a body changes of the account row among the settings that need no password: [the values db_update_user_account_settings() takes,
// the JSON names of the fields it changes]. An absent or unusable field leaves the account as it is. "notificationEmails" is one of the
// values (it is passed on as it is stored); whether it changes is account_update()'s to say, since it asks for the password.
function account_apply_json(array $account, array $body): array {
	$new = [
		"name" => $account["name_user_account"], "notification_emails" => $account["notification_emails"], "nfp_method" => $account["nfp_method"],
		"age" => $account["age"], "sponsor" => $account["sponsor"], "timeline_asc" => $account["timeline_asc"], "research" => $account["research"],
		"auto_mail_export" => $account["auto_mail_export"],
	];
	$changed = [];

	// a name that is not a string, is empty or is longer than its column is unusable
	[$name] = isset($body["name"]) ? day_format_text($body["name"], "name", ACCOUNT_LIMIT_NAME_CHARS) : [null];
	if (!is_null($name) && $name !== '') {
		$new["name"] = $name;
		$changed[] = "name";
	}
	if (isset($body["method"]) || isset($body["temperatureTracking"])) {
		$new["nfp_method"] = account_method_id_from_json($body);
		$changed[] = "method";
	}
	if (!empty($body["birthYear"]) && account_birth_year_valid(intval($body["birthYear"]))) {
		$new["age"] = intval($body["birthYear"]);
		$changed[] = "birthYear";
	}
	foreach (["timelineAscending" => "timeline_asc", "research" => "research", "sponsor" => "sponsor", "autoMailExport" => "auto_mail_export"] as $json => $column) {
		if (!isset($body[$json])) continue;
		$new[$column] = boolval($body[$json]) ? 1 : 0;
		$changed[] = $json;
	}

	return [$new, $changed];
}

// POST /api/account: every setting of the account is changed here. Answers what the endpoint says:
//   success: ["status" => 200, "data" => ["name", "fieldsUpdated", "lastWriteClientUtc", and when they were asked "loggedOutDevices", "loginEmailLinkSent"]]
//   refusal: ["status" => 4xx, "code" => ..., "message" => ...]
// Three changes want the password again, "password" in the body, because whoever holds a session must not be able to hand the account (or
// its cycles) to someone else with it: "newPassword" (with "logoutOtherDevices" if the other sessions are to close), "notificationEmails"
// (the list of addresses that receive the cycles, ";" between two) and "loginEmail" (the address that signs in; it also wants the TOTP code,
// "code", when the account has one). The first two are made at once. The third only asks: a link goes to the NEW address and the change
// happens when it is used (email_token.php). All of it is checked before anything is written, so a request that is refused changes nothing;
// the settings and the password are written together, then the mails go, then the link is made.
function account_update($db, array $user_account, array $body): array {
	$refuse = fn(int $status, string $code, string $message) => ["status" => $status, "code" => $code, "message" => $message];
	$no_user_account = intval($user_account["no_user_account"]);

	// --- what is asked, and whether it can be
	[$new, $changed] = account_apply_json($user_account, $body);

	$new_notification_emails = null;
	if (array_key_exists("notificationEmails", $body) && !is_null($body["notificationEmails"])) {
		$new_notification_emails = account_notification_emails_parse($body["notificationEmails"]);
		if (is_null($new_notification_emails)) {
			return $refuse(400, "invalid_notification_emails", "'notificationEmails' must be at most " . ACCOUNT_NOTIFICATION_EMAILS_MAX . " valid addresses separated by '" . ACCOUNT_NOTIFICATION_EMAILS_SEPARATOR . "' (or an empty string).");
		}
		if ($new_notification_emails === (account_notification_emails_parse($user_account["notification_emails"] ?? "") ?? "")) $new_notification_emails = null;
	}

	$new_login_email = null;
	if (array_key_exists("loginEmail", $body) && !is_null($body["loginEmail"])) {
		$new_login_email = account_email_normalise($body["loginEmail"]);
		if (!filter_var($new_login_email, FILTER_VALIDATE_EMAIL)) return $refuse(400, "invalid_email", "Provided email address is not valid.");
		if ($new_login_email === $user_account["login_email"]) return $refuse(400, "email_unchanged", "This is already the login email address of the account.");
	}

	$new_password = null;
	if (array_key_exists("newPassword", $body) && !is_null($body["newPassword"])) {
		if (!is_string($body["newPassword"]) || $body["newPassword"] === "") return $refuse(400, "invalid_parameter", "'newPassword' must be a non-empty string.");
		$problem = sec_password_problem($body["newPassword"]);
		if (!is_null($problem)) return $refuse(422, $problem[0], $problem[1]);
		$new_password = $body["newPassword"];
	}

	if (array_key_exists("logoutOtherDevices", $body) && !is_bool($body["logoutOtherDevices"])) {
		return $refuse(400, "invalid_parameter", "'logoutOtherDevices' must be true or false.");
	}
	$logout_other_devices = ($body["logoutOtherDevices"] ?? false) === true;

	// --- the password again, for what hands the account or its cycles over
	if (!is_null($new_notification_emails) || !is_null($new_login_email) || !is_null($new_password)) {
		// the demo accounts are shared and their password is public: it is no one's to change, nor their addresses
		if (sec_session_is_demo($user_account)) return $refuse(403, "demo_account", "Not available on a demo account.");

		if (!is_string($body["password"] ?? null) || $body["password"] === "") {
			return $refuse(400, "missing_password", "'password' is required to change 'loginEmail', 'newPassword' or 'notificationEmails'.");
		}
		$full_account = db_select_user_account_by_email($db, $user_account["login_email"]) ?? [];
		if (!isset($full_account["password"]) || !password_verify($body["password"], $full_account["password"])) {
			log_event("account.refused", ["act" => "account", "err" => "invalid_password"]);
			return $refuse(401, "invalid_password", "Incorrect password.");
		}
		if (!is_null($new_login_email) && $user_account["totp_state"] == TOTP_STATE_ACTIVE && !sec_totp_code_valid($user_account, $body["code"] ?? "")) {
			$given = is_scalar($body["code"] ?? null) && strlen((string) $body["code"]) > 0;
			log_event("account.refused", ["act" => "account", "err" => $given ? "totp_invalid" : "totp_required"]);
			return $given ? $refuse(401, "totp_invalid", "The TOTP code is incorrect.") : $refuse(401, "totp_required", "A TOTP code is required.");
		}
		if (!is_null($new_password) && password_verify($new_password, $full_account["password"])) {
			return $refuse(422, "password_unchanged", "New password is identical to the previous password.");
		}
	}

	// --- written together
	if (!is_null($new_notification_emails)) {
		$new["notification_emails"] = $new_notification_emails;
		$changed[] = "notificationEmails";
	}
	$last_write_client_UTC = http_client_timestamp($body["lastWriteClientUtc"] ?? null);
	$logged_out_devices = db_transaction($db, function () use ($db, $no_user_account, $user_account, $new, $changed, $last_write_client_UTC, $new_password, $logout_other_devices) {
		if (!empty($changed)) db_update_user_account_settings($db, $no_user_account, $new, $last_write_client_UTC);
		if (is_null($new_password)) return null;

		db_update_password($db, sec_hash($new_password), $no_user_account);
		// a link to choose a password, still in the mailbox, is no use any more; a pending change of address is left alone
		db_update_user_account_email_token_clear($db, $no_user_account, EMAIL_TOKEN_PURPOSE_RESET_PASSWORD);
		return $logout_other_devices ? db_delete_auth_tokens_other($db, $no_user_account, $user_account["no_auth_token"]) : 0;
	});

	if (!empty($changed)) log_event("account.settings_changed", ["fld" => $changed]);
	if (!is_null($new_password)) log_event("account.password_changed", ["out" => $logged_out_devices]);
	if (!is_null($new_notification_emails)) mail_send_notification_emails_changed($user_account["login_email"], $new_notification_emails);

	$data = ["name" => $new["name"], "fieldsUpdated" => is_null($new_password) ? $changed : array_merge($changed, ["password"])];
	if (!is_null($new_password)) $data["loggedOutDevices"] = $logged_out_devices;
	if (!is_null($new_login_email)) $data["loginEmailLinkSent"] = email_token_request_email_change($db, $user_account, $new_login_email);
	$data["lastWriteClientUtc"] = http_iso8601($last_write_client_UTC);

	return ["status" => 200, "data" => $data];
}

// ---------------------------------------------------------------------------
// GET /api/key_infos
// ---------------------------------------------------------------------------

// The account summary a client starts from, shared by GET /api/key_infos and GET /api/sync:
// who the user is, their settings, the shape of their timeline, and where the news banner comes from
// (NEWS_URL, null when it is off). $user_account is the row sec_auth_token() read. Nothing secret in it.
function account_key_infos($db, array $user_account): array {
	$nfp_method = intval($user_account["nfp_method"]);

	return [
		"userId" => $user_account["no_user_account"],
		"loginEmail" => $user_account["login_email"],
		"notificationEmails" => $user_account["notification_emails"],
		"method" => account_method_name($nfp_method),
		"temperatureTracking" => account_tracks_temperature($nfp_method),
		"birthYear" => $user_account["age"],
		"name" => $user_account["name_user_account"],
		"inscriptionDate" => http_iso8601($user_account["inscription_date"]),
		"sponsor" => boolval($user_account["sponsor"]),
		"research" => boolval($user_account["research"]),
		"timelineAscending" => boolval($user_account["timeline_asc"]),
		"autoMailExport" => boolval($user_account["auto_mail_export"]),
		"allCyclesFirstDay" => db_select_cycles($db, $user_account["no_user_account"]),
		"allPregnancyDates" => db_select_pregnancies($db, $user_account["no_user_account"]),
		"totpState" => sec_totp_state_name($user_account["totp_state"]),
		"lastWriteClientUtc" => http_iso8601($user_account["last_write_client_UTC"]),
		"newsUrl" => str_starts_with(NEWS_URL, "https://") ? NEWS_URL : null,
	];
}
