/* moncycle.app
**
** licence Creative Commons CC BY-NC-SA
**
** https://www.moncycle.app
** https://github.com/moncycle-app/backend-api-web-app
*/

// The sign-in page (auth.html), as a file of its own: the CSP is script-src 'self', so no inline script.

window.addEventListener("storage", function () {
	if (parseInt(localStorage.auth)>0) {
		window.location.replace('..');
	}
}, false);
if (parseInt(localStorage.auth)>0) {
	window.location.replace('..');
}
else {
	moncycle_store.clear_storage();
	$(document).ready(function(){
		// the sign-in asks for a captcha once the account has failed too often (the server says so with captchaRequired); the
		// token is tied to the account's counters, so a new one is made after every refused attempt
		const login_captcha = moncycle_captcha.attach({
			purpose: "login",
			email: $("#i_email1"),
			image: $("#l_captcha"),
			token: $("#i_captcha_token"),
			answer: $("#i_captcha"),
			active: function() { return $("#captcha_wrapper").is(":visible"); },
			error: function(message) { $("#message_err").text(message); },
		});
		// the lost password always asks for one
		const reset_captcha = moncycle_captcha.attach({
			purpose: "recover",
			email: $("#r_email1"),
			image: $("#rc_captcha_img"),
			token: $("#rc_captcha_token"),
			answer: $("#rc_captcha"),
			error: function(message) { $("#reset_error_msg").text(message); },
		});
		$("#connexion_form").on("submit", function(event) {
			event.preventDefault();
			$("#connexion_but").prop("disabled", true);
			$("#connexion_but").val("Connexion ⏳");
			var payload = {
				email: $("#i_email1").val(),
				password: $("#i_password").val(),
			};
			if ($("#i_code").val()) payload.code = parseInt($("#i_code").val());
			if ($("#captcha_wrapper").is(":visible")) {
				payload.captcha = $("#i_captcha").val();
				payload.captchaToken = $("#i_captcha_token").val();
			}
			$.ajax({
				type: "POST",
				url: "api/login",
				contentType: "application/json",
				data: JSON.stringify(payload),
			}).done(function(ret){
				console.log(ret);
				localStorage.auth = ret.data.userId;
				window.location.replace('..');
			}).fail(function(jqXHR){
				$("#connexion_but").prop("disabled", false);
				$("#connexion_but").val("Connexion 🔑");
				var err = (jqXHR.responseJSON || {}).error || {};
				const return_codes = {
					login_disabled : "La connexion est désactivée.",
					missing_credentials : "E-mail et/ou mot de passe manquant.",
					account_disabled : "Compte désactivé.",
					totp_required : "Bon mot de passe, mais un code TOTP est requis.",
					totp_invalid : "Bon mot de passe, mais le code TOTP est incorrect.",
					invalid_credentials : "Mauvais mot de passe ou compte inexistant.",
					captcha_required : "Captcha manquant ou incorrect.",
					captcha_expired : "Le captcha a expiré, recopiez le nouveau.",
					captcha_unavailable : "Le captcha est indisponible pour le moment, réessayez plus tard.",
					account_locked : "Compte temporairement bloqué après trop de tentatives échouées, réessayez plus tard.",
					rate_limited : "Trop de tentatives depuis ce réseau, réessayez plus tard."
				};
				if ((err.details || {}).captchaRequired) $("#captcha_wrapper").show();
				$("#message_err").empty().append(document.createTextNode(return_codes[err.code] || err.message || "Erreur inconnue."), $("<br>"));

				// the token is tied to the account's counters, which this attempt may have moved: a new one, whenever the
				// captcha is shown (and the first one, when this answer is the one that asks for it)
				login_captcha.reload(true);
			});
		});
		$("#reset_form").on("submit", function(event) {
			event.preventDefault();
			$("#reset_but").prop("disabled", true);
			$("#reset_but").val("Rénitialiser ⏳");
			$("#reset_error_msg").text("");
			var email = $("#r_email1").val();
			$.ajax({
				type: "POST",
				url: "api/recover_password",
				contentType: "application/json",
				data: JSON.stringify({email: email, captcha: $("#rc_captcha").val(), captchaToken: $("#rc_captcha_token").val()}),
			}).done(function(ret){
				console.log(ret);
				$("#reset_but").val("Rénitialiser 🤐");
				$("#reset_but").prop("disabled", false);
				if (ret.data && ret.data.sent) {
					$("#reset_form").hide();
					// the address is what was typed: a text node, never HTML
						$("#password_reseted_msg").empty().append(document.createTextNode("📧 Nouveau mot de passe envoyé par mail à : "), $("<br>"), $("<b>").text(email), $("<br>"), $("<br>"), document.createTextNode("(si ce compte existe)"));
				}
			}).fail(function(jqXHR){
				$("#reset_but").val("Rénitialiser 🤐");
				$("#reset_but").prop("disabled", false);
				console.error(jqXHR);
				var err = (jqXHR.responseJSON || {}).error || {};
				const return_codes = {
					missing_email : "L'adresse e-mail est manquante.",
					invalid_email : "L'adresse e-mail n'est pas valide.",
					captcha_invalid : "Erreur dans la saisie du captcha.",
					captcha_expired : "Le captcha a expiré, recopiez le nouveau.",
					captcha_unavailable : "Le captcha est indisponible pour le moment, réessayez plus tard.",
					rate_limited : "Trop de tentatives depuis ce réseau, réessayez plus tard."
				};
				$("#reset_error_msg").text(return_codes[err.code] || err.message || "Erreur inconnue.");
				// a captcha that was refused is not asked again with the same answer
				if (err.code === "captcha_invalid" || err.code === "captcha_expired") reset_captcha.reload(true);
			});
		});
		// The address of the link in a mail. A "+" that reaches the page as itself (a mail reader or a link checker that
		// decodes %2B) is read as a space by URLSearchParams, and an address never holds a space: it is a "+".
		const email1 = (new URLSearchParams(window.location.search)).get("email1");
		$("#i_email1").val(email1 === null ? "" : email1.trim().replace(/ /g, "+"));
		$("#but_demo_bill").on("click", function(event) {
			event.preventDefault();
			$("#i_email1").val("demo.bill@moncycle.app");
			$("#i_password").val("demo");
			$("#connexion_form").submit();
		});
		$("#but_demo_fc").on("click", function(event) {
			event.preventDefault();
			$("#i_email1").val("demo.fc@moncycle.app");
			$("#i_password").val("demo");
			$("#connexion_form").submit();
		});
	});
}
