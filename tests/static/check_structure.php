<?php
// Structure check for moncycle.app -- run from the host, nothing is written:
//   docker exec -i macbook_moncycle_app php < tests/static/check_structure.php
//
// Rules (AGENT.md "Code layout"):
//   1. functions and classes are defined in lib/ only        (api/, script/, config*, constants.php: none)
//   2. constants are defined in constants.php only           (config*.php hold the operator settings; the
//      lib/ exceptions are listed in ALLOWED_CONST)
//   3. every entry point (api/*, script/*) includes, directly or through another include, the lib file
//      of every lib function that its included files call -- php -l cannot see a missing require, it
//      only fails at runtime
//   3c. (warning) a lib file requires a lib file it calls nothing of
//   3b. a lib file requires, directly, the lib files whose functions it calls, so an endpoint only lists
//      what it uses itself
//   4. a lib function is named <lib file>_*                  (env.php -> env_*), reported as a warning
//   6. APP_VERSION (constants.php) is the highest N of script/migration/vN-1_to_vN.* and the schema_version row of script/db/schema.sql
chdir("/var/www/html");

const ALLOWED_CONST = ["NFP_FILE_SCHEMA" => "lib/nfp_format.php", "DB_SQL_USER_ACCOUNT_LAST_ACTIVITY" => "lib/db_querie.php"];
$skip = ["api/v2/test.php" => 1, "test.php" => 1, "info.php" => 1];

$files = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(".", FilesystemIterator::SKIP_DOTS)) as $f) {
	$p = substr($f->getPathname(), 2);
	if (substr($p, -4) !== ".php" || str_starts_with($p, "vendor/") || str_starts_with($p, "script/migration/") || isset($skip[$p])) continue;
	$files[] = $p;
}
sort($files);

function norm(string $path): string {
	$out = [];
	foreach (explode("/", $path) as $seg) { if ($seg === "..") array_pop($out); elseif ($seg !== "." && $seg !== "") $out[] = $seg; }
	return implode("/", $out);
}

$defs = []; $consts = []; $includes = []; $calls = [];
foreach ($files as $file) {
	$t = token_get_all(file_get_contents($file));
	$n = count($t); $depth = 0;
	for ($i = 0; $i < $n; $i++) {
		$tok = $t[$i];
		if ($tok === "{") { $depth++; continue; }
		if ($tok === "}") { $depth--; continue; }
		if (!is_array($tok)) continue;
		if ($tok[0] === T_CURLY_OPEN || $tok[0] === T_DOLLAR_OPEN_CURLY_BRACES) { $depth++; continue; }
		$prev = null; for ($j = $i - 1; $j >= 0; $j--) if (!is_array($t[$j]) || !in_array($t[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) { $prev = $t[$j]; break; }
		$next = null; for ($j = $i + 1; $j < $n; $j++) if (!is_array($t[$j]) || !in_array($t[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) { $next = $t[$j]; $nj = $j; break; }
		if ($tok[0] === T_FUNCTION && $depth === 0 && is_array($next) && $next[0] === T_STRING) $defs[$next[1]] = $file;
		elseif ($tok[0] === T_CLASS && is_array($prev) === false || $tok[0] === T_CLASS && is_array($prev) && $prev[0] !== T_DOUBLE_COLON && is_array($next) && $next[0] === T_STRING) $defs["class " . $next[1]] = $file;
		elseif ($tok[0] === T_CONST && is_array($next) && $next[0] === T_STRING) $consts[$next[1]] = $file;
		elseif ($tok[0] === T_STRING && $tok[1] === "define" && $next === "(") { for ($j = $i + 2; $j < $n; $j++) if (is_array($t[$j]) && $t[$j][0] === T_CONSTANT_ENCAPSED_STRING) { $consts[trim($t[$j][1], "\"'")] = $file; break; } }
		elseif (in_array($tok[0], [T_REQUIRE, T_REQUIRE_ONCE, T_INCLUDE, T_INCLUDE_ONCE])) {
			$expr = ""; for ($j = $i + 1; $j < $n && $t[$j] !== ";"; $j++) $expr .= is_array($t[$j]) ? $t[$j][1] : $t[$j];
			if (preg_match('/^\s*\(?\s*(?:__DIR__\s*\.\s*)?["\']([^"\']+)["\']/', $expr, $m)) {
				$base = str_contains($expr, "__DIR__") ? dirname($file) : dirname($file);
				$includes[$file][] = norm($base . "/" . ltrim($m[1], "/"));
			}
		}
		elseif ($tok[0] === T_STRING && $next === "(" && !(is_array($prev) && in_array($prev[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW])))
			$calls[$file][$tok[1]] = true;
	}
}

function reach(string $file, array $includes, array &$seen = []): array {
	foreach ($includes[$file] ?? [] as $inc) if (!isset($seen[$inc])) { $seen[$inc] = true; reach($inc, $includes, $seen); }
	return $seen;
}

$errors = []; $warnings = [];
foreach ($defs as $name => $file) {
	if (!str_starts_with($file, "lib/")) $errors[] = "RULE 1  $file defines " . (str_starts_with($name, "class ") ? $name : "$name()") . " -- move it to lib/";
	elseif (!str_starts_with($name, "class ") && !str_starts_with($name, substr(basename($file, ".php"), 0) . "_")) $warnings[] = "RULE 4  $file: $name() is not prefixed " . basename($file, ".php") . "_";
}
foreach ($consts as $name => $file) {
	if ($file !== "constants.php" && !str_starts_with($file, "config") && (ALLOWED_CONST[$name] ?? null) !== $file) $errors[] = "RULE 2  $file defines constant $name -- move it to constants.php (or config*.php if an operator sets it)";
}
$libFns = array_filter($defs, fn($f, $n) => str_starts_with($f, "lib/") && !str_starts_with($n, "class "), ARRAY_FILTER_USE_BOTH);
$implicit = [];
foreach ($files as $entry) {
	if (!str_starts_with($entry, "api/") && !str_starts_with($entry, "script/")) continue;
	$seen = []; $set = reach($entry, $includes, $seen); $set[$entry] = true;
	if (!isset($set["config.php"]) && !isset($set["constants.php"])) $warnings[] = "RULE 3  $entry never includes config.php";
	foreach (array_keys($set) as $file) foreach (array_keys($calls[$file] ?? []) as $fn) {
		if (!isset($libFns[$fn])) continue;
		$def = $libFns[$fn];
		if ($def === $file) continue;
		if (!isset($set[$def])) $errors["$entry|$def"] = "RULE 3  $entry: $file calls $fn() but $def is not included";
		if (str_starts_with($file, "lib/")) $implicit["$file -> $def"] = true;
	}
}
$errors = array_values($errors);
// 3c: a lib file that requires another lib file and calls nothing of it
foreach ($includes as $file => $incs) {
	if (!str_starts_with($file, "lib/")) continue;
	foreach (array_unique($incs) as $inc) {
		if (!str_starts_with($inc, "lib/")) continue;
		$callsIt = false;
		foreach (array_keys($calls[$file] ?? []) as $fn) if (($libFns[$fn] ?? null) === $inc) { $callsIt = true; break; }
		$usesClass = false;   // a class of the other file (DocPdf), or a vendor autoload
		foreach ($defs as $name => $defFile) if ($defFile === $inc && str_starts_with($name, "class ") && str_contains(file_get_contents($file), substr($name, 6))) $usesClass = true;
		if (!$callsIt && !$usesClass) $warnings[] = "RULE 3c $file requires $inc but calls nothing of it";
	}
}
foreach (array_keys($implicit) as $pair) {
	[$from, $to] = explode(" -> ", $pair);
	if (!in_array($to, $includes[$from] ?? [], true)) $errors[] = "RULE 3b $from calls $to but does not require it";
}
// 5: a lib function or a constant nothing refers to (a call, a use, or its name as a callback string);
// the definition itself is one mention
$mentions = [];
foreach ($files as $file) {
	foreach (token_get_all(file_get_contents($file)) as $tok) {
		if (!is_array($tok)) continue;
		if ($tok[0] === T_STRING) $mentions[$tok[1]] = ($mentions[$tok[1]] ?? 0) + 1;
		elseif ($tok[0] === T_CONSTANT_ENCAPSED_STRING) { $word = trim($tok[1], "\"'"); $mentions[$word] = ($mentions[$word] ?? 0) + 1; }
	}
}
foreach ($defs as $name => $file) {
	if (!str_starts_with($name, "class ") && ($mentions[$name] ?? 0) <= 1) $warnings[] = "RULE 5  $file: $name() is never called";
}
foreach ($consts as $name => $file) {
	if (!str_starts_with($file, "config") && ($mentions[$name] ?? 0) <= 1) $warnings[] = "RULE 5  $file: constant $name is never used";
}
// 6: the version of the schema is written in three places that must say the same
preg_match('/^const APP_VERSION = "(\d+)[^"]*";/m', file_get_contents("constants.php"), $m);
$code_version = isset($m[1]) ? intval($m[1]) : null;
$migrated = 0;
foreach (glob("script/migration/v*_to_v*.*") as $migration) {
	if (preg_match('/v(\d+)_to_v(\d+)\./', $migration, $m)) $migrated = max($migrated, intval($m[2]));
}
preg_match("/\('schema_version',\s*(\d+)\)/", file_get_contents("script/db/schema.sql"), $m);
$schema_version = isset($m[1]) ? intval($m[1]) : null;
if ($code_version !== $migrated || $code_version !== $schema_version) {
	$errors[] = "RULE 6  APP_VERSION is " . var_export($code_version, true) . " in constants.php, the newest migration is v$migrated, schema.sql says " . var_export($schema_version, true);
}
sort($errors); sort($warnings);
echo count($files), " php files, ", count($defs), " definitions, ", count($consts), " constants\n";
if ($implicit && getenv("SHOW_LIB_DEPS")) foreach (array_keys($implicit) as $d) echo "INFO    lib dependency $d\n";
foreach ($errors as $e) echo "ERROR   $e\n";
foreach ($warnings as $w) echo "WARN    $w\n";
echo $errors ? "FAIL: " . count($errors) . " error(s)\n" : "OK: no structure error\n";
