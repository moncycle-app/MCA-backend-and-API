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

// Environment variables read as the type of the setting, for config.docker.php -- the one lib
// file loaded before the config is, so it uses nothing the config defines. getenv() answers
// false when a variable is unset and a string otherwise, so an unset or empty one takes the
// default. A boolean is true/false, on/off, yes/no or 1/0, in any case.

function env_string(string $name, string $default = ""): string {
	$value = getenv($name);
	return $value === false || $value === "" ? $default : $value;
}

// A setting that may hold a secret: the content of the file named by <name>_FILE (a Docker secret; the newline at its end is not part of
// it) when it can be read, else the variable itself.
function env_secret(string $name): string {
	$file = env_string($name . "_FILE");
	return $file !== "" && is_readable($file) ? trim((string) file_get_contents($file)) : env_string($name);
}

function env_int(string $name, int $default): int {
	$value = filter_var(getenv($name), FILTER_VALIDATE_INT);
	return $value === false ? $default : $value;
}

function env_bool(string $name, bool $default): bool {
	$value = getenv($name);
	if ($value === false || $value === "") return $default;
	return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
}

// One of $allowed (any case), else the default: a typo never turns a feature off.
function env_choice(string $name, array $allowed, string $default): string {
	$value = strtolower(trim((string) getenv($name)));
	return in_array($value, $allowed, true) ? $value : $default;
}

// The items of a comma list that are in $allowed (any case, each once). Unset, empty or nothing
// valid gives [], which the setting reads as "all".
function env_list(string $name, array $allowed): array {
	$items = array_map(fn($item) => strtolower(trim($item)), explode(",", (string) getenv($name)));
	return array_values(array_unique(array_intersect($items, $allowed)));
}

// The origins of a comma list, each as "scheme://host[:port]" in lower case (a trailing "/" is
// tolerated). An item that is anything else, a path or a query included, is dropped.
function env_origins(string $name): array {
	$origins = [];
	foreach (explode(",", (string) getenv($name)) as $item) {
		$origin = strtolower(rtrim(trim($item), "/"));
		if (preg_match('#^https?://([a-z0-9]([a-z0-9.-]*[a-z0-9])?|\[[0-9a-f:.]+\])(:[0-9]{1,5})?$#', $origin)) $origins[] = $origin;
	}
	return array_values(array_unique($origins));
}

// A comma list of "language=https://page" pairs ("fr=https://a/fr.html,de=https://a/de.html") as ["fr" => "https://a/fr.html", ...]: the language
// code in lower case (2 or 3 letters, an optional region: pt-br), the page https. An item that is anything else is dropped; the last one of a
// language wins. A new language is one more pair, no code.
function parse_language_urls(string $list): array {
	$urls = [];
	foreach (explode(",", $list) as $item) {
		$parts = explode("=", trim($item), 2);
		if (count($parts) !== 2) continue;
		$code = strtolower(trim($parts[0]));
		$url = trim($parts[1]);
		if (preg_match('/^[a-z]{2,3}(-[a-z0-9]{2,8})?$/', $code) === 1 && str_starts_with($url, "https://")) $urls[$code] = $url;
	}
	return $urls;
}

// The "important information" of the server, one text per language: <PREFIX>_<CODE> for each code of ACCOUNT_LANGUAGES (SERVER_MESSAGE_EN,
// SERVER_MESSAGE_FR...). Plain text: control characters are dropped, the text is trimmed and cut at SERVER_MESSAGE_MAX_CHARS. A language
// with no text is not listed (a client then shows the English one). Keys are lower case language codes, like TERMS_OF_SERVICE_URLS.
function env_language_texts(string $prefix): array {
	$texts = [];
	foreach (ACCOUNT_LANGUAGES as $code) {
		$text = trim(preg_replace('/[\p{Cc}\p{Cf}]+/u', " ", env_string($prefix . "_" . $code)) ?? "");
		if ($text !== "") $texts[strtolower($code)] = mb_substr($text, 0, SERVER_MESSAGE_MAX_CHARS);
	}
	return $texts;
}

// The addresses and CIDR ranges ("10.0.0.0/8", "fd00::/8") of a comma list; an item that is not
// one is dropped.
function env_ip_ranges(string $name): array {
	$ranges = [];
	foreach (explode(",", (string) getenv($name)) as $item) {
		$parts = explode("/", trim($item));
		if (count($parts) > 2 || filter_var($parts[0], FILTER_VALIDATE_IP) === false) continue;
		$max_bits = str_contains($parts[0], ":") ? 128 : 32;
		if (isset($parts[1]) && !(ctype_digit($parts[1]) && intval($parts[1]) <= $max_bits)) continue;
		$ranges[] = implode("/", $parts);
	}
	return array_values(array_unique($ranges));
}
