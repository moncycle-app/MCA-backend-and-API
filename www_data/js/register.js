/* moncycle.app
**
** licence Creative Commons CC BY-NC-SA
**
** https://www.moncycle.app
** https://github.com/moncycle-app/backend-api-web-app
*/

// The sign-up page (register.html), as a file of its own: the CSP is script-src 'self', so no inline script.

// Someone on a demo account can sign up: they stay on this page, and what the browser keeps of the demo goes (its
// session ends on the server with the captcha this page asks for, which replaces its cookie).
if (parseInt(localStorage.auth)>0 && !moncycle_store.is_demo(localStorage.auth)) {
	window.location.replace('..');
}
else {
	moncycle_store.clear_storage();
	$(document).ready(function(){
		let year = new Date().getFullYear();
		yera = year - (year%5);
		for (let yy = year-100; yy < year; yy+=5) {
			$("#i_anaissance").append(`<option value="${yy}">entre ${yy} et ${yy+4}</option>`);
		}
		// the picture and its token are asked for once the address is typed (a click on the picture asks for another)
		const register_captcha = moncycle_captcha.attach({
			purpose: "register",
			email: $("#i_login_email"),
			image: $("#r_captcha"),
			token: $("#i_captcha_token"),
			answer: $("#i_captcha"),
			error: moncycle_captcha.message_in($("#register_error_msg")),
		});
		$("#f_registration").on("submit", function(event) {
			event.preventDefault();
			$("#register_error_msg").text("");
			if ($("#i_login_email").val() != $("#i_login_email_conf").val()) {
				$("#register_error_msg").text("L'addresse email et sa confirmation ne sont pas identique.");
				return;
			}
			if ($("#i_password").val() != $("#i_password_conf").val()) {
				$("#register_error_msg").text("Le mot de passe et sa confirmation ne sont pas identiques.");
				return;
			}
			$("#but_register").prop("disabled", true);
			$("#but_register").val("Créer mon compte ⏳");
			let reactivate_form = function() {
				$("#but_register").prop("disabled", false);
				$("#but_register").val("Créer mon compte 🥳");
			}
			var method = $('input[name="method"]:checked').val() == "3" ? "fertilityCare" : "billings";
			var payload = {
				firstName: $("#i_firstname").val(),
				email: $("#i_login_email").val(),
				password: $("#i_password").val(),
				birthYear: parseInt($("#i_anaissance").val()),
				captcha: $("#i_captcha").val(),
				captchaToken: $("#i_captcha_token").val(),
				method: method,
				temperatureTracking: $("#m_temp").is(':checked'),
				discoveredComment: $("#i_comment").val(),
				okForResearch: $("#jc_research").is(':checked'),
			};
			$.ajax({type : 'POST', "url" : "api/register", contentType: "application/json", "data" : JSON.stringify(payload)}).done(function(ret){
				console.log(ret);
				// the answer is the same for a new address and for one that has an account: the page says only what is sure
				$("#success_email").text($("#i_login_email").val());
				$("#i_password, #i_password_conf").val("");
				$("#register_form").hide();
				$("#register_ok").show();
				if (!ret.data.sent) {
					$("#register_error_msg").text("Le mail n'a pas pu être envoyé. Utilisez « mot de passe perdu » sur la page de connexion pour recevoir un nouveau lien.");
				}
			}).fail(function(jqXHR) {
				console.error(jqXHR);
				var err = (jqXHR.responseJSON || {}).error || {};
				const return_codes = {
					already_authenticated: "Compte déjà connecté.",
					registration_disabled: "La création de compte est désactivée.",
					missing_field: "Données manquantes pour créer le compte.",
					invalid_email: "L'adresse email renseignée n'est pas valide.",
					captcha_invalid: "Erreur dans la saisie du captcha.",
					captcha_expired: "Le captcha a expiré, recopiez le nouveau.",
					captcha_unavailable: "Le captcha est indisponible pour le moment, réessayez plus tard.",
					rate_limited: "Trop de tentatives depuis ce réseau, réessayez plus tard.",
					password: "Le mot de passe est invalide.",
					password_too_short: "Le mot de passe est trop court (10 caractères au moins).",
					password_too_long: "Le mot de passe est trop long (72 octets au plus).",
					password_invalid: "Le mot de passe contient un caractère interdit.",
					birthYear: "La date de naissance n'est pas réaliste.",
					firstName: "Le prénom est invalide ou trop long.",
					discoveredComment: "Le commentaire est invalide ou trop long."
				};
				$("#register_error_msg").text(moncycle_store.error_pick(jqXHR, return_codes) || moncycle_store.error_text(jqXHR));
				reactivate_form();
				// a captcha that was refused is not asked again with the same answer
				if (err.code === "captcha_invalid" || err.code === "captcha_expired") register_captcha.reload(true);
			});
		});
	});
}
