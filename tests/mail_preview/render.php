<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Writes every mail of lib/mail.php to a directory instead of sending it (a PHPMailer stand-in is loaded before the real one):
// <kind>.html, and <kind>.txt with the subject, the addresses and the text part. Run by tests/mail_preview/run.sh in the dev container.
//
//   MAIL_PREVIEW_DIR=<directory> php -d open_basedir= render.php
declare(strict_types=1);

namespace PHPMailer\PHPMailer {
	class PHPMailer {
		const ENCRYPTION_SMTPS = "ssl";
		public $Host, $SMTPAuth, $Username, $Password, $SMTPSecure, $Port, $Timeout, $CharSet, $Subject, $Body, $AltBody, $ErrorInfo = "";
		public array $to = [], $reply = [], $files = [];
		static string $dir = "", $name = "";
		function isSMTP() {}
		function isHTML($b) {}
		function setFrom($a, $n) {}
		function addReplyTo($a, $n) { $this->reply[] = $a; }
		function addAddress($a, $n) { $this->to[] = $a; }
		function addStringAttachment($c, $n) { $this->files[] = $n; }
		function send() {
			file_put_contents(self::$dir . "/" . self::$name . ".html", $this->Body);
			file_put_contents(self::$dir . "/" . self::$name . ".txt", "Subject: {$this->Subject}\nTo: " . implode(", ", $this->to) . "\nReply-To: " . implode(", ", $this->reply) . "\nFiles: " . implode(", ", $this->files) . "\n\n{$this->AltBody}\n");
			return true;
		}
	}
}

namespace {
	require "/var/www/html/config.php";
	require "/var/www/html/lib/mail.php";

	$dir = getenv("MAIL_PREVIEW_DIR") ?: exit("MAIL_PREVIEW_DIR is not set\n");
	@mkdir($dir, 0777, true);
	PHPMailer\PHPMailer\PHPMailer::$dir = $dir;
	// A nickname that holds HTML and a template tag: it must come out as text, and the tag must stay a tag.
	$account = ["login_email" => "alice@exemple.fr", "notification_emails" => "bob@exemple.fr", "name" => "Alice <b>{{why}}</b> & co"];
	$token = "Zk3pQ8vX2mR7tY1wB4nC6dF9gH0jK5lLzz";
	$sent = [];
	$run = function (string $name, callable $send) use (&$sent) {
		PHPMailer\PHPMailer\PHPMailer::$name = $name;
		ob_start();
		$ok = $send();
		ob_end_clean();
		$sent[] = $name;
		echo str_pad($name, 26), $ok ? "ok" : "FAILED", "\n";
	};
	foreach (["verify_email", "account_exists", "reset_password", "confirm_email"] as $kind) $run($kind, fn() => mail_send_link($kind, "alice@exemple.fr", $account["name"], $token));
	$run("password_changed", fn() => mail_send_password_changed("alice@exemple.fr", $account["name"]));
	$run("email_change_requested", fn() => mail_send_email_change_requested("alice@exemple.fr", "new@exemple.fr", $account["name"]));
	$run("email_change_taken", fn() => mail_send_email_change_taken("alice@exemple.fr"));
	$run("email_changed", fn() => mail_send_email_changed("alice@exemple.fr", "new@exemple.fr", $account["name"]));
	$run("notification_emails", fn() => mail_send_notification_emails_changed("alice@exemple.fr", "bob@exemple.fr;carol@exemple.fr"));
	$run("new_login", fn() => mail_send_new_login($account, "Firefox sur Mac", "a1b2c3", "readWrite", "Mozilla/5.0 (Macintosh; Intel Mac OS X 14_6) Firefox/131.0", "2027-10-27 10:00:00"));
	$run("reminder", fn() => mail_send_reminder($account));
	$run("deletion_warning", fn() => mail_send_deletion_warning($account, 10));
	$run("terms_update", fn() => mail_send_terms_update($account, "https://www.moncycle.app/cgu?v=2&x=<y>", "2026-12-01"));
	$run("cycle", fn() => mail_send_cycle($account, "01/09/2026", "28/09/2026", 28, ["cycle.pdf" => "x", "cycle.csv" => "y", "cycle.nfp" => "z"], 28));

	file_put_contents("$dir/kinds.txt", implode("\n", $sent) . "\n");
}
