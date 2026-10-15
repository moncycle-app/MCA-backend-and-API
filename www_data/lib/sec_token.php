<?php
/* moncycle.app
**
** licence Creative Commons CC BY-NC-SA
**
** https://www.moncycle.app
** https://github.com/moncycle-app/backend-api-web-app
*/

declare(strict_types=1);

// The account's tokens, and /api/token: the sign-in that makes one, the request that carries one (cookie or Bearer), the list and the
// revocation. A browser session and a script's token are the same row; only the name tells them apart. The token itself is kept nowhere:
// its SHA-256, and its first AUTH_TOKEN_PREFIX_CHARS characters in the clear (unique per account), which name it in the list and in
// DELETE /api/token/{prefix}.

require_once __DIR__ . "/day_format.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/http.php";
require_once __DIR__ . "/input.php";
require_once __DIR__ . "/log.php";
require_once __DIR__ . "/sec.php";
require_once __DIR__ . "/sec_captcha.php";

function sec_token_random(int $length = 256): string {
	$alphabet = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ1234567890';
	$token = '';
	for ($i = 0; $i < $length; $i++) $token .= $alphabet[random_int(0, strlen($alphabet) - 1)];
	return $token;
}

// Unsalted, so that a token is found with `WHERE = :hash`: the token's own entropy (256 characters) is what protects it.
function sec_token_hash(string $token): string {
	return hash("sha256", $token);
}

// "" when there is none, or when it is a list ("MONCYCLEAPP_TOKEN[]=x").
function sec_token_from_cookie(): string {
	$token = $_COOKIE[COOKIE_AUTH_TOKEN] ?? "";
	return is_string($token) ? $token : "";
}

// SameSite=None is only honoured on a Secure cookie: without PHP_SECURE_COOKIES it falls back to Lax.
function sec_token_cookie(string $name, string $value, int $expires): void {
	$samesite = COOKIE_SAMESITE === "none" ? (PHP_SECURE_COOKIES ? "None" : "Lax") : (COOKIE_SAMESITE === "lax" ? "Lax" : "Strict");
	setcookie($name, $value, ['expires' => $expires, 'path' => '/', 'secure' => PHP_SECURE_COOKIES, 'httponly' => true, 'samesite' => $samesite]);
}

function sec_token_clear_cookie(): void {
	sec_token_cookie(COOKIE_AUTH_TOKEN, '', -1);
}

// The account of the token the request carries, or null; the account and the session then name every line of the request's log. A
// token past its expiry date opens nothing, whether or not the cron has erased it yet.
function sec_token_authenticate($db): ?array {
	$token = sec_token_from_cookie();
	$authorization = getallheaders()["Authorization"] ?? null;
	if (is_string($authorization) && str_contains($authorization, "Bearer ")) $token = explode(' ', trim($authorization), 2)[1] ?? "";
	if ($token === "") return null;

	$user_account = db_select_user_account_auth_token($db, sec_token_hash($token));
	if (!is_null($user_account) && !boolval($user_account["expired_auth_token"]) && sec_account_enabled($user_account)) {
		db_update_auth_token_use($db, $user_account["no_auth_token"], sec_client_ip(), sec_token_user_agent());
		log_context(["uid" => intval($user_account["no_user_account"]), "sid" => intval($user_account["no_auth_token"])]);
		return $user_account;
	}
	$why = is_null($user_account) ? "unknown" : (boolval($user_account["expired_auth_token"]) ? "expired" : "disabled");
	log_event("auth.token_rejected", ["why" => $why, "uid" => $user_account["no_user_account"] ?? null, "sid" => $user_account["no_auth_token"] ?? null]);
	return null;
}

// api_start() calls it for every method that is not safe; an endpoint calls it itself for what else changes something.
function sec_token_exit_if_read_only(array $user_account): void {
	if (intval($user_account["scope_auth_token"] ?? AUTH_TOKEN_SCOPE_READ_WRITE) === AUTH_TOKEN_SCOPE_READ_ONLY) http_error(403, "read_only_token", "This token is read-only.");
}

// The user agent to keep: valid UTF-8 without control or invisible characters (the account page shows it, and a right-to-left override
// must not reorder the line), cut to the column. null when the client sent none.
function sec_token_user_agent(): ?string {
	$user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
	if (!is_string($user_agent)) return null;
	$user_agent = preg_replace('/\p{C}+/u', '', mb_scrub(substr($user_agent, 0, 4 * AUTH_TOKEN_LIMIT_USER_AGENT_CHARS))) ?? '';
	$user_agent = mb_substr($user_agent, 0, AUTH_TOKEN_LIMIT_USER_AGENT_CHARS);
	return $user_agent === '' ? null : $user_agent;
}

// A new token for the account, with its options (sec_token_parse_options()). A prefix the account already has makes another token.
// A cookie session replaces the one the browser held: nobody can use that one any more, so its row goes. A custom token does not touch
// the cookie of the session it is made from.
function sec_token_create($db, array $user_account, array $options): array {
	$try = 0;
	do {
		if ($try++ === AUTH_TOKEN_PREFIX_TRIES) throw new \RuntimeException("no free token prefix after " . AUTH_TOKEN_PREFIX_TRIES . " tries");
		$token = sec_token_random();
		$prefix = substr($token, 0, AUTH_TOKEN_PREFIX_CHARS);
		$no_auth_token = db_insert_auth_token($db, $user_account["no_user_account"], $options["name"], $options["scope"], $options["expires_in_days"],
			sec_token_user_agent(), sec_client_ip(), sec_token_hash($token), $prefix);
	} while (is_null($no_auth_token));
	log_context(["uid" => intval($user_account["no_user_account"]), "sid" => intval($no_auth_token)]);
	db_update_user_account_logged_in($db, $user_account["no_user_account"]);
	if ($options["cookie"]) {
		if (sec_token_from_cookie() !== "") log_context(["rev" => db_delete_auth_token_by_str($db, sec_token_hash(sec_token_from_cookie())) ?: null]);
		sec_token_cookie(COOKIE_AUTH_TOKEN, $token, strtotime(is_null($options["expires_in_days"]) ? '+5 years' : "+{$options["expires_in_days"]} days"));
	}
	return ["token" => $token, "prefix" => $prefix];
}

// ---------------------------------------------------------------------------
// The sign-in: POST /api/token
// ---------------------------------------------------------------------------

// The options of a new token from the body: [name, scope, expires_in_days, cookie]; a wrong field is an issue. expires_in_days null is
// the cron's rule (AUTH_TOKEN_IDLE_DAYS unused, or AUTH_TOKEN_DEFAULT_LIFETIME_DAYS old).
function sec_token_parse_options(array $body, array &$issues): array {
	$name = AUTH_TOKEN_DEFAULT_NAME;
	$text = day_format_body_text($body, "name", AUTH_TOKEN_LIMIT_NAME_CHARS, $issues);
	if (!is_null($text)) {
		$text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');   // a label in a list: one line
		if ($text !== "") $name = $text;
	}

	$scope = AUTH_TOKEN_SCOPE_READ_WRITE;
	if (array_key_exists("scope", $body)) {
		$found = is_string($body["scope"]) ? array_search($body["scope"], AUTH_TOKEN_SCOPE_NAMES, true) : false;
		if ($found === false) $issues[] = input_issue("scope", "invalid_value", "'scope' must be " . implode(" or ", array_map(fn($n) => "'$n'", AUTH_TOKEN_SCOPE_NAMES)) . ".");
		else $scope = $found;
	}

	$expires_in_days = $body["expiresInDays"] ?? null;
	if (!is_null($expires_in_days) && !is_int($expires_in_days)) {
		input_wrong_type($issues, "expiresInDays", $expires_in_days, "a whole number of days");
		$expires_in_days = null;
	}
	elseif (!is_null($expires_in_days) && ($expires_in_days < AUTH_TOKEN_EXPIRES_DAYS_MIN || $expires_in_days > AUTH_TOKEN_EXPIRES_DAYS_MAX)) {
		$issues[] = input_issue("expiresInDays", "out_of_range", "'expiresInDays' must be a whole number of days from " . AUTH_TOKEN_EXPIRES_DAYS_MIN . " to " . AUTH_TOKEN_EXPIRES_DAYS_MAX . ".");
		$expires_in_days = null;
	}

	return ["name" => $name, "scope" => $scope, "expires_in_days" => $expires_in_days, "cookie" => input_bool_field($body, "cookie", $issues) === true];
}

// The account's recent failed sign-ins: none counts once older than LOGIN_ATTEMPTS_DECAY_MINUTES (0 counts none at all).
function sec_token_login_attempts(array $user_account): int {
	if (LOGIN_ATTEMPTS_DECAY_MINUTES <= 0) return 0;
	if (!isset($user_account["nb_connection_attempts"]) || empty($user_account["last_failed_attempt"])) return 0;
	if (new DateTime($user_account["last_failed_attempt"]) < new DateTime("-" . LOGIN_ATTEMPTS_DECAY_MINUTES . " minutes")) return 0;
	return intval($user_account["nb_connection_attempts"]);
}

// Has the account reached a threshold (LOGIN_CAPTCHA_THRESHOLD, LOGIN_LOCKOUT_THRESHOLD; 0 is off)?
function sec_token_login_over(array $user_account, int $threshold): bool {
	return $threshold > 0 && sec_token_login_attempts($user_account) >= $threshold;
}

// A sign-in. $body has been checked by the endpoint (email normalised, password a non-empty string, the types of code, captcha and
// captchaToken), and so have $token_options. Answers ["status" => 201, "data" => ...] or ["status", "code", "message", "meta"].
// Every outcome is logged. A refusal is counted against the address (a row reserved before the checks, given back on success), and a
// wrong password or code against the account too, which drives the captcha and the lockout. The lockout only stops a wrong password, so
// it cannot lock the owner out; with TOTP it stops the code as well, since the code is then the only thing left to guess.
// An account that must answer a captcha is checked with its row locked from the captcha to the end: the captcha is tied to the
// account's counters, which every outcome moves, so of the requests that come together with one captcha only the first tries a password.
function sec_token_login($db, array $body, array $token_options): array {
	$client_ip = sec_client_ip();
	$user_account = db_select_user_account_by_email($db, $body["email"]) ?? [];
	$account_locked = sec_token_login_over($user_account, LOGIN_LOCKOUT_THRESHOLD);
	$captcha_required = sec_token_login_over($user_account, LOGIN_CAPTCHA_THRESHOLD);
	$meta = $captcha_required ? ["captchaRequired" => true] : [];   // a hint for the next attempt, on every outcome
	$attempt = 0;

	$refuse = function (int $status, string $code, string $message, bool $counted_on_account = false) use (&$user_account, $meta) {
		$known = isset($user_account["no_user_account"]);
		log_event("auth.login_failed", [
			"err" => $code,
			"att" => $known ? sec_token_login_attempts($user_account) + intval($counted_on_account) : null,
			"uid" => $known ? $user_account["no_user_account"] : null,
		]);
		return ["status" => $status, "code" => $code, "message" => $message, "meta" => $meta];
	};
	$counted = function (int $status, string $code, string $message, bool $wrong_credential = false) use ($db, $client_ip, $body, $refuse, &$attempt) {
		if ($wrong_credential) db_update_login_failure($db, $body["email"]);
		if ($attempt === 0) db_insert_login_attempt_ip($db, $client_ip);
		return $refuse($status, $code, $message, $wrong_credential);
	};
	$locked = fn() => $counted(403, "account_locked", "Account temporarily locked after too many failed attempts.", true);

	if (!LOGIN_ENABLED) return $counted(403, "login_disabled", "Login is disabled.");
	if ($captcha_required && !sec_captcha_ready()) return $refuse(503, "captcha_unavailable", "The captcha is not available.");
	// an address already throttled writes nothing, or it could grow the table forever
	$attempt = sec_attempt_reserve($db, $client_ip);
	if (is_null($attempt)) return $refuse(429, "rate_limited", "Too many attempts from this network, please try again later.");

	$check_credentials = function () use ($db, $body, $token_options, $meta, $counted, $refuse, $locked, &$user_account, &$account_locked, &$attempt) {
		if (isset($user_account["user_enabled"]) && !sec_account_enabled($user_account)) return $counted(403, "account_disabled", "Account deactivated.");

		// an address with no account is checked against a hash nobody can match, so that it takes as long
		if (!(password_verify($body["password"], $user_account["password"] ?? AUTH_DUMMY_PASSWORD_HASH) && isset($user_account["password"]))) {
			return $account_locked ? $locked() : $counted(401, "invalid_credentials", "Incorrect password or non-existent account.", true);
		}

		// told only to whoever knows the password; no failure, so nothing is counted
		if (is_null($user_account["email_verified_at"])) {
			sec_attempt_release($db, $attempt);
			return $refuse(403, "email_not_verified", "Verify your email address first: the link was sent to it when the account was created.");
		}

		$totp_active = $user_account["totp_state"] == TOTP_STATE_ACTIVE;
		$code = $body["code"] ?? "";
		if (!is_string($code) && !is_int($code)) $code = "";
		if ($totp_active && $account_locked) return $locked();
		if ($totp_active && !sec_totp_code_valid($user_account, $code)) {
			$given = strlen((string) $code) > 0 && intval(preg_replace('/\s+/', '', (string) $code)) > 0;
			return $given
				? $counted(401, "totp_invalid", "Correct password, but the TOTP code is incorrect.", true)
				: $counted(401, "totp_required", "Correct password, but a TOTP code is required.", true);
		}

		$attempts = sec_token_login_attempts($user_account);   // the failures this sign-in ends
		$session = sec_token_create($db, $user_account, $token_options);
		sec_attempt_release($db, $attempt);
		log_event("auth.login_succeeded", [
			"att" => $attempts, "totp" => $totp_active,
			"scope" => AUTH_TOKEN_SCOPE_NAMES[$token_options["scope"]], "cookie" => $token_options["cookie"],
		]);
		return ["status" => 201, "data" => array_merge([
			"prefix" => $session["prefix"], "token" => $session["token"], "userId" => $user_account["no_user_account"], "totpUsed" => $totp_active,
		], $meta)];
	};

	if (!$captcha_required) return $check_credentials();

	// a wrong captcha counts against the address only: the account's counters are what the captcha is tied to
	$captcha_refused = fn(string $verdict) => $verdict === "expired"
		? $counted(403, "captcha_expired", "The captcha has expired.")
		: $counted(403, "captcha_required", "Missing or incorrect captcha.");
	$captcha_verdict = fn() => sec_captcha_verify($db, "login", $body["email"], $body["captchaToken"] ?? null, $body["captcha"] ?? "", $client_ip);

	// first without the lock, so that a wrong answer (the usual case) never holds the account
	$verdict = $captcha_verdict();
	if ($verdict !== "ok") return $captcha_refused($verdict);

	return db_transaction($db, function () use ($db, $captcha_verdict, $captcha_refused, $check_credentials, &$user_account, &$account_locked) {
		// held until the end, bcrypt included, and read again: a request that came with the same captcha has moved the counters
		$no_user_account = $user_account["no_user_account"];
		$user_account = db_select_user_account_for_update($db, $no_user_account) ? (db_select_user_account($db, $no_user_account) ?? []) : [];
		$account_locked = sec_token_login_over($user_account, LOGIN_LOCKOUT_THRESHOLD);

		$verdict = $captcha_verdict();
		return $verdict === "ok" ? $check_credentials() : $captcha_refused($verdict);
	});
}

// ---------------------------------------------------------------------------
// The list and the revocation: GET and DELETE /api/token
// ---------------------------------------------------------------------------

// The address as the list shows it: cut to AUTH_TOKEN_IP_PREFIX_V4 / _V6 bits. The database keeps it whole.
function sec_token_ip_network(?string $ip): ?string {
	return sec_ip_network($ip, AUTH_TOKEN_IP_PREFIX_V4, AUTH_TOKEN_IP_PREFIX_V6);
}

// One row of db_select_auth_tokens_listing() as the API shows it: no hash, no database id. A token without an expiry date dies a year
// after its creation (or after AUTH_TOKEN_IDLE_DAYS unused, which is no date); one the operator made to last (expire 0) never.
function sec_token_to_json(array $row, int $current_no_auth_token): array {
	$expires_at = $row["date_expire"];
	if (is_null($expires_at) && intval($row["expire"]) !== 0) {
		$expires_at = gmdate("Y-m-d H:i:s", strtotime($row["date_creation"] . " UTC") + AUTH_TOKEN_DEFAULT_LIFETIME_DAYS * 86400);
	}
	return [
		"prefix" => $row["auth_token_prefix"],
		"name" => $row["name"],
		"scope" => AUTH_TOKEN_SCOPE_NAMES[intval($row["scope"])] ?? AUTH_TOKEN_SCOPE_NAMES[AUTH_TOKEN_SCOPE_READ_WRITE],
		"current" => intval($row["no_auth_token"]) === $current_no_auth_token,
		"createdAt" => http_iso8601($row["date_creation"]),
		"createdUserAgent" => $row["user_agent"],
		"createdIp" => sec_token_ip_network($row["ip_address"]),
		"lastUsedAt" => http_iso8601($row["date_use"]),
		"lastUsedUserAgent" => $row["user_agent_use"],
		"lastUsedIp" => sec_token_ip_network($row["ip_address_use"]),
		"expiresAt" => http_iso8601($expires_at),
	];
}

// The account's tokens, the newest first, the caller's own "current".
function sec_token_list($db, array $user_account): array {
	$current = intval($user_account["no_auth_token"]);
	return array_map(fn($row) => sec_token_to_json($row, $current), db_select_auth_tokens_listing($db, $user_account["no_user_account"]));
}

// The token just made, as the list shows it, null when it is not there.
function sec_token_find($db, int $no_user_account, string $prefix): ?array {
	foreach (db_select_auth_tokens_listing($db, $no_user_account) as $row) {
		if ($row["auth_token_prefix"] === $prefix) return sec_token_to_json($row, intval($row["no_auth_token"]));
	}
	return null;
}

// A demo account's tokens are its visitors' sessions: listing or revoking them belongs to no one.
function sec_token_exit_if_demo(array $user_account): void {
	if (sec_session_is_demo($user_account)) http_error(403, "demo_account", "Not available on a demo account.");
}

// Revokes the token of this prefix, or with a null $prefix every token but the caller's own; answers the ids revoked. A token of another
// account is "not found". A read-only token may end its own session and nothing else; ending its own clears the cookie.
function sec_token_revoke($db, array $user_account, ?string $prefix): array {
	$no_user_account = intval($user_account["no_user_account"]);
	$own = !is_null($prefix) && $prefix === $user_account["prefix_auth_token"];
	if (!$own) {
		sec_token_exit_if_read_only($user_account);
		sec_token_exit_if_demo($user_account);
	}
	if (is_null($prefix)) return db_delete_auth_tokens_other($db, $no_user_account, intval($user_account["no_auth_token"]));

	$revoked = db_delete_auth_token_by_prefix($db, $no_user_account, $prefix);
	if (!$revoked) http_error(404, "not_found", "No token of this account starts with these characters.");
	if ($own) sec_token_clear_cookie();
	return $revoked;
}
