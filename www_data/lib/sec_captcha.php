<?php
/* moncycle.app
**
** licence Creative Commons CC BY-NC-SA
**
** https://www.moncycle.app
** https://github.com/moncycle-app/backend-api-web-app
*/

declare(strict_types=1);

// The captcha: a signed challenge kept nowhere. The visitor gets a picture and a token "<exp>.<mac>", the HMAC-SHA256 (CAPTCHA_SECRET)
// of the purpose, the address the account is looked up by, the expiry, the phrase, the visitor's network and a state of the account
// that its own use changes, so that a token works once. To check an answer the server signs again with what was typed and compares.

use Gregwar\Captcha\CaptchaBuilder;
use Gregwar\Captcha\PhraseBuilder;

require_once __DIR__ . "/../vendor/autoload.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/http.php";
require_once __DIR__ . "/log.php";
require_once __DIR__ . "/sec.php";

// Without a key of CAPTCHA_SECRET_MIN_CHARS nothing is signed, and what needs a captcha answers 503.
function sec_captcha_ready(): bool {
	return strlen(CAPTCHA_SECRET) >= CAPTCHA_SECRET_MIN_CHARS;
}

// The visitor's network the token is tied to, "a.b.c.0/24" (CAPTCHA_IP_PREFIX_V4 / _V6 bits); "" when the address is none.
function sec_captcha_ip_scope(string $ip): string {
	$network = sec_ip_network($ip, CAPTCHA_IP_PREFIX_V4, CAPTCHA_IP_PREFIX_V6);
	return is_null($network) ? "" : $network . "/" . (str_contains($network, ":") ? CAPTCHA_IP_PREFIX_V6 : CAPTCHA_IP_PREFIX_V4);
}

// What the token is tied to in the account at $email, read the same way whether it exists or not ("" when not), so that a challenge
// tells nothing about it: for register and recover the password hash and the pending link (a link made or used, a new password, and the
// token is spent: one mail per captcha), for login the failure counters (a failed or a successful sign-in spends it).
function sec_captcha_state($db, string $purpose, string $email): string {
	$account = db_select_user_account_by_email($db, $email);
	if (is_null($account)) return "";
	if ($purpose === "login") return $account["nb_connection_attempts"] . ":" . $account["last_failed_attempt"];
	return hash("sha256", $account["password"] . "|" . ($account["email_token_hash"] ?? ""));
}

// 64 hex characters. Case and spaces of the phrase do not count: a phone capitalises the first letter, a space slips in.
function sec_captcha_sign(string $purpose, string $email, int $exp, string $phrase, string $ip_scope, string $state): string {
	if (!sec_captcha_ready()) throw new RuntimeException("CAPTCHA_SECRET is not set");
	$data = json_encode([$purpose, $email, $exp, strtolower(preg_replace('/\s+/', '', $phrase)), $ip_scope, $state], JSON_THROW_ON_ERROR);
	return hash_hmac("sha256", $data, CAPTCHA_SECRET);
}

// A new challenge: ["image" => "data:image/jpeg;base64,...", "token" => "<exp>.<mac>", "expiresAt" => exp]. Nothing is written. The
// picture is medium on purpose: no distortion, few lines, an alphabet without the glyphs that look alike (CAPTCHA_CHARSET).
function sec_captcha_issue($db, string $purpose, string $email, string $ip): array {
	$captcha = new CaptchaBuilder(null, new PhraseBuilder(CAPTCHA_LENGTH, CAPTCHA_CHARSET));
	$captcha->setDistortion(false)->setMaxBehindLines(2)->setMaxFrontLines(1)->setMaxAngle(6)->setMaxOffset(3);
	$captcha->build(CAPTCHA_WIDTH, CAPTCHA_HEIGHT);
	$exp = time() + CAPTCHA_VALID_SECONDS;
	$mac = sec_captcha_sign($purpose, $email, $exp, $captcha->getPhrase(), sec_captcha_ip_scope($ip), sec_captcha_state($db, $purpose, $email));
	return ["image" => $captcha->inline(), "token" => "$exp.$mac", "expiresAt" => $exp];
}

// "ok", "expired" (the expiry is in the token: no secret) or "invalid", one word for anything else, so that nothing tells which part
// was wrong. A token or an answer of the wrong type is "invalid", never an error. The state is read now: a caller that needs a token to
// pass once reads it under the account's lock.
function sec_captcha_verify($db, string $purpose, string $email, mixed $token, mixed $answer, string $ip): string {
	if (!is_string($token) || (!is_string($answer) && !is_int($answer))) return "invalid";
	$answer = (string) $answer;
	if ($answer === "" || strlen($answer) > CAPTCHA_ANSWER_MAX_BYTES) return "invalid";
	if (!preg_match('/\A([0-9]{1,12})\.([0-9a-f]{64})\z/', $token, $parts)) return "invalid";

	$exp = intval($parts[1]);
	if ($exp < time()) return "expired";

	$mac = sec_captcha_sign($purpose, $email, $exp, $answer, sec_captcha_ip_scope($ip), sec_captcha_state($db, $purpose, $email));
	return hash_equals($mac, $parts[2]) ? "ok" : "invalid";
}

// The start of register and recover_password: a 503 when the captcha is closed, a 429 when the address has used its attempts, else
// the attempt reserved for sec_captcha_exit_unless_ok(). $act names the refusals in the log.
function sec_captcha_exit_unless_attempt_allowed($db, string $act): int {
	if (!sec_captcha_ready()) {
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

// The end: a captcha that passes gives its attempt back, any other is a 403 and the attempt stays against the address.
function sec_captcha_exit_unless_ok($db, int $attempt, string $verdict, string $act): void {
	if ($verdict === "ok") {
		sec_attempt_release($db, $attempt);
		return;
	}
	$code = $verdict === "expired" ? "captcha_expired" : "captcha_invalid";
	log_event("account.refused", ["act" => $act, "err" => $code]);
	http_error(403, $code, $verdict === "expired" ? "The captcha has expired." : "Error in captcha input.");
}
