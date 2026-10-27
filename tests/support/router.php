<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Router of the test server (php -S, started by tests/run.sh). It does two things:
//
//  1. Apache's MultiViews: /api/v2/day/2026-03-10 runs api/v2/day.php with PATH_INFO=/2026-03-10, and an unknown endpoint is a 404.
//  2. /_test/*: the fixtures the tests need and the API cannot give (an account with a token, a one-time code, a captcha
//     answer, a mail link, a SQL read). They only exist on a database named test_*; anything else is refused.
//
// Never start this server on a real database.

declare(strict_types=1);

$docroot = $_SERVER["DOCUMENT_ROOT"];
$path = parse_url($_SERVER["REQUEST_URI"] ?? "", PHP_URL_PATH) ?: "/";

if (preg_match('#^/api/v2/([a-z_]+)(?:\.php)?(/.*)?$#', $path, $m)) {
	$script = "$docroot/api/v2/$m[1].php";
	if (!is_file($script)) {
		http_response_code(404);
		header("Content-Type: text/html; charset=iso-8859-1");
		echo "<h1>Not Found</h1>\n";
		return true;
	}
	$_SERVER["PATH_INFO"] = $m[2] ?? "";
	if ($_SERVER["PATH_INFO"] === "") unset($_SERVER["PATH_INFO"]);
	$_SERVER["SCRIPT_NAME"] = "/api/v2/$m[1].php";
	$_SERVER["SCRIPT_FILENAME"] = $script;
	chdir(dirname($script));
	require $script;
	return true;
}

if (!str_starts_with($path, "/_test/")) return false;   // static files (the Swagger page, the contract)

require_once "$docroot/config.php";
header("Content-Type: application/json");
if (!str_starts_with(DB_NAME, "test_")) {
	http_response_code(403);
	exit('{"error":"fixtures only on a test_* database"}');
}
require_once "$docroot/lib/db_ops.php";
require_once "$docroot/lib/db_querie.php";
require_once "$docroot/lib/sec.php";
require_once "$docroot/lib/sec_captcha.php";
require_once "$docroot/lib/sec_token.php";
require_once "$docroot/lib/email_token.php";
require_once "$docroot/vendor/autoload.php";

$in = json_decode(file_get_contents("php://input") ?: "{}", true) ?: [];
$db = db_ops_open();
$out = [];

switch (substr($path, strlen("/_test/"))) {
case "account":
	// A verified account and a read-write session token. The password hash is cheap (8 KiB, 1 pass): password_verify() runs
	// the same code, and the first sign-in makes the hash again with the real options.
	$email = strtolower((string) $in["email"]);
	$db->prepare("DELETE FROM user_account WHERE login_email = ?")->execute([$email]);
	$hash = password_hash((string) $in["password"], PASSWORD_ARGON2ID, ["memory_cost" => 8, "time_cost" => 1, "threads" => 1]);
	$id = db_querie_insert_user_account($db, (string) ($in["nickname"] ?? "Test"), (int) ($in["method"] ?? 2), 1990, $email, $hash, null, 0);
	db_querie_update_user_account_email_verified($db, $id);
	$token = sec_token_random();
	db_querie_insert_auth_token($db, $id, "tests", 2, null, null, null, sec_token_hash($token), substr($token, 0, AUTH_TOKEN_PREFIX_CHARS));
	$out = ["userId" => (int) $id, "token" => $token];
	break;

case "sql":
	$st = $db->prepare((string) $in["sql"]);
	$st->execute($in["params"] ?? []);
	$out = $st->columnCount() ? ["rows" => $st->fetchAll(PDO::FETCH_ASSOC)] : ["rowCount" => $st->rowCount()];
	break;

case "totp":
	$left = 30 - fmod(microtime(true), 30);
	if ($left < 3) usleep((int) (($left + 0.15) * 1000000));   // the server checks the current window only
	$out = ["code" => OTPHP\TOTP::createFromSecret((string) $in["secret"])->now()];
	break;

case "email_token":
	// The raw token of a mail link (the database keeps its hash, and no mail leaves the test server).
	$out = ["token" => email_token_issue($db, (int) $in["userId"], (int) $in["purpose"], $in["newEmail"] ?? null)];
	break;

case "captcha":
	// A captcha the test can answer: the token for the phrase "testcode", as the address `ip` (the X-Forwarded-For of the call).
	$exp = time() + 300;
	$mac = sec_captcha_sign((string) $in["purpose"], strtolower((string) $in["email"]), $exp, "testcode", sec_captcha_ip_scope((string) $in["ip"]), sec_captcha_state($db, (string) $in["purpose"], strtolower((string) $in["email"])));
	$out = ["token" => "$exp.$mac", "answer" => "testcode"];
	break;

default:
	http_response_code(404);
	$out = ["error" => "unknown fixture"];
}
echo json_encode($out);
return true;
