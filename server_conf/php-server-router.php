<?php
/* moncycle.app
**
** licence Creative Commons CC BY-NC-SA
**
** https://www.moncycle.app
** https://github.com/moncycle-app/backend-api-web-app
*/

// Router for PHP's built-in web server, to try the app without Docker or Apache (README, "Install"):
//
//     php -S 127.0.0.1:8000 -t www_data server_conf/php-server-router.php
//
// It does the two things the Apache of the image does that the built-in server does not: MultiViews (the pages and the
// API are asked for without their extension: /auth is auth.html, /api/day/2026-03-10 is api/day.php with the rest of the
// address as PATH_INFO) and the list of files that are not public (zz-moncycleapp.conf). It is a way to look at the app,
// not a production server: one process, no limit on the size of a body, none of the security headers.

$root = rtrim($_SERVER["DOCUMENT_ROOT"], "/");
$path = parse_url($_SERVER["REQUEST_URI"] ?? "/", PHP_URL_PATH);
$path = is_string($path) ? rawurldecode($path) : "/";

// Not public, whatever the file is: the settings, the code that is only required, the libraries the pages do not load, SQL.
if (
	str_contains($path, "..") || str_contains($path, "\0")
	|| preg_match('#^/(lib|script)(/|$)#', $path)
	|| preg_match('#^/vendor/(?!(components/jquery|chartjs|swagger-api/swagger-ui/dist)(/|$))#', $path)
	|| preg_match('/^(config(\.[a-z]+)?(\.php)?|constants(\.php)?|composer\.(json|lock)|.*\.(sql|md|bak|pw)|.*\.bak\..*)$/', basename($path))
) {
	http_response_code(403);
	echo "Forbidden\n";
	return true;
}

// /api/NAME and /api/NAME/anything: the script api/NAME.php, with the rest of the address as PATH_INFO.
if (preg_match('#^/api/([a-z_]+)(/.*)?$#', $path, $found) && is_file("$root/api/$found[1].php")) {
	$script = "$root/api/$found[1].php";
	$_SERVER["SCRIPT_NAME"] = "/api/$found[1].php";
	$_SERVER["SCRIPT_FILENAME"] = $script;
	$_SERVER["PATH_INFO"] = $found[2] ?? "";
	$_SERVER["PHP_SELF"] = $_SERVER["SCRIPT_NAME"] . $_SERVER["PATH_INFO"];
	chdir(dirname($script));   // the endpoints require "../config.php"
	require $script;
	return true;
}

// /auth is auth.html
if (preg_match('#^/([a-z_]+)$#', $path, $found) && is_file("$root/$found[1].html")) {
	header("Content-Type: text/html; charset=utf-8");
	readfile("$root/$found[1].html");
	return true;
}

// the built-in server answers a missing file of a folder with the folder's index: an unknown endpoint is a 404, as in Apache
if (str_starts_with($path, "/api/") && $path !== "/api/" && !is_file($root . $path)) {
	http_response_code(404);
	echo "Not Found\n";
	return true;
}

return false;
