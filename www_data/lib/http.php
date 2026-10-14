<?php
/* moncycle.app
**
** licence Creative Commons CC BY-NC-SA
**
** https://www.moncycle.app
** https://github.com/moncycle-app/backend-api-web-app
*/

require_once __DIR__ . "/log.php";

// Whether the request says its body is JSON: "application/json" in any case, parameters
// ("; charset=utf-8") allowed. Anything else, a form or "text/plain", is what a page of another site
// can send without asking the browser's leave: the writes of this API do not take it.
function http_content_type_is_json(): bool {
	$type = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
	return is_string($type) && strtolower(trim(explode(';', $type, 2)[0])) === 'application/json';
}

// Sends a 415 and exits unless the body is declared JSON. The endpoints that read the body
// themselves (api/import.php) call it; http_json_body() does.
function http_require_json_content_type(): void {
	if (!http_content_type_is_json()) http_error(415, "unsupported_media_type", "The body must be sent as 'Content-Type: application/json'.");
}

// reads and JSON-decodes the raw request body. Sends a 415 if it is not declared JSON and a 400
// error and exits if it's missing, empty, or not a JSON object -- endpoints that take no body (GET,
// DELETE-by-query-string) don't call this.
function http_json_body(): array {
	http_require_json_content_type();
	$raw = file_get_contents('php://input');
	$body = json_decode($raw, true);
	if (!is_array($body) || (!empty($body) && array_is_list($body))) http_error(400, "invalid_json", "Request body must be a valid JSON object.");
	return $body;
}

// Ends the request with a 503 "maintenance" when MAINTENANCE_MODE is on, whoever calls and whatever the method:
// every endpoint calls it first, before it opens the database (api_start() does for the JSON ones), so nothing
// is read or written. The health check is the one that does not, or a container in maintenance would be
// restarted as dead. The answer is the JSON envelope even for the endpoints that answer a file, an image or a redirect.
function http_exit_if_maintenance(): void {
	if (!MAINTENANCE_MODE) return;
	header('Content-Type: application/json');
	http_error(503, "maintenance", "The server is under maintenance. Try again later.");
}

function http_respond(int $status, array $body): never {
	http_response_code($status);
	echo json_encode($body);
	exit;
}

// $meta is what the answer says about itself and that is not part of the resource (POST /api/account: what changed). Only sent when it holds something.
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

// 405 with the Allow header: the endpoint does not take this method. HEAD is read as GET by the endpoints, so it is not listed.
function http_exit_method_not_allowed(array $allowed): never {
	header("Allow: " . implode(", ", $allowed));
	http_error(405, "method_not_allowed", "Method " . ($_SERVER['REQUEST_METHOD'] ?? "") . " is not supported here. Supported: " . implode(", ", $allowed) . ".");
}

// 404 for a route that does not exist: an unknown path after the endpoint's name, or a removed endpoint.
function http_exit_not_found(string $message = "This URL does not exist."): never {
	http_error(404, "not_found", $message);
}

// The request method, with HEAD read as GET (the body is not sent for it by the web server).
function http_method(): string {
	$method = $_SERVER['REQUEST_METHOD'] ?? "GET";
	return $method === "HEAD" ? "GET" : $method;
}

// Whether the request carries a body (GET and DELETE must not).
function http_body_present(): bool {
	return file_get_contents('php://input') !== "";
}

// The Location header of a 201: the address of the new thing, from the root of the API ("description/12" -> "/api/description/12").
function http_location(string $path): void {
	header("Location: " . rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? "/api/x"), "/") . "/" . ltrim($path, "/"));
}

// this codebase stores UTC timestamps as bare "YYYY-MM-DD HH:MM:SS" throughout; http_iso8601() is the shape
// the JSON API shows them in, and date_parse_rfc3339() (lib/date.php) reads what a client sends back.
function http_iso8601(?string $utc_timestamp): ?string {
	if (empty($utc_timestamp)) return null;
	return str_replace(' ', 'T', trim($utc_timestamp)) . 'Z';
}

// The client's own timestamp of a write ("lastWriteClientUtc"), already read by input_timestamp() as a UTC "Y-m-d H:i:s" (null when the client
// sent none), or the server's clock when it is absent or outside what the column can store: a client whose clock is wrong must still be able to write.
function http_client_timestamp(?string $utc): string {
	$storable = $utc && $utc >= TIMESTAMP_STORABLE_MIN && $utc <= TIMESTAMP_STORABLE_MAX;
	return $storable ? $utc : date('Y-m-d H:i:s');
}
