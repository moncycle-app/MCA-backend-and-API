<?php
/* moncycle.app
**
** licence Creative Commons CC BY-NC-SA
**
** https://www.moncycle.app
** https://github.com/moncycle-app/backend-api-web-app
*/

// The captcha: a signed challenge that is kept nowhere. The visitor is given a picture and a token "<exp>.<mac>"; the
// MAC is HMAC-SHA256 (CAPTCHA_SECRET) of what the answer must be tied to: the purpose, the address the account is
// looked up by, the expiry, the phrase of the picture, the visitor's network and a state of the account that its own
// use changes (so a token works once: a new password, a failed login, and it no longer matches). The server stores
// nothing; to check an answer it signs again with what the visitor typed and compares.

use Gregwar\Captcha\CaptchaBuilder;
use Gregwar\Captcha\PhraseBuilder;

require_once __DIR__ . "/../vendor/autoload.php";
require_once __DIR__ . "/db.php";

// Is the captcha open? Without a secret of CAPTCHA_SECRET_MIN_CHARS characters it is closed: nothing is signed with a
// short or empty key, and what needs a captcha is refused with a 503.
function captcha_ready(): bool {
	return strlen(CAPTCHA_SECRET) >= CAPTCHA_SECRET_MIN_CHARS;
}

// The picture and its phrase, from the CAPTCHA_* constants. The library's own defaults (a wave distortion, and
// an alphabet of both cases with i, l, o, 0, 1...) make a picture that few visitors read: this one is medium.
function captcha_image(): CaptchaBuilder {
	$captcha = new CaptchaBuilder(null, new PhraseBuilder(CAPTCHA_LENGTH, CAPTCHA_CHARSET));
	$captcha->setDistortion(false)->setMaxBehindLines(2)->setMaxFrontLines(1)->setMaxAngle(6)->setMaxOffset(3);
	return $captcha->build(CAPTCHA_WIDTH, CAPTCHA_HEIGHT);
}

// A phrase, or what was typed for it, as it is compared: case and spaces do not count (a phone capitalises the first
// letter and a space slips in; neither is a mistake in the reading).
function captcha_normalise_phrase(string $text): string {
	return strtolower(preg_replace('/\s+/', '', $text));
}

// The visitor's network, as the token is tied to it: the address cut to CAPTCHA_IP_PREFIX_V4 bits (IPv4) or
// CAPTCHA_IP_PREFIX_V6 bits (IPv6), written "a.b.c.0/24". An IPv4-mapped IPv6 address ("::ffff:10.0.0.1", what a
// dual-stack socket gives) is read as the IPv4 one. "" when the address is not one.
function captcha_ip_scope(string $ip): string {
	$packed = inet_pton($ip);
	if ($packed === false) return "";
	if (strlen($packed) === 16 && str_starts_with($packed, str_repeat("\0", 10) . "\xff\xff")) $packed = substr($packed, 12);

	$bits = strlen($packed) === 4 ? CAPTCHA_IP_PREFIX_V4 : CAPTCHA_IP_PREFIX_V6;
	$whole_bytes = intdiv($bits, 8);
	$network = substr($packed, 0, $whole_bytes);
	if ($bits % 8 !== 0) $network .= chr(ord($packed[$whole_bytes]) & ((0xFF << (8 - $bits % 8)) & 0xFF));
	return inet_ntop(str_pad($network, strlen($packed), "\0")) . "/" . $bits;
}

// What the token is tied to besides the visitor and the phrase, read from the account at $email (the same read whether
// it exists or not, so that issuing a challenge tells nothing about it):
//   register: "" (the unique key of the address decides who gets it)
//   recover:  the SHA-256 of the stored password hash (a reset, or any other change of the password, ends the token)
//   login:    "nb_connection_attempts:last_failed_attempt" as stored (a failed login, or a success, ends the token)
// "" for an address with no account.
function captcha_state($db, string $purpose, string $email): string {
	if ($purpose === "register") return "";

	$account = db_select_user_account_by_email($db, $email);
	if (is_null($account)) return "";
	if ($purpose === "recover") return hash("sha256", $account["password"]);
	return $account["nb_connection_attempts"] . ":" . $account["last_failed_attempt"];
}

// The MAC (64 hex characters) of a challenge. The phrase is normalised here, so that the one made at issue and the
// one typed at check agree. A closed captcha signs nothing: the callers answer 503 before they get here.
function captcha_sign(string $purpose, string $email, int $exp, string $phrase, string $ip_scope, string $state): string {
	if (!captcha_ready()) throw new RuntimeException("CAPTCHA_SECRET is not set");
	$data = json_encode([$purpose, $email, $exp, captcha_normalise_phrase($phrase), $ip_scope, $state], JSON_THROW_ON_ERROR);
	return hash_hmac("sha256", $data, CAPTCHA_SECRET);
}

// A new challenge for this purpose and this address, from the visitor at $ip:
// ["image" => "data:image/jpeg;base64,...", "token" => "<exp>.<mac>", "expiresAt" => exp]. Nothing is written.
function captcha_issue($db, string $purpose, string $email, string $ip): array {
	$captcha = captcha_image();
	$exp = time() + CAPTCHA_VALID_SECONDS;
	$state = captcha_state($db, $purpose, $email);
	$mac = captcha_sign($purpose, $email, $exp, $captcha->getPhrase(), captcha_ip_scope($ip), $state);
	return ["image" => $captcha->inline(), "token" => "$exp.$mac", "expiresAt" => $exp];
}

// Does the answer read the picture of this token? "ok", "expired" (the expiry is in the token, so it is no secret) or
// "invalid": one word for everything else (a wrong answer, another purpose, address or network, a token that was
// altered, cut or already used), so that nothing tells which part was wrong. The token and the answer come from a client:
// a list, an object or a number where a string is expected is "invalid", never an error. The state is read now,
// so the caller that needs a token to pass once reads it under the lock of the account.
function captcha_verify($db, string $purpose, string $email, mixed $token, mixed $answer, string $ip): string {
	if (!is_string($token) || (!is_string($answer) && !is_int($answer))) return "invalid";
	$answer = (string) $answer;
	if ($answer === "" || strlen($answer) > CAPTCHA_ANSWER_MAX_BYTES) return "invalid";
	if (!preg_match('/\A([0-9]{1,12})\.([0-9a-f]{64})\z/', $token, $parts)) return "invalid";

	$exp = intval($parts[1]);
	if ($exp < time()) return "expired";

	$mac = captcha_sign($purpose, $email, $exp, $answer, captcha_ip_scope($ip), captcha_state($db, $purpose, $email));
	return hash_equals($mac, $parts[2]) ? "ok" : "invalid";
}
