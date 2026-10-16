<?php
/* moncycle.app
**
** licence Creative Commons CC BY-NC-SA
**
** https://www.moncycle.app
** https://github.com/moncycle-app/backend-api-web-app
*/

declare(strict_types=1);

// Passwords, TOTP, client addresses, login throttling and the origin of writes. The session token is lib/sec_token.php, the
// captcha lib/sec_captcha.php.

use OTPHP\TOTP;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

require_once __DIR__ . "/../vendor/autoload.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/http.php";
require_once __DIR__ . "/input.php";

function sec_hash(string $password): string {
	return password_hash($password, PASSWORD_BCRYPT);
}

// The problem of a password a client chooses, as an issue of its field, or null. The message never holds the password.
function sec_password_issue(string $password, string $field): ?array {
	if (strlen($password) > PASSWORD_MAX_BYTES) return input_issue($field, "password_too_long", "'$field' is too long: the maximum is " . PASSWORD_MAX_BYTES . " bytes.");
	if (str_contains($password, "\0")) return input_issue($field, "password_invalid", "'$field' must not contain a null character.");
	if (!sec_password_matches_policy($password)) return input_issue($field, "password_too_weak", "'$field' does not meet the password policy (GET /api/v2/server_info, passwordPolicyRegex).");
	return null;
}

// The regex in force: the operator's PASSWORD_POLICY_REGEX, or the default when it is empty or is not a valid regex (a typo in the
// settings must not lock every sign-up out, nor let every password through).
function sec_password_policy_regex(): string {
	$set = PASSWORD_POLICY_REGEX;
	if ($set === "" || @preg_match(sec_password_policy_pattern($set), "") === false) return PASSWORD_POLICY_REGEX_DEFAULT;
	return $set;
}

// The regex as a PCRE pattern: the delimiter is a control character no setting holds, and /u reads the password as UTF-8.
function sec_password_policy_pattern(string $regex): string {
	return "\x01" . $regex . "\x01u";
}

function sec_password_matches_policy(string $password): bool {
	return preg_match(sec_password_policy_pattern(sec_password_policy_regex()), $password) === 1;
}

function sec_exit_if_logged_out(?array $user_account): void {
	if (!is_null($user_account)) return;
	header('WWW-Authenticate: Bearer');
	http_error(401, "unauthorized", "Authentication required.");
}

function sec_redirect_if_logged_out(?array $user_account): void {
	if (!is_null($user_account)) return;
	header('Location: auth');
	http_response_code(401);
	exit;
}

// A demo account's password is on the sign-in page: its session protects nothing, and register treats its holder as a visitor.
function sec_session_is_demo(?array $user_account): bool {
	return !is_null($user_account) && in_array(intval($user_account["no_user_account"] ?? 0), ACCOUNT_DEMO_IDS, true);
}

// A deactivated account, or a demo account while DEMO_ENABLED is off, can neither sign in nor use a session it has.
function sec_account_enabled(array $user_account): bool {
	return boolval($user_account["user_enabled"] ?? false) && (DEMO_ENABLED || !sec_session_is_demo($user_account));
}

// The row with its secret columns masked, for the raw data export.
function sec_obfuscate_columns(array $row, array $columns): array {
	foreach ($columns as $column) {
		if (isset($row[$column])) $row[$column] = substr($row[$column], 0, 3) . " [masqué] " . substr($row[$column], -3);
	}
	return $row;
}

// ---------------------------------------------------------------------------
// Where a write comes from (CSRF)
// ---------------------------------------------------------------------------

// The request's own host (any scheme: behind a TLS proxy the app sees http), the web app (APP_URL) and WEB_APP_ORIGINS.
function sec_origin_allowed(string $origin): bool {
	$origin = strtolower($origin);
	$host = $_SERVER['HTTP_HOST'] ?? "";
	if (is_string($host) && $host !== "" && preg_match('#^https?://' . preg_quote(strtolower($host), '#') . '\z#', $origin)) return true;

	$app = parse_url(APP_URL);
	$app_origin = is_array($app) && !empty($app["scheme"]) && !empty($app["host"])
		? strtolower($app["scheme"] . "://" . $app["host"] . (isset($app["port"]) ? ":" . $app["port"] : "")) : "";
	return $origin === $app_origin || in_array($origin, WEB_APP_ORIGINS, true);
}

// A 403 for a browser write (any method but GET, HEAD, OPTIONS) from a page that is not ours, logged in or not: the cookie rides along,
// and the sign-in itself is a target. Origin decides ("null" is refused); without it, Sec-Fetch-Site must be same-origin or none. A
// request with neither (curl, a native client) is no browser's and goes on to the authentication.
function sec_exit_if_cross_origin(): void {
	if (in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD', 'OPTIONS'], true)) return;

	$origin = $_SERVER['HTTP_ORIGIN'] ?? null;
	$fetch_site = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? null;
	if (is_string($origin)) $refused = !sec_origin_allowed($origin);
	else $refused = is_string($fetch_site) && !in_array(strtolower($fetch_site), ['same-origin', 'none'], true);

	if ($refused) http_error(403, "cross_origin_refused", "This request comes from a page that is not allowed to write to this API.");
}

// ---------------------------------------------------------------------------
// Two-factor authentication
// ---------------------------------------------------------------------------

function sec_totp_state_name(mixed $state): string {
	return is_int($state) ? TOTP_STATE_NAMES[$state] ?? "unknown" : "unknown";
}

// A new secret, stored in state "init", with what an authenticator app needs to add it.
function sec_totp_begin($db, array $user_account): array {
	$totp = TOTP::generate();
	$totp->setLabel($user_account["login_email"]);
	$totp->setIssuer('MONCYCLE.APP');
	$totp->setParameter('image', APP_URL . "img/moncycleapp512.jpg");
	db_update_user_account_totp($db, $user_account["no_user_account"], TOTP_STATE_INIT, $totp->getSecret());

	$qr_writer = new Writer(new ImageRenderer(new RendererStyle(150), new SvgImageBackEnd()));
	return [
		"totpState" => sec_totp_state_name(TOTP_STATE_INIT),
		"initSecret" => $totp->getSecret(),
		"otpauth" => $totp->getProvisioningUri(),
		"qrcode" => $qr_writer->writeString($totp->getProvisioningUri()),
	];
}

// Spaces are allowed, and the web client sends the code as a number ("091710" arrives as 91710): its zeros are put back first.
function sec_totp_code_valid(array $user_account, mixed $code): bool {
	if (!is_string($code) && !is_int($code)) return false;
	$number = intval(preg_replace('/\s+/', '', (string) $code));
	$totp = TOTP::createFromSecret($user_account["totp_secret"]);
	return $number > 0 && $totp->verify(str_pad((string) $number, $totp->getDigits(), "0", STR_PAD_LEFT));
}

// ---------------------------------------------------------------------------
// Client addresses
// ---------------------------------------------------------------------------

// The 4 or 16 bytes of an address, an IPv4-mapped IPv6 one ("::ffff:10.0.0.1", from a dual-stack socket) read as IPv4; null if none.
function sec_ip_packed(?string $ip): ?string {
	$packed = is_null($ip) ? false : inet_pton($ip);
	if ($packed === false) return null;
	return strlen($packed) === 16 && str_starts_with($packed, str_repeat("\0", 10) . "\xff\xff") ? substr($packed, 12) : $packed;
}

// The first $bits of the packed address, the rest zeroed.
function sec_ip_mask(string $packed, int $bits): string {
	$whole_bytes = intdiv($bits, 8);
	$network = substr($packed, 0, $whole_bytes);
	if ($bits % 8 !== 0) $network .= chr(ord($packed[$whole_bytes]) & ((0xFF << (8 - $bits % 8)) & 0xFF));
	return str_pad($network, strlen($packed), "\0");
}

// The address cut to its first $v4_bits (IPv4) or $v6_bits (IPv6), as text ("a.b.c.0"); null when it is no address.
function sec_ip_network(?string $ip, int $v4_bits, int $v6_bits): ?string {
	$packed = sec_ip_packed($ip);
	return is_null($packed) ? null : inet_ntop(sec_ip_mask($packed, strlen($packed) === 4 ? $v4_bits : $v6_bits));
}

// Is the address in one of the ranges ("10.0.0.0/8", "fd00::/8", or one address)? An IPv4-mapped base keeps its prefix in IPv4 bits.
function sec_ip_in_ranges(string $ip, array $ranges): bool {
	$packed = sec_ip_packed($ip);
	if (is_null($packed)) return false;

	foreach ($ranges as $range) {
		[$base, $bits] = array_pad(explode("/", $range, 2), 2, null);
		$base_packed = sec_ip_packed($base);
		if (is_null($base_packed) || strlen($base_packed) !== strlen($packed)) continue;
		$bits = is_null($bits) ? strlen($base_packed) * 8 : intval($bits) - (strlen($base_packed) === 4 && str_contains($base, ":") ? 96 : 0);
		if ($bits >= 0 && sec_ip_mask($packed, $bits) === sec_ip_mask($base_packed, $bits)) return true;
	}
	return false;
}

// REMOTE_ADDR, unless that peer is one of TRUSTED_PROXIES: then X-Forwarded-For is read from its right end, and the first hop that is
// not a trusted proxy is the client. The header of an untrusted peer is never read (it could dodge the throttling or frame another
// address), and a hop that is no address stops the walk at the peer.
function sec_client_ip(): string {
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

// ---------------------------------------------------------------------------
// Login throttling by address
// ---------------------------------------------------------------------------

// Reserves one attempt against the address. Counting then acting lets a burst through, so the row is written first and the count
// then says whether it fits: of the requests that write, the k-th to commit finds at least k rows. An address already at
// LOGIN_IP_MAX_ATTEMPTS is refused before anything is written. Answers the row's id (sec_attempt_release() gives it back when the
// attempt does not count), 0 when addresses are not limited, null when this one is over.
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

function sec_attempt_release($db, int $attempt): void {
	if ($attempt > 0) db_delete_login_attempt_ip($db, $attempt);
}
