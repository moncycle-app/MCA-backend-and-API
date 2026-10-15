<?php
/* moncycle.app
**
** licence Creative Commons CC BY-NC-SA
**
** https://www.moncycle.app
** https://github.com/moncycle-app/backend-api-web-app
*/

// What the server says of itself (GET /api/server_info) and the check that the database is the one this code is written for.
//
// Three versions, three places: the contract (API_VERSION) and the schema this code expects (SCHEMA_VERSION) are constants; the release,
// the build date and the commit are written at build time in lib/build.json (build.sh) and are not in the database; the version the
// database is at is the row `schema_version` of key_value, set by schema.sql and by each migration. Only API_VERSION is told to a client.

require_once __DIR__ . "/sec_captcha.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/http.php";

// The build: ["version", "build", "commit"]. The build date and the commit are null when the build did not set them (a development
// tree has placeholders in lib/build.json).
function server_build(): array {
	$file = json_decode((string) @file_get_contents(__DIR__ . "/build.json"), true);
	$file = is_array($file) ? $file : [];
	$version = $file["version"] ?? null;
	$build = $file["build"] ?? null;
	$commit = $file["commit"] ?? null;
	return [
		"version" => is_string($version) && $version !== "" ? $version : "unknown",
		"build" => is_string($build) && preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $build) ? $build : null,
		"commit" => is_string($commit) && preg_match('/\A[0-9a-f]{7,40}\z/', $commit) ? $commit : null,
	];
}

// The version the database says it is at, null when it says none.
function server_schema_version($db): ?int {
	$value = db_select_key_value($db, SCHEMA_VERSION_KEY);
	return $value === false || is_null($value) ? null : intval($value);
}

// Ends the request with a 503 when the database is not at the version of this code: a missing row, a number that is not SCHEMA_VERSION.
// Run after the maintenance exit (migrations are run in maintenance) by every endpoint that opens the database.
function server_exit_if_schema_mismatch($db): void {
	$version = server_schema_version($db);
	if ($version === SCHEMA_VERSION) return;
	header('Content-Type: application/json');
	http_error(503, "schema_mismatch", "The database is not at the version this server needs (it has " . ($version ?? "none") . ", the server needs " . SCHEMA_VERSION . "). Run the migrations, then start the server again.");
}

// The numbers a client needs to check a form before it sends it. Nothing secret.
function server_limits(): array {
	return [
		"passwordMinBytes" => PASSWORD_MIN_LENGTH,
		"passwordMaxBytes" => PASSWORD_MAX_BYTES,
		"accountNicknameMaxChars" => ACCOUNT_LIMIT_NICKNAME_CHARS,
		"registerCommentMaxChars" => ACCOUNT_LIMIT_REGISTER_COMMENT_CHARS,
		"notificationEmailsMax" => ACCOUNT_NOTIFICATION_EMAILS_MAX,
		"birthYearMaxAge" => ACCOUNT_BIRTH_YEAR_MAX_AGE,
		"tokenNameMaxChars" => AUTH_TOKEN_LIMIT_NAME_CHARS,
		"tokenExpiresInDaysMin" => AUTH_TOKEN_EXPIRES_DAYS_MIN,
		"tokenExpiresInDaysMax" => AUTH_TOKEN_EXPIRES_DAYS_MAX,
		"dayCommentMaxChars" => DAY_LIMIT_COMMENT_CHARS,
		"descriptionNameMaxChars" => DAY_LIMIT_DESCRIPTION_CHARS,
		"counterStartMax" => DAY_LIMIT_COUNTER_START,
		"temperatureMin" => DAY_TEMPERATURE_STORABLE_MIN,
		"temperatureMax" => DAY_TEMPERATURE_STORABLE_MAX,
		"queryDaysMax" => DAY_LIMIT_QUERY_DAYS,
		"requestBodyMaxBytes" => NFP_LIMIT_BODY_BYTES,
	];
}

// The data of GET /api/server_info: what a client needs before it signs in. It is made from the settings alone, the database is not opened
// (so it answers in maintenance too), and neither the release nor the schema is told: the version of the contract is `apiVersion`.
function server_info(): array {
	return [
		"app" => APP_NAME,
		"apiVersion" => API_VERSION,
		"maintenanceMode" => MAINTENANCE_MODE,
		"registrationEnabled" => REGISTRATION_ENABLED,
		"demoEnabled" => DEMO_ENABLED,
		"captchaAvailable" => sec_captcha_ready(),
		"newsUrl" => str_starts_with(NEWS_URL, "https://") ? NEWS_URL : null,
		"termsOfServiceUrl" => str_starts_with(TERMS_OF_SERVICE_URL, "https://") ? TERMS_OF_SERVICE_URL : null,
		"limits" => server_limits(),
	];
}
