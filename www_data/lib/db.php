<?php
/* moncycle.app
**
** licence Creative Commons CC BY-NC-SA
**
** https://www.moncycle.app
** https://github.com/moncycle-app/backend-api-web-app
*/

declare(strict_types=1);

// Every SQL statement of the app, one function each: its SQL and its parameters, no business logic. Reads answer a list of rows, one
// row or null, a column or a value; writes the number of rows changed, inserts the new id. MariaDB, utf8mb4_bin: "=" is exact.

// The charset is in the DSN: PDO knows it, and the server hears it in the handshake (no SET NAMES round trip).
function db_dsn(): string {
	return "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4";
}

// Persistent in a web request: the worker keeps the connection for its next request, which saves the connect and the login (about 250
// of 650 us, script/db_perf.php). Safe because nothing leaves a state on a connection (no SET, GET_LOCK, temporary table or autocommit
// change: db_perf.php scans for them), PDO replaces a dead one, and a transaction left open by a request that died is rolled back here.
// Each worker holds one: max_connections must cover the workers of every app container plus the cron.
function db_open(?bool $persistent = null): PDO {
	$db = new PDO(db_dsn(), DB_ID, DB_PASSWORD, [PDO::ATTR_PERSISTENT => $persistent ?? PHP_SAPI !== "cli"]);
	if ($db->inTransaction()) $db->exec("ROLLBACK");
	return $db;
}

// ---------------------------------------------------------------------------
// Running a statement
// ---------------------------------------------------------------------------

// $sql with ":name" parameters, prepared once per connection. An int or a bool binds as an integer, null as NULL, the rest as a string.
function db_run($db, string $sql, array $params = []): PDOStatement {
	static $statements = [];
	$statement = $statements[spl_object_id($db)][$sql] ??= $db->prepare($sql);

	foreach ($params as $name => $value) {
		$type = match (true) {
			is_null($value) => PDO::PARAM_NULL,
			is_int($value), is_bool($value) => PDO::PARAM_INT,
			default => PDO::PARAM_STR,
		};
		$statement->bindValue(":$name", is_bool($value) ? intval($value) : $value, $type);
	}
	$statement->execute();
	return $statement;
}

function db_rows($db, string $sql, array $params = []): array {
	return db_run($db, $sql, $params)->fetchAll(PDO::FETCH_ASSOC);
}

function db_row($db, string $sql, array $params = []): ?array {
	$statement = db_run($db, $sql, $params);
	$row = $statement->fetch(PDO::FETCH_ASSOC);
	$statement->closeCursor();
	return $row === false ? null : $row;
}

function db_column($db, string $sql, array $params = []): array {
	return db_run($db, $sql, $params)->fetchAll(PDO::FETCH_COLUMN);
}

// the first column of the first row, false when there is none
function db_value($db, string $sql, array $params = []): mixed {
	$statement = db_run($db, $sql, $params);
	$value = $statement->fetchColumn();
	$statement->closeCursor();
	return $value;
}

function db_exec($db, string $sql, array $params = []): int {
	return db_run($db, $sql, $params)->rowCount();
}

function db_insert($db, string $sql, array $params = []): string {
	db_run($db, $sql, $params);
	return $db->lastInsertId();
}

// What $write answers, or null when a unique key refuses it (MariaDB error 1062): the key decides between two requests that race.
function db_unless_duplicate(callable $write): mixed {
	try {
		return $write();
	} catch (\PDOException $error) {
		if (intval($error->errorInfo[1] ?? 0) === 1062) return null;
		throw $error;
	}
}

// $work in one transaction: committed and its result returned, or rolled back when it throws.
function db_transaction($db, callable $work): mixed {
	$db->exec("START TRANSACTION");
	try {
		$result = $work();
		$db->exec("COMMIT");
		return $result;
	} catch (\Throwable $error) {
		$db->exec("ROLLBACK");
		throw $error;
	}
}

// A script of several statements (script/db/*.sql), sent as it is: every result is read, or a failure after the first would go unseen.
function db_exec_script($db, string $sql): void {
	$statement = $db->query($sql);
	do {
	} while ($statement->nextRowset());
}

function db_count_tables($db): int {
	return intval(db_value($db, "SELECT COUNT(T.TABLE_NAME) FROM information_schema.TABLES AS T WHERE T.TABLE_SCHEMA = DATABASE()"));
}

// ---------------------------------------------------------------------------
// Accounts
// ---------------------------------------------------------------------------

function db_select_user_account($db, $no_user_account): ?array {
	return db_row($db, "SELECT * FROM user_account WHERE no_user_account = :no_user_account", ["no_user_account" => $no_user_account]);
}

// Holds the account's row until the end of the transaction, so that its writes go one after the other: two that read, find a row
// missing and insert it would otherwise both insert, and the unique key would throw. Answers whether the account exists.
function db_select_user_account_for_update($db, $no_user_account): bool {
	return db_value($db, "SELECT no_user_account FROM user_account WHERE no_user_account = :no_user_account FOR UPDATE", ["no_user_account" => $no_user_account]) !== false;
}

function db_select_user_account_by_email($db, string $email): ?array {
	return db_row($db, "SELECT * FROM user_account WHERE login_email = :login_email", ["login_email" => $email]);
}

// The new id, null when the address has an account. The terms are accepted now: the sign-up refuses a request that does not accept them.
function db_insert_user_account($db, $name, $nfp_method, $age, $email, $password_hash, $register_comment, $research): ?string {
	return db_unless_duplicate(fn() => db_insert($db,
		"INSERT INTO user_account (name, nfp_method, age, login_email, password, language, register_comment, research, terms_accepted_at)
		VALUES (:name, :nfp_method, :age, :login_email, :password, :language, :register_comment, :research, NOW())",
		["name" => $name, "nfp_method" => $nfp_method, "age" => $age, "login_email" => $email, "password" => $password_hash, "language" => ACCOUNT_DEFAULT_LANGUAGE, "register_comment" => $register_comment, "research" => $research]
	));
}

// An address already proved keeps the date it was proved on.
function db_update_user_account_email_verified($db, $no_user_account): int {
	return db_exec($db, "UPDATE user_account SET email_verified_at = COALESCE(email_verified_at, NOW()) WHERE no_user_account = :no_user_account", ["no_user_account" => $no_user_account]);
}

// The account's one link slot (lib/email_token.php), whatever was in it: the SHA-256 of the token, its purpose, its lifetime on the DB
// clock, and for a change of address the address to change to.
function db_update_user_account_email_token($db, $no_user_account, string $token_hash, int $purpose, int $lifetime_minutes, ?string $new_email): int {
	return db_exec($db,
		"UPDATE user_account SET email_token_hash = :hash, email_token_purpose = :purpose,
		email_token_expires_at = NOW() + INTERVAL :lifetime MINUTE, email_token_new_email = :new_email
		WHERE no_user_account = :no_user_account",
		["hash" => $token_hash, "purpose" => $purpose, "lifetime" => $lifetime_minutes, "new_email" => $new_email, "no_user_account" => $no_user_account]
	);
}

// With $purpose, only a link for that purpose: a password changed on the account page ends a reset link, not a change of address.
function db_update_user_account_email_token_clear($db, $no_user_account, ?int $purpose = null): int {
	return db_exec($db,
		"UPDATE user_account SET email_token_hash = NULL, email_token_purpose = NULL, email_token_expires_at = NULL, email_token_new_email = NULL
		WHERE no_user_account = :no_user_account AND (:purpose1 IS NULL OR email_token_purpose = :purpose2)",
		["no_user_account" => $no_user_account, "purpose1" => $purpose, "purpose2" => $purpose]
	);
}

function db_select_user_account_by_email_token($db, string $token_hash): ?array {
	return db_row($db,
		"SELECT u.no_user_account, u.name, u.login_email, u.email_verified_at, u.email_token_purpose, u.email_token_new_email,
		(u.email_token_expires_at <= NOW()) AS email_token_expired
		FROM user_account AS u WHERE u.email_token_hash = :hash",
		["hash" => $token_hash]
	);
}

// False when another account has the address.
function db_update_user_account_login_email($db, $no_user_account, string $email): bool {
	return !is_null(db_unless_duplicate(fn() => db_exec($db, "UPDATE user_account SET login_email = :login_email WHERE no_user_account = :no_user_account",
		["login_email" => $email, "no_user_account" => $no_user_account])));
}

function db_select_user_account_unverified_old($db, int $days): array {
	return db_rows($db,
		"SELECT u.no_user_account, u.login_email FROM user_account AS u
		WHERE u.email_verified_at IS NULL AND u.inscription_date < NOW() - INTERVAL :days DAY
		AND u.no_user_account NOT IN (" . ACCOUNT_DEMO_IDS_SQL . ")
		ORDER BY u.no_user_account",
		["days" => $days]
	);
}

// $fields: name, notification_emails, nfp_method, age, sponsor, timeline_asc, research, auto_mail_export
function db_update_user_account_settings($db, $no_user_account, array $fields, string $last_write_client_UTC): int {
	return db_exec($db,
		"UPDATE user_account SET `name` = :name, notification_emails = :notification_emails, nfp_method = :nfp_method, age = :age, sponsor = :sponsor,
		timeline_asc = :timeline_asc, research = :research, auto_mail_export = :auto_mail_export, last_write_client_UTC = :last_write_client_UTC
		WHERE no_user_account = :no_user_account",
		$fields + ["last_write_client_UTC" => $last_write_client_UTC, "no_user_account" => $no_user_account]
	);
}

function db_update_password($db, string $password_hash, $no_user_account): int {
	return db_exec($db, "UPDATE user_account SET password = :password, last_password_change = NOW() WHERE no_user_account = :no_user_account", ["password" => $password_hash, "no_user_account" => $no_user_account]);
}

// A cleared day is a row too.
function db_count_days_of_account($db, $no_user_account): int {
	return intval(db_value($db, "SELECT COUNT(dt.no_day) FROM day_timeline AS dt WHERE dt.no_user_account = :no_user_account", ["no_user_account" => $no_user_account]));
}

function db_count_descriptions_of_account($db, $no_user_account): int {
	return intval(db_value($db, "SELECT COUNT(d.no_description) FROM description AS d WHERE d.no_user_account = :no_user_account", ["no_user_account" => $no_user_account]));
}

function db_delete_user_account($db, $no_user_account): int {
	return db_exec($db, "DELETE FROM user_account WHERE no_user_account = :no_user_account", ["no_user_account" => $no_user_account]);
}

function db_update_is_inactive($db, $no_user_account, int $is_inactive): int {
	return db_exec($db, "UPDATE user_account SET is_inactive = :is_inactive WHERE no_user_account = :no_user_account", ["is_inactive" => $is_inactive, "no_user_account" => $no_user_account]);
}

function db_update_user_account_totp($db, $no_user_account, int $totp_state, ?string $totp_secret): int {
	return db_exec($db, "UPDATE user_account SET totp_state = :totp_state, totp_secret = :totp_secret WHERE no_user_account = :no_user_account",
		["totp_state" => $totp_state, "totp_secret" => $totp_secret, "no_user_account" => $no_user_account]);
}

// ---------------------------------------------------------------------------
// Tokens, login attempts
// ---------------------------------------------------------------------------

// The account behind a token (its SHA-256), with the token's own columns.
function db_select_user_account_auth_token($db, string $auth_token_str): ?array {
	return db_row($db,
		"SELECT J.no_user_account, J.no_auth_token, J.auth_token_prefix AS prefix_auth_token, C.name AS name_user_account, J.contry_code, J.name AS name_auth_token,
		J.scope AS scope_auth_token, " . db_sql_old_auth_token("J.") . " AS expired_auth_token,
		J.date_creation AS d_creation_auth_token, J.date_use AS d_use_auth_token, C.nfp_method, C.age, C.login_email, C.notification_emails,
		C.nb_connection_attempts, C.last_failed_attempt, C.sponsor, C.user_enabled, C.is_inactive, C.last_auth_date, C.inscription_date,
		C.last_password_change, C.register_comment, C.totp_secret, C.totp_state, C.research, C.timeline_asc, C.auto_mail_export, C.last_write_client_UTC
		FROM auth_token AS J INNER JOIN user_account AS C ON J.no_user_account = C.no_user_account
		WHERE auth_token_str = :auth_token_str LIMIT 1",
		["auth_token_str" => $auth_token_str]
	);
}

function db_select_auth_tokens($db, $no_user_account): array {
	return db_rows($db, "SELECT * FROM auth_token WHERE no_user_account = :no_user_account ORDER BY no_auth_token", ["no_user_account" => $no_user_account]);
}

// GET /api/v2/token, the newest first: everything but the hash, and no token past its expiry date.
function db_select_auth_tokens_listing($db, $no_user_account): array {
	return db_rows($db,
		"SELECT J.no_auth_token, J.auth_token_prefix, J.name, J.scope, J.expire, J.date_expire, J.date_creation, J.date_use,
		J.user_agent, J.user_agent_use, J.ip_address, J.ip_address_use
		FROM auth_token AS J
		WHERE J.no_user_account = :no_user_account AND (J.date_expire IS NULL OR J.date_expire > NOW())
		ORDER BY J.date_creation DESC, J.no_auth_token DESC",
		["no_user_account" => $no_user_account]
	);
}

// $expires_in_days null: the cron's rule (expire 2; INTERVAL NULL DAY is NULL); a number: it dies on that day whatever its use (expire
// 0). The new id, null when the account has a token with this prefix (the caller makes another).
function db_insert_auth_token($db, $no_user_account, string $name, int $scope, ?int $expires_in_days, ?string $user_agent, ?string $ip_address, string $auth_token_str, ?string $auth_token_prefix): ?string {
	return db_unless_duplicate(fn() => db_insert($db,
		"INSERT INTO auth_token (no_user_account, name, scope, expire, date_expire, user_agent, ip_address, auth_token_str, auth_token_prefix)
		VALUES (:no_user_account, :name, :scope, :expire, NOW() + INTERVAL :expires_in_days DAY, :user_agent, :ip_address, :auth_token_str, :auth_token_prefix)",
		[
			"no_user_account" => $no_user_account, "name" => $name, "scope" => $scope, "expire" => is_null($expires_in_days) ? 2 : 0,
			"expires_in_days" => $expires_in_days,
			"user_agent" => $user_agent, "ip_address" => $ip_address, "auth_token_str" => $auth_token_str, "auth_token_prefix" => $auth_token_prefix,
		]
	));
}

// Every authenticated request calls it, and what reads date_use counts in days: a token is written at most every 5 minutes.
function db_update_auth_token_use($db, $no_auth_token, string $ip_address, ?string $user_agent): int {
	return db_exec($db,
		"UPDATE auth_token SET date_use = NOW(), ip_address_use = :ip_address, user_agent_use = :user_agent
		WHERE no_auth_token = :no_auth_token AND (date_use IS NULL OR date_use < NOW() - INTERVAL 5 MINUTE)",
		["no_auth_token" => $no_auth_token, "ip_address" => $ip_address, "user_agent" => $user_agent]
	);
}

function db_delete_auth_token($db, $no_auth_token, $no_user_account): int {
	return db_exec($db, "DELETE FROM auth_token WHERE no_auth_token = :no_auth_token AND no_user_account = :no_user_account", ["no_auth_token" => $no_auth_token, "no_user_account" => $no_user_account]);
}

// The deletes by prefix, by hash and of the others answer the ids deleted (RETURNING: the log names them without a query of its own).
function db_delete_auth_token_by_prefix($db, $no_user_account, string $auth_token_prefix): array {
	return array_map('intval', db_column($db, "DELETE FROM auth_token WHERE no_user_account = :no_user_account AND auth_token_prefix = :auth_token_prefix RETURNING no_auth_token", ["no_user_account" => $no_user_account, "auth_token_prefix" => $auth_token_prefix]));
}

function db_delete_auth_token_by_str($db, string $auth_token_str): array {
	return array_map('intval', db_column($db, "DELETE FROM auth_token WHERE auth_token_str = :auth_token_str RETURNING no_auth_token", ["auth_token_str" => $auth_token_str]));
}

function db_delete_auth_tokens_other($db, $no_user_account, $no_auth_token_kept): array {
	return array_map('intval', db_column($db, "DELETE FROM auth_token WHERE no_user_account = :no_user_account AND no_auth_token <> :no_auth_token RETURNING no_auth_token", ["no_user_account" => $no_user_account, "no_auth_token" => $no_auth_token_kept]));
}

// The tokens past their date, and those without one unused for AUTH_TOKEN_IDLE_DAYS or older than AUTH_TOKEN_DEFAULT_LIFETIME_DAYS.
// One rule for the purge and for the request that carries the token ($alias: the table's alias with its dot, "J." or ""); NULL
// (no date, nothing to compare) counts as not old.
function db_sql_old_auth_token(string $alias = ""): string {
	return "COALESCE({$alias}date_expire < NOW() OR ({$alias}expire > 0 AND ({$alias}date_creation < CURDATE() - INTERVAL " . AUTH_TOKEN_DEFAULT_LIFETIME_DAYS
		. " DAY OR {$alias}date_use < CURDATE() - INTERVAL " . AUTH_TOKEN_IDLE_DAYS . " DAY)), 0)";
}

// Deletes them, or with $dry_run counts them. Answers how many.
function db_purge_auth_token($db, bool $dry_run): int {
	return $dry_run ? intval(db_value($db, "SELECT COUNT(*) FROM auth_token WHERE " . db_sql_old_auth_token())) : db_exec($db, "DELETE FROM auth_token WHERE " . db_sql_old_auth_token());
}

// A password or code that passed on a session: the failures before it are forgiven (a sign-in does the same, with the log-in date).
function db_update_login_failures_clear($db, $no_user_account): int {
	return db_exec($db, "UPDATE user_account SET nb_connection_attempts = 0 WHERE no_user_account = :no_user_account AND nb_connection_attempts <> 0", ["no_user_account" => $no_user_account]);
}

function db_update_user_account_logged_in($db, $no_user_account): int {
	return db_exec($db, "UPDATE user_account SET last_auth_date = NOW(), nb_connection_attempts = 0, is_inactive = 0 WHERE no_user_account = :no_user_account", ["no_user_account" => $no_user_account]);
}

// A failure older than LOGIN_ATTEMPTS_DECAY_MINUTES restarts the count at 1.
function db_update_login_failure($db, string $email): int {
	return db_exec($db,
		"UPDATE user_account SET
		nb_connection_attempts = IF(last_failed_attempt IS NULL OR last_failed_attempt < NOW() - INTERVAL :decay_minutes MINUTE, 1, nb_connection_attempts + 1),
		last_failed_attempt = NOW() WHERE login_email = :login_email",
		["decay_minutes" => LOGIN_ATTEMPTS_DECAY_MINUTES, "login_email" => $email]
	);
}

function db_insert_login_attempt_ip($db, string $ip_address): string {
	return db_insert($db, "INSERT INTO login_attempt_ip (ip_address) VALUES (:ip_address)", ["ip_address" => $ip_address]);
}

function db_delete_login_attempt_ip($db, int $no_login_attempt_ip): int {
	return db_exec($db, "DELETE FROM login_attempt_ip WHERE no_login_attempt_ip = :no_login_attempt_ip", ["no_login_attempt_ip" => $no_login_attempt_ip]);
}

// the attempts from this address in the last 15 minutes, whatever the account
function db_count_login_attempt_ip($db, string $ip_address): int {
	return intval(db_value($db, "SELECT COUNT(*) FROM login_attempt_ip WHERE ip_address = :ip_address AND date_attempt > NOW() - INTERVAL 15 MINUTE", ["ip_address" => $ip_address]));
}

// The attempts older than a day: deleted, or with $dry_run counted. Answers how many.
function db_purge_login_attempt_ip($db, bool $dry_run): int {
	$where = "date_attempt < NOW() - INTERVAL 1 DAY";
	return $dry_run ? intval(db_value($db, "SELECT COUNT(*) FROM login_attempt_ip WHERE $where")) : db_exec($db, "DELETE FROM login_attempt_ip WHERE $where");
}

// ---------------------------------------------------------------------------
// Descriptions: the free-text labels of a day, and their links to the days
// ---------------------------------------------------------------------------

function db_select_description($db, $no_description, $no_user_account): ?array {
	return db_row($db, "SELECT no_description, name, type FROM description WHERE no_description = :no_description AND no_user_account = :no_user_account",
		["no_description" => $no_description, "no_user_account" => $no_user_account]);
}

// is the name taken by another description of the account?
function db_select_description_name_exists($db, string $name, $no_user_account, $no_description): bool {
	return boolval(db_value($db, "SELECT COUNT(no_description) > 0 FROM description WHERE name = :name AND no_user_account = :no_user_account AND no_description != :no_description",
		["name" => $name, "no_user_account" => $no_user_account, "no_description" => $no_description]));
}

// The labels with the number of days each is on.
function db_select_description_with_count($db, $no_user_account): array {
	return db_rows($db,
		"SELECT d.no_description, d.name, d.type, COUNT(ld.no_day) AS use_count
		FROM description AS d LEFT JOIN link_day_timeline_description AS ld ON ld.no_description = d.no_description
		WHERE d.no_user_account = :no_user_account
		GROUP BY d.no_description, d.name, d.type ORDER BY use_count DESC, d.name DESC",
		["no_user_account" => $no_user_account]
	);
}

function db_select_description_exact_name($db, $no_user_account, string $name): ?array {
	return db_row($db, "SELECT no_description, name, type FROM description WHERE no_user_account = :no_user_account AND name = :name LIMIT 1",
		["no_user_account" => $no_user_account, "name" => $name]);
}

function db_select_description_name_type($db, $no_user_account): array {
	return db_rows($db, "SELECT name, type FROM description WHERE no_user_account = :no_user_account", ["no_user_account" => $no_user_account]);
}

function db_insert_description($db, $no_user_account, string $name, int $type, string $last_write_client_UTC): string {
	return db_insert($db,
		"INSERT INTO description (no_user_account, name, type, last_write_client_UTC) VALUES (:no_user_account, :name, :type, :last_write_client_UTC)",
		["no_user_account" => $no_user_account, "name" => $name, "type" => $type, "last_write_client_UTC" => $last_write_client_UTC]
	);
}

function db_update_description_name_type($db, $no_user_account, $no_description, string $name, int $type, string $last_write_client_UTC): int {
	return db_exec($db,
		"UPDATE description SET name = :name, type = :type, last_write_client_UTC = :last_write_client_UTC
		WHERE no_description = :no_description AND no_user_account = :no_user_account",
		["name" => $name, "type" => $type, "last_write_client_UTC" => $last_write_client_UTC, "no_description" => $no_description, "no_user_account" => $no_user_account]
	);
}

function db_delete_descriptions($db, $no_description, $no_user_account): int {
	return db_exec($db, "DELETE FROM description WHERE no_description = :no_description AND no_user_account = :no_user_account",
		["no_description" => $no_description, "no_user_account" => $no_user_account]);
}

function db_select_all_description_for_day_timeline($db, $no_user_account, $no_day): array {
	return db_rows($db,
		"SELECT ld.no_description, d.no_user_account, d.name, d.type
		FROM link_day_timeline_description AS ld LEFT JOIN description AS d ON ld.no_description = d.no_description
		WHERE ld.no_day = :no_day AND d.no_user_account = :no_user_account",
		["no_day" => $no_day, "no_user_account" => $no_user_account]
	);
}

// The labels of every day of a range in one query, ordered by day, type and id: a read of many days groups them by no_day.
function db_select_descriptions_for_day_timeline_frame($db, string $start_date, string $end_date, $no_user_account): array {
	return db_rows($db,
		"SELECT ld.no_day, d.no_description, d.name, d.type
		FROM day_timeline AS dt
		JOIN link_day_timeline_description AS ld ON ld.no_day = dt.no_day
		JOIN description AS d ON d.no_description = ld.no_description
		WHERE dt.no_user_account = :no_user_account AND dt.date_obs >= :start_date AND dt.date_obs <= :end_date AND d.no_user_account = :no_user_account
		ORDER BY ld.no_day ASC, d.type ASC, d.no_description ASC",
		["no_user_account" => $no_user_account, "start_date" => $start_date, "end_date" => $end_date]
	);
}

function db_insert_link_description_day_timeline($db, $no_day, $no_description): int {
	return db_exec($db, "INSERT INTO link_day_timeline_description (no_day, no_description) VALUES (:no_day, :no_description)",
		["no_day" => $no_day, "no_description" => $no_description]);
}

function db_delete_linked_descriptions($db, $no_day, $no_description): int {
	return db_exec($db, "DELETE FROM link_day_timeline_description WHERE no_description = :no_description AND no_day = :no_day",
		["no_description" => $no_description, "no_day" => $no_day]);
}

// A day shows its labels by name and type: renaming, retyping or deleting one changes the days it is on for the sync, though their rows
// do not change, so they are stamped.
function db_update_day_timeline_touch_description($db, $no_user_account, $no_description): int {
	return db_exec($db,
		"UPDATE day_timeline AS dt JOIN link_day_timeline_description AS ld ON ld.no_day = dt.no_day
		SET dt.last_write_db = CURRENT_TIMESTAMP
		WHERE ld.no_description = :no_description AND dt.no_user_account = :no_user_account",
		["no_description" => $no_description, "no_user_account" => $no_user_account]
	);
}

// ---------------------------------------------------------------------------
// Days. date_obs is unique per account: equality on the account then a range on the date is a range scan of that index, in order.
// ---------------------------------------------------------------------------

function db_select_all_day_timeline($db, $no_user_account): array {
	return db_rows($db, "SELECT * FROM day_timeline WHERE no_user_account = :no_user_account ORDER BY date_obs ASC", ["no_user_account" => $no_user_account]);
}

function db_select_day_timeline($db, string $date, $no_user_account): ?array {
	return db_row($db, "SELECT * FROM day_timeline WHERE date_obs = :date AND no_user_account = :no_user_account LIMIT 1", ["date" => $date, "no_user_account" => $no_user_account]);
}

// The database's clock, the one that stamps last_write_db: a client's clock never decides what a sync receives.
function db_select_now($db): string {
	return db_value($db, "SELECT NOW()");
}

// The dates written since a moment less $overlap_seconds. A day never stamped counts as written, so a first sync cannot miss it.
function db_select_day_timeline_dates_written_since($db, string $since, int $overlap_seconds, $no_user_account): array {
	return db_column($db,
		"SELECT date_obs FROM day_timeline
		WHERE no_user_account = :no_user_account AND (last_write_db IS NULL OR last_write_db >= :since - INTERVAL :overlap_seconds SECOND)
		ORDER BY date_obs ASC",
		["no_user_account" => $no_user_account, "since" => $since, "overlap_seconds" => $overlap_seconds]
	);
}

// The days from $start_date up to $end_date excluded (null: no end), with the columns a day shows.
function db_select_day_timelines_range($db, string $start_date, ?string $end_date, $no_user_account): array {
	return db_rows($db,
		"SELECT no_day, date_obs, day_not_observed, fc_score, fc_arrow, stamp, temperature, time_temp_taken, is_peak, counter_start,
		union_sex, cycle_1st_day, pregnancy, comment, last_write_client_UTC, last_write_db
		FROM day_timeline
		WHERE no_user_account = :no_user_account AND date_obs >= :start_date AND (:end_date IS NULL OR date_obs < :end_date)
		ORDER BY date_obs ASC",
		["no_user_account" => $no_user_account, "start_date" => $start_date, "end_date" => $end_date]
	);
}

// The dates of a range the account has a day on, from the index alone.
function db_select_day_timeline_dates_frame($db, string $start_date, string $end_date, $no_user_account): array {
	return db_column($db,
		"SELECT date_obs FROM day_timeline WHERE no_user_account = :no_user_account AND date_obs >= :start_date AND date_obs <= :end_date ORDER BY date_obs ASC",
		["no_user_account" => $no_user_account, "start_date" => $start_date, "end_date" => $end_date]
	);
}

function db_insert_day_timeline($db, string $date, $no_user_account): string {
	return db_insert($db, "INSERT INTO day_timeline (no_user_account, date_obs, stamp) VALUES (:no_user_account, :date, '')", ["no_user_account" => $no_user_account, "date" => $date]);
}

// What a day holds ($fields: day_format_from_json(); one left out is written empty). last_write_db is set here, not by ON UPDATE: a
// write that changes only the labels a day carries must still reach the sync.
function db_update_day_timeline($db, string $date, $no_user_account, string $last_write_client_UTC, array $fields = []): int {
	$fields += [
		"stamp" => '', "fc_score" => null, "fc_arrow" => null, "temp" => null, "htemp" => null, "is_peak" => null, "union_sex" => null,
		"cycle_1st_day" => null, "day_not_observed" => null, "pregnancy" => null, "comment" => null, "counter_start" => null,
	];
	return db_exec($db,
		"UPDATE day_timeline SET stamp = :stamp, fc_score = :fc_score, fc_arrow = :fc_arrow, temperature = :temp, time_temp_taken = :htemp,
		is_peak = :is_peak, union_sex = :union_sex, cycle_1st_day = :cycle_1st_day, day_not_observed = :day_not_observed,
		pregnancy = :pregnancy, comment = :comment, counter_start = :counter_start, last_write_client_UTC = :last_write_client_UTC,
		last_write_db = CURRENT_TIMESTAMP
		WHERE date_obs = :date AND no_user_account = :no_user_account",
		$fields + ["last_write_client_UTC" => $last_write_client_UTC, "date" => $date, "no_user_account" => $no_user_account]
	);
}

// ---------------------------------------------------------------------------
// Cycles: from a day marked cycle_1st_day to the day before the next one
// ---------------------------------------------------------------------------

// the first days of the account's cycles, latest first
function db_select_cycles($db, $no_user_account): array {
	return db_column($db, "SELECT date_obs FROM day_timeline WHERE no_user_account = :no_user_account AND cycle_1st_day = 1 ORDER BY date_obs DESC", ["no_user_account" => $no_user_account]);
}

function db_select_pregnancies($db, $no_user_account): array {
	return db_column($db, "SELECT date_obs FROM day_timeline WHERE no_user_account = :no_user_account AND pregnancy = 1 ORDER BY date_obs DESC", ["no_user_account" => $no_user_account]);
}

// the first day of the cycle a date is in, null before the first cycle
function db_select_cycle($db, string $date, $no_user_account): ?string {
	return db_value($db, "SELECT date_obs FROM day_timeline WHERE cycle_1st_day = 1 AND date_obs <= :date AND no_user_account = :no_user_account ORDER BY date_obs DESC LIMIT 1",
		["date" => $date, "no_user_account" => $no_user_account]) ?: null;
}

// ---------------------------------------------------------------------------
// The cron
// ---------------------------------------------------------------------------

// the accounts whose cycle began two days ago (so the one before is finished) and that receive their cycles (auto_mail_export)
function db_select_cycles_finished($db): array {
	return db_rows($db,
		"SELECT SUBDATE(obs.date_obs, 1) AS cycle_complet, obs.no_user_account, c.name, c.nfp_method, c.login_email, c.notification_emails
		FROM day_timeline AS obs JOIN user_account AS c ON obs.no_user_account = c.no_user_account
		WHERE obs.date_obs = CURDATE() - INTERVAL 2 DAY AND (obs.cycle_1st_day = 1 OR obs.pregnancy = 1) AND c.auto_mail_export = 1"
	);
}

// the accounts with no day written for 35 days, registered for more than that, not yet reminded
function db_select_user_account_inactive($db): array {
	return db_rows($db,
		"SELECT c.no_user_account, c.name, MAX(o.last_write_db) AS last_day_written, c.login_email, c.notification_emails, c.inscription_date
		FROM user_account AS c LEFT JOIN day_timeline AS o ON c.no_user_account = o.no_user_account
		WHERE c.no_user_account NOT IN (" . ACCOUNT_DEMO_IDS_SQL . ") AND c.is_inactive = 0 AND c.email_verified_at IS NOT NULL
		GROUP BY c.no_user_account, c.name, c.login_email, c.notification_emails, c.inscription_date
		HAVING (DATE(last_day_written) < DATE(NOW()) - INTERVAL 35 DAY OR last_day_written IS NULL) AND c.inscription_date < DATE(NOW()) - INTERVAL 35 DAY
		ORDER BY last_day_written DESC LIMIT 20"
	);
}

// An account's last sign of life: the latest of its registration, its last login, a write of a day or a label, a use of a token. Each
// table is reduced to one MAX() per account in a derived table, joined once, rather than a subquery per account. Without the demo
// accounts. The retention is re-evaluated on every run, so no "warned" flag exists: any activity takes the account out of both lists.
const DB_SQL_USER_ACCOUNT_LAST_ACTIVITY =
	"SELECT u.no_user_account, u.name, u.login_email, u.notification_emails,
	GREATEST(u.inscription_date, COALESCE(u.last_auth_date, u.inscription_date), COALESCE(dt.last_activity, u.inscription_date),
		COALESCE(de.last_activity, u.inscription_date), COALESCE(at.last_activity, u.inscription_date)) AS last_activity
	FROM user_account u
	LEFT JOIN (SELECT no_user_account, MAX(last_write_db) AS last_activity FROM day_timeline GROUP BY no_user_account) dt ON dt.no_user_account = u.no_user_account
	LEFT JOIN (SELECT no_user_account, MAX(last_write_db) AS last_activity FROM description GROUP BY no_user_account) de ON de.no_user_account = u.no_user_account
	LEFT JOIN (SELECT no_user_account, MAX(date_use) AS last_activity FROM auth_token GROUP BY no_user_account) at ON at.no_user_account = u.no_user_account
	WHERE u.no_user_account NOT IN (" . ACCOUNT_DEMO_IDS_SQL . ")";

// the accounts that will be erased in $warning_days_before days: the ones to warn today
function db_select_user_account_to_warn_before_deletion($db, int $years, int $warning_days_before): array {
	return db_rows($db,
		"SELECT * FROM (" . DB_SQL_USER_ACCOUNT_LAST_ACTIVITY . ") AS activity
		WHERE last_activity < NOW() - INTERVAL :years1 YEAR + INTERVAL :warning_days1 DAY
		AND last_activity >= NOW() - INTERVAL :years2 YEAR + INTERVAL :warning_days2 DAY - INTERVAL 1 DAY",
		["years1" => $years, "years2" => $years, "warning_days1" => $warning_days_before, "warning_days2" => $warning_days_before]
	);
}

function db_select_user_account_to_delete($db, int $years): array {
	return db_rows($db, "SELECT * FROM (" . DB_SQL_USER_ACCOUNT_LAST_ACTIVITY . ") AS activity WHERE last_activity < NOW() - INTERVAL :years YEAR", ["years" => $years]);
}

// ---------------------------------------------------------------------------
// Statistics (most leave the demo accounts out), and the counters of key_value
// ---------------------------------------------------------------------------

function db_count_user_accounts($db): int {
	return intval(db_value($db, "SELECT COUNT(no_user_account) FROM user_account WHERE email_verified_at IS NOT NULL"));
}

// accounts with a day in the last 35 days
function db_count_user_accounts_active($db): int {
	return intval(db_value($db, "SELECT COUNT(DISTINCT no_user_account) FROM day_timeline WHERE date_obs >= DATE(NOW()) - INTERVAL 35 DAY"));
}

function db_count_user_accounts_active_by_method($db, int $nfp_method): int {
	return intval(db_value($db,
		"SELECT COUNT(DISTINCT obs.no_user_account) FROM day_timeline AS obs JOIN user_account AS com ON obs.no_user_account = com.no_user_account
		WHERE obs.date_obs >= DATE(NOW()) - INTERVAL 35 DAY AND com.nfp_method = :nfp_method",
		["nfp_method" => $nfp_method]
	));
}

// accounts registered in the last 15 days that have logged in
function db_count_user_accounts_recent($db): int {
	return intval(db_value($db, "SELECT COUNT(no_user_account) FROM user_account WHERE inscription_date >= DATE(NOW()) - INTERVAL 15 DAY AND last_auth_date IS NOT NULL"));
}

function db_count_user_accounts_with_totp($db): int {
	return intval(db_value($db, "SELECT COUNT(no_user_account) FROM user_account WHERE totp_state = " . TOTP_STATE_ACTIVE . " AND no_user_account NOT IN (" . ACCOUNT_DEMO_IDS_SQL . ")"));
}

function db_count_auth_tokens($db): int {
	return intval(db_value($db, "SELECT COUNT(no_auth_token) FROM auth_token"));
}

function db_count_cycles($db): int {
	return intval(db_value($db, "SELECT COUNT(no_day) FROM day_timeline WHERE cycle_1st_day = 1 AND no_user_account NOT IN (" . ACCOUNT_DEMO_IDS_SQL . ")"));
}

function db_count_cycles_recent($db): int {
	return intval(db_value($db, "SELECT COUNT(no_day) FROM day_timeline WHERE cycle_1st_day = 1 AND date_obs >= DATE(NOW()) - INTERVAL 30 DAY AND no_user_account NOT IN (" . ACCOUNT_DEMO_IDS_SQL . ")"));
}

// The average age: the DB holds a birth year, and 2.5 is half the 5 years of a band of the sign-up form. Null without any account.
function db_select_average_age($db, bool $recent = false): ?float {
	$recent_only = $recent ? " WHERE inscription_date >= DATE(NOW()) - INTERVAL 15 DAY AND last_auth_date IS NOT NULL AND no_user_account NOT IN (" . ACCOUNT_DEMO_IDS_SQL . ")" : "";
	$age = db_value($db, "SELECT YEAR(NOW()) - AVG(age) + 2.5 FROM user_account" . $recent_only);
	return is_null($age) ? null : floatval($age);
}

function db_count_days($db): int {
	return intval(db_value($db, "SELECT COUNT(no_day) FROM day_timeline WHERE no_user_account NOT IN (" . ACCOUNT_DEMO_IDS_SQL . ")"));
}

// How many days each account holds, the biggest first (script/db_perf.php).
function db_select_day_counts_by_account($db): array {
	return db_rows($db, "SELECT dt.no_user_account, COUNT(dt.no_day) AS nb_days FROM day_timeline AS dt GROUP BY dt.no_user_account ORDER BY nb_days DESC, dt.no_user_account");
}

function db_count_days_today($db): int {
	return intval(db_value($db, "SELECT COUNT(no_day) FROM day_timeline WHERE date_obs = CURDATE() AND no_user_account NOT IN (" . ACCOUNT_DEMO_IDS_SQL . ")"));
}

function db_count_days_since($db, int $nb_days): int {
	return intval(db_value($db, "SELECT COUNT(no_day) FROM day_timeline WHERE date_obs >= DATE(NOW()) - INTERVAL :nb_days DAY AND no_user_account NOT IN (" . ACCOUNT_DEMO_IDS_SQL . ")", ["nb_days" => $nb_days]));
}

// false when the key has no row
function db_select_key_value($db, string $key): mixed {
	return db_value($db, "SELECT `value` FROM key_value WHERE `key` = :key", ["key" => $key]);
}

function db_upsert_key_value($db, string $key, int $value): int {
	return db_exec($db, "INSERT INTO key_value (`key`, `value`) VALUES (:key, :value) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)", ["key" => $key, "value" => $value]);
}

function db_update_increment_key_value($db, string $key): int {
	return db_exec($db, "UPDATE key_value SET `value` = `value` + 1 WHERE `key` = :key", ["key" => $key]);
}

function db_update_reset_key_value($db, string $key): int {
	return db_exec($db, "UPDATE key_value SET `value` = 0 WHERE `key` = :key", ["key" => $key]);
}
