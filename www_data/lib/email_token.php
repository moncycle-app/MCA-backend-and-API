<?php
/* moncycle.app
**
** licence Creative Commons CC BY-NC-SA
**
** https://www.moncycle.app
** https://github.com/moncycle-app/backend-api-web-app
*/

// The link by mail. No password is ever sent: an address is proved, a password is chosen again, and the primary address is changed,
// each by a link that carries a token. An account has ONE slot for it (user_account.email_token_*): a new link replaces the one
// before it, whatever that one was for, and anyone who can ask for a reset can therefore cancel a pending link (accepted).
// Only the SHA-256 of the token is kept. A link is single use and has a lifetime (EMAIL_TOKEN_LIFETIME_MINUTES); it is checked against
// what it is for, so that the link of one action cannot do another.
// The link points at a page of the app with the token in its fragment (never sent to a server, a log or a Referer), and the page
// posts it to POST /api/email_token: a mail scanner that only fetches the link uses nothing up.

require_once __DIR__ . "/db.php";
require_once __DIR__ . "/log.php";
require_once __DIR__ . "/mail.php";
require_once __DIR__ . "/sec.php";

// Writes a link in the account's slot (the one before it is gone) and answers the token for the mail. $new_email is the address a
// change of address will change to; the other purposes have none.
function email_token_issue($db, int $no_user_account, int $purpose, ?string $new_email = null): string {
	$token = rtrim(strtr(base64_encode(random_bytes(EMAIL_TOKEN_BYTES)), "+/", "-_"), "=");
	db_update_user_account_email_token($db, $no_user_account, sec_hash_token($token), $purpose, EMAIL_TOKEN_LIFETIME_MINUTES[$purpose], $new_email);
	log_context(["uid" => $no_user_account]);
	log_event("account.email_token_issued", ["purp" => EMAIL_TOKEN_PURPOSE_NAMES[$purpose]]);
	return $token;
}

// Uses the link $token, for $purpose: $apply($account) does what the link is for, inside the transaction and with the account held,
// and answers its result (["error" => code] leaves the link where it is). Answers ["account" => the row of
// db_select_user_account_by_email_token(), "result" => what $apply answered], or ["error" => "invalid_token" | "token_expired" | the
// code $apply gave]. "invalid_token" is every link that is not good for this: unknown, already used, replaced, for another purpose.
function email_token_use($db, mixed $token, int $purpose, callable $apply): array {
	if (!is_string($token) || $token === "" || strlen($token) > EMAIL_TOKEN_LIMIT_CHARS) return ["error" => "invalid_token"];
	$token_hash = sec_hash_token($token);
	$found = db_select_user_account_by_email_token($db, $token_hash);
	if (is_null($found)) return ["error" => "invalid_token"];

	return db_transaction($db, function () use ($db, $token_hash, $purpose, $apply, $found) {
		// read again under the lock: another request may have used the link or replaced it since
		db_select_user_account_for_update($db, $found["no_user_account"]);
		$account = db_select_user_account_by_email_token($db, $token_hash);
		if (is_null($account) || intval($account["email_token_purpose"]) !== $purpose) return ["error" => "invalid_token"];
		if (intval($account["email_token_expired"]) === 1) return ["error" => "token_expired"];

		$result = $apply($account);
		if (isset($result["error"])) return ["error" => $result["error"]];
		db_update_user_account_email_token_clear($db, $account["no_user_account"]);
		return ["account" => $account, "result" => $result];
	});
}

// POST /api/email_token: uses the link of a mail. $body: token, purpose ("verifyEmail", "resetPassword" or "changeEmail"), and for
// "resetPassword" newPassword. Answers what the endpoint says:
//   success: ["status" => 200, "data" => ["purpose" => ..., "email" => the address to sign in with from now on]]
//   refusal: ["status" => 4xx, "code" => ..., "message" => ...]
// The password is checked before the link is touched: a password that is refused does not use the link up. The mails that tell the
// owner what happened go once the work is committed.
function email_token_complete($db, array $body): array {
	$refuse = fn(int $status, string $code, string $message) => ["status" => $status, "code" => $code, "message" => $message];

	$purpose = is_string($body["purpose"] ?? null) ? array_search($body["purpose"], EMAIL_TOKEN_PURPOSE_NAMES, true) : false;
	if ($purpose === false) return $refuse(400, "invalid_purpose", "'purpose' must be one of: " . implode(", ", EMAIL_TOKEN_PURPOSE_NAMES) . ".");
	if (!is_string($body["token"] ?? null) || $body["token"] === "") return $refuse(400, "missing_token", "'token' is required.");

	$password_hash = null;
	if ($purpose === EMAIL_TOKEN_PURPOSE_RESET_PASSWORD) {
		if (!is_string($body["newPassword"] ?? null) || $body["newPassword"] === "") return $refuse(400, "missing_fields", "'newPassword' is required.");
		$problem = sec_password_problem($body["newPassword"]);
		if (!is_null($problem)) return $refuse(422, $problem[0], $problem[1]);
		$password_hash = sec_hash($body["newPassword"]);   // before the lock: bcrypt is slow
	}

	$used = email_token_use($db, $body["token"], $purpose, function (array $account) use ($db, $purpose, $password_hash) {
		if ($purpose === EMAIL_TOKEN_PURPOSE_RESET_PASSWORD) {
			db_update_password($db, $password_hash, $account["no_user_account"]);
			db_update_user_account_email_verified($db, $account["no_user_account"]);   // whoever reads the mail has the address
			return ["email" => $account["email1"]];
		}
		if ($purpose === EMAIL_TOKEN_PURPOSE_CHANGE_EMAIL) {
			// the address was free when the link was made; it may not be now
			if (!db_update_user_account_email1($db, $account["no_user_account"], $account["email_token_new_email"])) return ["error" => "email_taken"];
			return ["email" => $account["email_token_new_email"]];
		}
		db_update_user_account_email_verified($db, $account["no_user_account"]);
		return ["email" => $account["email1"]];
	});

	if (isset($used["error"])) {
		log_event("account.refused", ["act" => "email_token", "err" => $used["error"]]);
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
		mail_send_password_changed($account["email1"], $account["name"]);
	}
	elseif ($purpose === EMAIL_TOKEN_PURPOSE_CHANGE_EMAIL) {
		log_event("account.email_changed");
		mail_send_email_changed($account["email1"], $used["result"]["email"], $account["name"]);
	}
	else {
		log_event("account.email_verified");
	}

	return ["status" => 200, "data" => ["purpose" => EMAIL_TOKEN_PURPOSE_NAMES[$purpose], "email" => $used["result"]["email"]]];
}

// A change of the primary address, asked by a session that has given its password (the endpoint checks it, and the TOTP code when the
// account has one): the link goes to the NEW address, which proves it, and the old one is told. Answers whether the link mail was
// sent. An address another account has gets no link and no error (nobody can tell who has an account): its owner is told instead.
function email_token_request_email_change($db, array $user_account, string $new_email): bool {
	$no_user_account = intval($user_account["no_user_account"]);
	// null when the address belongs to an account already
	$token = db_transaction($db, function () use ($db, $no_user_account, $new_email) {
		db_select_user_account_for_update($db, $no_user_account);
		return db_select_user_account_exists($db, $new_email) ? null : email_token_issue($db, $no_user_account, EMAIL_TOKEN_PURPOSE_CHANGE_EMAIL, $new_email);
	});

	if (is_null($token)) return mail_send_email_change_taken($new_email);
	mail_send_email_change_requested($user_account["email1"], $new_email, $user_account["name_user_account"]);
	return mail_send_confirm_email($new_email, $user_account["name_user_account"], $token);
}
