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

// The weekly report of the team (WEEKLY_REPORT_EMAILS): the week in figures, compared with the week before, the comments of the
// sign-ups and the health of the database. Nothing is stored from one report to the next: every number is read from the tables as
// they are, so what a deleted row was is lost (the "≈" figures are estimated from the ids, which are never given twice). It holds no
// address and no nickname; the sign-up comments are the one user text, escaped.

require_once __DIR__ . "/db_querie.php";
require_once __DIR__ . "/mail.php";

// The addresses of WEEKLY_REPORT_EMAILS, each once; an item that is not an address is skipped.
function weekly_report_recipients(): array {
	$addresses = array_map(fn($item) => strtolower(trim($item)), explode(",", WEEKLY_REPORT_EMAILS));
	return array_values(array_unique(array_filter($addresses, fn($address) => filter_var($address, FILTER_VALIDATE_EMAIL) !== false)));
}

// ---------------------------------------------------------------------------
// The figures
// ---------------------------------------------------------------------------

// The ids inserted in each of the last $weeks weeks (deleted rows included): the next id after a week minus the first id of it.
// $first_id_since(n) is the first id of the rows dated n days before today or later.
function weekly_report_inserted_by_week(callable $first_id_since, int $auto_increment, int $weeks): array {
	$bound = [];
	for ($week = 0; $week <= $weeks; $week++) $bound[$week] = $first_id_since(7 * $week) ?? $auto_increment;
	// a week where every row was deleted takes the bound of the more recent one: ids only grow
	for ($week = 1; $week <= $weeks; $week++) $bound[$week] = min($bound[$week], $bound[$week - 1]);
	$inserted = [];
	for ($week = 0; $week < $weeks; $week++) $inserted[$week] = $bound[$week] - $bound[$week + 1];
	return $inserted;
}

// "Glaire cervicale + température" for a method id.
function weekly_report_method_label(int $nfp_method): string {
	[$method, $temperature] = NFP_METHOD_BY_ID[$nfp_method] ?? [NFP_METHOD_CERVICAL_MUCUS, false];
	return ($method === NFP_METHOD_CERVICAL_MUCUS ? "Glaire cervicale" : "FertilityCare") . ($temperature ? " + température" : "");
}

// Everything the report says, read from the database. Week 0 is the 7 days before today, week 1 the 7 before.
function weekly_report_collect($db): array {
	$weeks = WEEKLY_REPORT_WEEKS;
	$today = new DateTimeImmutable(db_querie_select_curdate($db));
	$empty = ["days" => 0, "writers" => 0, "cycles" => 0, "pregnancies" => 0, "signups" => 0, "verified" => 0];
	$by_week = array_fill(0, $weeks, $empty);
	foreach (db_querie_select_day_timeline_weekly($db, $weeks) as $row) {
		$by_week[intval($row["week"])] = ["days" => intval($row["days"]), "writers" => intval($row["accounts"]), "cycles" => intval($row["cycles"]), "pregnancies" => intval($row["pregnancies"])] + $by_week[intval($row["week"])];
	}
	foreach (db_querie_select_user_account_weekly($db, $weeks) as $row) {
		$by_week[intval($row["week"])]["signups"] = intval($row["accounts"]);
		$by_week[intval($row["week"])]["verified"] = intval($row["verified"]);
	}

	$active_now = db_querie_select_user_account_active_by_method_at($db, 0);
	$active_before = db_querie_select_user_account_active_by_method_at($db, 7);
	$methods = [];
	foreach (array_keys(NFP_METHOD_BY_ID) as $nfp_method) {
		$methods[] = ["label" => weekly_report_method_label($nfp_method), "now" => $active_now[$nfp_method] ?? 0, "before" => $active_before[$nfp_method] ?? 0];
	}

	$signups_ever = weekly_report_inserted_by_week(fn($days) => db_querie_select_user_account_first_id_since($db, $days), db_querie_select_table_auto_increment($db, "user_account"), 2);
	$tokens_ever = weekly_report_inserted_by_week(fn($days) => db_querie_select_auth_token_first_id_since($db, $days), db_querie_select_table_auto_increment($db, "auth_token"), 2);
	$tokens_kept = [db_querie_count_auth_tokens_created_between($db, 7, 0), db_querie_count_auth_tokens_created_between($db, 14, 7)];

	$comments = [];
	foreach (db_querie_select_user_account_register_comments($db, 7) as $row) {
		$comments[] = ["text" => strval($row["register_comment"]), "method" => weekly_report_method_label(intval($row["nfp_method"])), "date" => (new DateTimeImmutable(strval($row["inscription_date"])))->format("d/m"), "verified" => (bool) $row["verified"]];
	}

	$unverified = db_querie_select_user_accounts_unverified_summary($db);
	$cron_last = db_querie_select_key_value($db, CRON_LAST_SUCCESS_KEY);
	$cron_duration = db_querie_select_key_value($db, CRON_LAST_DURATION_KEY);
	$tables = db_querie_select_tables_size($db);
	usort($tables, fn($a, $b) => intval($b["bytes"]) <=> intval($a["bytes"]));

	return [
		"from" => $today->modify("-7 day"),
		"to" => $today->modify("-1 day"),
		"weeks" => $by_week,
		"active" => [array_sum($active_now), array_sum($active_before)],
		"methods" => $methods,
		"signups_ever" => $signups_ever,
		"tokens_ever" => $tokens_ever,
		"tokens_kept" => $tokens_kept,
		"tokens_open" => db_querie_count_auth_tokens($db),
		"connected" => db_querie_count_user_accounts_connected_since($db, 7),
		"comments" => $comments,
		"accounts" => db_querie_count_user_accounts($db),
		"totp" => db_querie_count_user_accounts_with_totp($db),
		"days_ever" => db_querie_count_days($db),
		"cycles_ever" => db_querie_count_cycles($db),
		"unverified" => [intval($unverified["nb"]), intval($unverified["oldest_seconds"] ?? 0)],
		"cron" => ($cron_last === false || is_null($cron_last)) ? null : [intval($cron_last), intval($cron_duration ?: 0)],
		"db_status" => db_querie_select_global_status($db),
		"db_max_connections" => db_querie_select_max_connections($db),
		"db_tables" => $tables,
	];
}

// ---------------------------------------------------------------------------
// Words and numbers
// ---------------------------------------------------------------------------

// "1 234" or "12,5", French style (a no-break space between thousands).
function weekly_report_number(int|float $number, int $decimals = 0): string {
	return number_format($number, $decimals, ",", "\u{00A0}");
}

// "12,3 Mo"
function weekly_report_bytes(int $bytes): string {
	if ($bytes >= 1073741824) return weekly_report_number($bytes / 1073741824, 1) . "\u{00A0}Go";
	if ($bytes >= 1048576) return weekly_report_number($bytes / 1048576, 1) . "\u{00A0}Mo";
	return weekly_report_number($bytes / 1024, 0) . "\u{00A0}Ko";
}

// "3 h", "2 j"
function weekly_report_duration(int $seconds): string {
	if ($seconds >= 172800) return weekly_report_number(intdiv($seconds, 86400)) . "\u{00A0}j";
	if ($seconds >= 7200) return weekly_report_number(intdiv($seconds, 3600)) . "\u{00A0}h";
	return weekly_report_number(intdiv($seconds, 60)) . "\u{00A0}min";
}

// "du 12 au 18 octobre 2026", "du 28 septembre au 4 octobre 2026"
function weekly_report_period(DateTimeImmutable $from, DateTimeImmutable $to): string {
	$start = mail_french_date($from->format("Y-m-d"));
	if ($from->format("Y-m") === $to->format("Y-m")) $start = explode(" ", $start)[0];
	elseif ($from->format("Y") === $to->format("Y")) $start = preg_replace('/ \d{4}$/', "", $start);
	return "du {$start} au " . mail_french_date($to->format("Y-m-d"));
}

// The change from $before to $now: its sign (1, 0, -1), and its words "+6 (+4 %)". $sense: "up" when more is better, "down" when
// less is, "neutral" when neither.
function weekly_report_delta(int|float $now, int|float $before, string $sense = "up"): array {
	$diff = $now - $before;
	$sign = $diff <=> 0;
	$words = $sign === 0 ? "stable" : ($diff > 0 ? "+" : "−") . weekly_report_number(abs($diff), is_float($diff) ? 1 : 0);
	if ($sign !== 0 && $before > 0) $words .= " (" . ($diff > 0 ? "+" : "−") . weekly_report_number(abs($diff) / $before * 100) . "\u{00A0}%)";
	$mood = $sense === "neutral" || $sign === 0 ? 0 : ($sense === "up" ? $sign : -$sign);
	return ["sign" => $sign, "mood" => $mood, "words" => $words];
}

// How the week went, from the four numbers that tell it best: [title, sentence].
function weekly_report_verdict(int $score): array {
	return match (true) {
		$score >= 3 => ["Semaine au top ! 🚀", "Tout monte : profitez-en, c'est le fruit de votre travail."],
		$score >= 1 => ["Belle semaine 🌱", "La tendance est bonne, on continue comme ça."],
		$score === 0 => ["Semaine stable ⚖️", "Rien ne bouge beaucoup : une base solide."],
		$score >= -2 => ["Semaine plus calme 🍃", "Un léger recul, rien d'alarmant : les semaines se suivent et ne se ressemblent pas."],
		default => ["Semaine en recul 💪", "Les chiffres baissent : un bon moment pour regarder ce qui a changé."],
	};
}

// The figures in the order the report shows them, worded once for the HTML and the text.
function weekly_report_figures(array $data): array {
	$w0 = $data["weeks"][0];
	$w1 = $data["weeks"][1];
	$deltas = [
		"active" => weekly_report_delta($data["active"][0], $data["active"][1]),
		"verified" => weekly_report_delta($w0["verified"], $w1["verified"]),
		"writers" => weekly_report_delta($w0["writers"], $w1["writers"]),
		"days" => weekly_report_delta($w0["days"], $w1["days"]),
	];
	$score = array_sum(array_column($deltas, "mood"));

	// a record: the best of the weeks the report shows
	$records = [];
	foreach (["writers" => "comptes qui ont noté", "days" => "observations", "verified" => "nouveaux comptes"] as $key => $label) {
		$past = array_column(array_slice($data["weeks"], 1), $key);
		if ($w0[$key] > 0 && $w0[$key] > max($past)) $records[] = $label;
	}

	$tokens_gone = [max(0, $data["tokens_ever"][0] - $data["tokens_kept"][0]), max(0, $data["tokens_ever"][1] - $data["tokens_kept"][1])];
	$signups_gone = [max(0, $data["signups_ever"][0] - $w0["signups"]), max(0, $data["signups_ever"][1] - $w1["signups"])];

	$facts = [
		"📚 " . weekly_report_number($data["days_ever"]) . " jours notés depuis le début de moncycle.app.",
		"🌀 " . weekly_report_number($data["cycles_ever"]) . " cycles commencés dans l'application depuis le début.",
		"🔐 " . ($data["accounts"] > 0 ? weekly_report_number($data["totp"] / $data["accounts"] * 100, 1) : "0") . "\u{00A0}% des comptes protégés par la double authentification (" . weekly_report_number($data["totp"]) . ").",
		"📈 " . weekly_report_number(array_sum(array_column($data["weeks"], "days"))) . " observations en " . WEEKLY_REPORT_WEEKS . " semaines, " . weekly_report_number(array_sum(array_column($data["weeks"], "verified"))) . " nouveaux comptes.",
	];

	return [
		"title" => weekly_report_verdict($score),
		"period" => weekly_report_period($data["from"], $data["to"]),
		"week_number" => intval($data["from"]->format("W")),
		"deltas" => $deltas,
		"records" => $records,
		"fact" => $facts[intval($data["from"]->format("W")) % count($facts)],
		"tokens_gone" => $tokens_gone,
		"signups_gone" => $signups_gone,
		"health" => weekly_report_health($data),
	];
}

// The health of the server: [ok, label, value, detail]. The MariaDB counters are counted since it started.
function weekly_report_health(array $data): array {
	$status = $data["db_status"];
	$rows = [];
	if (is_null($data["cron"])) $rows[] = [false, "Cron quotidien", "jamais terminé", "aucune exécution complète enregistrée"];
	else {
		$age = max(0, time() - $data["cron"][0]);
		$rows[] = [$age <= 26 * 3600, "Cron quotidien", "il y a " . weekly_report_duration($age), "dernière exécution complète en " . weekly_report_number($data["cron"][1] / 1000, 1) . "\u{00A0}s"];
	}
	$uptime = $status["Uptime"] ?? 0;
	$rows[] = [$uptime >= 7 * 86400, "MariaDB en ligne", "depuis " . weekly_report_duration($uptime), $uptime < 7 * 86400 ? "redémarrée cette semaine" : "pas de redémarrage cette semaine"];
	if ($data["db_max_connections"] > 0) {
		$peak = $status["Max_used_connections"] ?? 0;
		$percent = $peak / $data["db_max_connections"] * 100;
		$rows[] = [$percent < 80, "Pic de connexions", weekly_report_number($peak) . " / " . weekly_report_number($data["db_max_connections"]), weekly_report_number($percent) . "\u{00A0}% du maximum"];
	}
	$slow = $status["Slow_queries"] ?? 0;
	$rows[] = [$slow === 0, "Requêtes lentes", weekly_report_number($slow), "depuis le démarrage"];
	$deadlocks = $status["Innodb_deadlocks"] ?? 0;
	$waits = $status["Innodb_row_lock_waits"] ?? 0;
	$rows[] = [$deadlocks === 0, "Deadlocks", weekly_report_number($deadlocks), weekly_report_number($waits) . " attentes de verrou" . ($waits > 0 ? ", " . weekly_report_number(($status["Innodb_row_lock_time"] ?? 0) / $waits, 1) . "\u{00A0}ms en moyenne" : "")];
	$aborted = $status["Aborted_connects"] ?? 0;
	$rows[] = [$aborted === 0, "Connexions refusées", weekly_report_number($aborted), "échecs de connexion à MariaDB"];
	$requests = $status["Innodb_buffer_pool_read_requests"] ?? 0;
	if ($requests > 0) {
		$hit = (1 - ($status["Innodb_buffer_pool_reads"] ?? 0) / $requests) * 100;
		$rows[] = [$hit >= 99, "Cache InnoDB", weekly_report_number($hit, 2) . "\u{00A0}%", "lectures servies depuis la mémoire"];
	}
	$bytes = array_sum(array_map(fn($table) => intval($table["bytes"]), $data["db_tables"]));
	$biggest = array_map(fn($table) => $table["name"] . " " . weekly_report_bytes(intval($table["bytes"])), array_slice($data["db_tables"], 0, 2));
	$rows[] = [true, "Taille de la base", weekly_report_bytes($bytes), implode(" · ", $biggest)];
	[$waiting, $oldest] = $data["unverified"];
	$rows[] = [$oldest <= (ACCOUNT_UNVERIFIED_DELETE_DAYS + 1) * 86400, "Adresses à confirmer", weekly_report_number($waiting), $waiting > 0 ? "la plus ancienne depuis " . weekly_report_duration($oldest) . ", effacée après " . ACCOUNT_UNVERIFIED_DELETE_DAYS . "\u{00A0}j" : "aucune en attente"];
	return $rows;
}

// ---------------------------------------------------------------------------
// The HTML: one column, tables and inline styles only (mail readers), made for a phone first
// ---------------------------------------------------------------------------

// A badge for a change: green when it is good news, red when it is bad, grey otherwise.
function weekly_report_badge(array $delta): string {
	[$color, $tint] = match ($delta["mood"]) { 1 => ["#1e824c", "#e6f4ec"], -1 => ["#b03a2e", "#fbeceb"], default => ["#5b6660", "#eef1ef"] };
	$arrow = match ($delta["sign"]) { 1 => "▲ ", -1 => "▼ ", default => "= " };
	return "<span style=\"display:inline-block;padding:3px 8px;border-radius:999px;background:{$tint};color:{$color};font-size:12px;font-weight:700;\">{$arrow}{$delta["words"]}</span>";
}

// A tile: a label, a big number, a badge and what it is compared with ($delta null: no comparison). Two to a row.
function weekly_report_tile(string $label, string $value, ?array $delta, string $note): string {
	$label = htmlspecialchars($label);
	$note = htmlspecialchars($note);
	$badge = is_null($delta) ? "" : "<div style=\"padding-top:6px;\">" . weekly_report_badge($delta) . "</div>";
	return <<<HTML
	<td width="50%" valign="top" style="padding:5px;">
		<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#ffffff" style="background:#ffffff;border:1px solid #e1e9e3;border-radius:14px;">
			<tr><td style="padding:14px 14px 12px 14px;">
				<div style="font-size:12px;line-height:16px;font-weight:600;color:#5b7566;">{$label}</div>
				<div style="padding-top:4px;font-size:28px;line-height:34px;font-weight:700;color:#1b2a21;">{$value}</div>
				{$badge}
				<div style="padding-top:5px;font-size:12px;line-height:16px;color:#7a867f;">{$note}</div>
			</td></tr>
		</table>
	</td>
	HTML;
}

// Tiles two by two.
function weekly_report_tiles(array $tiles): string {
	$rows = "";
	foreach (array_chunk($tiles, 2) as $pair) $rows .= "<tr>" . implode("", $pair) . (count($pair) === 1 ? "<td width=\"50%\"></td>" : "") . "</tr>";
	return "<table role=\"presentation\" width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\">{$rows}</table>";
}

// The bars of the last weeks, oldest on the left, this week in the app's green.
function weekly_report_bars(string $label, array $values): string {
	$values = array_reverse($values);
	$max = max(1, max($values));
	$last = count($values) - 1;
	$cells = "";
	foreach ($values as $i => $value) {
		$height = max(3, (int) round($value / $max * 56));
		$color = $i === $last ? "#1e824c" : "#b7dcc6";
		$cells .= "<td valign=\"bottom\" style=\"padding:0 2px;\"><div style=\"height:{$height}px;line-height:{$height}px;font-size:0;background:{$color};border-radius:3px 3px 0 0;\">&nbsp;</div></td>";
	}
	$label = htmlspecialchars($label);
	$max_words = weekly_report_number($max);
	$weeks = count($values);
	return <<<HTML
	<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top:6px;">
		<tr><td style="padding:12px 5px 6px 5px;font-size:13px;line-height:18px;color:#3d4a42;font-weight:600;">{$label} <span style="font-weight:400;color:#7a867f;">· max {$max_words}</span></td></tr>
		<tr><td style="padding:0 3px;">
			<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" height="60" style="height:60px;border-bottom:1px solid #d5e3da;"><tr>{$cells}</tr></table>
		</td></tr>
		<tr><td style="padding:4px 5px 0 5px;">
			<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>
				<td style="font-size:11px;color:#7a867f;">il y a {$weeks} sem.</td>
				<td align="right" style="font-size:11px;color:#1e824c;font-weight:700;">cette semaine</td>
			</tr></table>
		</td></tr>
	</table>
	HTML;
}

// A card with a title.
function weekly_report_card(string $title, string $content): string {
	return <<<HTML
	<tr><td style="padding:22px 0 0 0;">
		<div style="padding:0 6px 8px 6px;font-size:17px;line-height:22px;font-weight:700;color:#1b2a21;">{$title}</div>
		{$content}
	</td></tr>
	HTML;
}

// Rows of a list: label and detail on the left, value on the right; $mark: "🟢", "🟠" or "".
function weekly_report_list(array $rows): string {
	$html = "";
	foreach ($rows as $i => [$mark, $label, $value, $detail]) {
		$border = $i === count($rows) - 1 ? "" : "border-bottom:1px solid #eef1ef;";
		$mark_cell = $mark === "" ? "" : "<td width=\"24\" valign=\"top\" style=\"padding:11px 0 0 0;font-size:13px;line-height:18px;{$border}\">{$mark}</td>";
		$detail = $detail === "" ? "" : "<div style=\"font-size:12px;line-height:16px;color:#7a867f;padding-top:2px;\">" . htmlspecialchars($detail) . "</div>";
		$html .= "<tr>{$mark_cell}<td valign=\"top\" style=\"padding:10px 8px 10px 0;{$border}\"><div style=\"font-size:14px;line-height:19px;color:#3d4a42;\">" . htmlspecialchars($label) . "</div>{$detail}</td>"
			. "<td align=\"right\" valign=\"top\" style=\"padding:10px 0;font-size:14px;line-height:19px;font-weight:700;color:#1b2a21;white-space:nowrap;{$border}\">{$value}</td></tr>";
	}
	return "<table role=\"presentation\" width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" bgcolor=\"#ffffff\" style=\"background:#ffffff;border:1px solid #e1e9e3;border-radius:14px;\"><tr><td style=\"padding:4px 16px;\"><table role=\"presentation\" width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\">{$html}</table></td></tr></table>";
}

function weekly_report_html(array $data, array $figures): string {
	$w0 = $data["weeks"][0];
	$w1 = $data["weeks"][1];
	$d = $figures["deltas"];
	$n = "weekly_report_number";
	[$title, $sentence] = $figures["title"];
	$period = htmlspecialchars($figures["period"]);
	$warnings = count(array_filter($figures["health"], fn($row) => !$row[0]));
	$health_line = $warnings === 0 ? "✅ Serveur en pleine forme" : "⚠️ " . $warnings . " point" . ($warnings > 1 ? "s" : "") . " à surveiller côté serveur";
	$records = $figures["records"] === [] ? "" : "<div style=\"margin-top:14px;padding:10px 12px;border-radius:10px;background:#ffffff;color:#1e824c;font-size:14px;line-height:20px;font-weight:700;\">🏆 Record sur " . WEEKLY_REPORT_WEEKS . " semaines : " . htmlspecialchars(implode(", ", $figures["records"])) . " !</div>";

	$accounts = weekly_report_tiles([
		weekly_report_tile("👥 Comptes actifs", $n($data["active"][0]), $d["active"], "un jour noté en 35 j · " . $n($data["active"][1]) . " il y a 7 j"),
		weekly_report_tile("✨ Nouveaux comptes", $n($w0["verified"]), $d["verified"], "adresse confirmée · " . $n($w1["verified"]) . " la sem. passée"),
	]) . weekly_report_bars("Nouveaux comptes par semaine", array_column($data["weeks"], "verified"));
	$method_rows = array_map(fn($method) => ["", $method["label"], $n($method["now"]), "actifs · " . weekly_report_delta($method["now"], $method["before"])["words"] . " en 7 j"], $data["methods"]);
	$accounts .= "<div style=\"height:10px;line-height:10px;font-size:0;\">&nbsp;</div>" . weekly_report_list(array_merge([
		["", "Inscriptions ≈", $n($data["signups_ever"][0]), $n($data["signups_ever"][1]) . " la sem. passée · dont " . $n($w0["signups"] - $w0["verified"]) . " en attente de confirmation, ≈ " . $n($figures["signups_gone"][0]) . " déjà effacées"],
		["", "Comptes au total", $n($data["accounts"]), "adresse confirmée"],
	], $method_rows));

	$comments = "";
	foreach ($data["comments"] as $comment) {
		$text = nl2br(htmlspecialchars($comment["text"]));
		$meta = htmlspecialchars($comment["method"] . " · " . $comment["date"] . ($comment["verified"] ? "" : " · adresse pas encore confirmée"));
		$comments .= "<table role=\"presentation\" width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" style=\"margin-bottom:8px;\"><tr><td bgcolor=\"#ffffff\" style=\"background:#ffffff;border:1px solid #e1e9e3;border-left:4px solid #1e824c;border-radius:4px 12px 12px 4px;padding:12px 14px;\">"
			. "<div style=\"font-size:15px;line-height:22px;color:#1b2a21;\">« {$text} »</div><div style=\"padding-top:6px;font-size:12px;line-height:16px;color:#7a867f;\">{$meta}</div></td></tr></table>";
	}
	if ($comments === "") $comments = "<div style=\"padding:0 6px;font-size:14px;line-height:20px;color:#7a867f;\">Aucun commentaire laissé à l'inscription cette semaine.</div>";
	$comments_title = "💬 Le mot des nouveaux comptes" . ($data["comments"] === [] ? "" : " <span style=\"font-weight:400;color:#7a867f;\">(" . count($data["comments"]) . ")</span>");

	$per_writer = $w0["writers"] > 0 ? $w0["days"] / $w0["writers"] : 0.0;
	$per_writer_before = $w1["writers"] > 0 ? $w1["days"] / $w1["writers"] : 0.0;
	$usage = weekly_report_tiles([
		weekly_report_tile("✍️ Comptes qui ont noté", $n($w0["writers"]), $d["writers"], $n($w1["writers"]) . " la sem. passée"),
		weekly_report_tile("📅 Observations", $n($w0["days"]), $d["days"], $n($w1["days"]) . " la sem. passée"),
		weekly_report_tile("🌀 Cycles commencés", $n($w0["cycles"]), weekly_report_delta($w0["cycles"], $w1["cycles"], "neutral"), $n($w1["cycles"]) . " la sem. passée"),
		weekly_report_tile("📝 Jours par compte", $n($per_writer, 1), weekly_report_delta(round($per_writer, 1), round($per_writer_before, 1)), "sur 7 · " . $n($per_writer_before, 1) . " la sem. passée"),
	]) . weekly_report_bars("Comptes qui ont noté, par semaine", array_column($data["weeks"], "writers"));
	if ($w0["pregnancies"] > 0) $usage .= "<div style=\"padding:12px 6px 0 6px;font-size:14px;line-height:20px;color:#3d4a42;\">🍼 " . $n($w0["pregnancies"]) . " début" . ($w0["pregnancies"] > 1 ? "s" : "") . " de grossesse noté" . ($w0["pregnancies"] > 1 ? "s" : "") . " cette semaine.</div>";

	$sessions = weekly_report_tiles([
		weekly_report_tile("🔑 Connexions ≈", $n($data["tokens_ever"][0]), weekly_report_delta($data["tokens_ever"][0], $data["tokens_ever"][1]), "jetons créés · " . $n($data["tokens_ever"][1]) . " la sem. passée"),
		weekly_report_tile("🗑️ Jetons disparus ≈", $n($figures["tokens_gone"][0]), weekly_report_delta($figures["tokens_gone"][0], $figures["tokens_gone"][1], "neutral"), "déconnexion, révocation, expiration"),
		weekly_report_tile("📱 Comptes connectés", $n($data["connected"]), null, "ont utilisé l'app ces 7 derniers jours"),
		weekly_report_tile("🎫 Sessions ouvertes", $n($data["tokens_open"]), null, "jetons valides aujourd'hui"),
	]);

	$health = weekly_report_list(array_map(fn($row) => [$row[0] ? "🟢" : "🟠", $row[1], htmlspecialchars($row[2]), $row[3]], $figures["health"]));

	$fact = htmlspecialchars($figures["fact"]);
	$cards = weekly_report_card("👥 Comptes", $accounts) . weekly_report_card($comments_title, $comments) . weekly_report_card("📊 Utilisation", $usage)
		. weekly_report_card("🔑 Connexions", $sessions) . weekly_report_card("🩺 Santé du serveur", $health);
	$wordmark = "mon<span style='color:#1e824c;'>cycle</span>.app";
	$version = htmlspecialchars(APP_VERSION);
	$preheader = htmlspecialchars("{$title} {$data["active"][0]} comptes actifs ({$d["active"]["words"]}), {$w0["verified"]} nouveaux comptes, {$w0["days"]} observations.");
	$active_words = htmlspecialchars($d["active"]["words"]);
	return <<<HTML
	<!DOCTYPE html>
	<html lang="fr">
	<head>
		<meta charset="utf-8" />
		<meta name="viewport" content="width=device-width, initial-scale=1" />
		<meta name="color-scheme" content="light only" />
		<meta name="supported-color-schemes" content="light only" />
		<title>Bilan de la semaine</title>
	</head>
	<body style="margin:0;padding:0;background:#f1f5f2;">
	<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:#f1f5f2;font-size:1px;line-height:1px;">{$preheader}</div>
	<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#f1f5f2" style="background:#f1f5f2;">
	<tr><td align="center" style="padding:18px 8px 28px 8px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
		<table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:600px;">
			<tr><td style="padding:0 6px 12px 6px;">
				<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>
					<td style="font-size:19px;font-weight:700;color:#1b2a21;">{$wordmark}</td>
					<td align="right" style="font-size:12px;font-weight:700;letter-spacing:.8px;text-transform:uppercase;color:#1e824c;">Semaine {$figures["week_number"]}</td>
				</tr></table>
			</td></tr>
			<tr><td bgcolor="#1e824c" style="background:#1e824c;border-radius:18px;padding:24px 22px 22px 22px;color:#ffffff;">
				<div style="font-size:12px;letter-spacing:1px;text-transform:uppercase;color:#cfe9da;">Bilan {$period}</div>
				<div style="padding-top:8px;font-size:26px;line-height:32px;font-weight:700;color:#ffffff;">{$title}</div>
				<div style="padding-top:6px;font-size:15px;line-height:22px;color:#e6f4ec;">{$sentence}</div>
				<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top:16px;"><tr>
					<td width="50%" valign="top">
						<div style="font-size:34px;line-height:38px;font-weight:700;color:#ffffff;">{$n($data["active"][0])}</div>
						<div style="font-size:13px;line-height:18px;color:#cfe9da;">comptes actifs · {$active_words}</div>
					</td>
					<td width="50%" valign="top">
						<div style="font-size:34px;line-height:38px;font-weight:700;color:#ffffff;">+{$n($w0["verified"])}</div>
						<div style="font-size:13px;line-height:18px;color:#cfe9da;">nouveaux comptes</div>
					</td>
				</tr></table>
				{$records}
				<div style="margin-top:14px;font-size:14px;line-height:20px;color:#ffffff;">{$health_line}</div>
			</td></tr>
			{$cards}
			<tr><td style="padding:22px 0 0 0;">
				<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#e6f4ec" style="background:#e6f4ec;border-radius:14px;"><tr>
					<td style="padding:14px 16px;font-size:14px;line-height:20px;color:#17301f;"><b>Le saviez-vous ?</b> {$fact}</td>
				</tr></table>
			</td></tr>
			<tr><td style="padding:18px 8px 0 8px;font-size:11px;line-height:16px;color:#7a867f;">
				<b>Comment lire ce bilan.</b> Une semaine va du lundi au dimanche ; la comparaison est faite avec la semaine d'avant. Rien n'est conservé d'un bilan à l'autre : tout est recalculé à partir de la base, donc une ligne supprimée disparaît aussi des semaines passées. « ≈ » : estimé d'après les identifiants de la base, qui ne servent qu'une fois ; une inscription sur une adresse déjà prise et les connexions aux comptes de démo y comptent. Observations et cycles : par date d'observation, comptes de démo exclus. Compteurs MariaDB : depuis son démarrage.
			</td></tr>
			<tr><td align="center" style="padding:16px 8px 0 8px;font-size:11px;line-height:16px;color:#9aa59e;">
				Envoyé chaque lundi par le cron de moncycle.app (version {$version}) aux adresses de WEEKLY_REPORT_EMAILS.
			</td></tr>
		</table>
	</td></tr>
	</table>
	</body>
	</html>
	HTML;
}

// ---------------------------------------------------------------------------
// The text part
// ---------------------------------------------------------------------------

function weekly_report_text(array $data, array $figures): string {
	$w0 = $data["weeks"][0];
	$w1 = $data["weeks"][1];
	$d = $figures["deltas"];
	$n = "weekly_report_number";
	[$title, $sentence] = $figures["title"];
	$lines = [
		"moncycle.app · bilan de la semaine {$figures["week_number"]}, {$figures["period"]}",
		"",
		"{$title}",
		$sentence,
		$figures["records"] === [] ? "" : "Record sur " . WEEKLY_REPORT_WEEKS . " semaines : " . implode(", ", $figures["records"]) . " !",
		"",
		"COMPTES",
		"- Comptes actifs (un jour noté en 35 j) : " . $n($data["active"][0]) . " ({$d["active"]["words"]})",
		"- Nouveaux comptes (adresse confirmée) : " . $n($w0["verified"]) . " ({$d["verified"]["words"]})",
		"- Inscriptions ≈ " . $n($data["signups_ever"][0]) . " (sem. passée " . $n($data["signups_ever"][1]) . "), dont " . $n($w0["signups"] - $w0["verified"]) . " en attente, ≈ " . $n($figures["signups_gone"][0]) . " déjà effacées",
		"- Comptes au total : " . $n($data["accounts"]),
	];
	foreach ($data["methods"] as $method) $lines[] = "- {$method["label"]} : " . $n($method["now"]) . " actifs (" . weekly_report_delta($method["now"], $method["before"])["words"] . ")";
	$lines[] = "- Nouveaux comptes par semaine, de la plus ancienne à celle-ci : " . implode(" ", array_reverse(array_column($data["weeks"], "verified")));
	$lines[] = "";
	$lines[] = "LE MOT DES NOUVEAUX COMPTES";
	if ($data["comments"] === []) $lines[] = "Aucun commentaire laissé à l'inscription cette semaine.";
	foreach ($data["comments"] as $comment) $lines[] = "- « " . str_replace(["\r", "\n"], " ", $comment["text"]) . " » ({$comment["method"]}, {$comment["date"]}" . ($comment["verified"] ? "" : ", adresse pas encore confirmée") . ")";
	array_push($lines,
		"",
		"UTILISATION",
		"- Comptes qui ont noté : " . $n($w0["writers"]) . " ({$d["writers"]["words"]})",
		"- Observations : " . $n($w0["days"]) . " ({$d["days"]["words"]})",
		"- Cycles commencés : " . $n($w0["cycles"]) . " (sem. passée " . $n($w1["cycles"]) . ")",
		"- Comptes qui ont noté, par semaine : " . implode(" ", array_reverse(array_column($data["weeks"], "writers"))),
		"",
		"CONNEXIONS",
		"- Connexions ≈ " . $n($data["tokens_ever"][0]) . " jetons créés (sem. passée " . $n($data["tokens_ever"][1]) . ")",
		"- Jetons disparus ≈ " . $n($figures["tokens_gone"][0]) . " (sem. passée " . $n($figures["tokens_gone"][1]) . ")",
		"- Comptes connectés ces 7 jours : " . $n($data["connected"]) . " · sessions ouvertes : " . $n($data["tokens_open"]),
		"",
		"SANTÉ DU SERVEUR",
	);
	foreach ($figures["health"] as [$ok, $label, $value, $detail]) $lines[] = ($ok ? "[ok] " : "[!!] ") . "{$label} : {$value} ({$detail})";
	array_push($lines,
		"",
		"Le saviez-vous ? {$figures["fact"]}",
		"",
		"≈ : estimé d'après les identifiants de la base (une inscription sur une adresse déjà prise et les connexions aux comptes de démo y comptent). Rien n'est conservé d'un bilan à l'autre : une ligne supprimée disparaît aussi des semaines passées. Compteurs MariaDB : depuis son démarrage.",
	);
	return implode("\n", $lines);
}

// ---------------------------------------------------------------------------
// The mail
// ---------------------------------------------------------------------------

// The report as it is today: [subject, html, text]. The subject holds numbers only, no user text.
function weekly_report_build($db): array {
	$data = weekly_report_collect($db);
	$figures = weekly_report_figures($data);
	$arrow = match ($figures["deltas"]["active"]["sign"]) { 1 => "▲", -1 => "▼", default => "=" };
	$subject = "📊 Semaine {$figures["week_number"]} · " . weekly_report_number($data["active"][0]) . " actifs {$arrow} · +" . weekly_report_number($data["weeks"][0]["verified"]) . " comptes";
	return [$subject, weekly_report_html($data, $figures), weekly_report_text($data, $figures)];
}

function weekly_report_send(array $to, array $report): bool {
	[$subject, $html, $text] = $report;
	return mail_send("weekly_report", $to, $subject, $html, $text);
}
