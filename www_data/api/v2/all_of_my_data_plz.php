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

require_once __DIR__ . "/../../config.php";
require_once __DIR__ . "/../../lib/doc_csv.php";
require_once __DIR__ . "/../../lib/log.php";
require_once __DIR__ . "/../../lib/sec.php";
require_once __DIR__ . "/../../lib/sec_token.php";
require_once __DIR__ . "/../../lib/server.php";

log_start(sec_client_ip());
http_exit_if_maintenance();
$db = db_ops_open();
server_exit_if_schema_mismatch($db);

$user_account = sec_token_authenticate($db);
sec_redirect_if_logged_out($user_account);
$no_user_account = $user_account["no_user_account"];

// the account, its sessions and its days as stored, secrets masked; read first, for the log
$account_row = sec_obfuscate_columns(db_querie_select_user_account($db, $no_user_account), ["password", "totp_secret", "email_token_hash"]);
$session_rows = array_map(fn($row) => sec_obfuscate_columns($row, ["auth_token_str"]), db_querie_select_auth_tokens($db, $no_user_account));
$day_rows = doc_csv_raw_days($db, $no_user_account);

log_event("export.raw_dump", ["n" => count($day_rows), "ntk" => count($session_rows)]);
header("content-type:application/csv;charset=UTF-8");
header('Content-Disposition: attachment; filename="export_moncycle_app.csv"');

$out = fopen('php://output', 'w');
fputs($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
fputs($out, "Export des données MONCYCLE.APP de " . $user_account["name_user_account"] . PHP_EOL . PHP_EOL);

doc_csv_dump($out, [$account_row]);
doc_csv_dump($out, $session_rows);
doc_csv_dump($out, $day_rows);

fclose($out);
