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

// The request's body and method, and the JSON envelope of every answer: {"data", "meta"?} or {"error": {"code", "message", "details"?}}.

require_once __DIR__ . "/log.php";

// A 415 unless the body is declared "application/json" (any case, parameters allowed): a form or "text/plain" is what a page of another
// site may send without asking the browser, and no write of this API takes it.
function http_require_json_content_type(): void {
	$type = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
	if (is_string($type) && strtolower(trim(explode(';', $type, 2)[0])) === 'application/json') return;
	http_error(415, "unsupported_media_type", "The body must be sent as 'Content-Type: application/json'.");
}

// The two refusals every request gets before anything is read, whatever the endpoint: 413 file_too_large when the declared Content-Length is
// over REQUEST_BODY_MAX_BYTES (a body sent in chunks has none: the endpoint that reads it checks its size), and 415 for a body PHP's own
// form parsers would take (multipart/*, application/x-www-form-urlencoded: no endpoint reads one, and the multipart parser is a known
// way to exhaust memory, CVE-2026-109618). The Docker image's Apache says the same two things before PHP starts (zz-moncycleapp.conf);
// this is the same rule for a server that has no such wall.
function http_exit_if_body_refused(): void {
	$length = $_SERVER['CONTENT_LENGTH'] ?? '';
	if (is_string($length) && ctype_digit($length) && intval($length) > REQUEST_BODY_MAX_BYTES) {
		http_error(413, "file_too_large", sprintf("The request body is over the limit of %d bytes. To import a file, split it into fewer cycles.", REQUEST_BODY_MAX_BYTES));
	}
	$type = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
	if (is_string($type) && preg_match('#^\s*(multipart/|application/x-www-form-urlencoded)#i', $type) === 1) {
		http_error(415, "unsupported_media_type", "The body must be sent as 'Content-Type: application/json'.");
	}
}

// The body as a JSON object: a 415 when it is not declared JSON, a 400 when it is not an object.
function http_json_body(): array {
	http_require_json_content_type();
	$body = json_decode(file_get_contents('php://input'), true);
	if (!is_array($body) || (!empty($body) && array_is_list($body))) http_error(400, "invalid_json", "Request body must be a valid JSON object.");
	return $body;
}

function http_body_present(): bool {
	return file_get_contents('php://input') !== "";
}

// HEAD is read as GET (the web server sends no body for it).
function http_method(): string {
	$method = $_SERVER['REQUEST_METHOD'] ?? "GET";
	return $method === "HEAD" ? "GET" : $method;
}

// A 503 in MAINTENANCE_MODE, before the database is opened, whatever the endpoint answers otherwise (a file, an image, a redirect).
// The health check does not call it, or a container in maintenance would be restarted as dead.
function http_exit_if_maintenance(): void {
	if (!MAINTENANCE_MODE) return;
	header('Content-Type: application/json');
	http_error(503, "maintenance", "The server is under maintenance. Try again later.");
}

function http_respond(int $status, array $body): never {
	http_response_code($status);
	echo json_encode($body, API_JSON_FLAGS);
	exit;
}

// $meta: what the answer says about itself that is not the resource (POST /api/v2/account: what changed). Only sent when it holds something.
function http_data(int $status, array $data, array $meta = []): never {
	http_respond($status, empty($meta) ? ["data" => $data] : ["data" => $data, "meta" => $meta]);
}

function http_no_content(): never {
	http_response_code(204);
	exit;
}

function http_error(int $status, string $code, string $message, array $details = []): never {
	$error = ["code" => $code, "message" => $message];
	if (!empty($details)) $error["details"] = $details;
	log_note(["err" => $code]);
	http_respond($status, ["error" => $error]);
}

// HEAD is read as GET by the endpoints, so it is not listed.
function http_exit_method_not_allowed(array $allowed): never {
	header("Allow: " . implode(", ", $allowed));
	http_error(405, "method_not_allowed", "Method " . ($_SERVER['REQUEST_METHOD'] ?? "") . " is not supported here. Supported: " . implode(", ", $allowed) . ".");
}

function http_exit_not_found(string $message = "This URL does not exist."): never {
	http_error(404, "not_found", $message);
}

// The Location of a 201, from the root of the API ("description/12" -> "/api/v2/description/12").
function http_location(string $path): void {
	header("Location: " . rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? "/api/v2/x"), "/") . "/" . ltrim($path, "/"));
}

// The app stores UTC as "YYYY-MM-DD HH:MM:SS"; the API shows it as RFC 3339.
function http_iso8601(?string $utc_timestamp): ?string {
	if (empty($utc_timestamp)) return null;
	return str_replace(' ', 'T', trim($utc_timestamp)) . 'Z';
}
