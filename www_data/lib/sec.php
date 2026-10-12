<?php
/* moncycle.app
**
** licence Creative Commons CC BY-NC-SA
**
** https://www.moncycle.app
** https://github.com/moncycle-app/backend-api-web-app
*/

use OTPHP\TOTP;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

require_once __DIR__ . "/../vendor/autoload.php";
require_once __DIR__ . "/captcha.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/http.php";
require_once __DIR__ . "/log.php";

// ---------------------------------------------------------------------------
// Passwords and tokens
// ---------------------------------------------------------------------------

function sec_random_password($length=12){
	$alphabet = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ1234567890';
	$pass = [];
	$alphaLength = strlen($alphabet)-1;
	for ($i = 0; $i < $length; $i++) {
		$n = random_int(0, $alphaLength);
		$pass[] = $alphabet[$n];
	}
	return implode($pass);
}

function sec_hash($text) {
	return password_hash($text, PASSWORD_BCRYPT);
}

// fast, non-salted hash used to store/look up auth tokens: unlike sec_hash(), it must be
// deterministic so a token can be matched with a plain `WHERE = :hash` query. Safe here
// because the token itself already carries 256 chars of entropy (see sec_random_password).
function sec_hash_token($token) {
	return hash("sha256", $token);
}

// the session token cookie of the request, "" when there is none (or when it is a list: a client can
// send "MONCYCLEAPP_TOKEN[]=x", and PHP reads it as one)
function sec_cookie_token(): string {
	$token = $_COOKIE[COOKIE_AUTH_TOKEN] ?? "";
	return is_string($token) ? $token : "";
}

// The SameSite attribute of the session cookie (COOKIE_SAMESITE). None is only honoured on a Secure
// cookie: a browser drops a SameSite=None cookie that is not, so it falls back to Lax.
function sec_cookie_samesite(): string {
	if (COOKIE_SAMESITE === "none") return PHP_SECURE_COOKIES ? "None" : "Lax";
	return COOKIE_SAMESITE === "lax" ? "Lax" : "Strict";
}

function sec_set_token_cookie(string $token, string $lifetime): void {
	setcookie(COOKIE_AUTH_TOKEN, $token, [
		'expires' => strtotime($lifetime),
		'path' => '/',
		'secure' => PHP_SECURE_COOKIES,
		'httponly' => true,
		'samesite' => sec_cookie_samesite(),
	]);
}

function sec_clear_token_cookie(): void {
	foreach ([COOKIE_AUTH_TOKEN, COOKIE_AUTH_TOKEN_LEGACY] as $name) {
		setcookie($name, '', [
			'expires' => -1,
			'path' => '/',
			'secure' => PHP_SECURE_COOKIES,
			'httponly' => true,
			'samesite' => sec_cookie_samesite(),
		]);
	}
}

// The account of the token the request carries (cookie, or "Authorization: Bearer"), or null. The
// account and the session it finds are the ones every line of the request's log carries. A token past its
// expiry date (POST /api/token, "expiresInDays") opens nothing, whether or not the cron has erased it yet.
function sec_auth_token($db) {
	$auth_token = $_COOKIE[COOKIE_AUTH_TOKEN_LEGACY] ?? ""; // legacy, to be removed in a few release
	if (!is_string($auth_token)) $auth_token = "";
	if (sec_cookie_token() !== "") $auth_token = sec_cookie_token();
	$head = getallheaders();
	if (isset($head["Authorization"]) && str_contains($head["Authorization"], "Bearer ")) $auth_token = explode(' ', trim($head["Authorization"]), 2)[1];
	if (strlen($auth_token) > 0) {
		$user_account = db_select_user_account_auth_token($db, sec_hash_token($auth_token));
		if (!is_null($user_account) && !boolval($user_account["expired_auth_token"]) && sec_account_enabled($user_account)) {
			db_update_auth_token_use($db, $user_account["no_auth_token"], sec_client_ip(), sec_user_agent());
			log_context(["uid" => intval($user_account["no_user_account"]), "sid" => intval($user_account["no_auth_token"])]);
			return $user_account;
		}
		$why = is_null($user_account) ? "unknown" : (boolval($user_account["expired_auth_token"]) ? "expired" : "disabled");
		log_event("auth.token_rejected", ["why" => $why, "uid" => $user_account["no_user_account"] ?? null]);
	}
	return null;
}

function sec_exit_if_logged_out($user_account) {
	if (is_null($user_account)) {
		log_note(["err" => "unauthorized"]);
		http_response_code(401);
		echo json_encode(["error" => ["code" => "unauthorized", "message" => "Authentication required."]]);
		exit;
	}
}

// Is the token of the request a read-only one? (POST /api/token, "scope".)
function sec_token_is_read_only(array $user_account): bool {
	return intval($user_account["scope_auth_token"] ?? AUTH_TOKEN_SCOPE_READ_WRITE) === AUTH_TOKEN_SCOPE_READ_ONLY;
}

// Ends the request with a 403 when the token is read-only. api_start() calls it for every method that is not safe (HTTP_SAFE_METHODS);
// an endpoint calls it itself for a GET that changes something (GET /api/totp starts a set-up).
function sec_exit_if_token_read_only(array $user_account): void {
	if (!sec_token_is_read_only($user_account)) return;
	http_error(403, "read_only_token", "This token is read-only.");
}

// Is the caller logged in as one of the public demo accounts? Its password is written on the sign-in page, so it is
// no session to protect: register treats whoever holds one as a visitor, who can then sign up.
function sec_session_is_demo(?array $user_account): bool {
	return !is_null($user_account) && in_array(intval($user_account["no_user_account"] ?? 0), ACCOUNT_DEMO_IDS, true);
}

// May this account sign in, and does its session still count? Not when it is deactivated (user_enabled), nor when it is
// one of the demo accounts and DEMO_ENABLED is off: their sessions then stop working at once, and their sign-in is
// refused like a deactivated account's.
function sec_account_enabled(array $user_account): bool {
	if (!boolval($user_account["user_enabled"] ?? false)) return false;
	return DEMO_ENABLED || !sec_session_is_demo($user_account);
}

function sec_redirect_if_logged_out($user_account) {
	if (is_null($user_account)) {
		header('Location: auth');
		http_response_code(401);
		exit;
	}
}

// The user agent the server received, for the token to keep: made valid UTF-8, stripped of every control and invisible character
// (a header can be 8 KB, is not always UTF-8 and is the client's own: the account page shows it, and a right-to-left override must
// not reorder the line around it) and cut to what the column holds. null when the client sent none.
function sec_user_agent(): ?string {
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
function sec_auth_success($db, $user_account, array $options = []) {
	$scope = $options["scope"] ?? AUTH_TOKEN_SCOPE_READ_WRITE;
	$expires_in_days = $options["expires_in_days"] ?? null;

	$no_auth_token = null;
	for ($try = 0; is_null($no_auth_token); $try++) {
		if ($try === AUTH_TOKEN_PREFIX_TRIES) throw new \RuntimeException("no free token prefix after $try tries");
		$auth_token = sec_random_password(256);
		$prefix = substr($auth_token, 0, AUTH_TOKEN_PREFIX_CHARS);
		$no_auth_token = db_insert_auth_token($db, $user_account["no_user_account"], $options["name"] ?? AUTH_TOKEN_DEFAULT_NAME, $scope, $expires_in_days, sec_user_agent(), sec_client_ip(), sec_hash_token($auth_token), $prefix);
	}
	log_context(["uid" => intval($user_account["no_user_account"]), "sid" => intval($no_auth_token)]);
	db_update_user_account_logged_in($db, $user_account["no_user_account"]);
	if ($options["cookie"] ?? false) {
		if (sec_cookie_token() !== "") db_delete_auth_token_by_str($db, sec_hash_token(sec_cookie_token()));
		sec_set_token_cookie($auth_token, is_null($expires_in_days) ? '+5 years' : "+$expires_in_days days");
	}

	return ["token" => $auth_token, "prefix" => $prefix];
}

function sec_obfuscate($str) {
	return substr($str, 0, 3) . " [masqué] " . substr($str, -3);
}

// the row with the given secret columns masked, for the data export
function sec_obfuscate_columns(array $row, array $columns): array {
	foreach ($columns as $column) {
		if (isset($row[$column])) $row[$column] = sec_obfuscate($row[$column]);
	}
	return $row;
}

// ---------------------------------------------------------------------------
// Where a write comes from (CSRF)
// ---------------------------------------------------------------------------

// "scheme://host[:port]" of a URL in lower case, or "" when it is not one.
function sec_origin_of_url(string $url): string {
	$parts = parse_url($url);
	if (!is_array($parts) || empty($parts["scheme"]) || empty($parts["host"])) return "";
	return strtolower($parts["scheme"] . "://" . $parts["host"] . (isset($parts["port"]) ? ":" . $parts["port"] : ""));
}

// May a browser at this origin write to this API? Its own origin (the host the request was sent to; the
// scheme is not compared: behind a TLS proxy the app sees plain http), the web app's (APP_URL), and the
// ones the operator lists (WEB_APP_ORIGINS). Nothing is assumed about the web app sharing the API's
// origin: a staging site or a front end on a sibling subdomain is one more entry.
function sec_origin_allowed(string $origin): bool {
	$origin = strtolower($origin);
	$host = $_SERVER['HTTP_HOST'] ?? "";
	if (is_string($host) && $host !== "" && preg_match('#^https?://' . preg_quote(strtolower($host), '#') . '\z#', $origin)) return true;
	return $origin === sec_origin_of_url(APP_URL) || in_array($origin, WEB_APP_ORIGINS, true);
}

// Ends the request with a 403 when a browser sends a write (any method but GET, HEAD, OPTIONS) from a
// page that is not ours, whoever is logged in: the session cookie rides along on such a request, and
// login is itself a target (login CSRF). Origin decides first: a browser sends it on every write, and
// "Origin: null" (a sandboxed page, a redirect) is refused with the rest. Only when it is absent does
// Sec-Fetch-Site speak: anything but same-origin or none is refused ("same-site" is a sibling subdomain:
// a web app hosted there is listed, and its Origin says so). A request with neither header (curl, a
// native client) is no browser's write and goes through to the authentication.
function sec_exit_if_cross_origin(): void {
	if (in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD', 'OPTIONS'], true)) return;

	$origin = $_SERVER['HTTP_ORIGIN'] ?? null;
	$fetch_site = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? null;
	if (is_string($origin)) $refused = !sec_origin_allowed($origin);
	elseif (is_string($fetch_site)) $refused = !in_array(strtolower($fetch_site), ['same-origin', 'none'], true);
	else $refused = false;

	if ($refused) http_error(403, "cross_origin_refused", "This request comes from a page that is not allowed to write to this API.");
}

// ---------------------------------------------------------------------------
// Two-factor authentication
// ---------------------------------------------------------------------------

// the string vocabulary (TOTP_STATE_NAMES, constants.php) the JSON API exposes instead of
// the raw 0-3 DB int -- shared by api/totp.php and api/key_infos.php so the two never drift apart.
function sec_totp_state_name($state) {
	if (!is_int($state)) return "unknown";
	return TOTP_STATE_NAMES[$state] ?? "unknown";
}

// Starts the set-up: a new secret is stored (state "init") and returned with what the user needs
// to add it to an authenticator app.
function sec_totp_begin($db, array $user_account): array {
	$totp = TOTP::generate();
	$totp->setLabel($user_account["email1"]);
	$totp->setIssuer('MONCYCLE.APP');
	$totp->setParameter('image', APP_URL . "img/moncycleapp512.jpg");
	db_update_user_account_totp_secret($db, $totp->getSecret(), $user_account["no_user_account"]);
	db_update_user_account_totp_state($db, TOTP_STATE_INIT, $user_account["no_user_account"]);

	$qr_writer = new Writer(new ImageRenderer(new RendererStyle(150), new SvgImageBackEnd()));
	return [
		"totpState" => sec_totp_state_name(TOTP_STATE_INIT),
		"initSecret" => $totp->getSecret(),
		"otpauth" => $totp->getProvisioningUri(),
		"qrcode" => $qr_writer->writeString($totp->getProvisioningUri()),
	];
}

// does the one-time code (spaces allowed) match the account's secret? The web client sends the code as a
// number, which loses a leading zero ("091710" arrives as 91710): it is given its zeros back first, or one
// code in ten would be refused.
function sec_totp_code_valid(array $user_account, $code): bool {
	if (!is_string($code) && !is_int($code)) return false;
	$number = intval(preg_replace('/\s+/', '', (string) $code));
	$totp = TOTP::createFromSecret($user_account["totp_secret"]);
	return $number > 0 && $totp->verify(str_pad((string) $number, $totp->getDigits(), "0", STR_PAD_LEFT));
}

// ---------------------------------------------------------------------------
// Login and its brute-force defence
// ---------------------------------------------------------------------------

// Is the address in one of the ranges ("10.0.0.0/8", "fd00::/8", or one address)? An IPv4-mapped
// IPv6 address ("::ffff:10.0.0.1", what a dual-stack socket gives) is read as the IPv4 one.
function sec_ip_in_ranges(string $ip, array $ranges): bool {
	$unmap = fn(string $packed) => strlen($packed) === 16 && str_starts_with($packed, str_repeat("\0", 10) . "\xff\xff") ? substr($packed, 12) : $packed;
	$packed = inet_pton($ip);
	if ($packed === false) return false;
	$packed = $unmap($packed);

	foreach ($ranges as $range) {
		[$base, $bits] = array_pad(explode("/", $range, 2), 2, null);
		$base_packed = inet_pton($base);
		if ($base_packed === false) continue;
		$base_packed = $unmap($base_packed);
		if (strlen($base_packed) !== strlen($packed)) continue;

		// a bare address is a range of one; an IPv4-mapped base keeps its prefix length in IPv4 bits
		$bits = is_null($bits) ? strlen($base_packed) * 8 : intval($bits) - (strlen($base_packed) === 4 && str_contains($base, ":") ? 96 : 0);
		if ($bits < 0) continue;
		$whole_bytes = intdiv($bits, 8);
		if (substr($packed, 0, $whole_bytes) !== substr($base_packed, 0, $whole_bytes)) continue;
		if ($bits % 8 === 0) return true;
		$mask = (0xFF << (8 - $bits % 8)) & 0xFF;
		if ((ord($packed[$whole_bytes]) & $mask) === (ord($base_packed[$whole_bytes]) & $mask)) return true;
	}
	return false;
}

// The address the request comes from, for the login throttling and the log. It is REMOTE_ADDR, the peer
// of the connection, unless that peer is one of TRUSTED_PROXIES: then X-Forwarded-For is read from its
// right end, where the proxies we trust wrote, and the first address that is not one of them is the
// client's. Never read the header of an untrusted peer: it is the client's own and can say anything, to
// dodge the throttling or to frame another address. A header that is garbage where the client should be
// is no help: the peer stays the answer.
function sec_client_ip() {
	$peer = $_SERVER['REMOTE_ADDR'] ?? '';
	if (empty(TRUSTED_PROXIES) || !sec_ip_in_ranges($peer, TRUSTED_PROXIES)) return $peer;

	$forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
	if (!is_string($forwarded)) return $peer;
	foreach (array_reverse(explode(",", $forwarded)) as $hop) {
		$hop = trim($hop);
		if (filter_var($hop, FILTER_VALIDATE_IP) === false) return $peer;
		if (!sec_ip_in_ranges($hop, TRUSTED_PROXIES)) return $hop;
	}
	return $peer;
}

// Reserves one attempt against this address, counted in the window of db_count_login_attempt_ip(). Counting first
// and acting after lets a burst through (the requests that come together all count the same number), so the attempt is
// written first and the count then says whether it fits; one past LOGIN_IP_MAX_ATTEMPTS gives its row back. Of the
// requests that write, the k-th to commit finds at least k rows, so no more pass than the limit leaves. An address
// already at the limit is refused before anything is written: a refused request must not use up an id of the table.
// Answers the id of the row (kept when the attempt fails, given back by sec_attempt_release() when it does not count),
// 0 when addresses are not limited (LOGIN_IP_MAX_ATTEMPTS is 0: nothing is written), null when this one is over.
function sec_attempt_reserve($db, string $ip): ?int {
	if (LOGIN_IP_MAX_ATTEMPTS <= 0) return 0;
	if (db_count_login_attempt_ip($db, $ip) >= LOGIN_IP_MAX_ATTEMPTS) return null;

	$no_login_attempt_ip = intval(db_insert_login_attempt_ip($db, $ip));
	if (db_count_login_attempt_ip($db, $ip) > LOGIN_IP_MAX_ATTEMPTS) {
		db_delete_login_attempt_ip($db, $no_login_attempt_ip);
		return null;
	}
	return $no_login_attempt_ip;
}

// Gives back an attempt of sec_attempt_reserve(): only the failures count against an address.
function sec_attempt_release($db, int $attempt): void {
	if ($attempt > 0) db_delete_login_attempt_ip($db, $attempt);
}

// The start of an endpoint that a captcha guards (register, recover_password): ends the request with a 503 when the
// captcha is closed and with a 429 when the address has used its attempts; otherwise reserves one and answers it, for
// sec_exit_unless_captcha_ok() to keep or give back. $act is the name the refusals are logged under.
function sec_exit_unless_attempt_allowed($db, string $act): int {
	if (!captcha_ready()) {
		log_event("account.refused", ["act" => $act, "err" => "captcha_unavailable"]);
		http_error(503, "captcha_unavailable", "The captcha is not available.");
	}
	$attempt = sec_attempt_reserve($db, sec_client_ip());
	if (is_null($attempt)) {
		log_event("account.refused", ["act" => $act, "err" => "rate_limited"]);
		http_error(429, "rate_limited", "Too many attempts from this network, please try again later.");
	}
	return $attempt;
}

// The end of it, with the verdict of captcha_verify(): a captcha that passes gives its attempt back; any other ends
// the request with a 403 (`captcha_expired` or `captcha_invalid`) and the attempt stays against the address.
function sec_exit_unless_captcha_ok($db, int $attempt, string $verdict, string $act): void {
	if ($verdict === "ok") {
		sec_attempt_release($db, $attempt);
		return;
	}
	$code = $verdict === "expired" ? "captcha_expired" : "captcha_invalid";
	log_event("account.refused", ["act" => $act, "err" => $code]);
	http_error(403, $code, $verdict === "expired" ? "The captcha has expired." : "Error in captcha input.");
}

// number of recent failed login attempts on this account, ignoring attempts older than
// LOGIN_ATTEMPTS_DECAY_MINUTES so a stale streak doesn't linger forever. The login settings are
// in the config file, and each one at 0 turns its feature off: a decay of 0 counts no attempt
// at all, so neither the captcha nor the lockout can trigger.
function sec_login_effective_attempts($user_account) {
	if (LOGIN_ATTEMPTS_DECAY_MINUTES <= 0) return 0;
	if (!isset($user_account["nb_connection_attempts"]) || empty($user_account["last_failed_attempt"])) return 0;

	$cutoff = new DateTime("-" . LOGIN_ATTEMPTS_DECAY_MINUTES . " minutes");
	if (new DateTime($user_account["last_failed_attempt"]) < $cutoff) return 0;

	return intval($user_account["nb_connection_attempts"]);
}

function sec_login_captcha_required($user_account) {
	return LOGIN_CAPTCHA_THRESHOLD > 0 && sec_login_effective_attempts($user_account) >= LOGIN_CAPTCHA_THRESHOLD;
}

function sec_login_account_locked($user_account) {
	return LOGIN_LOCKOUT_THRESHOLD > 0 && sec_login_effective_attempts($user_account) >= LOGIN_LOCKOUT_THRESHOLD;
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
// The address in $body["email"] is read as it is: the endpoint has already normalised it
// (account_email_normalise(), api/token.php), which is how it is stored.
// $token_options is what sec_auth_success() takes (name, scope, expires_in_days, cookie), checked by the endpoint (token_parse_options()).
function sec_login($db, array $body, array $token_options = []): array {
	$user_account = [];

	$refuse = function (int $status, string $code, string $message, array $meta = [], bool $counted_on_account = false) use (&$user_account) {
		$known = isset($user_account["no_user_account"]);
		log_event("auth.login_failed", [
			"err" => $code,
			"att" => $known ? sec_login_effective_attempts($user_account) + intval($counted_on_account) : null,
			"uid" => $known ? $user_account["no_user_account"] : null,
		]);
		return ["status" => $status, "code" => $code, "message" => $message, "meta" => $meta];
	};

	if (!isset($body["email"]) || !isset($body["password"]) || !is_string($body["password"]) || !filter_var($body["email"], FILTER_VALIDATE_EMAIL)) {
		return $refuse(400, "missing_credentials", "'email' and 'password' are required.");
	}

	$client_ip = sec_client_ip();
	$user_account = db_select_user_account_by_email($db, $body["email"]) ?? [];
	$account_locked = sec_login_account_locked($user_account);

	// surfaced on every outcome below (successes included): it is a hint for the client's *next*
	// attempt, not tied to whether this one succeeded.
	$captcha_required = sec_login_captcha_required($user_account);
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
	if ($captcha_required && !captcha_ready()) return $refuse(503, "captcha_unavailable", "The captcha is not available.", $meta);

	// already throttled: nothing is written, or a blocked IP could grow this table forever
	$attempt = sec_attempt_reserve($db, $client_ip);
	if (is_null($attempt)) {
		return $refuse(429, "rate_limited", "Too many attempts from this network, please try again later.", $meta);
	}

	if (empty($body["email"]) || empty($body["password"])) return $counted(400, "missing_credentials", "'email' and 'password' are required.");

	// the account, the password, the code: what a login is once the captcha (if it is asked) is passed
	$check_credentials = function () use ($db, $body, $token_options, $meta, $counted, &$user_account, &$account_locked, &$attempt) {
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

		// the failures this login ends, read before sec_auth_success() resets them
		$attempts = sec_login_effective_attempts($user_account);
		unset($user_account["password"], $user_account["totp_secret"]);
		$session = sec_auth_success($db, $user_account, $token_options);
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
	$captcha_verdict = fn() => captcha_verify($db, "login", $body["email"], $body["captchaToken"] ?? null, $body["captcha"] ?? "", $client_ip);

	// checked first without a lock, so that a request that cannot pass (a wrong answer is the usual one) never holds
	// the account: only a valid token takes it
	$verdict = $captcha_verdict();
	if ($verdict !== "ok") return $captcha_refused($verdict);

	return db_transaction($db, function () use ($db, $captcha_verdict, $captcha_refused, $check_credentials, &$user_account, &$account_locked) {
		// the row is held until the end of the login, bcrypt included, and read again under the lock: a request that
		// came with the same token has finished by now, and the counters it moved no longer match
		$no_user_account = $user_account["no_user_account"];
		$user_account = db_select_user_account_for_update($db, $no_user_account) ? (db_select_user_account($db, $no_user_account) ?? []) : [];
		$account_locked = sec_login_account_locked($user_account);

		$verdict = $captcha_verdict();
		return $verdict === "ok" ? $check_credentials() : $captcha_refused($verdict);
	});
}
