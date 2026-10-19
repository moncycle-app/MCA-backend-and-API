<?php
/* moncycle.app
**
** licence Creative Commons CC BY-NC-SA
**
** https://www.moncycle.app
** https://github.com/moncycle-app/backend-api-web-app
*/

declare(strict_types=1);

// The link by mail: it proves an address, lets a new password be chosen, or changes the login address. An account has ONE slot for it
// (user_account.email_token_*): a new link replaces the one before, whatever it was for, so whoever can ask for a reset can cancel a
// pending link (accepted). Only the SHA-256 of the token is kept; a link is single use, has a lifetime (EMAIL_TOKEN_LIFETIME_MINUTES) and
// is checked against its purpose. It points at a page of the app with the token in the fragment (never sent to a server, a log or a
// Referer), and the page posts it to POST /api/v2/verify_email: a mail scanner that fetches the link uses nothing up.

require_once __DIR__ . "/db_ops.php";
require_once __DIR__ . "/db_querie.php";
require_once __DIR__ . "/input.php";
require_once __DIR__ . "/log.php";
require_once __DIR__ . "/mail.php";
require_once __DIR__ . "/sec.php";
require_once __DIR__ . "/sec_token.php";

// A new link in the account's slot; answers the token for the mail. $new_email: the address a change of address changes to.
function email_token_issue($db, int $no_user_account, int $purpose, ?string $new_email = null): string {
	$token = rtrim(strtr(base64_encode(random_bytes(EMAIL_TOKEN_BYTES)), "+/", "-_"), "=");
	db_querie_update_user_account_email_token($db, $no_user_account, sec_token_hash($token), $purpose, EMAIL_TOKEN_LIFETIME_MINUTES[$purpose], $new_email);
	log_context(["uid" => $no_user_account]);
	log_event("account.email_token_issued", ["purp" => EMAIL_TOKEN_PURPOSE_NAMES[$purpose]]);
	return $token;
}

// What would refuse the link, read without a lock: "invalid_token" (unknown, used, replaced, or for another purpose), "token_expired", or
// null. It lets a caller skip expensive work (a password hash) for a link that cannot work; email_token_use() decides, under the lock.
function email_token_refusal($db, mixed $token, int $purpose): ?string {
	if (!is_string($token) || $token === "" || strlen($token) > EMAIL_TOKEN_LIMIT_CHARS) return "invalid_token";
	$account = db_querie_select_user_account_by_email_token($db, sec_token_hash($token));
	if (is_null($account) || intval($account["email_token_purpose"]) !== $purpose) return "invalid_token";
	return intval($account["email_token_expired"]) === 1 ? "token_expired" : null;
}

// Uses the link for $purpose: $apply($account) does what it is for, inside the transaction with the account held (["error" => code]
// leaves the link in place). Answers ["account" => the row, "result" => what $apply answered] or ["error" => "invalid_token" (unknown,
// used, replaced, or for another purpose) | "token_expired" | $apply's code].
function email_token_use($db, mixed $token, int $purpose, callable $apply): array {
	if (!is_string($token) || $token === "" || strlen($token) > EMAIL_TOKEN_LIMIT_CHARS) return ["error" => "invalid_token"];
	$token_hash = sec_token_hash($token);
	$found = db_querie_select_user_account_by_email_token($db, $token_hash);
	if (is_null($found)) return ["error" => "invalid_token"];

	return db_ops_transaction($db, function () use ($db, $token_hash, $purpose, $apply, $found) {
		// read again under the lock: another request may have used or replaced the link since
		db_querie_select_user_account_for_update($db, $found["no_user_account"]);
		$account = db_querie_select_user_account_by_email_token($db, $token_hash);
		if (is_null($account) || intval($account["email_token_purpose"]) !== $purpose) return ["error" => "invalid_token"];
		if (intval($account["email_token_expired"]) === 1) return ["error" => "token_expired"];

		$result = $apply($account);
		if (isset($result["error"])) return ["error" => $result["error"]];
		db_querie_update_user_account_email_token_clear($db, $account["no_user_account"]);
		return ["account" => $account, "result" => $result];
	});
}

// POST /api/v2/verify_email: token, purpose, and newPassword for "resetPassword". Answers ["status" => 200, "data" => ["purpose", "loginEmail"
// to sign in with from now on]] or ["status" => 4xx, "code", "message"]. The password is checked before the link is touched, so a refused
// one does not use it up; a reset leaves the sessions and TOTP alone. The mails go once the work is committed.
function email_token_complete($db, array $body, array $issues = []): array {
	$refuse = fn(int $status, string $code, string $message) => ["status" => $status, "code" => $code, "message" => $message];

	$name = input_choice($body, "purpose", EMAIL_TOKEN_PURPOSE_NAMES, $issues);
	$purpose = is_null($name) ? false : array_search($name, EMAIL_TOKEN_PURPOSE_NAMES, true);
	input_secret($body, "token", $issues);

	$new_password = null;
	if ($purpose === EMAIL_TOKEN_PURPOSE_RESET_PASSWORD) {
		$new_password = input_password($body, "newPassword", $issues);
		$issue = is_null($new_password) ? null : sec_password_issue($new_password, "newPassword");
		if (!is_null($issue)) $issues[] = $issue;
	}
	elseif ($purpose !== false && array_key_exists("newPassword", $body)) {
		$issues[] = input_issue("newPassword", "invalid_value", "'newPassword' only goes with the purpose 'resetPassword'.");
	}
	input_exit_if_issues($issues);

	// before the lock, as a password hash is slow, and only for a link that can work: nobody can make the server hash for a token it does not know
	$password_hash = null;
	$refusal = $purpose === EMAIL_TOKEN_PURPOSE_RESET_PASSWORD ? email_token_refusal($db, $body["token"], $purpose) : null;
	if ($purpose === EMAIL_TOKEN_PURPOSE_RESET_PASSWORD && is_null($refusal)) $password_hash = sec_password_hash($new_password);

	$used = !is_null($refusal) ? ["error" => $refusal] : email_token_use($db, $body["token"], $purpose, function (array $account) use ($db, $purpose, $password_hash) {
		if ($purpose === EMAIL_TOKEN_PURPOSE_CHANGE_EMAIL) {
			// the address was free when the link was made; it may not be now
			if (!db_querie_update_user_account_login_email($db, $account["no_user_account"], $account["email_token_new_email"])) return ["error" => "email_taken"];
			return ["loginEmail" => $account["email_token_new_email"]];
		}
		if ($purpose === EMAIL_TOKEN_PURPOSE_RESET_PASSWORD) db_querie_update_password($db, $password_hash, $account["no_user_account"]);
		db_querie_update_user_account_email_verified($db, $account["no_user_account"]);   // whoever reads the mail has the address
		return ["loginEmail" => $account["login_email"]];
	});

	if (isset($used["error"])) {
		log_event("account.refused", ["act" => "verify_email", "err" => $used["error"]]);
		return match ($used["error"]) {
			"token_expired" => $refuse(400, "token_expired", "This link has expired: ask for a new one."),
			"email_taken" => $refuse(409, "email_taken", "This address is used by another account."),
			default => $refuse(400, "invalid_token", "This link is not valid: it may have been used already, or replaced by a newer one."),
		};
	}

	$account = $used["account"];
	log_context(["uid" => intval($account["no_user_account"])]);
	if ($purpose === EMAIL_TOKEN_PURPOSE_RESET_PASSWORD) {
		log_event("account.password_reset");
		mail_send_password_changed($account["login_email"], $account["name"]);
	}
	elseif ($purpose === EMAIL_TOKEN_PURPOSE_CHANGE_EMAIL) {
		log_event("account.email_changed");
		mail_send_email_changed($account["login_email"], $used["result"]["loginEmail"], $account["name"]);
	}
	else log_event("account.email_verified");

	return ["status" => 200, "data" => ["purpose" => $name, "loginEmail" => $used["result"]["loginEmail"]]];
}

// A change of login address, asked by a session that gave its password (and its TOTP code): the link goes to the NEW address, which it
// proves, and the old one is told. An address another account has gets no link and no error (nobody can tell who has an account): its
// owner is told instead. Answers whether the mail was sent.
function email_token_request_email_change($db, array $user_account, string $new_email): bool {
	$no_user_account = intval($user_account["no_user_account"]);
	$token = db_ops_transaction($db, function () use ($db, $no_user_account, $new_email) {
		db_querie_select_user_account_for_update($db, $no_user_account);
		if (!is_null(db_querie_select_user_account_by_email($db, $new_email))) return null;
		return email_token_issue($db, $no_user_account, EMAIL_TOKEN_PURPOSE_CHANGE_EMAIL, $new_email);
	});

	if (is_null($token)) return mail_send_email_change_taken($new_email);
	mail_send_email_change_requested($user_account["login_email"], $new_email, $user_account["name_user_account"]);
	return mail_send_link("confirm_email", $new_email, $user_account["name_user_account"], $token);
}
