/* moncycle.app
**
** licence Creative Commons CC BY-NC-SA
**
** https://www.moncycle.app
** https://github.com/moncycle-app/backend-api-web-app
*/

// The settings page (settings.html). It is a menu and eight panels; the address says which one is open (settings#securite).
// Reading order of this file:
//   1. the text the user reads        5. the panels, one section each:
//   2. small helpers                     suivi, descriptions, notifications, data (export, import), connexion (address, password,
//   3. the account, loaded once          double authentication), appareils (connections, tokens), apropos, suppression
//   4. the menu (which panel is open) 6. start
// A form carries the data it sends and the script submits it; the one exception is the description rows, which save as they are typed.

/* ===========================================================================
** 1. THE TEXT THE USER READS
** ======================================================================== */

const settings_text = {

	// "1 jour" / "6 jours": French agrees the noun from 2 on, so 0 and 1 stay singular
	count : function (n, one, many) {
		return `${n} ${n > 1 ? many : one}`;
	},

	saved : "✅ enregistré",

	/* --- the keywords (descriptions) --------------------------------------- */
	desc_none : "Vous n’avez aucune sensation ou observation renseignée dans l’application.",
	desc_name_empty : "le nom ne peut pas être vide",
	desc_duplicate : "ce mot clé existe déjà",
	desc_deleted : "✅ supprimé",
	desc_count_title : "Nombre de jours associés à ce mot clé",
	desc_type_name : {0: "Type à définir", 1: "Observation", 2: "Sensation"},
	desc_confirm_delete_many : function (name, count) {
		return `Êtes-vous sûr de vouloir supprimer la description « ${name} » ?\n\nLes jours auxquels ${name} a été associé perdront cette information de manière irréversible. La description « ${name} » est actuellement associée à ${count} dates différentes.`;
	},
	desc_confirm_delete_one : function (name) {
		return `Êtes-vous sûr de vouloir supprimer la description « ${name} » ?\n\nLes jours auxquels ${name} a été associé perdront cette information de manière irréversible. La description « ${name} » est actuellement associée à une date.`;
	},

	/* --- a description moved into the comments of its days ------------------ */
	desc_comment_button_title : "Déplacer cette description dans le commentaire de tous les jours associés, puis la supprimer (action définitive)",
	desc_delete_button_title : "Supprimer cette description sans rien copier",
	desc_comment_syncing : "⏳ mise à jour de vos données…",
	desc_comment_sync_failed : "action impossible : vos données n’ont pas pu être mises à jour (connexion ?).",
	desc_comment_gone : "Cette description n’existe plus.",
	desc_comment_unsaved : "Le nom affiché n’est pas (encore) enregistré : attendez « ✅ enregistré » ou corrigez-le, puis recommencez.",
	desc_comment_nothing : function (name) {
		return `Aucun jour n’est associé à « ${name} » : il n’y a rien à déplacer.\n\nPour supprimer cette description, utilisez le bouton ❌.`;
	},
	// refused before anything is written: the description would be deleted, and these days would lose it
	desc_comment_too_long : function (name, dates, max) {
		let n = dates.length;
		let shown = dates.slice(0, 5).map(function (date) { return date.split("-").reverse().join("/"); }).join(", ") + (n > 5 ? ", …" : "");
		return `Rien n’a été modifié : la description « ${name} » reste en place.\n\n${n > 1 ? `${n} jours ont` : "1 jour a"} un commentaire trop long pour y ajouter « ${name} » (${max} caractères au plus) : ${shown}.\n\nRaccourcissez ${n > 1 ? "ces commentaires" : "ce commentaire"} dans le tableau, puis recommencez.`;
	},
	// asked before the move: it cannot be taken back
	desc_comment_confirm : function (name, plan) {
		let n = plan.change.length;
		let kept = plan.kept.length;
		let text;
		if (n > 0) {
			text = `Déplacer « ${name} » dans le commentaire de ${settings_text.count(n, "jour", "jours")} ?\n\n`
				+ `Sur ${n > 1 ? "chacun de ces jours" : "ce jour"}, « ${name} » sera ajouté à la fin du commentaire, après un « | » si le jour en a déjà un. Ensuite la description « ${name} » sera supprimée : elle ne sera plus associée à aucun jour.\n\n`
				+ `⚠️ Action définitive, sans retour en arrière : le texte ajouté fait partie du commentaire et ne se distingue plus du vôtre, et la description ne pourra pas être rétablie.`;
			if (kept == 1) text += `\n\n1 jour a déjà « ${name} » dans son commentaire : son commentaire ne sera pas modifié, il perdra seulement la description.`;
			else if (kept > 1) text += `\n\n${kept} jours ont déjà « ${name} » dans leur commentaire : leurs commentaires ne seront pas modifiés, ils perdront seulement la description.`;
		}
		else {
			text = `Supprimer la description « ${name} » ?\n\n`
				+ `Elle figure déjà dans le commentaire de ${settings_text.count(kept, "jour", "jours")} auxquels elle est associée : les commentaires ne seront pas modifiés, la description sera seulement supprimée.\n\n`
				+ `⚠️ Action définitive, sans retour en arrière.`;
		}
		return text;
	},
	desc_comment_progress : function (done, total) {
		return `⏳ ${done}/${total}`;
	},
	desc_comment_deleting : "⏳ suppression de la description…",
	desc_comment_done : "✅ déplacée",
	// what the row says once the description is gone
	desc_comment_moved : function (name, plan) {
		if (plan.change.length == 0) return `✅ « ${name} » figurait déjà dans le commentaire de ${settings_text.count(plan.kept.length, "jour", "jours")} : la description a été supprimée.`;
		return `✅ La description « ${name} » a été déplacée dans le commentaire de ${settings_text.count(plan.change.length, "jour", "jours")}, puis supprimée.`;
	},
	desc_comment_offline : "envoi interrompu : les changements sont gardés sur cet appareil et partiront dès que la connexion revient. La description n’est pas supprimée : relancez l’action ensuite. Gardez cette page ouverte.",
	desc_comment_refused : function (done, refused) {
		return `${settings_text.count(done, "jour modifié", "jours modifiés")}, ${settings_text.count(refused, "refusé", "refusés")} par le serveur (modifié depuis un autre appareil ?). La description n’est pas supprimée : relancez l’action pour reprendre ${refused > 1 ? "ces jours" : "ce jour"}.`;
	},
	desc_comment_delete_failed : "les commentaires sont écrits, mais la description n’a pas pu être supprimée. Relancez l’action pour la supprimer.",

	/* --- export ----------------------------------------------------------- */
	export_errors : {
		no_data_in_period : "aucune observation sur cette période.",
		invalid_range : "la 1ère date ne peut pas être après la 2ème.",
		invalid_date : "dates non valides.",
		invalid_value : "format non valide.",
		missing_field : "dates vides.",
	},

	/* --- NFP import: the form ---------------------------------------------- */
	import_pick_file : "choisissez un fichier.",
	import_unreadable : "fichier illisible.",
	import_checking : "⏳ vérification…",
	import_writing : "⏳ import…",
	import_but_check : "🔍 Vérifier le fichier",
	import_but_check_busy : "🔍 Vérification…",
	import_but_submit : "📥 Importer",
	import_but_submit_busy : "📥 Import…",
	// asked before a real import that is allowed to replace what is already there, since that is the only thing this form can destroy
	import_confirm_override : function (filename) {
		return `Importer « ${filename} » en écrasant les jours déjà renseignés ?\n\nLes journées de votre tableau dont la date figure dans le fichier seront entièrement remplacées, et leur contenu actuel sera définitivement perdu.\n\nPour savoir lesquelles sont concernées avant de vous décider, annulez et utilisez « Vérifier le fichier » : la simulation n’écrit rien.`;
	},

	/* --- NFP import: the report -------------------------------------------- */
	import_head_check : "🔍 Simulation : rien n’a été écrit dans votre tableau.",
	import_head_done : "✅ Import terminé.",
	import_head_refused : "❌ Fichier refusé : rien n’a été écrit dans votre tableau.",
	import_source : function (version, app) {
		return app ? `Fichier NFP ${version}, écrit par ${app}.` : `Fichier NFP ${version}.`;
	},
	import_read : function (cycles, days) {
		return `${settings_text.count(cycles, "cycle lu", "cycles lus")}, ${settings_text.count(days, "jour lu", "jours lus")}.`;
	},
	import_nothing_to_write : "Aucune journée renseignée dans ce fichier : il n’y a rien à importer.",
	import_back_to_timeline : "👈 Revenez aux cycles pour voir vos données.",

	// the per-day outcomes. Each heading is the summary line of a foldable list of dates.
	import_days_created : function (n, dry) {
		return dry
			? `${settings_text.count(n, "jour sera créé", "jours seront créés")} dans votre tableau.`
			: `${settings_text.count(n, "jour créé", "jours créés")}.`;
	},
	import_days_overwritten : function (n, dry) {
		return dry
			? `${settings_text.count(n, "jour déjà renseigné sera entièrement remplacé", "jours déjà renseignés seront entièrement remplacés")}.`
			: `${settings_text.count(n, "jour remplacé", "jours remplacés")}.`;
	},
	import_days_skipped : function (n, dry) {
		return dry
			? `${settings_text.count(n, "jour déjà renseigné sera ignoré", "jours déjà renseignés seront ignorés")} : cochez « Écraser les jours déjà renseignés » pour les remplacer.`
			: `${settings_text.count(n, "jour ignoré", "jours ignorés")} car déjà renseignés : cochez « Écraser les jours déjà renseignés » et relancez l’import pour les remplacer.`;
	},
	import_descriptions : function (n, dry) {
		return dry
			? `${settings_text.count(n, "nouvelle sensation/observation sera créée", "nouvelles sensations/observations seront créées")}.`
			: `${settings_text.count(n, "sensation/observation créée", "sensations/observations créées")}.`;
	},

	// the three "nothing is dropped silently" lists the API answers with
	import_warnings : function (n) {
		return `⚠️ ${settings_text.count(n, "avertissement", "avertissements")} : les données sont importées, mais lisez ceci.`;
	},
	import_mapped : function (n) {
		return `ℹ️ ${settings_text.count(n, "information importée de façon simplifiée", "informations importées de façon simplifiée")}.`;
	},
	import_ignored : function (n) {
		return `ℹ️ ${settings_text.count(n, "information du fichier que cette application ne sait pas conserver", "informations du fichier que cette application ne sait pas conserver")}.`;
	},

	// a refused file
	import_issues : function (shown, total) {
		return shown < total
			? `❌ ${total} problèmes, dont les ${shown} premiers :`
			: `❌ ${settings_text.count(total, "problème", "problèmes")} :`;
	},
	import_duplicates : function (n) {
		return `❌ ${settings_text.count(n, "date est présente deux fois dans le fichier", "dates sont présentes deux fois dans le fichier")} : deux de ses cycles se chevauchent, et la journée appartiendrait aux deux. Le fichier doit être corrigé avant d’être importé.`;
	},
	import_duplicates_list : function (n) {
		return settings_text.count(n, "date en double", "dates en double");
	},
	import_list_more : function (n) {
		return `… et ${settings_text.count(n, "autre", "autres")}.`;
	},

	/* --- POST /api/v2/account: what the server refuses when the password is asked again (its own words are English); the keys are the
	   codes of the problems (moncycle_store.error_pick), or "field:code" --- */
	account_errors : {
		invalid_password : "mot de passe incorrect",
		password_required : "le mot de passe est nécessaire",
		totp_required : "un code d’authentification multifacteur est nécessaire",
		totp_invalid : "le code d’authentification multifacteur est incorrect",
		invalid_email : "l’adresse e-mail n’est pas valide",
		email_unchanged : "c’est déjà l’adresse de connexion de votre compte",
		"notificationEmails" : "les adresses doivent être valides, séparées par « ; », 5 au plus",
		password_too_short : "le nouveau mot de passe est trop court (10 caractères au moins)",
		password_too_long : "le nouveau mot de passe est trop long (72 octets au plus)",
		password_invalid : "le nouveau mot de passe contient un caractère interdit",
		password_unchanged : "le nouveau mot de passe est identique à l’ancien",
		demo_account : "indisponible sur un compte de démo",
		read_only_token : "cette connexion est en lecture seule",
		account_locked : "compte temporairement bloqué après trop de tentatives échouées, réessayez plus tard",
	},
	// what any request can come back with, whatever the page was doing; the keys are error codes, then "field:code" / issue codes
	common_errors : {
		unauthorized : "votre session a pris fin, reconnectez-vous",
		rate_limited : "trop de tentatives, réessayez dans quelques instants",
		maintenance : "le service est en maintenance, réessayez plus tard",
		database_unreachable : "le service est momentanément indisponible, réessayez plus tard",
		schema_mismatch : "le service est en cours de mise à jour, réessayez plus tard",
		unexpected_error : "le serveur a rencontré un problème, réessayez plus tard",
		cross_origin_refused : "requête refusée : ouvrez l’application depuis son adresse habituelle",
		demo_account : "indisponible sur un compte de démo",
		read_only_token : "cette connexion est en lecture seule",
		account_locked : "compte temporairement bloqué après trop de tentatives échouées, réessayez plus tard",
		stale_write : "une version plus récente existe déjà, rechargez la page",
		not_found : "élément introuvable : il a peut-être déjà été supprimé",
		unsupported_media_type : "requête non comprise par le serveur",
		invalid_json : "requête non comprise par le serveur",
	},
	error_network : "pas de connexion au serveur : vérifiez votre connexion et réessayez",
	error_server : function (status) {
		return `le serveur a répondu de façon inattendue (code ${status}), réessayez plus tard`;
	},
	// the keywords' own refusals
	desc_errors : {
		duplicate_name : "ce mot clé existe déjà avec ce type",
	},
	not_saved : "pas enregistré : ",
	account_not_loaded : "vos réglages n’ont pas pu être chargés : ",
	password_mismatch : "le nouveau mot de passe et sa confirmation ne sont pas identiques.",
	email_link_sent : "✅ Lien envoyé à la nouvelle adresse. Le changement aura lieu quand vous l’aurez ouvert.",
	email_link_not_sent : "le mail n’a pas pu être envoyé, réessayez plus tard.",
	password_changed : "✅ Mot de passe changé.",
	notification_emails_saved : "✅ Adresses enregistrées.",

	/* --- connections and access tokens ---------------------------------------- */
	tokens_current : "cet appareil",
	tokens_scope : {readOnly : "lecture seule", readWrite : "lecture et écriture"},
	tokens_created : "Créée le",
	tokens_last_used : "Dernière utilisation le",
	tokens_never_used : "Jamais utilisée",
	tokens_expires : "Expire le",
	tokens_revoke : "Déconnecter",
	tokens_prefix_title : "Début du jeton: il permet de distinguer les connexions d'une même application.",
	tokens_none : "Aucune connexion.",
	tokens_confirm_revoke_all : "Déconnecter tous vos autres appareils et applications ?\n\nCet appareil reste connecté. Les autres devront se reconnecter.",
	tokens_revoked : function (n) {
		return `✅ ${settings_text.count(n, "connexion supprimée", "connexions supprimées")}`;
	},
	token_creating : "⏳ création…",
	token_copy_failed : "Copie impossible: sélectionnez le jeton et copiez-le à la main.",
	token_copied : "✅ copié",
	token_errors : {
		missing_field : "Mot de passe manquant.",
		invalid_email : "L'adresse e-mail n'est pas valide.",
		invalid_credentials : "Mot de passe incorrect.",
		totp_required : "Un code d'authentification multifacteur est requis.",
		totp_invalid : "Le code d'authentification multifacteur est incorrect.",
		captcha_required : "Captcha manquant ou incorrect.",
		captcha_expired : "Le captcha a expiré, recopiez le nouveau.",
		captcha_unavailable : "Le captcha est indisponible pour le moment, réessayez plus tard.",
		account_locked : "Compte temporairement bloqué après trop de tentatives échouées, réessayez plus tard.",
		rate_limited : "Trop de tentatives depuis ce réseau, réessayez plus tard.",
	},

	/* --- double authentication ------------------------------------------------ */
	totp_copy_failed : "Copie impossible: autorisez l’accès au presse-papiers, ou sélectionnez le secret à la main.",

	/* --- account deletion ----------------------------------------------------- */
	delete_confirm : function (nickname) {
		return `${nickname}, êtes-vous sûr de vouloir supprimer votre compte ainsi que toutes vos données ? Cette action est irréversible. 😟`;
	},
	delete_done : function (nickname) {
		return `${nickname}, votre compte a bien été supprimé. 😢💔`;
	},
	delete_failed : function (nickname, reason) {
		return `${nickname}, votre compte n’a pas été supprimé : ${reason}`;
	},
};


/* ===========================================================================
** 2. SMALL HELPERS
** ======================================================================== */

// The API takes and gives JSON: this is the one place a request is made. `body` is sent as JSON when there is one.
function settings_api(method, url, body) {
	const options = {type: method, url: url};
	if (body !== undefined) {
		options.contentType = "application/json";
		options.data = JSON.stringify(body);
	}
	return $.ajax(options);
}

// The value of a field of a form by its name (the checked one of a group of radios).
function settings_read(form, name) {
	return form.find(`[name="${name}"]:not([type=radio]), [name="${name}"][type=radio]:checked`).val();
}

function settings_is_checked(form, name) {
	return form.find(`[name="${name}"]`).is(":checked");
}

// A session that is over (401) goes back to the sign-in page. Only for what the page loads by itself: a 401 `invalid_password` on a form
// is a wrong password, not the end of the session.
function settings_leave_if_signed_out(jqXHR) {
	if (jqXHR.status == 401) {
		moncycle_store.clear_storage();
		window.location.replace("/auth");
	}
}

// What went wrong, in French, for any failed request: the page's own words for the error (`own_map`, keyed like moncycle_store.error_pick),
// then the common ones, then no answer at all, then a server that did not speak the API's JSON, and at last the server's own message (English)
// with every problem of a validation_failed. Never empty, and without a final full stop: the caller adds its own punctuation.
function settings_error(jqXHR, own_map = {}) {
	const known = moncycle_store.error_pick(jqXHR, own_map) || moncycle_store.error_pick(jqXHR, settings_text.common_errors);
	if (known) return known.replace(/\.$/, "");
	if (jqXHR.status === 0) return settings_text.error_network;
	if (!(jqXHR.responseJSON || {}).error) return settings_text.error_server(jqXHR.status);
	return moncycle_store.error_text(jqXHR).replace(/\.$/, "");
}

// The same for what a refusal of POST /api/v2/account says.
function settings_account_error(jqXHR) {
	return settings_error(jqXHR, settings_text.account_errors);
}

// A session that has ended answers 401 `unauthorized` to anything: back to the sign-in page, whichever request it was. (A 401 with another code
// is a wrong password or code on a form: the session goes on.)
$(document).ajaxError(function (event, jqXHR) {
	const error = (jqXHR.responseJSON || {}).error || {};
	if (jqXHR.status == 401 && error.code == "unauthorized") settings_leave_if_signed_out(jqXHR);
});

// Report lines from /api/v2/import quote what was in the file -- a sensation name, the app that wrote it -- so they are never dropped
// into HTML as they come.
function settings_escape_html(text) {
	return $("<span>").text(text === null || text === undefined ? "" : text).html();
}

// Every answer of the page -- a refusal, a success, "in progress" -- goes in a line of class form_msg, next to what it is about: under a
// form's button, under a control that saves by itself, in a keyword's row. kind: "busy", "ok" (green), "error" (red, with its ❌); "" empties
// the line. The message is text: the server's words can quote what the user typed (duplicate_name echoes the label), and are never read as
// HTML. A success of a control that saves by itself is not left there for good: it goes after a moment (`fades`).
function settings_say(line, kind, message, fades = false) {
	clearTimeout(line.data("fade"));
	line.removeClass("vert rouge").empty();
	if (kind === "busy") line.text(message);
	if (kind === "ok") line.addClass("vert").text(message);
	if (kind === "error") line.addClass("rouge").text("❌ " + message);
	if (kind === "ok" && fades) line.data("fade", setTimeout(function () { line.empty(); }, 2500));
}

// The line of a form.
function settings_form_say(form, kind, message) {
	settings_say(form.find(".form_msg").last(), kind, message);
}

// Something changed on the server that the local copy (js/store.js) shows: the copy is brought up to date once the changes stop coming
// (a name typed letter by letter is one change per letter).
let settings_sync_timer = null;
function settings_sync_later() {
	clearTimeout(settings_sync_timer);
	settings_sync_timer = setTimeout(function () { moncycle_store.sync().catch(function () { }); }, 1500);
}

// 2026-09-20 in the user's own time zone (toISOString would be UTC: the day before, late in the evening)
function settings_today() {
	const now = new Date();
	const two = function (n) { return String(n).padStart(2, "0"); };
	return `${now.getFullYear()}-${two(now.getMonth() + 1)}-${two(now.getDate())}`;
}

function settings_french_date(ymd) {
	return ymd.split("-").reverse().join("/");
}

function settings_french_time(iso) {
	return iso ? new Date(iso).toLocaleString("fr-FR", {dateStyle: "short", timeStyle: "short"}) : "";
}

// A form that changes the account with the password asked again (an address, the password): POST /api/v2/account, its answer under the
// button. `build_payload(form)` reads the form when it is submitted; `on_done(ret)` says what went well; the server's refusal is said
// here. `check(form)` (optional) answers a text when the form must not be sent at all.
function settings_confirmed_change(form, build_payload, on_done, check) {
	form.on("submit", function (event) {
		event.preventDefault();
		const button = form.find("button[type=submit]");
		settings_form_say(form, "", "");
		const problem = check ? check(form) : "";
		if (problem) return settings_form_say(form, "error", problem);
		button.prop("disabled", true);
		settings_api("POST", "api/v2/account", build_payload(form)).done(function (ret) {
			on_done(ret);
		}).fail(function (jqXHR) {
			console.error(jqXHR);
			settings_form_say(form, "error", settings_account_error(jqXHR));
		}).always(function () {
			button.prop("disabled", false);
		});
	});
}


/* ===========================================================================
** 3. THE ACCOUNT: read once, kept here, and kept up to date by what the panels save
** ======================================================================== */

let settings_account = {};

function settings_is_demo() {
	return moncycle_store.is_demo(settings_account.userId);
}

// Fills the panels that show something of the account, and hides the entries that make no sense for it.
function settings_show_account(account) {
	settings_account = account;
	const demo = settings_is_demo();

	$("#name").text(account.nickname);
	document.title = "moncycle.app - réglages " + account.nickname;
	$("#warning_demo").prop("hidden", !demo);
	// a demo account cannot change its sign-in, its connections, its mails, and cannot be deleted
	["connexion", "appareils", "notifications", "suppression"].forEach(function (panel) { $("#menu_" + panel).prop("hidden", demo); });
	settings_show_method_entries();

	settings_fill_suivi(account);
	settings_fill_notifications(account);
	settings_fill_data(account);
	settings_fill_connexion(account);
	settings_fill_about(account);
	settings_show_panel();
}

// FertilityCare has no sensation/observation keywords of its own to manage
function settings_show_method_entries() {
	$("#menu_descriptions").prop("hidden", settings_account.method == "fertilityCare");
}


/* ===========================================================================
** 4. THE MENU: which panel is open is the address (#suivi); no address is the menu
** ======================================================================== */

// what a panel loads each time it opens
const settings_on_open = {
	descriptions : function () { settings_descriptions_load(); },
	appareils : function () { settings_tokens_load(); },
};

function settings_show_panel() {
	let name = location.hash.slice(1);
	const entry = $("#menu_" + name);
	if (entry.length == 0 || entry.prop("hidden")) name = "";

	$("#menu").prop("hidden", name !== "");
	$(".panel").prop("hidden", true);
	if (name !== "") {
		$("#panel_" + name).prop("hidden", false);
		if (settings_on_open[name] && settings_account.userId !== undefined) settings_on_open[name]();
	}
	window.scrollTo(0, 0);
}


/* ===========================================================================
** 5a. MON SUIVI: nickname, method, temperature, display, birth year, research. Each control saves by itself.
** ======================================================================== */

// What each autosaved control sends, by its name. The method and the temperature make one setting of the account: each change sends both.
const settings_autosave = {
	nickname : function (form) { return {nickname: settings_read(form, "nickname")}; },
	method : function (form) { return {method: settings_read(form, "method"), temperatureTracking: settings_is_checked(form, "temperature_tracking")}; },
	temperature_tracking : function (form) { return settings_autosave.method(form); },
	timeline_asc : function (form) { return {timelineAscending: settings_is_checked(form, "timeline_asc")}; },
	age : function (form) { return {birthYear: parseInt(settings_read(form, "age"))}; },
	research : function (form) { return {research: settings_is_checked(form, "research")}; },
	auto_mail_export : function (form) { return {autoMailExport: settings_is_checked(form, "auto_mail_export")}; },
};

// The saves go one after the other, so that the server applies them in the order they were made: the method and the temperature are two
// controls of one setting, and two quick clicks would otherwise be free to arrive in reverse.
let settings_save_queue = Promise.resolve();
function settings_save(payload, line) {
	settings_say(line, "busy", "⏳");
	settings_save_queue = settings_save_queue.then(function () {
		return settings_api("POST", "api/v2/account", payload).then(function (ret) {
			settings_say(line, "ok", settings_text.saved, true);
			Object.assign(settings_account, ret.data || {});
			if (ret.data && ret.data.nickname) $("#name").text(ret.data.nickname);
			settings_show_method_entries();
			settings_sync_later();
		}, function (jqXHR) {
			console.error(jqXHR);
			settings_say(line, "error", settings_text.not_saved + settings_account_error(jqXHR));
			// the controls show what is saved, not what was refused
			settings_fill_suivi(settings_account);
			settings_fill_notifications(settings_account);
		});
	});
}

function settings_autosave_changed(event) {
	const field = event.target;
	const form = $(field.form);
	// a nickname being typed is not saved while it is empty
	if (!field.checkValidity()) return;
	const build_payload = settings_autosave[field.name];
	// its answer goes in the line of its own group (the nearest fieldset, field or form that has one)
	if (build_payload) settings_save(build_payload(form), $(field).closest("fieldset, .field, form").find(".form_msg").first());
}

function settings_setup_autosave() {
	const forms = $("#f_suivi, #f_auto_mail");
	// (the nickname is saved as it is typed, below, not again when the field is left)
	forms.on("change", "input:not([name=nickname]), select", settings_autosave_changed);
	// the nickname is saved as it is typed, once the typing pauses
	let timer = null;
	$("#i_nickname").on("input", function (event) {
		clearTimeout(timer);
		timer = setTimeout(function () { settings_autosave_changed(event); }, 600);
	});
	forms.on("submit", function (event) { event.preventDefault(); });
}

function settings_fill_suivi(account) {
	const form = $("#f_suivi");
	form.find("[name=nickname]").val(account.nickname);
	form.find("[name=method]").each(function () { this.checked = this.value == account.method; });
	form.find("[name=temperature_tracking]").prop("checked", !!account.temperatureTracking);
	form.find("[name=timeline_asc]").prop("checked", !!account.timelineAscending);
	form.find("[name=research]").prop("checked", !!account.research);
	settings_fill_birth_years(form.find("[name=age]"), account.birthYear);
}

// The years are asked in blocks of five: "entre 1985 et 1989", saved as 1985. The stored year can be any year (1992): it selects the block
// it falls in, and a block outside the usual range is added rather than lost.
function settings_fill_birth_years(select, birth_year) {
	const this_year = (new Date()).getFullYear();
	const blocks = [];
	for (let year = (this_year - this_year % 5) - 75; year < this_year - 5; year += 5) blocks.push(year);
	const own_block = birth_year ? birth_year - birth_year % 5 : null;
	if (own_block !== null && !blocks.includes(own_block)) blocks.push(own_block);
	blocks.sort(function (a, b) { return a - b; });

	select.empty();
	if (own_block === null) select.append(new Option("Choisir…", "", true, true)).find("option:first").prop("disabled", true);
	blocks.forEach(function (year) {
		select.append(new Option(`entre ${year} et ${year + 4}`, year, false, year == own_block));
	});
}


/* ===========================================================================
** 5b. SENSATIONS & OBSERVATIONS: the keywords of the cervical mucus method
** ======================================================================== */

// The API says "sensation"; the page keeps the integer of the old forms.
const settings_desc_type_to_int = {"undefined": 0, "observation": 1, "sensation": 2};
const settings_desc_type_to_api = {0: "undefined", 1: "observation", 2: "sensation"};
// shown before a keyword's name, so that its type is known without opening it
const settings_desc_type_icon = {0: "❓", 1: "👀", 2: "🧠"};

let settings_descriptions = [];   // {id, name, type, use_count, sent}; `sent` is the last name and type sent, before the answer
let settings_desc_last_edit = 0;
let settings_desc_move_run = null;   // while a move into the comments runs: {dates: {date: true}, done, refused: [dates]}

function settings_desc_from_api(d) {
	return {id: d.id, name: d.name, type: settings_desc_type_to_int[d.type] || 0, use_count: d.useCount};
}

function settings_desc_find(id) {
	return settings_descriptions.find(function (known) { return known.id === id; });
}

// another_than: the keyword being edited, which is not a duplicate of itself (its saved name can be the one typed back while the answer
// to the change that took it away is still on its way)
function settings_desc_exists(name, type, another_than) {
	return settings_descriptions.some(function (known) { return known.id !== another_than && known.name == name && known.type == type; });
}

function settings_descriptions_load() {
	return $.get("api/v2/description").done(function (ret) {
		settings_descriptions = ret.data.map(settings_desc_from_api);
		settings_desc_draw();
	}).fail(function (jqXHR) {
		settings_say($("#desc_msg"), "error", settings_error(jqXHR));
	});
}

function settings_desc_draw() {
	const container = $("#desc_froms_container").empty();
	if (settings_descriptions.length == 0) {
		container.append($("<i>", {class: "tech_info"}).text(settings_text.desc_none));
		return;
	}
	settings_descriptions.forEach(function (description) { container.append(settings_desc_row(description)); });
}

// A keyword is a form: its line (type's emoji, name, count) and, under it while it is the one being edited, its type and its two buttons.
// Built with the DOM, never as HTML: a name is free text and would break out of value="...".
function settings_desc_row(description) {
	const type_select = $("<select>", {name: "type", "aria-label": "Type"}).append(
		$("<option>", {value: "2", selected: description.type == 2}).text("🧠 Sensation"),
		$("<option>", {value: "1", selected: description.type == 1}).text("👀 Observation"),
		$("<option>", {value: "0", selected: description.type == 0, disabled: true}).text("❓ à définir"));
	const line = $("<div>", {class: "desc_line"}).append(
		$("<span>", {class: "desc_icon", title: settings_text.desc_type_name[description.type]}).text(settings_desc_type_icon[description.type]),
		$("<input>", {class: "desc_name", type: "text", name: "name", maxlength: 256, title: description.name, "aria-label": "Nom"}).val(description.name),
		$("<span>", {class: "desc_count", title: settings_text.desc_count_title}).text(description.use_count));
	const actions = $("<div>", {class: "desc_actions"}).append(
		type_select,
		$("<button>", {type: "submit", name: "action", value: "comment", class: "desc_comment", title: settings_text.desc_comment_button_title, "aria-label": settings_text.desc_comment_button_title}).text("💬"),
		$("<button>", {type: "submit", name: "action", value: "delete", class: "desc_delete", title: settings_text.desc_delete_button_title, "aria-label": settings_text.desc_delete_button_title}).text("❌"));
	// the first submit button is the one Enter presses in the name field: it does nothing (the name is saved as it is typed)
	const enter_button = $("<button>", {type: "submit", name: "action", value: "none", hidden: true});
	const message = $("<p>", {class: "form_msg", role: "status"});
	return $("<form>", {class: "desc_row", id: "desc_row_" + description.id}).data("id", description.id).append(enter_button, line, actions, message);
}

// Name or type changed: save it. Only a difference is sent (focus, Tab and arrows make events with none): what is typed is compared with
// what was sent last, or else what is saved.
function settings_desc_save(row) {
	const id = row.data("id");
	const known = settings_desc_find(id);
	const name = row.find(".desc_name").val();
	// the whole name is in the tooltip: the field shows what fits
	row.find(".desc_name").attr("title", name);
	// the option selected, not the value of the select: the "à définir" option is disabled, and the select answers nothing for it
	const type = parseInt(row.find("select option:selected").val());
	const last = known.sent || known;
	if (last.name == name && last.type == type) return;

	const edit = ++settings_desc_last_edit;
	const line = row.find(".form_msg");
	if (name.trim() == "") return settings_say(line, "error", settings_text.desc_name_empty);
	if (settings_desc_exists(name, type, id)) return settings_say(line, "error", settings_text.desc_duplicate);

	settings_say(line, "busy", "⏳");
	known.sent = {name: name, type: type};
	settings_api("POST", "api/v2/description/" + encodeURIComponent(id), {name: name, type: settings_desc_type_to_api[type]}).done(function () {
		if (edit == settings_desc_last_edit) settings_say(line, "ok", settings_text.saved, true);
		// what the page holds of the keyword follows what was saved (the duplicate check, the emoji)
		known.name = name;
		known.type = type;
		row.find(".desc_icon").text(settings_desc_type_icon[type]).attr("title", settings_text.desc_type_name[type]);
		settings_sync_later();
	}).fail(function (jqXHR) {
		console.error(jqXHR);
		if (edit == settings_desc_last_edit) {
			settings_say(line, "error", settings_error(jqXHR, settings_text.desc_errors));
			// refused or lost: the next key sends it again
			known.sent = null;
		}
	});
}

function settings_desc_delete(row) {
	const id = row.data("id");
	const known = settings_desc_find(id);
	if (known.use_count > 1 && !confirm(settings_text.desc_confirm_delete_many(known.name, known.use_count))) return;
	if (known.use_count == 1 && !confirm(settings_text.desc_confirm_delete_one(known.name))) return;
	settings_say(row.find(".form_msg"), "busy", "⏳");
	settings_api("DELETE", "api/v2/description/" + encodeURIComponent(id)).done(function () {
		settings_descriptions = settings_descriptions.filter(function (other) { return other.id !== id; });
		row.remove();
		// the row is gone: the line of the list says it
		settings_say($("#desc_msg"), "ok", settings_text.desc_deleted, true);
		settings_sync_later();
	}).fail(function (jqXHR) {
		console.error(jqXHR);
		settings_say(row.find(".form_msg"), "error", settings_error(jqXHR, settings_text.desc_errors));
	});
}

// A keyword moved into the comment of its days, then deleted. All in the browser: the days come from the local copy (js/store.js),
// brought up to date first, and go back through its queue like any write of a day. Once the server has every one of them, the keyword is
// deleted. The server sees ordinary writes of days and one delete of a description.
async function settings_desc_move_to_comments(row) {
	if (settings_desc_move_run) return;
	const line = row.find(".form_msg");
	settings_desc_move_run = {dates: {}, done: 0, refused: [], line: line};
	const run = settings_desc_move_run;
	const id = row.data("id");
	const button = row.find(".desc_comment");
	let button_after = "💬";
	button.prop("disabled", true).text("⏳");
	settings_say(line, "busy", settings_text.desc_comment_syncing);
	try {
		// the days to change are the ones the server holds now
		try { await moncycle_store.sync(); }
		catch (e) {
			settings_say(line, "error", settings_text.desc_comment_sync_failed);
			return;
		}
		settings_say(line, "", "");
		const description = moncycle_store.descriptions.find(function (known) { return known.id === id; });
		if (!description) return alert(settings_text.desc_comment_gone);
		// the name that will be written is the one saved, which must be the one the user sees
		if (description.name !== row.find(".desc_name").val()) return alert(settings_text.desc_comment_unsaved);
		const plan = moncycle_store.plan_description_to_comments(description);
		// all or nothing: a day that cannot take the name would lose the description with the others
		if (plan.too_long.length) return alert(settings_text.desc_comment_too_long(description.name, plan.too_long, moncycle_store.comment_max_chars));
		if (plan.change.length + plan.kept.length == 0) return alert(settings_text.desc_comment_nothing(description.name));
		if (!confirm(settings_text.desc_comment_confirm(description.name, plan))) return;

		if (plan.change.length) {
			plan.change.forEach(function (one) { run.dates[one.date] = true; });
			settings_say(line, "busy", settings_text.desc_comment_progress(0, plan.change.length));
			moncycle_store.queue_comments(plan.change, new Date().toISOString().replace(/\.\d+Z$/, "Z"));
			try { await moncycle_store.flush(); }
			catch (e) { /* what the server could not take stays in the queue, and is sent again */ }
			// the description is deleted only when every day has the name in its comment
			const waiting = plan.change.filter(function (one) { return moncycle_store.pending[one.date]; }).length;
			const refused = run.refused.length;
			if (waiting || refused) {
				settings_say(line, "error", waiting ? settings_text.desc_comment_offline : settings_text.desc_comment_refused(plan.change.length - refused, refused));
				button_after = "⚠️";
				return;
			}
		}

		settings_say(line, "busy", settings_text.desc_comment_deleting);
		try { await moncycle_store.request("DELETE", "api/v2/description/" + encodeURIComponent(id)); }
		catch (jqXHR) {
			console.error(jqXHR);
			settings_say(line, "error", settings_text.desc_comment_delete_failed);
			button_after = "⚠️";
			return;
		}
		// the copy follows what the server did (the descriptions, the days that carried this one)
		try { await moncycle_store.sync(); }
		catch (e) { /* at the next sync */ }
		settings_descriptions = settings_descriptions.filter(function (other) { return other.id !== id; });
		// the row is a message now: what it said is in the comments
		row.attr("class", "desc_moved").removeAttr("id").removeData("id").empty().append(document.createTextNode(settings_text.desc_comment_moved(description.name, plan)));
		settings_say($("#desc_msg"), "ok", settings_text.desc_comment_done, true);
	}
	finally {
		settings_desc_move_run = null;
		button.prop("disabled", false).text(button_after);
		if (button_after != "💬") setTimeout(function () { button.text("💬"); }, 3000);
	}
}

function settings_setup_descriptions() {
	const container = $("#desc_froms_container");

	// What acts on a keyword shows under the one being edited, and stays while the focus or the pointer is in that row. Not :focus-within:
	// a click on a button does not focus it in Safari and Firefox on macOS, and the row would close under the finger.
	$(document).on("focusin mousedown", function (event) {
		const row = $(event.target).closest(".desc_row");
		$(".desc_row.desc_active").not(row).removeClass("desc_active");
		row.addClass("desc_active");
	});

	container.on("input", ".desc_name", function () { settings_desc_save($(this).closest(".desc_row")); });
	container.on("change", ".desc_actions select", function () { settings_desc_save($(this).closest(".desc_row")); });
	container.on("submit", ".desc_row", function (event) {
		event.preventDefault();
		const row = $(this);
		const action = event.originalEvent && event.originalEvent.submitter ? event.originalEvent.submitter.value : "";
		if (action == "comment") settings_desc_move_to_comments(row);
		else if (action == "delete") settings_desc_delete(row);
	});

	// the progress of a move: each day the queue sends
	moncycle_store.on("sent", function (detail) {
		const run = settings_desc_move_run;
		if (!run || !run.dates[detail.date]) return;
		run.done += 1;
		settings_say(run.line, "busy", settings_text.desc_comment_progress(run.done, Object.keys(run.dates).length));
	});
	moncycle_store.on("failed", function (detail) {
		if (settings_desc_move_run && settings_desc_move_run.dates[detail.date]) settings_desc_move_run.refused.push(detail.date);
	});

	$("#f_new_description").on("submit", function (event) {
		event.preventDefault();
		const form = $(this);
		const name = settings_read(form, "name");
		const type = parseInt(settings_read(form, "type"));
		if (settings_desc_exists(name, type)) return settings_form_say(form, "error", settings_text.desc_duplicate);
		settings_form_say(form, "busy", "⏳");
		settings_api("POST", "api/v2/description", {name: name, type: settings_desc_type_to_api[type]}).done(function () {
			settings_form_say(form, "ok", settings_text.saved);
			form[0].reset();
			settings_descriptions_load();
			settings_sync_later();
		}).fail(function (jqXHR) {
			console.error(jqXHR);
			settings_form_say(form, "error", settings_error(jqXHR, settings_text.desc_errors));
		});
	});
}


/* ===========================================================================
** 5c. CYCLES PAR E-MAIL: the automatic mail of each cycle, and who gets it
** ======================================================================== */

function settings_fill_notifications(account) {
	$("#i_auto_mail_export").prop("checked", !!account.autoMailExport);
	$("#i_notification_emails").val(account.notificationEmails);
}

// The cycles are the owner's, so the password confirms a change of who receives them.
function settings_setup_notifications() {
	const form = $("#form_notification_emails");
	settings_confirmed_change(form, function () {
		return {notificationEmails: settings_read(form, "notification_emails"), password: settings_read(form, "password")};
	}, function () {
		form.find("[name=password]").val("");
		settings_form_say(form, "ok", settings_text.notification_emails_saved);
		settings_sync_later();
	});
}


/* ===========================================================================
** 5d. MES DONNÉES: export a period, import a file, the raw export
** ======================================================================== */

function settings_fill_data(account) {
	const today = settings_today();
	const form = $("#f_export_complexe");
	form.find("[name=from], [name=to]").attr("max", today);
	// nothing to export before the first day; with no day at all, nothing to export
	if (account.oldestDay) {
		form.find("[name=from], [name=to]").attr("min", account.oldestDay);
		$("#export_oldest_day").text(settings_french_date(account.oldestDay));
		$("#export_oldest").prop("hidden", false);
		// the whole history is what most exports want: ready to go
		form.find("[name=from]").val(account.oldestDay);
		form.find("[name=to]").val(today);
	}
}

function settings_setup_export() {
	const form = $("#f_export_complexe");
	const error_line = $("#export_err");

	$("#but_export_all").on("click", function () {
		form.find("[name=from]").val(form.find("[name=from]").attr("min"));
		form.find("[name=to]").val(settings_today());
	});

	form.on("submit", function (event) {
		event.preventDefault();
		const from = settings_read(form, "from");
		const to = settings_read(form, "to");
		const format = settings_read(form, "format");
		const show_error = function (reason) { settings_say(error_line, "error", reason); };
		settings_say(error_line, "", "");
		if (from == "" || to == "") return show_error(settings_text.export_errors.missing_field);
		if (new Date(from) > new Date(to)) return show_error(settings_text.export_errors.invalid_range);
		moncycle_store.download(`${form.attr("action")}/${encodeURIComponent(format)}?from=${from}&to=${to}`).catch(function (failure) {
			const own = settings_text.export_errors[failure.issues.length ? failure.issues[0].code : failure.code];
			const common = settings_text.common_errors[failure.code];
			show_error(own || common || (failure.status === 0 ? settings_text.error_network : failure.message) || "export impossible.");
		});
	});
}

/* --- the NFP import --------------------------------------------------------------
** One form, two buttons. "Vérifier le fichier" asks api/v2/import for a dry run: it runs every check, reads the account to see which of
** the file's days are already there, and reports what the write would do -- without writing. "Importer" does it for real. Both answer the
** same report, and on a dry run a refused file answers an error whose details carry the same lists, so one renderer draws all three
** outcomes.
*/

let settings_import_running = false;

// A foldable list, because these can run to thousands of dates. The limit keeps the page from drawing them all; the count in the heading
// is always the true one.
function settings_import_list(heading, items, limit) {
	items = items || [];
	if (items.length == 0) return "";
	const shown = items.slice(0, limit);
	let lines = shown.map(function (item) { return `<li>${settings_escape_html(item)}</li>`; }).join("");
	if (items.length > shown.length) lines += `<li class="label_info">${settings_text.import_list_more(items.length - shown.length)}</li>`;
	return `<details class="import_list"><summary>${heading}</summary><ul>${lines}</ul></details>`;
}

function settings_import_line(text, css) {
	return `<p class="import_line ${css || ""}">${text}</p>`;
}

// warnings / mappedFields / ignoredFields: what the file carries that is not simply stored as it was. The API fills them on an accepted
// file and, on a dry run, on a refused one too.
function settings_import_notes(source) {
	return settings_import_list(settings_text.import_warnings((source.warnings || []).length), source.warnings, 50)
		+ settings_import_list(settings_text.import_mapped((source.mappedFields || []).length), source.mappedFields, 50)
		+ settings_import_list(settings_text.import_ignored((source.ignoredFields || []).length), source.ignoredFields, 50);
}

// An accepted file, dry run or real: the same report either way, with the verbs put in the future tense on a dry run.
function settings_import_report(report) {
	const dry = !!report.dryRun;
	let html = settings_import_line(dry ? settings_text.import_head_check : settings_text.import_head_done, dry ? "import_head" : "import_head vert");
	html += settings_import_line(settings_escape_html(settings_text.import_source(report.schemaVersion, report.sourceApp)), "label_info");
	html += settings_import_line(settings_text.import_read(report.cyclesRead, report.daysRead), "label_info");
	if (report.daysToWrite == 0) html += settings_import_line(settings_text.import_nothing_to_write);

	// the three per-day outcomes, each a date list
	html += settings_import_list(settings_text.import_days_created((report.daysCreated || []).length, dry), report.daysCreated, 40);
	html += settings_import_list(settings_text.import_days_overwritten((report.daysOverwritten || []).length, dry), report.daysOverwritten, 40);
	html += settings_import_list(settings_text.import_days_skipped((report.daysSkipped || []).length, dry), report.daysSkipped, 40);
	if (report.descriptionsCreated > 0) html += settings_import_line(settings_text.import_descriptions(report.descriptionsCreated, dry), "label_info");

	html += settings_import_notes(report);
	if (!dry && report.daysWritten > 0) html += `<p class="import_line"><a href="/">${settings_text.import_back_to_timeline}</a></p>`;
	return html;
}

function settings_import_refusal(jqXHR) {
	const error = (jqXHR.responseJSON || {}).error || {};
	const details = error.details || {};
	let html = settings_import_line(settings_text.import_head_refused, "import_head rouge");
	// the API's own wording for the refusal, shown as it comes
	html += settings_import_line(settings_escape_html(error.message || settings_error(jqXHR)), "label_info");

	// Dates the file itself carries twice. They are among the issues below as well, but a list of its own is what makes the file fixable.
	const duplicated = details.duplicatedDaysInFile || [];
	if (duplicated.length > 0) {
		html += settings_import_line(settings_text.import_duplicates(duplicated.length), "rouge");
		html += settings_import_list(settings_text.import_duplicates_list(duplicated.length), duplicated, 40);
	}
	const issues = details.issues || [];
	if (issues.length > 0) html += settings_import_list(settings_text.import_issues(issues.length, details.issueCount || issues.length), issues, 50);

	return html + settings_import_notes(details);
}

function settings_import_run(dry_run) {
	if (settings_import_running) return;
	const form = $("#f_import");
	const file = form.find("[name=file]")[0].files[0];
	const line = form.find(".form_msg");
	if (!file) return settings_say(line, "error", settings_text.import_pick_file);
	const override = settings_is_checked(form, "override");
	if (!dry_run && override && !confirm(settings_text.import_confirm_override(file.name))) return;

	settings_import_running = true;
	$("#import_report").empty();
	settings_say(line, "busy", dry_run ? settings_text.import_checking : settings_text.import_writing);
	$("#i_import_check").prop("disabled", true).text(dry_run ? settings_text.import_but_check_busy : settings_text.import_but_check);
	$("#i_import_submit").prop("disabled", true).text(dry_run ? settings_text.import_but_submit : settings_text.import_but_submit_busy);
	const finish = function () {
		settings_import_running = false;
		settings_say(line, "", "");
		$("#i_import_check").prop("disabled", false).text(settings_text.import_but_check);
		$("#i_import_submit").prop("disabled", false).text(settings_text.import_but_submit);
	};

	// api/v2/import reads the file as the request body, so it goes up as it is -- no multipart form, and processData off so jQuery does not
	// try to encode the string.
	file.text().then(function (body) {
		const query = $.param({dryRun: dry_run, override: override, lastWriteClientUtc: (new Date()).toISOString()});
		$.ajax({type: "POST", url: `api/v2/import?${query}`, contentType: "application/json", data: body, processData: false}).done(function (ret) {
			finish();
			$("#import_report").html(settings_import_report(ret.data || {}));
			// A real import adds days and cycles: the local copy gets them with a sync.
			if (!dry_run) settings_sync_later();
		}).fail(function (jqXHR) {
			finish();
			settings_leave_if_signed_out(jqXHR);
			console.error(jqXHR);
			$("#import_report").html(settings_import_refusal(jqXHR));
		});
	}, function (err) {
		finish();
		console.error(err);
		settings_say(line, "error", settings_text.import_unreadable);
	});
}

function settings_setup_import() {
	$("#i_import_check").on("click", function () { settings_import_run(true); });
	$("#f_import").on("submit", function (event) {
		event.preventDefault();
		settings_import_run(false);
	});
	// another file: the report of the previous one is no longer about it
	$("#i_import_file").on("change", function () { $("#import_report").empty(); });
}


/* ===========================================================================
** 5e. CONNEXION ET SÉCURITÉ: login address, password, double authentication
** ======================================================================== */

function settings_fill_connexion(account) {
	$("#login_email_shown").text(account.loginEmail);
	$("#i_token_new_email").val(account.loginEmail);
	settings_show_totp_state(account.totpState == "active");
}

// the login address changes by a link sent to the NEW one: the change happens when it is used (verify_email.html)
function settings_setup_login_email() {
	const form = $("#form_login_email");
	settings_confirmed_change(form, function () {
		const payload = {loginEmail: settings_read(form, "new_login_email"), password: settings_read(form, "password")};
		const code = settings_read(form, "code");
		if (code) payload.code = code;
		return payload;
	}, function (ret) {
		form.find("[type=password], [type=number]").val("");
		if ((ret.meta || {}).loginEmailLinkSent) {
			form.find("[name=new_login_email]").val("");
			settings_form_say(form, "ok", settings_text.email_link_sent);
		}
		else {
			settings_form_say(form, "error", settings_text.email_link_not_sent);
		}
	});
}

function settings_setup_password() {
	const form = $("#form_mdp_change");
	settings_confirmed_change(form, function () {
		return {password: settings_read(form, "old_pw"), newPassword: settings_read(form, "pw1"), logoutOtherDevices: settings_is_checked(form, "logout_other_devices")};
	}, function () {
		form.find("[type=password]").val("");
		form.find("[name=logout_other_devices]").prop("checked", false);
		settings_form_say(form, "ok", settings_text.password_changed);
	}, function () {
		// the confirmation is checked here, before anything is sent
		return settings_read(form, "pw1") == settings_read(form, "pw2") ? "" : settings_text.password_mismatch;
	});
}

/* --- the double authentication (TOTP): off -> set-up (QR code, then a code and the password) -> on -> off ---- */

function settings_show_totp_state(active) {
	$("#totp_explications").prop("hidden", active);
	$("#totp_miseenpalce").prop("hidden", true);
	$("#totp_state").prop("hidden", !active);
	// the forms that sign in again ask for the code only when the account has one
	$("#login_email_code_field, #token_new_code_wrapper").prop("hidden", !active);
}

function settings_setup_totp() {
	const error_line = $("#totp_err_msg");
	let secret = "";

	$("#f_totp_start").on("submit", function (event) {
		event.preventDefault();
		const button = $("#i_activate_otp").prop("disabled", true);
		error_line.empty();
		settings_api("POST", $(this).attr("action")).done(function (ret) {
			secret = ret.data.initSecret;
			$("#totp_explications").prop("hidden", true);
			$("#totp_miseenpalce").prop("hidden", false);
			$("#totp_auto_conf").attr("href", ret.data.otpauth);
			// the QR code is an image the server draws, not text a user stored
			$("#totp_qrcode").html(ret.data.qrcode);
			$("#totp_copy_secret_ok").text("");
		}).fail(function (jqXHR) {
			settings_say(error_line, "error", settings_error(jqXHR));
		}).always(function () {
			button.prop("disabled", false);
		});
	});

	$("#totp_copy_secret").on("click", function () {
		navigator.clipboard.writeText(secret).then(function () {
			$("#totp_copy_secret_ok").text(settings_text.token_copied);
		}, function () {
			$("#totp_copy_secret_ok").text(settings_text.totp_copy_failed);
		});
	});

	// the code the application shows, and the password, confirm the set-up
	$("#f_totp_validation").on("submit", function (event) {
		event.preventDefault();
		const form = $(this);
		const body = {code: settings_read(form, "tmp_code"), password: settings_read(form, "password")};
		error_line.empty();
		form[0].reset();
		settings_api("POST", form.attr("action"), body).done(function (ret) {
			if (ret.data.totpState == "active") {
				settings_account.totpState = "active";
				settings_show_totp_state(true);
			}
		}).fail(function (jqXHR) {
			settings_say(error_line, "error", settings_error(jqXHR));
		});
	});

	$("#f_totp_desac").on("submit", function (event) {
		event.preventDefault();
		const form = $(this);
		const body = {code: settings_read(form, "tmp_code")};
		error_line.empty();
		form[0].reset();
		settings_api("POST", form.attr("action"), body).done(function (ret) {
			if (ret.data.totpState == "disabled") {
				settings_account.totpState = "disabled";
				settings_show_totp_state(false);
			}
		}).fail(function (jqXHR) {
			settings_say(error_line, "error", settings_error(jqXHR));
		});
	});
}


/* ===========================================================================
** 5f. APPAREILS ET ACCÈS: the connections, and the access tokens made by hand
** ======================================================================== */

// Everything the server says of a token (a name, a user agent) is the device's own word: text nodes only, never HTML.
function settings_token_card(token) {
	const head = $("<div>", {class: "token_title"}).append($("<b>").text(token.name));
	// the first characters of the token (null for an old one that started like another): what tells two connections of one app apart
	if (token.prefix) head.append($("<code>", {class: "token_prefix", title: settings_text.tokens_prefix_title}).text(token.prefix + "…"));
	head.append($("<span>", {class: "label_info"}).text(" — " + settings_text.tokens_scope[token.scope]));
	if (token.current) head.append($("<b>", {class: "vert"}).text(" (" + settings_text.tokens_current + ")"));

	// key / value rows: the date in bold, then the address, and the device's own words under it in small
	const facts = $("<dl>", {class: "kv"});
	const add_fact = function (key, value, ip, agent) {
		const cell = $("<dd>").text(value);
		const where = [ip, agent].filter(Boolean).join(" · ");
		if (where) cell.append($("<small>").text(where));
		facts.append($("<dt>").text(key), cell);
	};
	add_fact(settings_text.tokens_created, settings_french_time(token.createdAt), token.createdIp, token.createdUserAgent);
	if (token.lastUsedAt) add_fact(settings_text.tokens_last_used, settings_french_time(token.lastUsedAt), token.lastUsedIp, token.lastUsedUserAgent);
	else add_fact(settings_text.tokens_last_used, settings_text.tokens_never_used);
	if (token.expiresAt) add_fact(settings_text.tokens_expires, settings_french_time(token.expiresAt));
	const card = $("<div>", {class: "token_row"}).append(head, facts);

	// this connection is the one in use: it ends with the logout button, not here
	if (!token.current && token.prefix) {
		const revoke = $("<form>", {class: "f_token_revoke", action: "api/v2/token/" + encodeURIComponent(token.prefix), method: "delete"});
		revoke.append($("<button>", {type: "submit", class: "rouge"}).text("🗑️ " + settings_text.tokens_revoke));
		card.append(revoke);
	}
	return card;
}

function settings_tokens_load() {
	return $.get("api/v2/token").done(function (ret) {
		const container = $("#tokens_container").empty();
		if (ret.data.length === 0) container.text(settings_text.tokens_none);
		ret.data.forEach(function (token) { container.append(settings_token_card(token)); });
	}).fail(function (jqXHR) {
		settings_leave_if_signed_out(jqXHR);
		settings_say($("#tokens_msg"), "error", settings_error(jqXHR));
	});
}

// The form says what to revoke by its address: api/v2/token/{prefix} for one connection, api/v2/token?others=true for all the others.
function settings_tokens_revoke(form) {
	settings_say($("#tokens_msg"), "busy", "⏳");
	settings_api("DELETE", form.attr("action")).done(function (ret) {
		settings_say($("#tokens_msg"), "ok", settings_text.tokens_revoked(ret.data.revoked));
	}).fail(function (jqXHR) {
		settings_leave_if_signed_out(jqXHR);
		settings_say($("#tokens_msg"), "error", settings_error(jqXHR));
	}).always(settings_tokens_load);
}

function settings_setup_tokens() {
	$("#tokens_container").on("submit", ".f_token_revoke", function (event) {
		event.preventDefault();
		settings_tokens_revoke($(this));
	});
	$("#f_tokens_revoke_all").on("submit", function (event) {
		event.preventDefault();
		if (confirm(settings_text.tokens_confirm_revoke_all)) settings_tokens_revoke($(this));
	});

	// A token is made like a sign-in that sets no cookie: the password (and the code, and the captcha once the account asks for one) is
	// asked again.
	const form = $("#f_token_create");
	const captcha_field = $("#token_new_captcha_wrapper");
	const captcha = moncycle_captcha.attach({
		purpose: "login",
		email: $("#i_token_new_email"),
		image: $("#i_token_new_captcha_img"),
		token: $("#i_token_new_captcha_token"),
		answer: $("#i_token_new_captcha"),
		active: function () { return !captcha_field.prop("hidden"); },
		error: moncycle_captcha.message_in($("#token_new_err")),
	});

	form.on("submit", function (event) {
		event.preventDefault();
		const button = $("#but_token_create");
		const payload = {
			email: settings_read(form, "email"),
			password: settings_read(form, "password"),
			name: settings_read(form, "name"),
			scope: settings_read(form, "scope"),
			expiresInDays: parseInt(settings_read(form, "expiresInDays")),
			cookie: settings_read(form, "cookie") === "true",
		};
		if (settings_read(form, "code")) payload.code = settings_read(form, "code");
		if (!captcha_field.prop("hidden")) {
			payload.captcha = settings_read(form, "captcha");
			payload.captchaToken = settings_read(form, "captchaToken");
		}
		settings_say($("#token_new_err"), "", "");
		$("#token_new_result").prop("hidden", true);
		button.prop("disabled", true).text("🔑 " + settings_text.token_creating);
		settings_api("POST", "api/v2/token", payload).done(function (ret) {
			$("#i_token_new_value").val(ret.data.token);
			$("#token_copy_ok").text("");
			$("#token_new_result").prop("hidden", false);
			form.find("[name=password], [name=code], [name=name]").val("");
			settings_tokens_load();
		}).fail(function (jqXHR) {
			const error = (jqXHR.responseJSON || {}).error || {};
			// the server asks for a captcha once the account has failed too often (a wrong password here counts like a wrong sign-in)
			if ((error.details || {}).captchaRequired) captcha_field.prop("hidden", false);
			const shown = settings_error(jqXHR, settings_text.token_errors);
			settings_say($("#token_new_err"), "error", shown);
		}).always(function () {
			button.prop("disabled", false).text("🔑 Créer le jeton");
			// the captcha is tied to the account's counters, which this attempt may have moved: a new one whenever it is shown
			captcha.reload(true);
		});
	});

	$("#but_token_copy").on("click", function () {
		navigator.clipboard.writeText($("#i_token_new_value").val()).then(function () {
			$("#token_copy_ok").text(settings_text.token_copied);
		}, function () {
			$("#token_copy_ok").text(settings_text.token_copy_failed);
		});
	});
}


/* ===========================================================================
** 5g. À PROPOS: the application, the terms, the numbers of the account
** ======================================================================== */

function settings_fill_about(account) {
	$("#tech_info_no").text(account.userId);
	$("#tech_info_id").text(account.loginEmail);
	const since = new Date(account.inscriptionDate);
	$("#tech_info_insc").text(since.toLocaleDateString("fr-FR", {day: "2-digit", month: "2-digit", year: "numeric"}));
}

// The version, and the terms of service: their page is a setting of the server (TERMS_OF_SERVICE_URL, told by server_info). None told: the
// paragraph that sums them up is not shown.
function settings_load_server_info() {
	$.get("api/v2/server_info").done(function (ret) {
		const info = (ret || {}).data || {};
		$("#tech_info_ver").text(info.appVersion ?? "?");
		if (!/^https:\/\//i.test(info.termsOfServiceUrl || "")) return;
		$("#a_terms").attr("href", info.termsOfServiceUrl);
		$("#terms_about").prop("hidden", false);
	}).fail(settings_leave_if_signed_out);
}


/* ===========================================================================
** 5h. SUPPRIMER MON COMPTE
** ======================================================================== */

function settings_setup_deletion() {
	$("#f_suppr_user_account").on("submit", function (event) {
		event.preventDefault();
		const form = $(this);
		const nickname = settings_account.nickname;
		if (!confirm(settings_text.delete_confirm(nickname))) return;
		settings_api("POST", form.attr("action"), {password: settings_read(form, "pw_before_deletion")}).done(function () {
			moncycle_store.clear_storage();
			alert(settings_text.delete_done(nickname));
			window.location.replace("auth");
		}).fail(function (jqXHR) {
			alert(settings_text.delete_failed(nickname, settings_error(jqXHR)));
		});
	});
}


/* ===========================================================================
** 6. START
** ======================================================================== */

$(document).ready(function () {
	moncycle_store.init();

	// logout: the server ends the session (and clears its cookie, which only it can), then the local copy goes with it
	$("#f_logout").on("submit", function (event) {
		event.preventDefault();
		$("#but_logout").prop("disabled", true);
		settings_api("DELETE", "api/v2/token").done(function () {
			moncycle_store.clear_storage();
			window.location.replace("/auth");
		}).fail(function (jqXHR) {
			$("#but_logout").prop("disabled", false);
			alert("Déconnexion impossible : " + settings_error(jqXHR));
		});
	});

	settings_setup_autosave();
	settings_setup_descriptions();
	settings_setup_notifications();
	settings_setup_export();
	settings_setup_import();
	settings_setup_login_email();
	settings_setup_password();
	settings_setup_totp();
	settings_setup_tokens();
	settings_setup_deletion();

	$(window).on("hashchange", settings_show_panel);
	settings_show_panel();

	settings_load_server_info();
	$.get("api/v2/account").done(function (ret) {
		settings_show_account(ret.data);
	}).fail(function (jqXHR) {
		settings_say($("#page_msg"), "error", settings_text.account_not_loaded + settings_error(jqXHR));
	});
});
