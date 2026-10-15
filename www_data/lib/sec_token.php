<?php
/* moncycle.app
**
** licence Creative Commons CC BY-NC-SA
**
** https://www.moncycle.app
** https://github.com/moncycle-app/backend-api-web-app
*/

require_once __DIR__ . "/day_format.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/http.php";
require_once __DIR__ . "/input.php";
require_once __DIR__ . "/log.php";
require_once __DIR__ . "/sec.php";
require_once __DIR__ . "/sec_captcha.php";

// What the account's tokens look like to the account page and to API clients: a list, a revocation, and what a client may ask of a
// new token (POST /api/token). A token is a session (the browser's, in a cookie) or a custom token (a script's, in a header): the
// table and the endpoint do not tell them apart, the name does.

// The options of a new token, from the body of POST /api/token: [name, scope, expires_in_days, cookie] as sec_token_create() takes
// them. A body field that is not what it must be is an issue (the endpoint ends the request with them, before anything is read from the
// database). "name" is the client's own text (a browser says "WEB-APP <domain>"), cleaned like any text a client stores; "expiresInDays"
// is a whole number of days, absent for the rule of the cron (40 days unused, or a year old).
function sec_token_parse_options(array $body, array &$issues): array {
	$name = AUTH_TOKEN_DEFAULT_NAME;
	$text = day_format_body_text($body, "name", AUTH_TOKEN_LIMIT_NAME_CHARS, $issues);
	if (!is_null($text)) {
		// one line: a name is a label in a list (day_format_clean_text() keeps line breaks and tabs, which a comment may have)
		$text = trim(preg_replace('/\s+/u', ' ', $text));
		if ($text !== "") $name = $text;
	}

	$scope = AUTH_TOKEN_SCOPE_READ_WRITE;
	if (array_key_exists("scope", $body)) {
		$found = is_string($body["scope"]) ? array_search($body["scope"], AUTH_TOKEN_SCOPE_NAMES, true) : false;
		if ($found === false) $issues[] = input_issue("scope", "invalid_value", "'scope' must be " . implode(" or ", array_map(fn($n) => "'$n'", AUTH_TOKEN_SCOPE_NAMES)) . ".");
		else $scope = $found;
	}

	$expires_in_days = $body["expiresInDays"] ?? null;
	if (!is_null($expires_in_days)) {
		if (!is_int($expires_in_days)) {
			input_wrong_type($issues, "expiresInDays", $expires_in_days, "a whole number of days");
			$expires_in_days = null;
		}
		elseif ($expires_in_days < AUTH_TOKEN_EXPIRES_DAYS_MIN || $expires_in_days > AUTH_TOKEN_EXPIRES_DAYS_MAX) {
			$issues[] = input_issue("expiresInDays", "out_of_range", "'expiresInDays' must be a whole number of days from " . AUTH_TOKEN_EXPIRES_DAYS_MIN . " to " . AUTH_TOKEN_EXPIRES_DAYS_MAX . ".");
			$expires_in_days = null;
		}
	}

	$cookie = input_bool_field($body, "cookie", $issues);

	return ["name" => $name, "scope" => $scope, "expires_in_days" => $expires_in_days, "cookie" => $cookie === true];
}

// An address cut to its network, as the token list shows it: the first AUTH_TOKEN_IP_PREFIX_V4 bits of an IPv4 address ("a.b.c.0"),
// the first AUTH_TOKEN_IP_PREFIX_V6 of an IPv6 one ("2001:db8:1234::"). An IPv4-mapped IPv6 address is read as the IPv4 one.
// null when there is none or it is not an address. The database keeps the whole address.
function sec_token_ip_network(?string $ip): ?string {
	$packed = is_null($ip) ? false : inet_pton($ip);
	if ($packed === false) return null;
	if (strlen($packed) === 16 && str_starts_with($packed, str_repeat("\0", 10) . "\xff\xff")) $packed = substr($packed, 12);

	$bits = strlen($packed) === 4 ? AUTH_TOKEN_IP_PREFIX_V4 : AUTH_TOKEN_IP_PREFIX_V6;
	$whole_bytes = intdiv($bits, 8);
	$network = substr($packed, 0, $whole_bytes);
	if ($bits % 8 !== 0) $network .= chr(ord($packed[$whole_bytes]) & ((0xFF << (8 - $bits % 8)) & 0xFF));
	return inet_ntop(str_pad($network, strlen($packed), "\0"));
}

// When a token dies, "Y-m-d H:i:s" UTC: its expiry date when it has one, else the year after its creation (a token with none is
// also erased after AUTH_TOKEN_IDLE_DAYS without use: that is not a date), null for a token the operator made to last (expire 0).
function sec_token_expires_at(array $row): ?string {
	if (!is_null($row["date_expire"])) return $row["date_expire"];
	if (intval($row["expire"]) === 0) return null;
	return gmdate("Y-m-d H:i:s", strtotime($row["date_creation"] . " UTC") + AUTH_TOKEN_DEFAULT_LIFETIME_DAYS * 86400);
}

// One row of db_select_auth_tokens_listing() in the JSON API's shape. The hash is not in it, and the token itself is kept nowhere:
// it was shown once, when it was made; what is shown is its first characters ("prefix"), which name it for DELETE /api/token/{prefix} (null
// for a token of before v15 that started like another of its account). The addresses are cut (sec_token_ip_network()). No database id.
function sec_token_to_json(array $row, int $current_no_auth_token): array {
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
		"expiresAt" => http_iso8601(sec_token_expires_at($row)),
	];
}

// The account's tokens, the newest first, the caller's own marked "current".
function sec_token_list($db, array $user_account): array {
	$current = intval($user_account["no_auth_token"]);
	return array_map(fn($row) => sec_token_to_json($row, $current), db_select_auth_tokens_listing($db, $user_account["no_user_account"]));
}

// The JSON of one token of the account, by its prefix (the one just made, so the caller's own), null when it is not there.
function sec_token_find($db, int $no_user_account, string $prefix): ?array {
	foreach (db_select_auth_tokens_listing($db, $no_user_account) as $row) {
		if ($row["auth_token_prefix"] === $prefix) return sec_token_to_json($row, intval($row["no_auth_token"]));
	}
	return null;
}

// Is this what a client may send to name a token: exactly AUTH_TOKEN_PREFIX_CHARS characters of the alphabet tokens are made of?
function sec_token_prefix_is_valid(mixed $prefix): bool {
	return is_string($prefix) && preg_match('/\A[A-Za-z0-9]{' . AUTH_TOKEN_PREFIX_CHARS . '}\z/', $prefix) === 1;
}

// The demo accounts are shared by every visitor (their password is on the sign-in page): their tokens are the visitors' sessions, and
// neither the list nor the revocation of the others' belongs to anyone. Ends the request with a 403.
function sec_token_exit_if_demo(array $user_account): void {
	if (sec_session_is_demo($user_account)) http_error(403, "demo_account", "Not available on a demo account.");
}

// DELETE /api/token/{prefix} revokes the token that starts with these characters, DELETE /api/token?others=true every token but the caller's
// own. $prefix is null for the second. Answers the ids of the tokens revoked (the log names them). A token of another account is "not found", as one that does not exist.
// A read-only token may end its own session and nothing else. The caller's own token going (by its prefix) is a logout: the cookie goes with it.
function sec_token_revoke($db, array $user_account, ?string $prefix): array {
	$no_user_account = intval($user_account["no_user_account"]);

	if (is_null($prefix)) {
		sec_token_exit_if_read_only($user_account);
		sec_token_exit_if_demo($user_account);
		return db_delete_auth_tokens_other($db, $no_user_account, intval($user_account["no_auth_token"]));
	}

	$own = $prefix === $user_account["prefix_auth_token"];
	if (!$own) {
		sec_token_exit_if_read_only($user_account);
		sec_token_exit_if_demo($user_account);
	}
	$revoked = db_delete_auth_token_by_prefix($db, $no_user_account, $prefix);
	if (!$revoked) http_error(404, "not_found", "No token of this account starts with these characters.");
	if ($own) sec_token_clear_cookie();
	return $revoked;
}


// fast, non-salted hash used to store/look up auth tokens: unlike sec_hash(), it must be
// deterministic so a token can be matched with a plain `WHERE = :hash` query. Safe here
// because the token itself already carries 256 chars of entropy (see sec_random_password).
function sec_token_hash($token) {
	return hash("sha256", $token);
}

// the session token cookie of the request, "" when there is none (or when it is a list: a client can
// send "MONCYCLEAPP_TOKEN[]=x", and PHP reads it as one)
function sec_token_from_cookie(): string {
	$token = $_COOKIE[COOKIE_AUTH_TOKEN] ?? "";
	return is_string($token) ? $token : "";
}

// The SameSite attribute of the session cookie (COOKIE_SAMESITE). None is only honoured on a Secure
// cookie: a browser drops a SameSite=None cookie that is not, so it falls back to Lax.
function sec_token_cookie_samesite(): string {
	if (COOKIE_SAMESITE === "none") return PHP_SECURE_COOKIES ? "None" : "Lax";
	return COOKIE_SAMESITE === "lax" ? "Lax" : "Strict";
}

function sec_token_set_cookie(string $token, string $lifetime): void {
	setcookie(COOKIE_AUTH_TOKEN, $token, [
		'expires' => strtotime($lifetime),
		'path' => '/',
		'secure' => PHP_SECURE_COOKIES,
		'httponly' => true,
		'samesite' => sec_token_cookie_samesite(),
	]);
}

function sec_token_clear_cookie(): void {
	foreach ([COOKIE_AUTH_TOKEN, COOKIE_AUTH_TOKEN_LEGACY] as $name) {
		setcookie($name, '', [
			'expires' => -1,
			'path' => '/',
			'secure' => PHP_SECURE_COOKIES,
			'httponly' => true,
			'samesite' => sec_token_cookie_samesite(),
		]);
	}
}

// The account of the token the request carries (cookie, or "Authorization: Bearer"), or null. The
// account and the session it finds are the ones every line of the request's log carries. A token past its
// expiry date (POST /api/token, "expiresInDays") opens nothing, whether or not the cron has erased it yet.
function sec_token_authenticate($db) {
	$auth_token = $_COOKIE[COOKIE_AUTH_TOKEN_LEGACY] ?? ""; // legacy, to be removed in a few release
	if (!is_string($auth_token)) $auth_token = "";
	if (sec_token_from_cookie() !== "") $auth_token = sec_token_from_cookie();
	$head = getallheaders();
	if (isset($head["Authorization"]) && str_contains($head["Authorization"], "Bearer ")) $auth_token = explode(' ', trim($head["Authorization"]), 2)[1];
	if (strlen($auth_token) > 0) {
		$user_account = db_select_user_account_auth_token($db, sec_token_hash($auth_token));
		if (!is_null($user_account) && !boolval($user_account["expired_auth_token"]) && sec_account_enabled($user_account)) {
			db_update_auth_token_use($db, $user_account["no_auth_token"], sec_client_ip(), sec_token_user_agent());
			log_context(["uid" => intval($user_account["no_user_account"]), "sid" => intval($user_account["no_auth_token"])]);
			return $user_account;
		}
		$why = is_null($user_account) ? "unknown" : (boolval($user_account["expired_auth_token"]) ? "expired" : "disabled");
		log_event("auth.token_rejected", ["why" => $why, "uid" => $user_account["no_user_account"] ?? null, "sid" => $user_account["no_auth_token"] ?? null]);
	}
	return null;
}

// Is the token of the request a read-only one? (POST /api/token, "scope".)
function sec_token_is_read_only(array $user_account): bool {
	return intval($user_account["scope_auth_token"] ?? AUTH_TOKEN_SCOPE_READ_WRITE) === AUTH_TOKEN_SCOPE_READ_ONLY;
}

// Ends the request with a 403 when the token is read-only. api_start() calls it for every method that is not safe (HTTP_SAFE_METHODS);
// an endpoint calls it itself for a GET that changes something (GET /api/totp starts a set-up).
function sec_token_exit_if_read_only(array $user_account): void {
	if (!sec_token_is_read_only($user_account)) return;
	http_error(403, "read_only_token", "This token is read-only.");
}

// The user agent the server received, for the token to keep: made valid UTF-8, stripped of every control and invisible character
// (a header can be 8 KB, is not always UTF-8 and is the client's own: the account page shows it, and a right-to-left override must
// not reorder the line around it) and cut to what the column holds. null when the client sent none.
function sec_token_user_agent(): ?string {
	$user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
	if (!is_string($user_agent)) return null;
	$user_agent = preg_replace('/\p{C}+/u', '', mb_scrub(substr($user_agent, 0, 4 * AUTH_TOKEN_LIMIT_USER_AGENT_CHARS))) ?? '';
	$user_agent = mb_substr($user_agent, 0, AUTH_TOKEN_LIMIT_USER_AGENT_CHARS);
	return $user_agent === '' ? null : $user_agent;
}

// Opens a session: stores a new token for the account and returns it, with what the server saw of the client (its address, its
// user agent). $options: "name", "scope" (AUTH_TOKEN_SCOPE_*), "expires_in_days" (null: the cron's rule), "cookie" (set the cookie of
// the token: a browser's session; a custom token made on the account page must not replace the cookie it is made from, so it
// does not). A cookie session replaces the one the browser held, which nobody holds any more: that row goes, or the list of
// tokens would fill with sessions nobody can use. The new session is the one the rest of the request's log names. The token's first
// characters (AUTH_TOKEN_PREFIX_CHARS) are kept in the clear and are unique per account: a token that would repeat one is made again.
function sec_token_create($db, $user_account, array $options = []) {
	$scope = $options["scope"] ?? AUTH_TOKEN_SCOPE_READ_WRITE;
	$expires_in_days = $options["expires_in_days"] ?? null;

	$no_auth_token = null;
	for ($try = 0; is_null($no_auth_token); $try++) {
		if ($try === AUTH_TOKEN_PREFIX_TRIES) throw new \RuntimeException("no free token prefix after $try tries");
		$auth_token = sec_random_password(256);
		$prefix = substr($auth_token, 0, AUTH_TOKEN_PREFIX_CHARS);
		$no_auth_token = db_insert_auth_token($db, $user_account["no_user_account"], $options["name"] ?? AUTH_TOKEN_DEFAULT_NAME, $scope, $expires_in_days, sec_token_user_agent(), sec_client_ip(), sec_token_hash($auth_token), $prefix);
	}
	log_context(["uid" => intval($user_account["no_user_account"]), "sid" => intval($no_auth_token)]);
	db_update_user_account_logged_in($db, $user_account["no_user_account"]);
	if ($options["cookie"] ?? false) {
		if (sec_token_from_cookie() !== "") log_context(["rev" => db_delete_auth_token_by_str($db, sec_token_hash(sec_token_from_cookie())) ?: null]);
		sec_token_set_cookie($auth_token, is_null($expires_in_days) ? '+5 years' : "+$expires_in_days days");
	}

	return ["token" => $auth_token, "prefix" => $prefix];
}

// number of recent failed login attempts on this account, ignoring attempts older than
// LOGIN_ATTEMPTS_DECAY_MINUTES so a stale streak doesn't linger forever. The login settings are
// in the config file, and each one at 0 turns its feature off: a decay of 0 counts no attempt
// at all, so neither the captcha nor the lockout can trigger.
function sec_token_login_attempts($user_account) {
	if (LOGIN_ATTEMPTS_DECAY_MINUTES <= 0) return 0;
	if (!isset($user_account["nb_connection_attempts"]) || empty($user_account["last_failed_attempt"])) return 0;

	$cutoff = new DateTime("-" . LOGIN_ATTEMPTS_DECAY_MINUTES . " minutes");
	if (new DateTime($user_account["last_failed_attempt"]) < $cutoff) return 0;

	return intval($user_account["nb_connection_attempts"]);
}

function sec_token_login_captcha_required($user_account) {
	return LOGIN_CAPTCHA_THRESHOLD > 0 && sec_token_login_attempts($user_account) >= LOGIN_CAPTCHA_THRESHOLD;
}

function sec_token_login_locked($user_account) {
	return LOGIN_LOCKOUT_THRESHOLD > 0 && sec_token_login_attempts($user_account) >= LOGIN_LOCKOUT_THRESHOLD;
}

// The outcome of a login attempt, for the endpoint to answer with:
//   success: ["status" => 200, "data" => [...]]
//   refusal: ["status" => 4xx/503, "code" => ..., "message" => ..., "meta" => [...]]
// Every refusal but a malformed body, a rate-limited IP and a closed captcha is also counted against the IP, and a
// wrong password or TOTP code against the account (the captcha and lockout thresholds). The IP is counted by a row
// that sec_attempt_reserve() writes before the checks and the login gives back when it succeeds.
// An account that must answer a captcha (captchaRequired) is checked with its row locked, from the captcha to the end:
// the token is tied to the account's counters, which every outcome that tests a password changes, so of the requests
// that arrive together with one token the first takes it and the others find the counters moved: one password is tried
// per token. (The answer of an account that is deactivated changes no counter: that token can be tried again, and only
// ever gets `account_disabled`. And a login that succeeds ends the failures: a request that reads the account after
// it finds one that asks for no captcha, and signs in on its password alone like any login.)
// Every outcome is logged: the refusals as `auth.login_failed`, with the account when the address
// has one and its count of failed attempts, this one included when it was counted against it.
// $body["email"] (a valid address, normalised: user_account_email_normalise(), how it is stored) and $body["password"] (a non-empty string) have been
// checked by the endpoint (api/token.php), and so have the types of "code", "captcha" and "captchaToken".
// $token_options is what sec_token_create() takes (name, scope, expires_in_days, cookie), checked by the endpoint (sec_token_parse_options()).
function sec_token_login($db, array $body, array $token_options = []): array {
	$user_account = [];

	$refuse = function (int $status, string $code, string $message, array $meta = [], bool $counted_on_account = false) use (&$user_account) {
		$known = isset($user_account["no_user_account"]);
		log_event("auth.login_failed", [
			"err" => $code,
			"att" => $known ? sec_token_login_attempts($user_account) + intval($counted_on_account) : null,
			"uid" => $known ? $user_account["no_user_account"] : null,
		]);
		return ["status" => $status, "code" => $code, "message" => $message, "meta" => $meta];
	};

	$client_ip = sec_client_ip();
	$user_account = db_select_user_account_by_email($db, $body["email"]) ?? [];
	$account_locked = sec_token_login_locked($user_account);

	// surfaced on every outcome below (successes included): it is a hint for the client's *next*
	// attempt, not tied to whether this one succeeded.
	$captcha_required = sec_token_login_captcha_required($user_account);
	$meta = $captcha_required ? ["captchaRequired" => true] : [];

	// the row this login reserved against the address: 0 until it has (login_disabled is counted without one)
	$attempt = 0;

	// a refusal counted against the IP (its reserved row stays), and against the account when it is a wrong credential
	$counted = function (int $status, string $code, string $message, bool $wrong_credential = false) use ($db, $client_ip, $body, $meta, $refuse, &$attempt) {
		if ($wrong_credential) db_update_login_failure($db, $body["email"]);
		if ($attempt === 0) db_insert_login_attempt_ip($db, $client_ip);
		return $refuse($status, $code, $message, $meta, $wrong_credential);
	};

	if (!LOGIN_ENABLED) return $counted(403, "login_disabled", "Login is disabled.");

	// no key to check the captcha with: the server is at fault, nothing is counted against anyone
	if ($captcha_required && !sec_captcha_ready()) return $refuse(503, "captcha_unavailable", "The captcha is not available.", $meta);

	// already throttled: nothing is written, or a blocked IP could grow this table forever
	$attempt = sec_attempt_reserve($db, $client_ip);
	if (is_null($attempt)) {
		return $refuse(429, "rate_limited", "Too many attempts from this network, please try again later.", $meta);
	}

	// the account, the password, the code: what a login is once the captcha (if it is asked) is passed
	$check_credentials = function () use ($db, $body, $token_options, $meta, $counted, $refuse, &$user_account, &$account_locked, &$attempt) {
		if (isset($user_account["user_enabled"]) && !sec_account_enabled($user_account)) return $counted(403, "account_disabled", "Account deactivated.");

		// an address with no account is checked against a hash nobody can match, for the time a check takes
		$password_ok = password_verify($body["password"], $user_account["password"] ?? AUTH_DUMMY_PASSWORD_HASH) && isset($user_account["password"]);

		// hard lockout only kicks in on a WRONG password: the account owner can still log in with the
		// correct credentials, so this can't be abused to lock a victim out (an account with a TOTP code
		// is the exception, see below)
		if (!$password_ok) {
			return $account_locked
				? $counted(403, "account_locked", "Account temporarily locked after too many failed attempts.", true)
				: $counted(401, "invalid_credentials", "Incorrect password or non-existent account.", true);
		}

		// An address nobody proved yet: said only to whoever knows the password, so that a sign-in tells nothing about an account it
		// cannot open. The credentials were right, so it is no failure: nothing is counted, the attempt is given back.
		if (is_null($user_account["email_verified_at"])) {
			sec_attempt_release($db, $attempt);
			return $refuse(403, "email_not_verified", "Verify your email address first: the link was sent to it when the account was created.", $meta);
		}

		$totp_active = $user_account["totp_state"] == TOTP_STATE_ACTIVE;
		$code = $body["code"] ?? "";
		if (!is_string($code) && !is_int($code)) $code = "";
		// With the password right, the code is the only thing left to guess: the wrong ones are counted on the
		// account (below), and past the lockout threshold the code is no longer looked at, the right one included.
		// The password alone can then keep a TOTP account locked: the price of a code that cannot be guessed.
		if ($totp_active && $account_locked) {
			return $counted(403, "account_locked", "Account temporarily locked after too many failed attempts.", true);
		}
		if ($totp_active && !sec_totp_code_valid($user_account, $code)) {
			// wrong or missing code: one outcome either way
			$given = strlen((string) $code) > 0 && intval(preg_replace('/\s+/', '', (string) $code)) > 0;
			return $counted(401, $given ? "totp_invalid" : "totp_required",
				$given ? "Correct password, but the TOTP code is incorrect." : "Correct password, but a TOTP code is required.", true);
		}

		// the failures this login ends, read before sec_token_create() resets them
		$attempts = sec_token_login_attempts($user_account);
		unset($user_account["password"], $user_account["totp_secret"], $user_account["email_token_hash"]);
		$session = sec_token_create($db, $user_account, $token_options);
		sec_attempt_release($db, $attempt);
		log_event("auth.login_succeeded", [
			"att" => $attempts,
			"totp" => $totp_active,
			"scope" => AUTH_TOKEN_SCOPE_NAMES[$token_options["scope"] ?? AUTH_TOKEN_SCOPE_READ_WRITE],
			"cookie" => boolval($token_options["cookie"] ?? false),
		]);

		return ["status" => 201, "data" => array_merge([
			"prefix" => $session["prefix"],
			"token" => $session["token"],
			"userId" => $user_account["no_user_account"],
			"totpUsed" => $totp_active,
		], $meta)];
	};

	if (!$captcha_required) return $check_credentials();

	// A wrong or missing captcha: one refusal, or "expired" for a token that is past its time. It is counted against the
	// IP only, not the account: the counters are what the token is tied to.
	$captcha_refused = fn(string $verdict) => $verdict === "expired"
		? $counted(403, "captcha_expired", "The captcha has expired.")
		: $counted(403, "captcha_required", "Missing or incorrect captcha.");
	$captcha_verdict = fn() => sec_captcha_verify($db, "login", $body["email"], $body["captchaToken"] ?? null, $body["captcha"] ?? "", $client_ip);

	// checked first without a lock, so that a request that cannot pass (a wrong answer is the usual one) never holds
	// the account: only a valid token takes it
	$verdict = $captcha_verdict();
	if ($verdict !== "ok") return $captcha_refused($verdict);

	return db_transaction($db, function () use ($db, $captcha_verdict, $captcha_refused, $check_credentials, &$user_account, &$account_locked) {
		// the row is held until the end of the login, bcrypt included, and read again under the lock: a request that
		// came with the same token has finished by now, and the counters it moved no longer match
		$no_user_account = $user_account["no_user_account"];
		$user_account = db_select_user_account_for_update($db, $no_user_account) ? (db_select_user_account($db, $no_user_account) ?? []) : [];
		$account_locked = sec_token_login_locked($user_account);

		$verdict = $captcha_verdict();
		return $verdict === "ok" ? $check_credentials() : $captcha_refused($verdict);
	});
}
