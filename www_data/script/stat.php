<?php
/* moncycle.app
**
** licence Creative Commons CC BY-NC-SA
**
** https://www.moncycle.app
** https://github.com/moncycle-app/backend-api-web-app
*/

declare(strict_types=1);

require_once __DIR__ . "/../config.php";
require_once __DIR__ . "/../lib/db_ops.php";
require_once __DIR__ . "/../lib/db_querie.php";

header("Content-Type: text/plain");

$db = db_ops_open();

$accounts = db_querie_count_user_accounts($db);
$active = db_querie_count_user_accounts_active($db);

// one metric per line, "moncycle_app_<name> <value>"
$metrics = [
	"nb_user_account" => $accounts,
	"nb_session" => db_querie_count_auth_tokens($db),
	"visite_mensuel" => db_querie_select_key_value($db, "pub_visit_monthly"),
	"visite_hebdo" => db_querie_select_key_value($db, "pub_visit_weekly"),
	"visite_jour" => db_querie_select_key_value($db, "pub_visit_daily"),
	"nb_user_account_actif" => $active,
	"pc_user_account_actif" => $accounts > 0 ? round(($active / $accounts) * 100, 1) : 0,
	"nb_user_account_avec_totp" => db_querie_count_user_accounts_with_totp($db),
];

// the rest only means something with active accounts
if ($active > 0) {
	foreach (NFP_METHOD_BY_ID as $nfp_method => [$method, $temperature]) {
		$name = strtolower($method) . ($temperature ? "_temp" : "");
		$nb = db_querie_count_user_accounts_active_by_method($db, $nfp_method);
		$metrics["nb_user_account_actif_$name"] = $nb;
		$metrics["pc_user_account_actif_$name"] = round(($nb / $active) * 100, 1);
	}

	$metrics += [
		"nb_user_account_recent" => round(db_querie_count_user_accounts_recent($db)),
		"nb_cycle" => db_querie_count_cycles($db),
		"nb_cycle_recent" => db_querie_count_cycles_recent($db),
		"age_moyen" => round(db_querie_select_average_age($db) ?? 0, 1),
		"age_moyen_recent" => round(db_querie_select_average_age($db, true) ?? 0, 1),
		"nb_total_observation" => db_querie_count_days($db),
		"nb_observation_aujourdhui" => db_querie_count_days_today($db),
	];
	foreach ([1, 5, 15, 30] as $days) {
		$metrics["nb_observation_{$days}j"] = db_querie_count_days_since($db, $days);
	}
}

foreach ($metrics as $name => $value) echo "moncycle_app_$name $value" . PHP_EOL;
