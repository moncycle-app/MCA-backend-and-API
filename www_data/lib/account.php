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
	$full_account = db_select_user_account_by_email($db, $user_account["email1"]) ?? [];
	return isset($full_account["password"]) && password_verify($password, $full_account["password"]);
}

// ---------------------------------------------------------------------------
// POST /api/register, POST /api/recover_password
// ---------------------------------------------------------------------------

// Signs an address up: a new account that cannot be used until the address is proved, and the link that proves it. $new holds the
// checked fields: first_name, email, password, birth_year, nfp_method, comment, research. An address that already has an account
// (proved or not) is left exactly as it is, whatever the request says: its owner gets a link to choose a new password instead, so
// nothing a stranger types can reach an account, and the answer is the same either way.
// Answers ["captcha" => the verdict of captcha_verify(), "account" => ["no_user_account", "name", "email1"] or null, "token" => the
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
					"captcha" => "ok", "created" => true, "account" => ["no_user_account" => $no_user_account, "name" => $new["first_name"], "email1" => $new["email"]],
					"token" => email_token_issue($db, intval($no_user_account), EMAIL_TOKEN_PURPOSE_VERIFY_EMAIL),
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

// The secondary address a body asks for when it is a change: the new address (normalised, "" to clear
// it), or null when the body asks nothing, something unusable, or what the account already has. The
// secondary address receives every cycle by mail, so the endpoint asks for the password before it
// lets a session change it.
function account_secondary_email_change(array $account, array $body): ?string {
	if (!isset($body["secondaryEmail"]) || !is_string($body["secondaryEmail"])) return null;
	$email = account_email_normalise($body["secondaryEmail"]);
	if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) return null;
	return $email === account_email_normalise($account["email2"]) ? null : $email;
}

// What a body changes of the account row: [the values db_update_user_account_settings() takes, the
// JSON names of the fields it changes]. An absent or unusable field leaves the account as it is.
// The password that lets a session change "secondaryEmail" is checked by the endpoint, not here.
function account_apply_json(array $account, array $body): array {
	$new = [
		"name" => $account["name_user_account"], "email2" => $account["email2"], "nfp_method" => $account["nfp_method"],
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
	if (isset($body["secondaryEmail"]) && is_string($body["secondaryEmail"])) {
		$email2 = account_email_normalise($body["secondaryEmail"]);
		if ($email2 === '' || filter_var($email2, FILTER_VALIDATE_EMAIL)) {
			$new["email2"] = $email2;
			$changed[] = "secondaryEmail";
		}
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
		"email" => $user_account["email1"],
		"secondaryEmail" => $user_account["email2"],
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
