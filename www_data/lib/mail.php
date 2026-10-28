<?php
/* moncycle.app
**
** Copyright (C) 2021-2026 Tech 4 Fertility, aka. T4F (https://tech4fertility.org)
** SPDX-License-Identifier: AGPL-3.0-or-later
**
** This program is free software: you can redistribute it and/or modify it under
** the terms of the GNU Affero General Public License as published by the Free
** Software Foundation, either version 3 of the License, or (at your option) any
** later version. It is distributed WITHOUT ANY WARRANTY; see the licence for details.
**
** https://www.moncycle.app
** https://github.com/moncycle-app/backend-api-web-app
*/

declare(strict_types=1);

// The mails of the app. A name or an address comes from a user: it is escaped before it reaches the HTML. No word a user reads is in
// this file: they are in mail/lang/<language>.php, read through mail_t().

use PHPMailer\PHPMailer\PHPMailer;

require_once __DIR__ . "/../vendor/autoload.php";
require_once __DIR__ . "/log.php";

// Sends one mail. $kind names it in the log, which keeps nothing else of it but how many addresses and files (never an address, a
// subject or a body: several hold a link that opens an account). $body is the slots of the template (the subject added), or a whole HTML
// page of its own (the weekly report). $text is the plain-text part: with slots, its body only (the frame adds greeting, link, sign-off
// and footer, layout.txt; null = the "content" slot as text), otherwise the whole text. Empty addresses are skipped; $attachments is [file name => content];
// $log_fields is what the caller adds to the log line. An answer goes to MAIL_REPLY_TO. True when the SMTP server took it; never throws.
function mail_send(string $kind, array $to, string $subject, array|string $body, ?string $text = null, array $attachments = [], array $log_fields = []): bool {
	$log = ["kind" => $kind, "to" => count(array_filter($to)), "fil" => count($attachments)] + $log_fields;
	try {
		$mail = new PHPMailer();
		$mail->isSMTP();
		$mail->Host       = SMTP_HOST;
		$mail->SMTPAuth   = true;
		$mail->Username   = SMTP_MAIL;
		$mail->Password   = SMTP_PASSWORD;
		$mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
		$mail->Port       = SMTP_PORT;
		$mail->Timeout    = 10;   // seconds; PHPMailer's 300 would hold a worker for minutes on a stalled server
		$mail->CharSet    = 'UTF-8';
		$mail->setFrom(SMTP_MAIL, 'MONCYCLE.APP');
		$mail->addReplyTo(MAIL_REPLY_TO, 'MONCYCLE.APP');

		foreach (array_filter($to) as $address) $mail->addAddress($address, $address);
		$mail->isHTML(true);
		$mail->Subject = $subject;
		$mail->Body = is_array($body) ? mail_render(["subject" => $subject] + $body) : $body;
		$mail->AltBody = is_array($body) ? mail_render(["text" => $text ?? mail_text_from_html($body["content"] ?? "")] + mail_text_slots($body), false) : (string) $text;
		foreach ($attachments as $file_name => $content) $mail->addStringAttachment($content, $file_name);

		if ($mail->send()) {
			log_event("mail.sent", $log);
			return true;
		}
		log_event("mail.failed", $log + ["msg" => log_scrub($mail->ErrorInfo)]);
		return false;
	} catch (\Throwable $e) {
		log_event("mail.failed", $log + ["msg" => get_class($e)]);
		return false;
	}
}

// ---------------------------------------------------------------------------
// The words (MAIL_LANG_DIR, one file per language of an account) and the template (MAIL_LAYOUT, MAIL_LAYOUT_TEXT)
// ---------------------------------------------------------------------------

// The language of the mails being made, an ACCOUNT_LANGUAGES code. Every mail_send_*() sets it from the language of the account it is for
// (user_account.language) before it says a word; a code that is not a language of the app is the default one.
function mail_language(?string $code = null): string {
	static $current = ACCOUNT_DEFAULT_LANGUAGE;
	if (!is_null($code)) $current = in_array($code, ACCOUNT_LANGUAGES, true) ? $code : ACCOUNT_DEFAULT_LANGUAGE;
	return $current;
}

// Every word of the current language of the mails: [key => string, or a list].
function mail_words(): array {
	static $words = [];
	$language = mail_language();
	$words[$language] ??= require MAIL_LANG_DIR . "/" . strtolower($language) . ".php";
	return $words[$language];
}

// A string of the language file: a {@other.key} is replaced by that string (a phrase said in several mails is written once), then its
// {name} marks by $vars. The caller escapes what it passes for HTML, and passes plain text for a slot the template escapes.
function mail_t(string $key, array $vars = []): string {
	$text = mail_words()[$key] ?? throw new RuntimeException("mail text missing: {$key}");
	if (!is_string($text)) throw new RuntimeException("mail text is not a string: {$key}");
	$text = preg_replace_callback('/\{@([\w.]+)\}/', fn(array $m): string => mail_t($m[1]), $text);
	$marks = [];
	foreach ($vars as $name => $value) $marks["{" . $name . "}"] = (string) $value;
	return strtr($text, $marks);
}

// MAIL_LAYOUT ($html) or MAIL_LAYOUT_TEXT filled with $slots. The sections are resolved on the layout alone, then every tag in one pass: a
// value is never read again for tags, so a nickname holding "{{why}}" stays as it is. HTML: a {{tag}} is escaped, the comments of the
// file go (Outlook's conditional ones stay); plain text: nothing is escaped.
function mail_render(array $slots, bool $html = true): string {
	static $layouts = [];
	$layout = $layouts[$html] ??= mail_layout($html);
	$slots += [
		"site_url" => MAIL_SITE_URL,
		"contact_email" => MAIL_REPLY_TO,
		"lang" => mail_t("layout.lang"),
		"signoff" => mail_t("layout.signoff"),
		"button_fallback" => mail_t("layout.button_fallback"),
		"footer_auto" => mail_t("layout.footer_auto"),
	];
	$value = fn(string $name): string => (string) ($slots[$name] ?? "");
	$out = preg_replace_callback('/\{\{#(\w+)\}\}(.*?)\{\{\/\1\}\}/s', fn(array $m): string => $value($m[1]) === "" ? "" : $m[2], $layout);
	// {{{name}}} leaves $m[2] unset, {{name}} sets it.
	$out = preg_replace_callback('/\{\{\{(\w+)\}\}\}|\{\{(\w+)\}\}/',
		fn(array $m): string => isset($m[2]) ? ($html ? htmlspecialchars($value($m[2])) : $value($m[2])) : $value($m[1]), $out);
	// A section left out leaves its lines behind: one blank line between blocks is enough.
	return $html ? $out : trim(preg_replace("/\n{3,}/", "\n\n", $out)) . "\n";
}

// A layout file ready to fill; the comments of the HTML one go, Outlook's conditional ones stay.
function mail_layout(bool $html): string {
	$file = file_get_contents($html ? MAIL_LAYOUT : MAIL_LAYOUT_TEXT);
	if ($file === false) throw new RuntimeException("mail layout unreadable");
	return $html ? preg_replace('/<!--(?!\[if).*?-->\s*/s', "", $file) : $file;
}

// The slots of a mail for its plain text: "ps" is HTML, so it is made text, unless the mail gives its own "ps_text".
function mail_text_slots(array $slots): array {
	$slots["ps"] = $slots["ps_text"] ?? mail_text_from_html($slots["ps"] ?? "");
	return $slots;
}

// The plain text of a block of the mails' HTML, so that no sentence is written twice: a paragraph or a row on its own line, the cells of a
// row joined by ": ", a link as "label (url)" (the label alone when it is the address itself).
function mail_text_from_html(string $html): string {
	$html = preg_replace('/\s+/u', " ", $html);
	$html = preg_replace_callback('/<a\b[^>]*\bhref="([^"]*)"[^>]*>(.*?)<\/a>/', function (array $m): string {
		$url = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, "UTF-8");
		$label = html_entity_decode(strip_tags($m[2]), ENT_QUOTES | ENT_HTML5, "UTF-8");
		return $label === $url || "mailto:" . $label === $url ? $m[2] : $m[2] . " (" . htmlspecialchars($url) . ")";
	}, $html);
	$html = preg_replace(['/<\/div> *<\/td>/', '/<\/td> *<td\b/', '/<\/(?:p|table)>/', '/<\/(?:div|tr)>/'], ['</td>', '</td>: <td', "\n\n", "\n"], $html);
	$text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, "UTF-8");
	return trim(preg_replace("/\n{3,}/", "\n\n", preg_replace("/ *\n */", "\n", $text)));
}

// The pieces below are the components of docs/mail.md: their classes are what the dark theme of the layout hooks on. Their $html is the
// caller's, escaped by it.

function mail_p(string $html): string {
	return "<p style=\"margin:0 0 16px 0;\">{$html}</p>";
}

// A link in the text ($url escaped here).
function mail_a(string $url, string $html): string {
	$url = htmlspecialchars($url);
	return "<a class=\"mc-link\" href=\"{$url}\" style=\"color:#178048;text-decoration:underline;\">{$html}</a>";
}

// The team's address, as a link.
function mail_contact(): string {
	return mail_a("mailto:" . MAIL_REPLY_TO, MAIL_REPLY_TO);
}

// A short code, a token prefix (escaped here).
function mail_code(string $text): string {
	$text = htmlspecialchars($text);
	return "<code class=\"mc-code\" style=\"background:#e9efeb;color:#14231a;border-radius:6px;padding:2px 6px;font-family:Menlo,Consolas,monospace;font-size:13px;\">{$text}</code>";
}

// A panel of [label => value] rows (the label is plain text of the language file, the value HTML).
function mail_facts(array $rows): string {
	$html = "";
	foreach ($rows as $label => $value) {
		$label = htmlspecialchars((string) $label);
		$html .= "<tr><td class=\"mc-fact-label mc-muted\" width=\"42%\" style=\"padding:8px 12px 8px 0;font-size:14px;line-height:20px;color:#5f6e65;\">{$label}</td>"
			. "<td class=\"mc-fact-value mc-heading\" style=\"padding:8px 0;font-size:14px;line-height:20px;font-weight:600;color:#14231a;\">{$value}</td></tr>";
	}
	return "<table role=\"presentation\" width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" style=\"margin:4px 0 20px 0;\"><tr>"
		. "<td class=\"mc-panel\" bgcolor=\"#f4f8f5\" style=\"background:#f4f8f5;border:1px solid #e3ebe5;border-radius:12px;padding:8px 18px;\">"
		. "<table role=\"presentation\" width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\">{$html}</table></td></tr></table>";
}

// A box: "info" (green, a tip) or "warn" (amber: "if this is not you", an erasure ahead).
function mail_callout(string $kind, string $html): string {
	[$background, $border, $color] = $kind === "warn" ? ["#fff7e0", "#e0a800", "#5a4300"] : ["#eaf8f0", "#32be73", "#14402a"];
	return "<table role=\"presentation\" width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" style=\"margin:4px 0 20px 0;\"><tr>"
		. "<td class=\"mc-callout-{$kind}\" bgcolor=\"{$background}\" style=\"background:{$background};border-left:4px solid {$border};border-radius:8px;padding:14px 18px;font-size:15px;line-height:23px;color:{$color};\">{$html}</td>"
		. "</tr></table>";
}

// The warning of the security mails: "this is not you" and what to do.
function mail_not_you(string $html): string {
	return mail_callout("warn", mail_t("callout.not_you", ["html" => $html]));
}

// The warning of the mails that follow a change of the account: sign in, change the password, sign out the other devices.
function mail_not_you_password(string $email, string $more = ""): string {
	$login = mail_link($email, "auth", mail_t("action.sign_in"));
	return mail_not_you(mail_t("not_you.password", ["login" => $login]) . ($more === "" ? "" : " " . $more));
}

// "Forgot your password? Choose a new one." (the link is the sign-up page, which sends the reset link of an address that has an account).
function mail_forgot_password(string $email): string {
	return mail_t("forgot.password", ["reset" => mail_link($email, "register", mail_t("forgot.reset_link"))]);
}

// ---------------------------------------------------------------------------
// What every mail shares
// ---------------------------------------------------------------------------

// Who gets what the owner is meant to read (the cycles, the reminder, the warning before an erasure): the login address and the
// notification list, once each.
function mail_recipients(array $account): array {
	$list = explode(ACCOUNT_NOTIFICATION_EMAILS_SEPARATOR, $account["notification_emails"] ?? "");
	return array_values(array_unique(array_filter(array_merge([$account["login_email"]], $list))));
}

// The greeting: the nickname as it is (the template escapes it), or none.
function mail_greeting(?string $name): string {
	return $name === null ? mail_t("greeting.anonymous") : mail_t("greeting.named", ["name" => $name]);
}

// $reason is the key of what completes "Vous le recevez car ..."; $necessary adds why mails are needed, which a mail the user can turn off
// (the cycles) or one that says it itself (the erasure) leaves out.
function mail_why(string $reason = "why.account", bool $necessary = true): string {
	return mail_t("why.lead", ["because" => mail_t($reason)]) . ($necessary ? " " . mail_t("why.necessary") : "");
}

// The subject of a mail named by its title: "Mot de passe changé (16/10 14:32)".
function mail_subject(string $title): string {
	return mail_t("subject.when", ["title" => $title, "when" => mail_when()]);
}

// Short moment for a subject: "16/10 14:32". Every subject is short and carries a detail of its own (this moment, or what the mail is
// about: a token prefix, an erasure date), so that two mails of a kind never share one and a mailbox does not stack them. It holds no
// address and no text of a user: a subject crosses servers in clear.
function mail_when(): string {
	return date(mail_t("format.when"));
}

// "15 novembre 2026" for "2026-11-15".
function mail_long_date(string $ymd): string {
	$date = new DateTimeImmutable($ymd);
	$day = $date->format("j") === "1" ? mail_t("date.first") : $date->format("j");
	return mail_t("format.long_date", ["day" => $day, "month" => mail_words()["months"][intval($date->format("n")) - 1], "year" => $date->format("Y")]);
}

// How long a link lives, in words: "3 jours", "5 heures".
function mail_lifetime(int $minutes): string {
	return match (true) {
		$minutes % 1440 === 0 => mail_t($minutes > 1440 ? "lifetime.days" : "lifetime.day", ["n" => $minutes / 1440]),
		$minutes % 60 === 0 => mail_t($minutes > 60 ? "lifetime.hours" : "lifetime.hour", ["n" => $minutes / 60]),
		default => mail_t("lifetime.minutes", ["n" => $minutes]),
	};
}

// The URL of a page of the app with the address filled in.
function mail_page(string $email, string $page): string {
	return rtrim(APP_URL, "/") . "/" . $page . "?login_email=" . urlencode($email);
}

// A link to that page.
function mail_link(string $email, string $page, string $text): string {
	return mail_a(mail_page($email, $page), $text);
}

// ---------------------------------------------------------------------------
// The mails
// ---------------------------------------------------------------------------

// The mail of a link (lib/email_token.php), $kind: "verify_email" (a new account; it is erased after ACCOUNT_UNVERIFIED_DELETE_DAYS
// unproved), "account_exists" (a sign-up with an address that has an account: a new password may be chosen), "reset_password", or
// "confirm_email" (to the new address of a change). The template shows the link in full under the button, so that any mail reader lets
// it be copied whole; the token is in its fragment, which no server, log or Referer sees.
function mail_send_link(string $kind, string $email, string $name, string $token, string $language): bool {
	mail_language($language);
	$purpose = match ($kind) {
		"verify_email" => EMAIL_TOKEN_PURPOSE_ACCOUNT_CREATION,
		"confirm_email" => EMAIL_TOKEN_PURPOSE_CHANGE_EMAIL,
		default => EMAIL_TOKEN_PURPOSE_RESET_PASSWORD,
	};
	$url = rtrim(APP_URL, "/") . "/verify_email#purpose=" . EMAIL_TOKEN_PURPOSE_NAMES[$purpose] . "&t=" . $token;
	$lifetime = mail_lifetime(EMAIL_TOKEN_LIFETIME_MINUTES[$purpose]);

	$content = match ($kind) {
		"verify_email" => mail_p(mail_t("verify_email.intro")) . mail_callout("info", mail_t("verify_email.tip")),
		"account_exists" => mail_p(mail_t("account_exists.intro")) . mail_p(mail_t("account_exists.forgotten")),
		"reset_password" => mail_p(mail_t("reset_password.intro")),
		"confirm_email" => mail_p(mail_t("confirm_email.intro", ["name" => htmlspecialchars($name)])),
	};
	$slots = [
		"preheader" => mail_t("{$kind}.preheader"),
		"eyebrow" => mail_t("{$kind}.eyebrow"),
		"title" => mail_t("{$kind}.title"),
		"greeting" => $kind === "confirm_email" ? mail_greeting(null) : mail_greeting($name),
		"content" => $content,
		"button_url" => $url,
		"button_label" => mail_t("{$kind}.button"),
		"button_note" => mail_t("link.valid", ["lifetime" => $lifetime]) . match ($kind) {
			"verify_email" => " " . mail_t("link.unverified", ["days" => ACCOUNT_UNVERIFIED_DELETE_DAYS]),
			"confirm_email" => "",
			default => " " . mail_t("link.once"),
		},
		"ps" => match ($kind) {
			"verify_email" => "",
			"account_exists" => mail_t("link.ps_ignore_account"),
			"reset_password" => mail_t("link.ps_ignore_password"),
			"confirm_email" => mail_t("link.ps_ignore_email"),
		},
		"why" => match ($kind) {
			"verify_email" => mail_why("why.account_created"),
			"confirm_email" => mail_why("why.address_asked"),
			default => mail_why(),
		},
	];
	return mail_send($kind, [$email], mail_subject(mail_t("{$kind}.title")), $slots);
}

// A password replaced by a link: the sessions already open stay open, so this mail is how the owner learns of it.
function mail_send_password_changed(string $email, string $name, string $language): bool {
	mail_language($language);
	return mail_send('password_changed', [$email], mail_subject(mail_t("password_changed.title")), [
		"preheader" => mail_t("password_changed.preheader"),
		"eyebrow" => mail_t("eyebrow.security"),
		"title" => mail_t("password_changed.title"),
		"greeting" => mail_greeting($name),
		"content" => mail_p(mail_t("password_changed.intro")) . mail_not_you_password($email),
		"why" => mail_why(),
	]);
}

// To the current address: a change to $new_email was asked for, and happens once the new address is confirmed.
function mail_send_email_change_requested(string $email, string $new_email, string $name, string $language): bool {
	mail_language($language);
	return mail_send('email_change_requested', [$email], mail_subject(mail_t("email_change_requested.title")), [
		"preheader" => mail_t("email_change_requested.preheader"),
		"eyebrow" => mail_t("eyebrow.security"),
		"title" => mail_t("email_change_requested.title"),
		"greeting" => mail_greeting($name),
		"content" => mail_p(mail_t("email_change_requested.intro", ["new" => htmlspecialchars($new_email)]))
			. mail_not_you(mail_t("email_change_requested.not_you")),
		"why" => mail_why(),
	]);
}

// To an address that has an account, asked for another one: nothing changes, and nobody is told which.
function mail_send_email_change_taken(string $email, string $language): bool {
	mail_language($language);
	return mail_send('email_change_taken', [$email], mail_subject(mail_t("email_change_taken.title")), [
		"preheader" => mail_t("email_change_taken.preheader"),
		"eyebrow" => mail_t("eyebrow.email"),
		"title" => mail_t("email_change_taken.title"),
		"greeting" => mail_greeting(null),
		"content" => mail_p(mail_t("email_change_taken.intro")) . mail_p(mail_t("email_change_taken.nothing")),
		"why" => mail_why(),
	]);
}

// To the address the account just left.
function mail_send_email_changed(string $email, string $new_email, string $name, string $language): bool {
	mail_language($language);
	return mail_send('email_changed', [$email], mail_subject(mail_t("email_changed.title")), [
		"preheader" => mail_t("email_changed.preheader"),
		"eyebrow" => mail_t("eyebrow.security"),
		"title" => mail_t("email_changed.title"),
		"greeting" => mail_greeting($name),
		"content" => mail_p(mail_t("email_changed.intro", ["new" => htmlspecialchars($new_email)]))
			. mail_not_you(mail_t("email_changed.not_you", ["contact" => mail_contact()])),
		"why" => mail_why("why.account_left"),
	]);
}

// To the login address: the addresses that receive every cycle changed ("" = emptied), which whoever holds the account must see.
function mail_send_notification_emails_changed(string $email, string $new_list, string $language): bool {
	mail_language($language);
	$listed = str_replace(ACCOUNT_NOTIFICATION_EMAILS_SEPARATOR, ", ", $new_list);
	$what = $new_list === ""
		? mail_t("notification_emails.emptied")
		: mail_t("notification_emails.listed", ["list" => htmlspecialchars($listed)]);
	return mail_send('notification_emails', [$email], mail_subject(mail_t("notification_emails.title")), [
		"preheader" => mail_t("notification_emails.preheader"),
		"eyebrow" => mail_t("app.cycles_by_email"),
		"title" => mail_t("notification_emails.title"),
		"greeting" => mail_greeting(null),
		"content" => mail_p($what) . mail_not_you_password($email),
		"why" => mail_why(),
	]);
}

// To the account's own address at every sign-in, a browser's or a custom token's: a custom token outlives the session it is made from,
// and a stolen password is first used to sign in. The name and the user agent are the client's own words (escaped); no address, as a
// mail crosses servers in clear. $prefix (shown on the account page too) tells which connection of the list this is. $expires_at:
// "Y-m-d H:i:s" UTC, null for the rule of the cron.
function mail_send_new_login(array $account, string $name, string $prefix, string $scope, ?string $user_agent, ?string $expires_at): bool {
	mail_language($account["language"]);
	$email = $account["login_email"];
	$when = date(mail_t("format.date_time"));
	$facts = [
		mail_t("new_login.fact_date") => $when,
		mail_t("new_login.fact_name") => htmlspecialchars($name),
		mail_t("new_login.fact_token") => mail_code($prefix),
		mail_t("new_login.fact_access") => mail_t($scope === "readOnly" ? "new_login.access_read_only" : "new_login.access_read_write"),
	];
	if (!is_null($expires_at)) $facts[mail_t("new_login.fact_until")] = gmdate(mail_t("format.date"), strtotime($expires_at . " UTC"));
	$facts[mail_t("new_login.fact_agent")] = is_null($user_agent) ? mail_t("new_login.agent_unknown") : htmlspecialchars(mb_substr($user_agent, 0, MAIL_USER_AGENT_CHARS));
	return mail_send('new_login', [$email], mail_t("new_login.subject", ["prefix" => $prefix]), [
		"preheader" => mail_t("new_login.intro"),
		"eyebrow" => mail_t("eyebrow.security"),
		"title" => mail_t("new_login.title"),
		"greeting" => mail_greeting($account["name"]),
		"content" => mail_p(mail_t("new_login.intro")) . mail_facts($facts)
			. mail_p(mail_t("notice.nothing_to_do"))
			. mail_not_you_password($email, mail_t("new_login.sessions")),
		"why" => mail_why(),
	]);
}

// To an account whose chart has had no new day for a while (db_querie_select_user_account_inactive()), once: a door back in, and a
// way to tell us what went wrong.
function mail_send_reminder(array $account): bool {
	mail_language($account["language"]);
	$email = $account["login_email"];
	return mail_send('reminder', mail_recipients($account), mail_t("reminder.subject", ["when" => mail_when()]), [
		"preheader" => mail_t("reminder.preheader"),
		"eyebrow" => mail_t("reminder.eyebrow"),
		"title" => mail_t("reminder.title"),
		"greeting" => mail_greeting($account["name"]),
		"content" => mail_p(mail_t("reminder.quiet")) . mail_p(mail_t("reminder.pause") . " " . mail_forgot_password($email))
			. mail_callout("info", mail_t("reminder.feedback", ["contact" => mail_contact()])),
		"button_url" => mail_page($email, "auth"),
		"button_label" => mail_t("reminder.button"),
		"ps" => mail_t("reminder.ps"),
		"why" => mail_why("why.account", false),
	]);
}

function mail_send_deletion_warning(array $account, int $days): bool {
	mail_language($account["language"]);
	$email = $account["login_email"];
	return mail_send('deletion_warning', mail_recipients($account), mail_t("deletion_warning.subject", ["date" => date(mail_t("format.date"), strtotime("+$days days"))]), [
		"preheader" => mail_t("deletion_warning.preheader", ["days" => $days]),
		"eyebrow" => mail_t("deletion_warning.eyebrow"),
		"title" => mail_t("deletion_warning.title"),
		"greeting" => mail_greeting($account["name"]),
		"content" => mail_p(mail_t("deletion_warning.intro"))
			. mail_callout("warn", mail_t("deletion_warning.warning", ["days" => $days]))
			. mail_p(mail_t("deletion_warning.keep") . " " . mail_forgot_password($email)),
		"button_url" => mail_page($email, "auth"),
		"button_label" => mail_t("deletion_warning.button"),
		"ps" => mail_t("deletion_warning.ps", ["contact" => mail_contact()]),
		"why" => mail_why("why.inactive", false),
	]);
}

// The notice of new terms of service to the whole account (login address and notifications). $url and $effective_date ("YYYY-MM-DD") are
// the caller's, checked by it (script/terms_notice.php): the template escapes the URL all the same.
function mail_send_terms_update(array $account, string $url, string $effective_date): bool {
	mail_language($account["language"]);
	$day = mail_long_date($effective_date);
	return mail_send('terms_update', mail_recipients($account), mail_t("terms_update.subject", ["day" => $day]), [
		"preheader" => mail_t("terms_update.preheader", ["day" => $day]),
		"eyebrow" => mail_t("terms_update.eyebrow"),
		"title" => mail_t("terms_update.title"),
		"greeting" => mail_greeting($account["name"]),
		"content" => mail_p(mail_t("terms_update.intro", ["day" => $day])) . mail_p(mail_t("terms_update.accept")),
		"button_url" => $url,
		"button_label" => mail_t("terms_update.button"),
		"why" => mail_why("why.terms"),
	]);
}

// ---------------------------------------------------------------------------
// The mail of a finished cycle: the cycle in figures, a row per attachment, and no "mails are needed" footer, since the user can turn
// this one off.
// ---------------------------------------------------------------------------

// One attachment: a badge with its format, and what it is for ($about is plain text).
function mail_cycle_file(string $format, string $color, string $tint, string $about): string {
	$about = htmlspecialchars($about);
	return <<<HTML
	<tr>
		<td width="52" valign="top" style="padding:0 14px 16px 0;">
			<div style="width:52px;line-height:30px;text-align:center;border-radius:8px;background:{$tint};color:{$color};font-size:12px;font-weight:700;letter-spacing:.6px;">{$format}</div>
		</td>
		<td valign="top" class="mc-text" style="padding:4px 0 16px 0;font-size:14px;line-height:21px;color:#3b4a41;">{$about}</td>
	</tr>
	HTML;
}

// The export of a finished cycle (a row of db_querie_select_cycles_finished()), with $files: [file name => content].
function mail_send_cycle(array $account, string $first_day, string $last_day, int $nb_days, array $files, int $rows_read): bool {
	mail_language($account["language"]);
	$about = ["pdf" => mail_t("cycle.about_pdf"), "csv" => mail_t("cycle.about_csv"), "nfp" => mail_t("cycle.about_nfp")];
	$opt_out = mail_t("cycle.opt_out");
	$settings_url = APP_URL . "settings#notifications";
	$marks = ["first" => $first_day, "last" => $last_day, "n" => $nb_days];
	$files_html = mail_cycle_file("PDF", "#b03a2e", "#fbeceb", $about["pdf"])
		. mail_cycle_file("CSV", "#178048", "#e3f6eb", $about["csv"])
		. mail_cycle_file("NFP", "#3949ab", "#eaecf8", $about["nfp"]);
	return mail_send('cycle', mail_recipients($account), mail_t("cycle.subject", $marks), [
		"preheader" => mail_t("cycle.preheader", $marks),
		"eyebrow" => mail_t("cycle.eyebrow"),
		"title" => mail_t("cycle.title"),
		"greeting" => mail_greeting($account["name"]),
		"content" => mail_p(mail_t("cycle.intro"))
			. mail_facts([
				mail_t("cycle.fact_cycle") => htmlspecialchars(mail_t("cycle.range", $marks)),
				mail_t("cycle.fact_duration") => htmlspecialchars(mail_t("cycle.duration", $marks)),
			])
			. "<div class=\"mc-heading\" style=\"padding:4px 0 14px 0;font-size:16px;font-weight:700;color:#14231a;\">" . htmlspecialchars(mail_t("cycle.attachments")) . "</div>"
			. "<table role=\"presentation\" width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\">{$files_html}</table>",
		"ps" => htmlspecialchars($opt_out) . " " . mail_a($settings_url, htmlspecialchars(mail_t("cycle.manage"))),
		"ps_text" => $opt_out . "\n" . $settings_url,
		"why" => mail_why("why.cycle", false),
	], null, $files, ["n" => $rows_read]);
}
