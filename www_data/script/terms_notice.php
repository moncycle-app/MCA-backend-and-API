<?php
/* moncycle.app
**
** licence Creative Commons CC BY-NC-SA
**
** https://www.moncycle.app
** https://github.com/moncycle-app/backend-api-web-app
*/

declare(strict_types=1);

require_once __DIR__ . "/../config.php";
require_once __DIR__ . "/../lib/date.php";
require_once __DIR__ . "/../lib/db_ops.php";
require_once __DIR__ . "/../lib/db_querie.php";
require_once __DIR__ . "/../lib/mail.php";

// Tells every user that the terms of service change, when they take effect, and that using the app after that date means accepting them.
//   php terms_notice.php --url=https://... --date=2026-12-01 --test-email=me@example.org   a trial: ONE mail, to that address only, nobody else
//   php terms_notice.php --url=https://... --date=2026-12-01 --send                         the real run: one mail per account (login address and notification list)
// From a browser (script/ answers this machine only, see zz-moncycleapp.conf): terms_notice.php?url=...&date=...&test_email=...  or  ...&send=1
// The URL is the one given here, whatever TERMS_OF_SERVICE_URL says. Neither --test-email nor --send is refused, and so is any other
// argument: a typo must not turn a trial into a mailing. The trial mail is addressed to "Test". A real run mails everyone again if it is
// run twice (nothing is remembered): read its list of failures, do not rerun it whole.
$is_cli = PHP_SAPI === "cli";
if (!$is_cli) header("Content-Type: text/plain; charset=utf-8");

$options = [];
if ($is_cli) {
	foreach (array_slice($_SERVER["argv"], 1) as $argument) {
		if (!preg_match('/^--([a-z-]+)(?:=(.*))?$/s', $argument, $m)) $options["?"] = $argument;
		else $options[$m[1]] = $m[2] ?? "1";
	}
}
else {
	foreach ($_GET as $key => $value) $options[str_replace("_", "-", (string) $key)] = is_string($value) ? $value : "";
}

$url = $options["url"] ?? "";
$date = date_parse_ymd($options["date"] ?? null);
$test_email = $options["test-email"] ?? null;
$send = isset($options["send"]);

$problems = [];
if (array_diff(array_keys($options), ["url", "date", "test-email", "send"])) $problems[] = "unknown argument";
if (!preg_match('#^https://[^\s<>"\']+$#', $url) || filter_var($url, FILTER_VALIDATE_URL) === false) $problems[] = "url must be an https address";
if (is_null($date)) $problems[] = "date must be YYYY-MM-DD";
elseif ($date < date("Y-m-d")) $problems[] = "date is in the past";
if (is_null($test_email) === !$send) $problems[] = "give --test-email=ADDRESS (a trial) or --send (the real run), not both nor neither";
if (!is_null($test_email) && filter_var($test_email, FILTER_VALIDATE_EMAIL) === false) $problems[] = "test-email is not an address";
if ($problems) {
	$message = implode("; ", $problems) . PHP_EOL . "usage: php terms_notice.php --url=https://... --date=YYYY-MM-DD (--test-email=ADDRESS | --send)" . PHP_EOL;
	if ($is_cli) fwrite(STDERR, $message);
	else {
		http_response_code(400);
		echo $message;
	}
	exit(2);
}

if (MAINTENANCE_MODE) {
	echo "MAINTENANCE_MODE is on: nothing was sent." . PHP_EOL;
	if (!$is_cli) http_response_code(503);
	exit(1);
}

if (!is_null($test_email)) {
	$sent = mail_send_terms_update(["name" => "Test", "login_email" => $test_email, "notification_emails" => ""], $url, $date);
	echo ($sent ? "trial mail sent to " : "COULD NOT send the trial mail to ") . $test_email . PHP_EOL;
	exit($sent ? 0 : 1);
}

$accounts = db_querie_select_user_account_to_notify(db_ops_open());
echo "terms of service {$url}, in force " . mail_french_date($date) . ": " . count($accounts) . " accounts" . PHP_EOL;
$sent = $failed = 0;
foreach ($accounts as $account) {
	if (mail_send_terms_update($account, $url, $date)) $sent++;
	else {
		$failed++;
		echo "COULD NOT send to {$account["login_email"]} (and {$account["notification_emails"]})" . PHP_EOL;
	}
}
echo "{$sent} sent, {$failed} failed" . PHP_EOL;
exit($failed ? 1 : 0);
