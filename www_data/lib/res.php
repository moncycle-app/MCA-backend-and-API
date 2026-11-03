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

// The words a user reads, whatever says them (a mail, a PDF): res/lang/<language>/<domain>.php returns [key => string, or a list]. A
// language is an ACCOUNT_LANGUAGES code; one the app does not have is the default language.

// Every word of one domain in one language.
function res_words(string $domain, string $language): array {
	static $words = [];
	$language = in_array($language, ACCOUNT_LANGUAGES, true) ? $language : ACCOUNT_DEFAULT_LANGUAGE;
	return $words[$domain][$language] ??= require RES_LANG_DIR . "/" . strtolower($language) . "/" . $domain . ".php";
}

// A string of a language file: a {@other.key} is replaced by that string of the same file (a phrase said in several places is written
// once), then its {name} marks by $vars. The caller escapes what it passes when the text goes into HTML.
function res_t(string $domain, string $language, string $key, array $vars = []): string {
	$text = res_words($domain, $language)[$key] ?? throw new RuntimeException("{$domain} text missing: {$key}");
	if (!is_string($text)) throw new RuntimeException("{$domain} text is not a string: {$key}");
	$text = preg_replace_callback('/\{@([\w.]+)\}/', fn(array $m): string => res_t($domain, $language, $m[1]), $text);
	$marks = [];
	foreach ($vars as $name => $value) $marks["{" . $name . "}"] = (string) $value;
	return strtr($text, $marks);
}
