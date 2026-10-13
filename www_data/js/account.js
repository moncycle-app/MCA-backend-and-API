/* moncycle.app
**
** licence Creative Commons CC BY-NC-SA
**
** https://www.moncycle.app
** https://github.com/moncycle-app/backend-api-web-app
*/

var moncycle_app_usr = {};
var description_list = [];

/* ===========================================================================
** USER-FACING STRINGS
**
** Same convention as js/tableau.js: the text the user reads lives here rather than in the
** logic below, so translating a page is one object to go through and not a hunt. It covers
** the NFP import for now -- the older sections of this file still have their strings inline,
** and new ones belong here.
** ======================================================================== */

const moncycle_app_text = {

	/* --- counts --------------------------------------------------------- */
	// "1 jour" / "6 jours": French agrees the noun from 2 on, so 0 and 1 stay singular
	count : function (n, one, many) {
		return `${n} ${n > 1 ? many : one}`;
	},

	/* --- NFP import: the form ------------------------------------------- */
	import_pick_file : "\u274C&nbsp;choisissez un fichier.",
	import_unreadable : "\u274C&nbsp;fichier illisible.",
	import_checking : "\u23F3&nbsp;vérification\u2026",
	import_writing : "\u23F3&nbsp;import\u2026",
	import_but_check : "\uD83D\uDD0D Vérifier le fichier",
	import_but_check_busy : "\uD83D\uDD0D Vérification\u2026",
	import_but_submit : "\uD83D\uDCE5 Importer",
	import_but_submit_busy : "\uD83D\uDCE5 Import\u2026",
	// asked before a real import that is allowed to replace what is already there, since that
	// is the only thing this form can destroy
	import_confirm_override : function (filename) {
		return `Importer « ${filename} » en écrasant les jours déjà renseignés ?\n\nLes journées de votre tableau dont la date figure dans le fichier seront entièrement remplacées, et leur contenu actuel sera définitivement perdu.\n\nPour savoir lesquelles sont concernées avant de vous décider, annulez et utilisez « Vérifier le fichier » : la simulation n\u2019écrit rien.`;
	},

	/* --- NFP import: the report ----------------------------------------- */
	import_head_check : "\uD83D\uDD0D Simulation : rien n\u2019a été écrit dans votre tableau.",
	import_head_done : "\u2705 Import terminé.",
	import_head_refused : "\u274C Fichier refusé : rien n\u2019a été écrit dans votre tableau.",
	import_source : function (version, app) {
		return app ? `Fichier NFP ${version}, écrit par ${app}.` : `Fichier NFP ${version}.`;
	},
	import_read : function (cycles, days) {
		return `${moncycle_app_text.count(cycles, "cycle lu", "cycles lus")}, ${moncycle_app_text.count(days, "jour lu", "jours lus")}.`;
	},
	import_nothing_to_write : "Aucune journée renseignée dans ce fichier : il n\u2019y a rien à importer.",
	import_back_to_timeline : "\uD83D\uDC48 Revenez aux cycles pour voir vos données.",

	// the per-day outcomes. Each heading is the summary line of a foldable list of dates.
	import_days_created : function (n, dry) {
		return dry
			? `${moncycle_app_text.count(n, "jour sera créé", "jours seront créés")} dans votre tableau.`
			: `${moncycle_app_text.count(n, "jour créé", "jours créés")}.`;
	},
	import_days_overwritten : function (n, dry) {
		return dry
			? `${moncycle_app_text.count(n, "jour déjà renseigné sera entièrement remplacé", "jours déjà renseignés seront entièrement remplacés")}.`
			: `${moncycle_app_text.count(n, "jour remplacé", "jours remplacés")}.`;
	},
	import_days_skipped : function (n, dry) {
		return dry
			? `${moncycle_app_text.count(n, "jour déjà renseigné sera ignoré", "jours déjà renseignés seront ignorés")} : cochez « Écraser les jours déjà renseignés » pour les remplacer.`
			: `${moncycle_app_text.count(n, "jour ignoré", "jours ignorés")} car déjà renseignés : cochez « Écraser les jours déjà renseignés » et relancez l\u2019import pour les remplacer.`;
	},
	import_descriptions : function (n, dry) {
		return dry
			? `${moncycle_app_text.count(n, "nouvelle sensation/observation sera créée", "nouvelles sensations/observations seront créées")}.`
			: `${moncycle_app_text.count(n, "sensation/observation créée", "sensations/observations créées")}.`;
	},

	// the three "nothing is dropped silently" lists the API answers with
	import_warnings : function (n) {
		return `\u26A0\uFE0F ${moncycle_app_text.count(n, "avertissement", "avertissements")} : les données sont importées, mais lisez ceci.`;
	},
	import_mapped : function (n) {
		return `\u2139\uFE0F ${moncycle_app_text.count(n, "information importée de façon simplifiée", "informations importées de façon simplifiée")}.`;
	},
	import_ignored : function (n) {
		return `\u2139\uFE0F ${moncycle_app_text.count(n, "information du fichier que cette application ne sait pas conserver", "informations du fichier que cette application ne sait pas conserver")}.`;
	},

	// a refused file
	import_issues : function (shown, total) {
		return shown < total
			? `\u274C ${total} problèmes, dont les ${shown} premiers :`
			: `\u274C ${moncycle_app_text.count(total, "problème", "problèmes")} :`;
	},
	import_duplicates : function (n) {
		return `\u274C ${moncycle_app_text.count(n, "date est présente deux fois dans le fichier", "dates sont présentes deux fois dans le fichier")} : deux de ses cycles se chevauchent, et la journée appartiendrait aux deux. Le fichier doit être corrigé avant d\u2019être importé.`;
	},
	import_duplicates_list : function (n) {
		return moncycle_app_text.count(n, "date en double", "dates en double");
	},
	import_list_more : function (n) {
		return `\u2026 et ${moncycle_app_text.count(n, "autre", "autres")}.`;
	},

	/* --- the description forms ------------------------------------------- */
	desc_name_empty : " ❌\u00A0le nom ne peut pas être vide",
	desc_type_name : {0: "Type à définir", 1: "Observation", 2: "Sensation"},

	/* --- a description moved into the comments of its days ------------------ */
	desc_comment_button_title : "Déplacer cette description dans le commentaire de tous les jours associés, puis la supprimer (action définitive)",
	desc_comment_syncing : "⏳\u00A0mise à jour de vos données…",
	desc_comment_sync_failed : "❌\u00A0action impossible : vos données n’ont pas pu être mises à jour (connexion ?).",
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
			text = `Déplacer « ${name} » dans le commentaire de ${moncycle_app_text.count(n, "jour", "jours")} ?\n\n`
				+ `Sur ${n > 1 ? "chacun de ces jours" : "ce jour"}, « ${name} » sera ajouté à la fin du commentaire, après un « | » si le jour en a déjà un. Ensuite la description « ${name} » sera supprimée : elle ne sera plus associée à aucun jour.\n\n`
				+ `⚠️ Action définitive, sans retour en arrière : le texte ajouté fait partie du commentaire et ne se distingue plus du vôtre, et la description ne pourra pas être rétablie.`;
			if (kept == 1) text += `\n\n1 jour a déjà « ${name} » dans son commentaire : son commentaire ne sera pas modifié, il perdra seulement la description.`;
			else if (kept > 1) text += `\n\n${kept} jours ont déjà « ${name} » dans leur commentaire : leurs commentaires ne seront pas modifiés, ils perdront seulement la description.`;
		}
		else {
			text = `Supprimer la description « ${name} » ?\n\n`
				+ `Elle figure déjà dans le commentaire de ${moncycle_app_text.count(kept, "jour", "jours")} auxquels elle est associée : les commentaires ne seront pas modifiés, la description sera seulement supprimée.\n\n`
				+ `⚠️ Action définitive, sans retour en arrière.`;
		}
		return text;
	},
	desc_comment_progress : function (done, total) {
		return `⏳\u00A0${done}/${total}`;
	},
	desc_comment_deleting : "⏳\u00A0suppression de la description…",
	desc_comment_done : " ✅\u00A0déplacée",
	// what the row says once the description is gone
	desc_comment_moved : function (name, plan) {
		if (plan.change.length == 0) return `✅ « ${name} » figurait déjà dans le commentaire de ${moncycle_app_text.count(plan.kept.length, "jour", "jours")} : la description a été supprimée.`;
		return `✅ La description « ${name} » a été déplacée dans le commentaire de ${moncycle_app_text.count(plan.change.length, "jour", "jours")}, puis supprimée.`;
	},
	desc_comment_offline : " ⚠️\u00A0envoi interrompu : les changements sont gardés sur cet appareil et partiront dès que la connexion revient. La description n’est pas supprimée : relancez l’action ensuite. Gardez cette page ouverte.",
	desc_comment_refused : function (done, refused) {
		return ` ⚠️\u00A0${moncycle_app_text.count(done, "jour modifié", "jours modifiés")}, ${moncycle_app_text.count(refused, "refusé", "refusés")} par le serveur (modifié depuis un autre appareil ?). La description n’est pas supprimée : relancez l’action pour reprendre ${refused > 1 ? "ces jours" : "ce jour"}.`;
	},
	desc_comment_delete_failed : " ⚠️\u00A0les commentaires sont écrits, mais la description n’a pas pu être supprimée. Relancez l’action pour la supprimer.",

	/* --- connections (tokens): the list and the creation -------------------- */
	tokens_current : "cet appareil",
	tokens_scope : {readOnly : "lecture seule", readWrite : "lecture et écriture"},
	tokens_created : function (when, ip, agent) {
		return `Créée le ${when}${ip ? ` depuis ${ip}` : ""}${agent ? ` — ${agent}` : ""}`;
	},
	tokens_last_used : function (when, ip, agent) {
		return when ? `Dernière utilisation le ${when}${ip ? ` depuis ${ip}` : ""}${agent ? ` — ${agent}` : ""}` : "Jamais utilisée";
	},
	tokens_expires : function (when) {
		return `Expire le ${when}`;
	},
	tokens_revoke : "Déconnecter",
	tokens_prefix_title : "Début du jeton: il permet de distinguer les connexions d'une même application.",
	tokens_none : "Aucune connexion.",

	/* --- PATCH /api/account: what the server refuses when the password is asked again (its own words are English); the keys are the
	   codes of the problems (moncycle_store.error_pick), or "field:code" --- */
	account_errors : {
		invalid_password : "mot de passe incorrect",
		password_required : "le mot de passe est nécessaire",
		totp_required : "un code d\u2019authentification multifacteur est nécessaire",
		totp_invalid : "le code d\u2019authentification multifacteur est incorrect",
		invalid_email : "l\u2019adresse e-mail n\u2019est pas valide",
		email_unchanged : "c\u2019est déjà l\u2019adresse de connexion de votre compte",
		"notificationEmails" : "les adresses doivent être valides, séparées par « ; », 5 au plus",
		password_too_short : "le nouveau mot de passe est trop court (10 caractères au moins)",
		password_too_long : "le nouveau mot de passe est trop long (72 octets au plus)",
		password_invalid : "le nouveau mot de passe contient un caractère interdit",
		password_unchanged : "le nouveau mot de passe est identique à l\u2019ancien",
		demo_account : "indisponible sur un compte de démo",
		read_only_token : "cette connexion est en lecture seule",
	},

	export_errors : {
		no_data_in_period : "aucune observation sur cette période.",
		invalid_range : "la 1ère date ne peut pas être après la 2ème.",
		invalid_date : "dates non valides.",
		invalid_value : "format non valide.",
		missing_field : "dates vides.",
	},

	tokens_confirm_revoke_all : "Déconnecter tous vos autres appareils et applications ?\n\nCet appareil reste connecté. Les autres devront se reconnecter.",
	tokens_revoked : function (n) {
		return ` ✅\u00A0${moncycle_app_text.count(n, "connexion supprimée", "connexions supprimées")}`;
	},
	token_creating : "⏳\u00A0création…",
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
};

/* ===========================================================================
** API ADAPTERS
**
** The backend speaks a structured JSON API (see api/moncycle_app_open_api.yaml):
** every response is {"data": ...} on success or {"error": {"code","message"}} on
** failure, and a few fields that used to be a single overloaded integer are now
** split into more explicit ones. These small helpers are the only place in this
** file that need to know about that -- everything else below still works with
** the same flat field names (no_description, type as 0/1/2, ...) it always has.
** ======================================================================== */

function moncycle_app_redirect_if_unauthenticated(jqXHR) {
	if (jqXHR.status == 401) {
		moncycle_store.clear_storage();
		window.location.replace('/auth');
	}
}

// Something changed on the server that the local copy (js/store.js) shows: the copy is brought up to
// date once the changes stop coming (a name typed letter by letter is one change per letter).
let moncycle_app_sync_timer = null;
function moncycle_app_sync_later() {
	clearTimeout(moncycle_app_sync_timer);
	moncycle_app_sync_timer = setTimeout(function () { moncycle_store.sync().catch(function () { }); }, 1500);
}

function moncycle_app_error_message(jqXHR) {
	return moncycle_store.error_text(jqXHR);
}

// "<prefix><b>label</b> message<suffix>" in the element. The label is ours; the message is the server's and
// can quote what the user typed (duplicate_name echoes the label), so it goes in as a text node. Never
// through append(string): jQuery reads a string with a "<" or an entity in it as HTML.
function moncycle_app_show_error(target, label, message, prefix = "", suffix = "") {
	target.empty().append(document.createTextNode(prefix), $("<b>").text(label), document.createTextNode(" " + message + suffix));
}

// Report lines from /api/import quote what was in the file -- a sensation name, the app that
// wrote it -- so they are never dropped into HTML as they come.
function moncycle_app_escape_html(text) {
	return $("<span>").text(text === null || text === undefined ? "" : text).html();
}

// the "nfp_method" 1-4 integer (still used by account.html's radio buttons) conflates the
// NFP method with whether temperature is also tracked; the API exposes those as two
// separate fields (method: "billings"|"fertilityCare", temperatureTracking: bool) instead.
const moncycle_app_nfp_method_table = {1: ["billings", true], 2: ["billings", false], 3: ["fertilityCare", false], 4: ["fertilityCare", true]};
function moncycle_app_nfp_method_to_api(nfp_method) {
	let m = moncycle_app_nfp_method_table[nfp_method] || ["billings", false];
	return {method: m[0], temperatureTracking: m[1]};
}
function moncycle_app_nfp_method_from_api(method, temperatureTracking) {
	for (const k in moncycle_app_nfp_method_table) {
		let m = moncycle_app_nfp_method_table[k];
		if (m[0] == method && m[1] == !!temperatureTracking) return parseInt(k);
	}
	return 2;
}

const moncycle_app_desc_type_to_int = {"undefined": 0, "observation": 1, "sensation": 2};
const moncycle_app_desc_type_from_int = {0: "undefined", 1: "observation", 2: "sensation"};
// shown before a description's name, so that its type is known without opening it
const moncycle_app_desc_type_icon = {0: "❓", 1: "👀", 2: "🧠"};
function moncycle_app_description_from_api(d) {
	return {no_description: d.id, name: d.name, type: moncycle_app_desc_type_to_int[d.type] || 0, use_count: d.useCount};
}

$(document).ready(function(){

	moncycle_store.init();

	// LOGOUT: the server ends the session (and clears its cookie, which only it can), then the local copy goes with it
	$("#f_logout").on("submit", function (event) {
		event.preventDefault();
		$("#but_logout").prop("disabled", true);
		$.ajax({type: "DELETE", url: "api/token"}).done(function () {
			moncycle_store.clear_storage();
			window.location.replace("/auth");
		}).fail(function (jqXHR) {
			$("#but_logout").prop("disabled", false);
			alert("Déconnexion impossible: " + moncycle_app_error_message(jqXHR));
		});
	});

	// TELECHARGEMENT DES DONNES DES UTILISATEUR
	$.get("api/account", {}).done(function(ret) {
		moncycle_app_usr = ret.data;
		$("#f_info_pref")[0].reset();
		$("#name").text(moncycle_app_usr.nickname);
		document.title = "moncycle.app - compte " + moncycle_app_usr.nickname;
		$("#i_nickname").val(moncycle_app_usr.nickname);
		$("#tech_info_no").text(moncycle_app_usr.userId);
		if(moncycle_app_usr.sponsor) $("#merci_don").show();
		if (moncycle_store.is_demo(moncycle_app_usr.userId)) $("#warning_demo").show();
		if(moncycle_app_usr.method == "fertilityCare") $("#description_section").hide();
		$("#tech_info_id").text(moncycle_app_usr.loginEmail);
		$("#i_login_email").val(moncycle_app_usr.loginEmail);
		$("#i_token_new_email").val(moncycle_app_usr.loginEmail);
		if (moncycle_app_usr.totpState == "active") $("#token_new_code_wrapper").show();
		if (moncycle_store.is_demo(moncycle_app_usr.userId)) $("#tokens_section").hide();
		else tokens_load();
		$("#i_notification_emails").val(moncycle_app_usr.notificationEmails);
		let nfp_method = moncycle_app_nfp_method_from_api(moncycle_app_usr.method, moncycle_app_usr.temperatureTracking);
		$(`#m_${nfp_method}`).attr("checked", "");
		if (moncycle_app_usr.research) $("#i_research").prop('checked', true);
		if (moncycle_app_usr.timelineAscending) $("#i_timeline_asc").prop('checked', true);
		$("#i_auto_mail_export").prop('checked', !!moncycle_app_usr.autoMailExport);
		let d = new Date(moncycle_app_usr.inscriptionDate);
		let m = d.getMonth()+1;
		let j = d.getDate();
		$("#tech_info_insc").text([j<10 ? "0"+j : j, m<10 ? "0"+m : m, d.getFullYear()].join("/"));
		const cette_annee = (new Date()).getFullYear();
		for (let y = (cette_annee - cette_annee%5)-75; y < cette_annee-5; y += 5) {
			var selected = ""
			if (y==moncycle_app_usr.birthYear) selected = 'selected';
			$("#i_anaissance").append(`<option ${selected} value="${y}">entre ${y} et ${y+4}</option>`);
		}
		if (moncycle_app_usr.totpState != "active") $("#totp_explications").show();
		else $("#totp_state").show();
	}).fail(moncycle_app_redirect_if_unauthenticated);


	// TELECHARGEMENT/MODIFICATION/CRATION/SUPPRESSION DES DESCRIPTIONS BILLINGS
	// another_than: the description being edited, which is not a duplicate of itself (its saved name can be the one typed
	// back while the answer to the change that took it away is still on its way)
	let check_if_desc_exist = function(desc, type, another_than) {
		for (let i = 0; i < description_list.length; i+=1) {
			if (description_list[i].no_description == another_than) continue;
			if (description_list[i].name == desc && description_list[i].type == type) return true;
		}
		return false;
	}
	// A name is saved as it is typed, one request per key, and the answers come in any order: only the answer to
	// the last edit says how it went.
	let desc_last_edit = 0;

	// What acts on a description (its type, the copy to its comments, the delete) shows under the one being edited, and
	// stays while the focus or the pointer is in that row. Not :focus-within: a click on a button does not focus it
	// in Safari and Firefox on macOS, and the row would close under the finger.
	$(document).on("focusin mousedown", function (event) {
		let row = $(event.target).closest(".desc_row");
		$(".desc_row.desc_active").not(row).removeClass("desc_active");
		row.addClass("desc_active");
	});

	// DEPLACEMENT D'UNE DESCRIPTION DANS LE COMMENTAIRE DE SES JOURS
	// All in the browser: the days come from the local copy (js/store.js), brought up to date first, and go back
	// through its queue like any write of a day. Once the server has every one of them, the description is deleted.
	// The server sees ordinary writes of days and one delete of a description.
	let move_run = null;   // while one runs: {dates: {date: true}, done, refused: [dates]}
	moncycle_store.on("sent", function (detail) {
		if (!move_run || !move_run.dates[detail.date]) return;
		move_run.done += 1;
		$("#desc_net_stat").text(moncycle_app_text.desc_comment_progress(move_run.done, Object.keys(move_run.dates).length));
	});
	moncycle_store.on("failed", function (detail) {
		if (move_run && move_run.dates[detail.date]) move_run.refused.push(detail.date);
	});
	let move_description_to_comments = async function (form) {
		if (move_run) return;
		move_run = {dates: {}, done: 0, refused: []};
		let button = form.find(".i_desc_comment");
		let row = form.closest(".desc_row");
		let id = parseInt(form.find('input[name="no_description"]').val());
		let button_after = "💬";
		button.prop("disabled", true).val("⏳");
		$("#desc_net_stat").text(moncycle_app_text.desc_comment_syncing);
		try {
			// the days to change are the ones the server holds now
			try { await moncycle_store.sync(); }
			catch (e) {
				$("#desc_net_stat").text(moncycle_app_text.desc_comment_sync_failed);
				return;
			}
			$("#desc_net_stat").text("");
			let description = moncycle_store.descriptions.find(function (known) { return known.id === id; });
			if (!description) return alert(moncycle_app_text.desc_comment_gone);
			// the name that will be written is the one saved, which must be the one the user sees
			if (description.name !== $(`#f_edit_description_${id} .i_desc_name`).val()) return alert(moncycle_app_text.desc_comment_unsaved);
			let plan = moncycle_store.plan_description_to_comments(description);
			// all or nothing: a day that cannot take the name would lose the description with the others
			if (plan.too_long.length) return alert(moncycle_app_text.desc_comment_too_long(description.name, plan.too_long, moncycle_store.comment_max_chars));
			if (plan.change.length + plan.kept.length == 0) return alert(moncycle_app_text.desc_comment_nothing(description.name));
			if (!confirm(moncycle_app_text.desc_comment_confirm(description.name, plan))) return;

			if (plan.change.length) {
				plan.change.forEach(function (one) { move_run.dates[one.date] = true; });
				$("#desc_net_stat").text(moncycle_app_text.desc_comment_progress(0, plan.change.length));
				moncycle_store.queue_comments(plan.change, new Date().toISOString().replace(/\.\d+Z$/, "Z"));
				try { await moncycle_store.flush(); }
				catch (e) { /* what the server could not take stays in the queue, and is sent again */ }
				// the description is deleted only when every day has the name in its comment
				let waiting = plan.change.filter(function (one) { return moncycle_store.pending[one.date]; }).length;
				let refused = move_run.refused.length;
				if (waiting || refused) {
					$("#desc_net_stat").text(waiting ? moncycle_app_text.desc_comment_offline : moncycle_app_text.desc_comment_refused(plan.change.length - refused, refused));
					button_after = "⚠️";
					return;
				}
			}

			$("#desc_net_stat").text(moncycle_app_text.desc_comment_deleting);
			try { await moncycle_store.request("DELETE", "api/description/" + encodeURIComponent(id)); }
			catch (jqXHR) {
				console.error(jqXHR);
				$("#desc_net_stat").text(moncycle_app_text.desc_comment_delete_failed);
				button_after = "⚠️";
				return;
			}
			// the copy follows what the server did (the descriptions, the days that carried this one)
			try { await moncycle_store.sync(); }
			catch (e) { /* at the next sync */ }
			let at = description_list.findIndex(function (known) { return known.no_description == id; });
			if (at >= 0) description_list.splice(at, 1);
			// the row is a message now: what it said is in the comments
			row.attr("class", "desc_moved").removeAttr("id").removeData("id").empty().append(document.createTextNode(moncycle_app_text.desc_comment_moved(description.name, plan)));
			$("#desc_net_stat").text(moncycle_app_text.desc_comment_done);
		}
		finally {
			move_run = null;
			button.prop("disabled", false).val(button_after);
			if (button_after != "💬") setTimeout(function () { button.val("💬"); }, 3000);
		}
	};

	let load_description = function(ret) {
		let data = ret.data.map(moncycle_app_description_from_api);
		description_list = data;
		$("#desc_froms_container").empty();
		if (data.length == 0) {
			$("#desc_froms_container").append("<i class='tech_info'>Vous n’avez aucune sensation ou observation renseignée dans l’application.</i>");
			return
		}
		for (const description of data) {
			// built with the DOM, never as HTML: a name is free text and would break out of value="..."
			let input_form = $("<form>", {class: "f_edit_description", id: "f_edit_description_" + description.no_description}).append(
				$("<input>", {type: "hidden", name: "no_description"}).val(description.no_description),
				$("<span>", {class: "i_desc_icon", title: moncycle_app_text.desc_type_name[description.type]}).text(moncycle_app_desc_type_icon[description.type]),
				$("<input>", {class: "i_desc_name", type: "text", name: "name", maxlength: 256, title: description.name}).val(description.name),
				$("<span>", {class: "i_desc_count", title: "Nombre de jours associés à cette description"}).text(description.use_count));
			let input_type = $("<select>", {class: "i_desc_type", name: "type"}).append(
				$("<option>", {value: "2", selected: description.type == 2}).text("🧠 Sensations"),
				$("<option>", {value: "1", selected: description.type == 1}).text("👀 Observation"),
				$("<option>", {value: "0", selected: description.type == 0, disabled: true}).text("❓ à définir"));
			let input_comment = $("<form>", {class: "f_comment_description", id: "f_comment_description_" + description.no_description}).append(
				$("<input>", {type: "hidden", name: "no_description"}).val(description.no_description),
				$("<input>", {class: "i_desc_comment", type: "submit", title: moncycle_app_text.desc_comment_button_title, "aria-label": moncycle_app_text.desc_comment_button_title}).val("💬"));
			let input_del = $("<form>", {class: "f_delete_description", id: "f_delete_description_" + description.no_description}).append(
				$("<input>", {type: "hidden", name: "no_description"}).val(description.no_description),
				$("<input>", {type: "hidden", class: "del_data_name"}).val(description.name),
				$("<input>", {type: "hidden", class: "del_data_count"}).val(description.use_count),
				$("<input>", {class: "i_desc_del", type: "submit"}).val("❌"));
			// a description is a line (its type's emoji, its name, its count); what acts on it shows under it while it is the one being edited
			let row = $("<div>", {class: "desc_row", id: "desc_row_" + description.no_description}).data("id", description.no_description).append(
				input_form,
				$("<div>", {class: "desc_actions"}).append(input_type, input_comment, input_del));
			$("#desc_froms_container").append(row);
		}
		let update_desc = function (e) {
			e.stopPropagation();
			let row = $(this).closest(".desc_row");
			let id = row.data("id");
			let name = row.find(".i_desc_name").val();
			// the whole name is in the tooltip: the field shows what fits
			row.find(".i_desc_name").attr("title", name);
			// the option selected, not the value of the select: the "à définir" option is disabled, and the select answers nothing for it
			let type_int = parseInt(row.find(".i_desc_type option:selected").val());
			let saved = description_list.find(function (known) { return known.no_description == id; });
			// Only a difference is sent (focus, Tab and arrows make key and change events with none): the name and the type are
			// compared with what was sent last, or else what is saved.
			let last = saved.sent || saved;
			if (last.name == name && last.type == type_int) return;
			let edit = ++desc_last_edit;
			if (name.trim() == "") {
				$("#desc_net_stat").text(moncycle_app_text.desc_name_empty);
				return;
			}
			if (check_if_desc_exist(name, type_int, id)) {
				$("#desc_net_stat").html(' ❌&nbsp;description doublon');
				return;
			}
			$("#desc_net_stat").html('⏳');
			saved.sent = {name: name, type: type_int};
			let payload = {name: name, type: moncycle_app_desc_type_from_int[type_int]};
			$.ajax({type: "PATCH", url: "api/description/" + encodeURIComponent(id), contentType: "application/json", data: JSON.stringify(payload)}).done(function(ret){
				if (edit == desc_last_edit) $("#desc_net_stat").html(' ✅&nbsp;enregistré');
				// what the page holds of the description follows what was saved (the duplicate check, the delete dialog, the emoji)
				saved.name = name;
				saved.type = type_int;
				row.find(".i_desc_icon").text(moncycle_app_desc_type_icon[type_int]).attr("title", moncycle_app_text.desc_type_name[type_int]);
				$(`#f_delete_description_${id} .del_data_name`).val(name);
				moncycle_app_sync_later();
			}).fail(function(jqXHR){
				if (edit == desc_last_edit) {
					$("#desc_net_stat").html('');
					// refused or lost: the next key sends it again
					saved.sent = null;
				}
				console.error(jqXHR);
			});
		}
		$(".desc_row .i_desc_type").on("change", update_desc);
		$(".desc_row .i_desc_name").on("keyup", update_desc);
		// Enter in the name is not a page to load: the name is saved as it is typed
		$(".f_edit_description").on("submit", function(event){ event.preventDefault(); });
		$(".f_comment_description").on("submit", function(event){
			event.preventDefault();
			move_description_to_comments($(this));
		});
		$(".f_delete_description").on("submit", function(event){
			event.preventDefault();
			let html_form = $(this).closest('form');
			let name = html_form.find(".del_data_name").val();
			let count = parseInt(html_form.find(".del_data_count").val());
			let id = html_form.find('input[name="no_description"]').val();
			if (count>1 && !confirm(`Êtes-vous sûr de vouloir supprimer la description « ${name} » ?\n\nLes jours auxquels ${name} a été associé perdront cette information de manière irréversible. La description « ${name} » est actuellement associé à ${count} dates différentes.`)) return;
			else if (count>0 && !confirm(`Êtes-vous sûr de vouloir supprimer la description « ${name} » ?\n\nLes jours auxquels ${name} a été associé perdront cette information de manière irréversible. La description « ${name} » est actuellement associé à une date.`)) return;
			$("#desc_net_stat").html('⏳');
			$.ajax({type : 'DELETE', "url" : "api/description/" + encodeURIComponent(id)}).done(function(){
				$("#desc_net_stat").html('');
				$(`#desc_row_${id}`).remove();
				$("#desc_net_stat").html(' ✅&nbsp;supprimé');
				moncycle_app_sync_later();
			}).fail(function(jqXHR){
				$("#desc_net_stat").html('');
				console.error(jqXHR);
			});
		});

	};
	$.get("api/description", {}).done(load_description).fail(moncycle_app_redirect_if_unauthenticated);
	$("#f_new_description").on("submit", function(event){
		event.preventDefault();
		let name = $("#i_desc_name_new").val();
		let type_int = parseInt($("#i_desc_type_new").val());
		if (check_if_desc_exist(name, type_int)) {
			$("#desc_net_stat").html(' ❌&nbsp;description doublon');
			return;
		}
		$("#desc_net_stat").html('⏳');
		$.ajax({type: "POST", url: "api/description", contentType: "application/json", data: JSON.stringify({name: name, type: moncycle_app_desc_type_from_int[type_int]})}).done(function(ret){
			$("#desc_net_stat").html(' ✅&nbsp;enregistré');
			$("#f_new_description")[0].reset();
			$.get("api/description", {}).done(load_description).fail(moncycle_app_redirect_if_unauthenticated);
			moncycle_app_sync_later();
		}).fail(function(jqXHR){
			$("#desc_net_stat").html('');
			console.error(jqXHR);
		});
	});


	// AFFICHAGE VERSION
	$.get("api/server_info", {}).done(function(ret) {
		$("#tech_info_ver").text((ret.data || {}).serverVersion ?? "?");
	}).fail(moncycle_app_redirect_if_unauthenticated);


	// MISE A JOURS DES PARAMETTRE DU COMPTE
	const moncycle_app_account_field_map = {nickname: "nickname", age: "birthYear", timeline_asc: "timelineAscending", research: "research", auto_mail_export: "autoMailExport"};
	$(".auto_save").on("keyup change", function() {
		// the status goes next to the section the field is in (data-net-stat), the settings one by default
		let net_stat = $("#" + ($(this).data("net-stat") || "net_stat"));
		net_stat.text('⏳');
		let field_name = $(this).attr('name');
		let payload = {};
		if (field_name == "nfp_method") {
			Object.assign(payload, moncycle_app_nfp_method_to_api(parseInt(this.value)));
		}
		else {
			let val = this.value;
			if (this.type == "checkbox") val = this.checked;
			else if (field_name == "age") val = parseInt(val);
			payload[moncycle_app_account_field_map[field_name] || field_name] = val;
		}
		$.ajax({
			type: "PATCH",
			url: "../api/account",
			contentType: "application/json",
			data: JSON.stringify(payload),
		}).done(function(ret){
			if (ret.data && ret.data.nickname) $("#name").text(ret.data.nickname);
			net_stat.html(' ✅&nbsp;enregistré');
			net_stat.addClass('vert');
			net_stat.removeClass('rouge');
			moncycle_app_sync_later();
		}).fail(function(jqXHR){
			console.error(jqXHR);
			net_stat.html(' ❌&nbsp;erreur');
			net_stat.addClass('rouge');
			net_stat.removeClass('vert');
		});
	});


	// Everything about the account is changed by PATCH /api/account: the settings as they are typed, and what hands the account (or its cycles)
	// over -- the addresses and the password -- with the current password sent again. A form carries the data, the script sends the form.
	const account_update = function (payload, done, fail) {
		$.ajax({type: "PATCH", url: "../api/account", contentType: "application/json", data: JSON.stringify(payload)}).done(done).fail(function (jqXHR) {
			console.error(jqXHR);
			fail(jqXHR);
		});
	};
	// what the server refuses, in French (its own words are English); the server's message when the code is not known here
	const account_error = function (jqXHR) {
		return moncycle_store.error_pick(jqXHR, moncycle_app_text.account_errors) || moncycle_app_error_message(jqXHR).replace(/\.$/, "");
	};

	// THE ADDRESSES THAT RECEIVE THE CYCLES: it is the cycles of the owner, so the password confirms a change
	$("#form_notification_emails").on("submit", function(event) {
		event.preventDefault();
		$("#notification_emails_ok").text('');
		$("#notification_emails_msg").text('');
		$("#but_notification_emails").prop("disabled", true);
		account_update({notificationEmails: $("#i_notification_emails").val(), password: $("#i_notification_emails_password").val()}, function () {
			$("#but_notification_emails").prop("disabled", false);
			$("#i_notification_emails_password").val('');
			$("#notification_emails_ok").text('✅ enregistré');
			moncycle_app_sync_later();
		}, function (jqXHR) {
			$("#but_notification_emails").prop("disabled", false);
			moncycle_app_show_error($("#notification_emails_msg"), "erreur:", account_error(jqXHR), "❌ ", ".");
		});
	});

	// CHANGEMENT DU MOT DE PASSE
	$("#form_mdp_change").on("submit", function(event) {
		event.preventDefault();
		$("#mdp_change_ok").text('');
		$("#mdp_ret_msg").text('');
		if ($("#i_pw1").val() != $("#i_pw2").val()) {
			$("#mdp_ret_msg").html("❌ <b>erreur:</b> le nouveau mot de passe et sa confirmation ne sont pas identiques.");
			return;
		}
		$("#but_mdp_change").prop("disabled", true);
		account_update({password: $("#i_old_pw").val(), newPassword: $("#i_pw1").val(), logoutOtherDevices: $("#i_logout_other_devices").is(":checked")}, function () {
			$("#but_mdp_change").prop("disabled", false);
			$("#form_mdp_change input[type=password]").val('');
			$("#i_logout_other_devices").prop("checked", false);
			$("#mdp_change_ok").text('✅ enregistré');
		}, function (jqXHR) {
			$("#but_mdp_change").prop("disabled", false);
			moncycle_app_show_error($("#mdp_ret_msg"), "erreur:", account_error(jqXHR), "❌ ", ".");
		});
	});

	// CHANGEMENT DE L'ADRESSE DE CONNEXION: the link goes to the new address, the change happens when it is used (verify_email.html)
	$("#form_login_email").on("submit", function(event) {
		event.preventDefault();
		$("#login_email_ok").text('');
		$("#login_email_msg").text('');
		$("#but_login_email").prop("disabled", true);
		var payload = {loginEmail: $("#i_login_email_new").val(), password: $("#i_login_email_password").val()};
		if ($("#i_login_email_code").val()) payload.code = $("#i_login_email_code").val();
		account_update(payload, function (ret) {
			$("#but_login_email").prop("disabled", false);
			$("#form_login_email input[type=password], #form_login_email input[type=number]").val('');
			if ((ret.meta || {}).loginEmailLinkSent) {
				$("#login_email_ok").text('✅ lien envoyé');
				$("#i_login_email_new").val('');
			}
			else {
				$("#login_email_msg").text("❌ le mail n'a pas pu être envoyé, réessayez plus tard.");
			}
		}, function (jqXHR) {
			$("#but_login_email").prop("disabled", false);
			moncycle_app_show_error($("#login_email_msg"), "erreur:", account_error(jqXHR), "❌ ", ".");
		});
	});

	// MES CONNEXIONS (tokens): the list, the revocation of one or of every other, and the creation of a custom token.
	// Everything the server says of a token (a name, a user agent) is the device's own word: text nodes only, never HTML.
	const token_when = function (iso) {
		return iso ? new Date(iso).toLocaleString("fr-FR", {dateStyle: "short", timeStyle: "short"}) : "";
	};
	const token_render = function (tokens) {
		const container = $("#tokens_container").empty();
		if (tokens.length === 0) container.append(document.createTextNode(moncycle_app_text.tokens_none));
		tokens.forEach(function (token) {
			const row = $("<div>").addClass("token_row");
			const title = $("<b>").text(token.name);
			const scope = $("<span>").addClass("label_info").text(" — " + moncycle_app_text.tokens_scope[token.scope]);
			const head = $("<div>").addClass("token_title").append(title);
			// the first characters of the token (null for an old one that started like another): what tells two connections of one app apart
			if (token.prefix) head.append($("<code>").addClass("token_prefix").attr("title", moncycle_app_text.tokens_prefix_title).text(token.prefix + "…"));
			head.append(scope);
			if (token.current) head.append($("<b>").addClass("vert").text(" (" + moncycle_app_text.tokens_current + ")"));
			row.append(head);
			const details = [
				moncycle_app_text.tokens_created(token_when(token.createdAt), token.createdIp, token.createdUserAgent),
				moncycle_app_text.tokens_last_used(token_when(token.lastUsedAt), token.lastUsedIp, token.lastUsedUserAgent),
			];
			if (token.expiresAt) details.push(moncycle_app_text.tokens_expires(token_when(token.expiresAt)));
			details.forEach(function (line) { row.append($("<div>").addClass("label_info token_detail").text(line)); });
			if (!token.current && token.prefix) {
				const form = $("<form>").addClass("f_token_revoke").attr({action: "api/token/" + encodeURIComponent(token.prefix), method: "delete"});
				form.append($("<button>").attr("type", "submit").addClass("rouge").text("🗑️ " + moncycle_app_text.tokens_revoke));
				row.append(form);
			}
			container.append(row);
		});
	};
	const tokens_load = function () {
		return $.get("api/token").done(function (ret) {
			token_render(ret.data);
		}).fail(function (jqXHR) {
			moncycle_app_redirect_if_unauthenticated(jqXHR);
			// the demo accounts have no list: their sessions are the visitors' (the server says 403 demo_account)
			if (jqXHR.status == 403) $("#tokens_section").hide();
			else moncycle_app_show_error($("#tokens_err"), "❌\u00a0erreur:", moncycle_app_error_message(jqXHR));
		});
	};
	// The form says what to revoke by its address: api/token/{prefix} for one connection, api/token?others=true for all the others.
	const tokens_revoke = function (form) {
		$("#tokens_err").empty();
		const net_stat = $("#tokens_net_stat").removeClass("vert rouge").text("⏳");
		return $.ajax({type: "DELETE", url: form.attr("action")}).done(function (ret) {
			net_stat.addClass("vert").text(moncycle_app_text.tokens_revoked(ret.data.revoked));
			tokens_load();
		}).fail(function (jqXHR) {
			moncycle_app_redirect_if_unauthenticated(jqXHR);
			net_stat.empty();
			moncycle_app_show_error($("#tokens_err"), "❌\u00a0erreur:", moncycle_app_error_message(jqXHR));
			tokens_load();
		});
	};
	$("#tokens_container").on("submit", ".f_token_revoke", function (event) {
		event.preventDefault();
		tokens_revoke($(this));
	});
	$("#f_tokens_revoke_all").on("submit", function (event) {
		event.preventDefault();
		if (!confirm(moncycle_app_text.tokens_confirm_revoke_all)) return;
		tokens_revoke($(this));
	});

	// the creation is a sign-in that sets no cookie: the password (and the code, and the captcha once the account asks for one) is asked again
	const token_captcha = moncycle_captcha.attach({
		purpose: "login",
		email: $("#i_token_new_email"),
		image: $("#i_token_new_captcha_img"),
		token: $("#i_token_new_captcha_token"),
		answer: $("#i_token_new_captcha"),
		active: function () { return $("#token_new_captcha_wrapper").is(":visible"); },
		error: moncycle_captcha.message_in($("#token_new_err")),
	});
	$("#f_token_create").on("submit", function (event) {
		event.preventDefault();
		const form = $(this);
		const field = function (name) { return form.find("[name=" + name + "]"); };
		$("#token_new_err").empty();
		$("#token_new_result").hide();
		const payload = {
			email: field("email").val(),
			password: field("password").val(),
			name: field("name").val(),
			scope: field("scope").val(),
			expiresInDays: parseInt(field("expiresInDays").val()),
			cookie: field("cookie").val() === "true",
		};
		if (field("code").val()) payload.code = field("code").val();
		if ($("#token_new_captcha_wrapper").is(":visible")) {
			payload.captcha = field("captcha").val();
			payload.captchaToken = field("captchaToken").val();
		}
		$("#but_token_create").prop("disabled", true).val("🔑 " + moncycle_app_text.token_creating);
		$.ajax({type: "POST", url: "api/token", contentType: "application/json", data: JSON.stringify(payload)}).done(function (ret) {
			$("#i_token_new_value").val(ret.data.token);
			$("#token_copy_ok").text("");
			$("#token_new_result").show();
			field("password").val("");
			field("code").val("");
			field("name").val("");
			tokens_load();
		}).fail(function (jqXHR) {
			const err = (jqXHR.responseJSON || {}).error || {};
			// the server asks for a captcha once the account has failed too often (a wrong password here counts like a wrong sign-in)
			if ((err.details || {}).captchaRequired) $("#token_new_captcha_wrapper").show();
			const shown = moncycle_store.error_pick(jqXHR, moncycle_app_text.token_errors) || moncycle_store.error_text(jqXHR);
			$("#token_new_err").empty().append(document.createTextNode("❌ " + shown));
		}).always(function () {
			$("#but_token_create").prop("disabled", false).val("🔑 Créer le jeton");
			// the captcha is tied to the account's counters, which this attempt may have moved: a new one whenever it is shown
			token_captcha.reload(true);
		});
	});
	$("#but_token_copy").on("click", function () {
		navigator.clipboard.writeText($("#i_token_new_value").val()).then(function () {
			$("#token_copy_ok").text(moncycle_app_text.token_copied);
		}, function () {
			$("#token_copy_ok").text(moncycle_app_text.token_copy_failed);
		});
	});

	// TOTP AUTH MULTI FACTEUR
	$("#f_totp_start").on("submit", function(event) {
		event.preventDefault();
		$("#i_activate_otp").prop("disabled", true);
		$("#totp_err_msg").html("");
		$.ajax({type: "POST", url: $(this).attr("action")}).done(function(ret) {
			$("#i_activate_otp").prop("disabled", false);
			$("#totp_explications").hide();
			$("#totp_miseenpalce").show();
			$("#totp_auto_conf").attr("href", ret.data.otpauth);
			$("#totp_qrcode").html(ret.data.qrcode);
			$("#totp_copy_secret").on("click", function(event) {
				navigator.clipboard.writeText(ret.data.initSecret).then(function () {
					$("#totp_copy_secret_ok").text("✅ copié");
				}, function () {
					alert('Failure to copy. Check permissions for clipboard');
				});
			});
		}).fail(function(jqXHR) {
			$("#i_activate_otp").prop("disabled", false);
			moncycle_app_show_error($("#totp_err_msg"), "❌\u00a0erreur:", moncycle_app_error_message(jqXHR));
		});
	});

	$("#f_totp_validation").on("submit", function(event) {
		event.preventDefault();
		$("#totp_err_msg").html("");
		var code = $('#f_totp_validation input[name="tmp_code"]').val();
		$("#f_totp_validation").trigger("reset");
		$.ajax({type: "POST", url: $(this).attr("action"), contentType: "application/json", data: JSON.stringify({code: code})}).done(function(ret){
			if (ret.data.totpState == "active") {
				$("#totp_miseenpalce").hide();
				$("#totp_state").show();
			}
		}).fail(function(jqXHR) {
			moncycle_app_show_error($("#totp_err_msg"), "❌\u00a0erreur:", moncycle_app_error_message(jqXHR));
		});
	});

	$("#f_totp_desac").on("submit", function(event) {
		event.preventDefault();
		$("#totp_err_msg").html("");
		var code = $('#f_totp_desac input[name="tmp_code"]').val();
		$("#f_totp_desac").trigger("reset");
		$.ajax({type: "POST", url: $(this).attr("action"), contentType: "application/json", data: JSON.stringify({code: code})}).done(function(ret){
			if (ret.data.totpState == "disabled") {
				$("#totp_explications").show();
				$("#totp_state").hide();
			}
		}).fail(function(jqXHR) {
			moncycle_app_show_error($("#totp_err_msg"), "❌\u00a0erreur:", moncycle_app_error_message(jqXHR));
		});
	});

	// EXPORT PDF
	$("#i_start_date").attr("max", new Date().toISOString().substr(0, 10));
	$("#i_end_date").attr("max", new Date().toISOString().substr(0, 10));
	$("#f_export_complexe").on("submit", function(event) {
		event.preventDefault();
		var start_date = $("#i_start_date").val();
		var end_date = $("#i_end_date").val();
		var file_format = $("#i_file_format").val();
		var show_error = function (reason) { moncycle_app_show_error($("#export_err"), "erreur:", reason, "❌\u00a0"); };
		$("#export_err").empty();
		if (start_date == "" || end_date == "") show_error(moncycle_app_text.export_errors.missing_field);
		else if (new Date(start_date) > new Date(end_date)) show_error(moncycle_app_text.export_errors.invalid_range);
		else {
			moncycle_store.download(`${$(this).attr("action")}/${encodeURIComponent(file_format)}?from=${start_date}&to=${end_date}`).catch(function (failure) {
				show_error(moncycle_app_text.export_errors[failure.issues.length ? failure.issues[0].code : failure.code] || failure.message || "export impossible.");
			});
		}
	});

	// IMPORT NFP
	//
	// One form, two buttons. "Vérifier le fichier" asks api/import for a dry run: it runs every
	// check, reads the account to see which of the file's days are already there, and reports
	// what the write would do -- without writing. "Importer" does it for real. Both answer the
	// same report, and on a dry run a refused file answers an error whose details carry the same
	// lists, so one renderer draws all three outcomes.
	let import_running = false;

	// A foldable list, because these can run to thousands of dates. The limit keeps the page
	// from drawing them all; the count in the heading is always the true one.
	let import_list = function (heading, items, limit) {
		items = items || [];
		if (items.length == 0) return "";
		let shown = items.slice(0, limit);
		let lines = shown.map(function (item) { return `<li>${moncycle_app_escape_html(item)}</li>`; }).join("");
		if (items.length > shown.length) {
			lines += `<li class="label_info">${moncycle_app_text.import_list_more(items.length - shown.length)}</li>`;
		}
		return `<details class="import_list"><summary>${heading}</summary><ul>${lines}</ul></details>`;
	};

	let import_line = function (text, css) {
		return `<p class="import_line ${css || ''}">${text}</p>`;
	};

	// An accepted file, dry run or real: the same report either way, with the verbs put in the
	// future tense on a dry run.
	let import_render_report = function (report) {
		let dry = !!report.dryRun;
		let html = import_line(dry ? moncycle_app_text.import_head_check : moncycle_app_text.import_head_done, dry ? "import_head" : "import_head vert");

		html += import_line(moncycle_app_escape_html(moncycle_app_text.import_source(report.schemaVersion, report.sourceApp)), "label_info");
		html += import_line(moncycle_app_text.import_read(report.cyclesRead, report.daysRead), "label_info");

		if (report.daysToWrite == 0) html += import_line(moncycle_app_text.import_nothing_to_write);

		// the three per-day outcomes, each a date list
		html += import_list(moncycle_app_text.import_days_created((report.daysCreated || []).length, dry), report.daysCreated, 40);
		html += import_list(moncycle_app_text.import_days_overwritten((report.daysOverwritten || []).length, dry), report.daysOverwritten, 40);
		html += import_list(moncycle_app_text.import_days_skipped((report.daysSkipped || []).length, dry), report.daysSkipped, 40);

		if (report.descriptionsCreated > 0) html += import_line(moncycle_app_text.import_descriptions(report.descriptionsCreated, dry), "label_info");

		html += import_render_notes(report);

		if (!dry && report.daysWritten > 0) html += `<p class="import_line"><a href="/">${moncycle_app_text.import_back_to_timeline}</a></p>`;
		return html;
	};

	// warnings / mappedFields / ignoredFields: what the file carries that is not simply stored
	// as it was. The API fills them on an accepted file and, on a dry run, on a refused one too.
	let import_render_notes = function (source) {
		let html = "";
		html += import_list(moncycle_app_text.import_warnings((source.warnings || []).length), source.warnings, 50);
		html += import_list(moncycle_app_text.import_mapped((source.mappedFields || []).length), source.mappedFields, 50);
		html += import_list(moncycle_app_text.import_ignored((source.ignoredFields || []).length), source.ignoredFields, 50);
		return html;
	};

	let import_render_error = function (jqXHR) {
		let error = (jqXHR.responseJSON || {}).error || {};
		let details = error.details || {};
		let html = import_line(moncycle_app_text.import_head_refused, "import_head rouge");
		// the API's own wording for the refusal, shown as it comes
		html += import_line(moncycle_app_escape_html(error.message || moncycle_app_error_message(jqXHR)), "label_info");

		// Dates the file itself carries twice. They are among the issues below as well, but
		// a list of its own is what makes the file fixable.
		if ((details.duplicatedDaysInFile || []).length > 0) {
			html += import_line(moncycle_app_text.import_duplicates(details.duplicatedDaysInFile.length), "rouge");
			html += import_list(moncycle_app_text.import_duplicates_list(details.duplicatedDaysInFile.length), details.duplicatedDaysInFile, 40);
		}

		let issues = details.issues || [];
		if (issues.length > 0) html += import_list(moncycle_app_text.import_issues(issues.length, details.issueCount || issues.length), issues, 50);

		html += import_render_notes(details);
		return html;
	};

	let import_run = function (dry_run) {
		if (import_running) return;

		let file = $("#i_import_file")[0].files[0];
		if (!file) {
			$("#import_net_stat").html(moncycle_app_text.import_pick_file).addClass("rouge").removeClass("vert");
			return;
		}

		let override = $("#i_import_override").prop("checked");
		if (!dry_run && override && !confirm(moncycle_app_text.import_confirm_override(file.name))) return;

		import_running = true;
		$("#import_report").empty();
		$("#import_net_stat").html(dry_run ? moncycle_app_text.import_checking : moncycle_app_text.import_writing).removeClass("rouge vert");
		$("#i_import_check").prop("disabled", true).val(dry_run ? moncycle_app_text.import_but_check_busy : moncycle_app_text.import_but_check);
		$("#i_import_submit").prop("disabled", true).val(dry_run ? moncycle_app_text.import_but_submit : moncycle_app_text.import_but_submit_busy);

		let import_done = function () {
			import_running = false;
			$("#import_net_stat").html("");
			$("#i_import_check").prop("disabled", false).val(moncycle_app_text.import_but_check);
			$("#i_import_submit").prop("disabled", false).val(moncycle_app_text.import_but_submit);
		};

		// api/import reads the file as the request body, so it goes up as it is -- no multipart
		// form, and processData off so jQuery does not try to encode the string.
		file.text().then(function (body) {
			let query = $.param({
				dryRun: dry_run,
				override: override,
				lastWriteClientUtc: (new Date()).toISOString(),
			});
			$.ajax({
				type: "POST",
				url: `api/import?${query}`,
				contentType: "application/json",
				data: body,
				processData: false,
			}).done(function (ret) {
				import_done();
				$("#import_report").html(import_render_report(ret.data || {}));
				// A real import adds days and cycles: the local copy gets them with a sync.
				if (!dry_run) moncycle_app_sync_later();
			}).fail(function (jqXHR) {
				import_done();
				moncycle_app_redirect_if_unauthenticated(jqXHR);
				console.error(jqXHR);
				$("#import_report").html(import_render_error(jqXHR));
			});
		}, function (err) {
			import_done();
			console.error(err);
			$("#import_net_stat").html(moncycle_app_text.import_unreadable).addClass("rouge");
		});
	};

	$("#i_import_check").on("click", function () { import_run(true); });
	$("#f_import").on("submit", function (event) {
		event.preventDefault();
		import_run(false);
	});
	$("#i_import_file").on("change", function () {
		$("#import_report").empty();
		$("#import_net_stat").html("").removeClass("rouge vert");
	});


	// SUPPRESSION DU COMPTE
	$("#f_suppr_user_account").on("submit", function(event) {
		event.preventDefault();
		var password = $('#f_suppr_user_account input[name="pw_before_deletion"]').val();
		if (!confirm(moncycle_app_usr.nickname + ', êtes-vous sur de vouloir supprimer votre compte ainsi que toutes vos données? Cette action est irréversible. 😟')) return;
		$.ajax({type : 'POST', "url" : $(this).attr("action"), contentType: "application/json", data: JSON.stringify({password: password})}).done(function(){
			moncycle_store.clear_storage();
			alert(moncycle_app_usr.nickname + ", votre compte a bien été supprimé. 😢💔");
			window.location.replace('auth');
		}).fail(function(jqXHR) {
			alert(moncycle_app_usr.nickname + ", votre compte n'a pas été supprimé: " + moncycle_app_error_message(jqXHR));
		});
	});

});
