<?php
/* moncycle.app
**
** licence Creative Commons CC BY-NC-SA
**
** https://www.moncycle.app
** https://github.com/moncycle-app/backend-api-web-app
*/

declare(strict_types=1);

// The app's own log: what happened, to which account and row, from where -- never the content ("account 21 wrote day 4412", not what the
// day holds; what is never logged is in docs/logs.md). One line per event of LOG_EVENTS: flat JSON or "key=value" text, to stdout, stderr
// or a file (LOG_OUTPUT). Logging never breaks a request: nothing here throws, answers or queries the database.
//
// log_start() starts a web request and writes its `http.request` line at the end. log_context() holds what every line of the request
// shares (the account, the session, the ids of the rows it handles), log_note() what only its own line carries (the error code, the
// number of rows read). An id is logged only when the code already holds it, and a read says how many rows, never which: no query.

// The state of the request or run, by reference so that the shutdown functions share it.
function &log_state(): array {
	static $state = [
		"rid" => null, "ip" => null, "started" => null, "context" => [], "note" => [],
		"settings" => null, "sink" => null, "handle" => null, "why" => "", "cron" => null,
	];
	return $state;
}

// The LOG_* settings, each checked: a missing or wrong one is the default, never "off".
function log_settings(): array {
	$state = &log_state();
	if ($state["settings"] !== null) return $state["settings"];

	$read = fn(string $name) => defined($name) ? constant($name) : LOG_DEFAULTS[$name];
	$choice = fn(string $name, array $allowed) => in_array($read($name), $allowed, true) ? $read($name) : LOG_DEFAULTS[$name];
	$text = fn(string $name) => is_string($read($name)) ? trim($read($name)) : LOG_DEFAULTS[$name];
	$categories = $read("LOG_CATEGORIES");

	return $state["settings"] = [
		"level" => $choice("LOG_LEVEL", LOG_LEVEL_VALUES),
		"categories" => is_array($categories) ? array_values(array_intersect($categories, LOG_CATEGORY_VALUES)) : [],
		"output" => $text("LOG_OUTPUT"),
		"format" => $choice("LOG_FORMAT", LOG_FORMAT_VALUES),
		"ip" => $choice("LOG_IP", LOG_IP_VALUES),
		"header" => $text("LOG_REQUEST_ID_HEADER"),
	];
}

// ---------------------------------------------------------------------------
// A web request
// ---------------------------------------------------------------------------

// First thing in a web request: its id (sent back as X-Request-Id) and its `http.request` line at the end. $client_ip is sec_client_ip(),
// passed in so that this file knows nothing of sec.php.
function log_start(string $client_ip = ""): void {
	try {
		$state = &log_state();
		if ($state["started"] !== null) return;
		$state["started"] = microtime(true);
		$state["ip"] = log_ip($client_ip);
		if (!headers_sent()) header("X-Request-Id: " . log_rid());
		register_shutdown_function('log_request_end');
	} catch (\Throwable $e) {
	}
}

// "/api/v2/day" for /api/v2/day/2026-03-10 and /api/v2/day?dates=...: the arguments of the URL and the query string are content, never logged.
function log_endpoint(): ?string {
	$script = $_SERVER["SCRIPT_NAME"] ?? "";
	if (is_string($script) && $script !== "") return preg_replace('/\.php\z/', '', $script);
	$path = parse_url($_SERVER["REQUEST_URI"] ?? "", PHP_URL_PATH);
	return is_string($path) ? $path : null;
}

// method, endpoint, status, duration, and what the endpoint noted
function log_request_end(): void {
	try {
		$state = &log_state();
		$endpoint = log_endpoint();
		$fields = [
			"m" => log_scrub((string) ($_SERVER["REQUEST_METHOD"] ?? "")),
			"p" => is_string($endpoint) ? log_scrub($endpoint) : null,
			"st" => http_response_code() ?: null,
			"ms" => (int) round((microtime(true) - $state["started"]) * 1000),
		];
		log_event("http.request", $fields + array_merge(["err" => null, "n" => null, "full" => null], $state["note"]));
	} catch (\Throwable $e) {
	}
}

// What every line of the request carries: "uid" (the account), "sid" (the session, never the token), and the ids of the rows it handles
// ("day", "dsc", "rev"), set as soon as the code knows them, so that a `system.exception` names them too. An event's own value wins; null
// removes the key.
function log_context(array $fields): void {
	$state = &log_state();
	foreach ($fields as $key => $value) {
		if ($value === null) unset($state["context"][$key]);
		else $state["context"][$key] = $value;
	}
}

// Fields of the request's own `http.request` line only (err, n, full).
function log_note(array $fields): void {
	$state = &log_state();
	$state["note"] = array_merge($state["note"], $fields);
}

// The id the client or the proxy sent (LOG_REQUEST_ID_HEADER) when it is plain (it only serves correlation), else a new one.
function log_rid(): string {
	$state = &log_state();
	if ($state["rid"] !== null) return $state["rid"];

	$header = log_settings()["header"];
	$sent = PHP_SAPI !== "cli" && $header !== "" ? ($_SERVER["HTTP_" . strtoupper(str_replace("-", "_", $header))] ?? "") : "";
	if (is_string($sent) && preg_match(LOG_REQUEST_ID_PATTERN, $sent)) return $state["rid"] = $sent;

	try {
		return $state["rid"] = bin2hex(random_bytes(6));
	} catch (\Throwable $e) {
		return $state["rid"] = substr(sha1(uniqid("", true)), 0, 12);
	}
}

// The address as LOG_IP allows it: whole, cut (a.b.c.0, the first 3 groups of IPv6), or none.
function log_ip(string $ip): ?string {
	$mode = log_settings()["ip"];
	if ($ip === "" || $mode === "none") return null;
	if ($mode === "full") return mb_substr($ip, 0, 45);

	$packed = @inet_pton($ip);
	if ($packed === false) return null;
	if (strlen($packed) === 16 && str_starts_with($packed, str_repeat("\0", 10) . "\xff\xff")) $packed = substr($packed, 12); // ::ffff:a.b.c.d
	if (strlen($packed) === 4) return implode(".", array_map('ord', str_split(substr($packed, 0, 3)))) . ".0";

	$groups = str_split(bin2hex(substr($packed, 0, 6)), 4);
	return implode(":", array_map(fn($group) => ltrim($group, "0") ?: "0", $groups)) . "::";
}

// ---------------------------------------------------------------------------
// Writing an event
// ---------------------------------------------------------------------------

// An event of LOG_EVENTS with its own keys (the glossary of docs/logs.md). $level overrides the event's, for one whose outcome decides it.
function log_event(string $event, array $fields = [], ?string $level = null): void {
	try {
		$level ??= LOG_EVENTS[$event] ?? "info";
		$settings = log_settings();
		$rank = array_search($level, LOG_LEVEL_VALUES, true);
		if ($rank === false || $rank === 0 || $rank > array_search($settings["level"], LOG_LEVEL_VALUES, true)) return;
		if ($settings["categories"] && !in_array(strstr($event, ".", true), $settings["categories"], true)) return;

		$state = &log_state();
		$uid = $fields["uid"] ?? $state["context"]["uid"] ?? null;
		$sid = $fields["sid"] ?? $state["context"]["sid"] ?? null;
		$common = [
			"ts" => log_timestamp(), "lv" => $level, "ev" => $event, "rid" => log_rid(),
			"src" => PHP_SAPI === "cli" ? "cli" : null,
			"uid" => is_numeric($uid) ? intval($uid) : null,
			"sid" => is_numeric($sid) ? intval($sid) : null,
			"ip" => $state["ip"],
		];
		unset($fields["uid"], $fields["sid"]);
		$objects = array_diff_key($state["context"], ["uid" => 0, "sid" => 0]);
		log_write(log_line($common, $objects ? array_replace($objects, $fields) : $fields));
	} catch (\Throwable $e) {
	}
}

// The class, where it was thrown, the message scrubbed. A PDOException gives its SQLSTATE and no message, which can hold the SQL or a value.
function log_exception(\Throwable $e): void {
	try {
		$sql = $e instanceof PDOException ? (string) ($e->errorInfo[0] ?? $e->getCode()) : null;
		log_event("system.exception", [
			"cls" => get_class($e),
			"sql" => $sql,
			"at" => str_replace(dirname(__DIR__) . "/", "", $e->getFile()) . ":" . $e->getLine(),
			"msg" => is_null($sql) ? log_scrub($e->getMessage()) : null,
		]);
	} catch (\Throwable $e) {
	}
}

// A text the code did not write (an exception message, PHPMailer's ErrorInfo) made fit for a line: addresses masked, controls gone, clipped.
function log_scrub(string $text): string {
	$text = preg_replace('/[\x00-\x1F\x7F]+/', ' ', mb_substr(mb_scrub($text), 0, 4 * LOG_LIMIT_FIELD_CHARS)) ?? "";
	$text = preg_replace('/[^\s@\'"<>(),;:\[\]]+@[^\s@\'"<>(),;:\[\]]+/u', '[email]', $text) ?? "";
	return mb_substr($text, 0, LOG_LIMIT_FIELD_CHARS);
}

// UTC, ISO-8601, milliseconds
function log_timestamp(): string {
	$now = microtime(true);
	return gmdate("Y-m-d\TH:i:s", (int) $now) . sprintf(".%03dZ", (int) (($now - floor($now)) * 1000));
}

// A string clipped, a list cut to its first items, a number or a boolean as is; anything else is null.
function log_value(mixed $value, bool $in_list = false): mixed {
	if (is_string($value)) return mb_substr($value, 0, LOG_LIMIT_FIELD_CHARS);
	if (is_int($value) || is_float($value) || is_bool($value)) return $value;
	if (!is_array($value) || $in_list) return null;
	$items = array_map(fn($item) => log_value($item, true), array_slice($value, 0, LOG_LIMIT_LIST_ITEMS));
	return array_values(array_filter($items, fn($item) => $item !== null));
}

// the values of log_value(), without the nulls
function log_clean(array $fields): array {
	$clean = [];
	foreach ($fields as $key => $value) {
		$value = log_value($value);
		if ($value !== null) $clean[$key] = $value;
	}
	return $clean;
}

// The common keys, then the event's; too long for one atomic write, it loses its last keys and says "cut".
function log_line(array $common, array $fields): string {
	$common = log_clean($common);
	$fields = log_clean($fields);
	$line = log_render($common + $fields);
	while (strlen($line) >= LOG_LIMIT_LINE_BYTES && $fields) {
		array_pop($fields);
		$line = log_render($common + $fields + ["cut" => true]);
	}
	return $line;
}

// No newline in either format: JSON escapes it, and a text value with a control character is quoted as JSON.
function log_render(array $pairs): string {
	if (log_settings()["format"] !== "text") return (string) json_encode($pairs, LOG_JSON_FLAGS);

	$parts = array_values(array_slice($pairs, 0, 3));     // ts, lv, ev
	foreach (array_slice($pairs, 3) as $key => $value) {
		if (is_bool($value)) $value = $value ? "true" : "false";
		elseif (is_array($value)) $value = json_encode($value, LOG_JSON_FLAGS);
		elseif (is_string($value) && ($value === "" || preg_match('/[\s"\x00-\x1F\x7F]|\xE2\x80[\xA8\xA9]|\xC2\x85/', $value))) $value = json_encode($value, LOG_JSON_FLAGS);
		$parts[] = $key . "=" . $value;
	}
	return implode(" ", $parts);
}

// ---------------------------------------------------------------------------
// The sink: stdout, stderr or a file
// ---------------------------------------------------------------------------

// [kind, path]: stdout, stderr, or a file for a value that starts with "/"; anything else is stdout, so a typo never turns the log off.
function log_sink(): array {
	$output = log_settings()["output"];
	if (strtolower($output) === "stderr") return ["stderr", ""];
	if (str_starts_with($output, "/")) return ["file", $output];
	return ["stdout", ""];
}

// Opened once per request (a rename-based logrotate needs no signal). A file is appended to, never locked: O_APPEND and a line under
// LOG_LIMIT_LINE_BYTES keep lines whole between workers. False when it cannot be opened, the reason in the state.
function log_open(array $sink) {
	[$kind, $path] = $sink;
	error_clear_last();
	$handle = @fopen(match ($kind) { "stderr" => "php://stderr", "stdout" => "php://stdout", default => $path }, $kind === "file" ? "ab" : "wb");
	if ($handle !== false) return $handle;

	$state = &log_state();
	$state["why"] = log_open_failure($path, error_get_last()["message"] ?? "");
	return false;
}

// Why, in a word. PHP 8.4 words an open_basedir refusal "Operation not permitted": that one is told from the setting.
function log_open_failure(string $path, string $error): string {
	$allowed = array_filter(explode(PATH_SEPARATOR, (string) ini_get("open_basedir")));
	if ($allowed && !array_filter($allowed, fn($dir) => $path === rtrim($dir, "/") || str_starts_with($path, rtrim($dir, "/") . "/"))) return "open_basedir";

	return match (true) {
		str_contains($error, "Permission denied") => "permission_denied",
		str_contains($error, "Read-only file system") => "read_only",
		str_contains($error, "No such file") => "missing_directory",
		str_contains($error, "Is a directory") => "is_directory",
		str_contains($error, "Operation not permitted") => "not_permitted",
		default => "cannot_open",
	};
}

// One line in one fwrite. A file that cannot be opened or written does not lose it: the rest goes to stdout, and
// `system.log_sink_failed` says so once.
function log_write(string $line): void {
	if ($line === "") return;
	$state = &log_state();
	$line .= "\n";

	for ($attempt = 0; $attempt < 2; $attempt++) {
		$state["sink"] ??= log_sink();
		$state["handle"] ??= log_open($state["sink"]);
		if ($state["handle"] && @fwrite($state["handle"], $line) === strlen($line)) return;
		if ($state["sink"][0] !== "file") return;     // stdout or stderr unwritable: nowhere else to go

		$file = $state["sink"][1];
		$why = $state["handle"] ? "write_failed" : $state["why"];
		if ($state["handle"]) @fclose($state["handle"]);
		$state["sink"] = ["stdout", ""];
		$state["handle"] = null;
		log_event("system.log_sink_failed", ["f" => $file, "why" => $why]);
	}
}

// ---------------------------------------------------------------------------
// script/cron.php
// ---------------------------------------------------------------------------

// First thing in a cron run: one id for the run, `system.cron_started`, and `system.cron_ended` with ok:false if the run dies before
// log_cron_end(). A dry run says so (`dry`) on both lines.
function log_cron_start(bool $dry_run = false): void {
	$state = &log_state();
	$state["cron"] = ["started" => microtime(true), "done" => false, "dry" => $dry_run ?: null, "count" => array_fill_keys(["sent", "ko", "del", "tok", "ipa"], 0)];
	register_shutdown_function('log_cron_shutdown');
	log_event("system.cron_started", ["dry" => $state["cron"]["dry"]]);
}

// sent and ko (mails), del (accounts deleted), tok (tokens purged), ipa (login attempts purged)
function log_cron_count(string $counter, int $by = 1): void {
	$state = &log_state();
	if (isset($state["cron"]["count"][$counter])) $state["cron"]["count"][$counter] += $by;
}

// A step done, even with nothing to do, so that a run that died tells how far it got. $handled: what it took care of, $failed: the mails
// among them not sent. The line belongs to no account.
function log_cron_step(string $step, ?int $handled = null, ?int $failed = null): void {
	$state = &log_state();
	log_context(["uid" => null]);
	log_event("system.cron_step", ["step" => $step, "dry" => $state["cron"]["dry"] ?? null, "n" => $handled, "ko" => $failed]);
}

// The run reached its end.
function log_cron_end(): void {
	log_cron_finish(true, null);
}

function log_cron_shutdown(): void {
	$error = error_get_last();
	$fatal = $error !== null && in_array($error["type"], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR], true);
	log_cron_finish(false, $fatal ? log_fatal_message($error["message"]) : null);
}

// `system.cron_ended`, once, with the counters as they are.
function log_cron_finish(bool $ok, ?string $message): void {
	$state = &log_state();
	if (($state["cron"]["done"] ?? true) === true) return;
	$state["cron"]["done"] = true;
	$fields = ["ok" => $ok, "dry" => $state["cron"]["dry"], "ms" => (int) round((microtime(true) - $state["cron"]["started"]) * 1000)] + $state["cron"]["count"] + ["msg" => $message];
	log_event("system.cron_ended", $fields, $ok ? null : "error");
}

// The first line of a fatal error, scrubbed; of an uncaught PDOException only the SQLSTATE.
function log_fatal_message(string $message): string {
	if (preg_match('/^Uncaught PDOException: SQLSTATE\[(\w+)\]/', $message, $found)) return "Uncaught PDOException SQLSTATE[" . $found[1] . "]";
	return log_scrub(strtok($message, "\n") ?: "");
}
