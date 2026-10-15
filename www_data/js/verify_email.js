/* moncycle.app
**
** licence Creative Commons CC BY-NC-SA
**
** https://www.moncycle.app
** https://github.com/moncycle-app/backend-api-web-app
*/

// The page of the link in a mail (verify_email.html), as a file of its own: the CSP is script-src 'self', so no inline script.
// The address holds the purpose and the token in its fragment ("#purpose=resetPassword&t=..."): a fragment is never sent to a server,
// written in a log or given as a Referer, and a mail scanner that only fetches the link does not run this script, so it uses nothing
// up. The fragment is read once and then taken off the address; the form below carries the purpose and the token (hidden fields) and
// is what is sent to POST /api/v2/verify_email.

$(document).ready(function(){
	const pages = {
		accountCreation: {
			title: "Confirmer votre adresse e-mail",
			text: "Un compte a été créé avec cette adresse. Confirmez-la pour pouvoir vous connecter.",
			button: "Confirmer mon adresse ✅",
			done: "Adresse confirmée! Vous pouvez maintenant vous connecter. 🎉",
		},
		resetPassword: {
			title: "Choisir un nouveau mot de passe",
			text: "Choisissez le nouveau mot de passe de votre compte. L'ancien ne servira plus.",
			button: "Enregistrer le mot de passe 💾",
			done: "Mot de passe changé! Vous pouvez vous connecter avec. 🎉",
			password: true,
		},
		changeEmail: {
			title: "Confirmer votre nouvelle adresse e-mail",
			text: "Cette adresse remplacera l'adresse de connexion du compte, et recevra ses cycles par mail.",
			button: "Confirmer cette adresse ✅",
			done: "Adresse changée! Elle est maintenant celle de connexion du compte. 🎉",
		},
	};

	const fragment = new URLSearchParams(window.location.hash.slice(1));
	window.history.replaceState(null, "", window.location.pathname + window.location.search);
	const purpose = fragment.get("purpose");
	const page = Object.prototype.hasOwnProperty.call(pages, purpose) ? pages[purpose] : null;
	if (page === null || !fragment.get("t")) {
		$("#et_invalid").show();
		return;
	}

	$("#i_purpose").val(purpose);
	$("#i_token").val(fragment.get("t"));
	$("#et_title").text(page.title);
	$("#et_text").text(page.text);
	$("#but_et").val(page.button);
	if (page.password) {
		$("#et_password_block").show();
		$("#i_new_password, #i_new_password_conf").prop("required", true);
	}
	$("#et_form_box").show();

	$("#f_verify_email").on("submit", function(event) {
		event.preventDefault();
		$("#et_error_msg").text("");
		if (page.password && $("#i_new_password").val() != $("#i_new_password_conf").val()) {
			$("#et_error_msg").text("Le mot de passe et sa confirmation ne sont pas identiques.");
			return;
		}
		$("#but_et").prop("disabled", true);
		var payload = {purpose: $("#i_purpose").val(), token: $("#i_token").val()};
		if (page.password) payload.newPassword = $("#i_new_password").val();
		$.ajax({
			type: "POST",
			url: "api/v2/verify_email",
			contentType: "application/json",
			data: JSON.stringify(payload),
		}).done(function(ret) {
			console.log(ret);
			$("#i_new_password, #i_new_password_conf, #i_token").val("");
			$("#et_form_box").hide();
			$("#et_done_text").text(page.done);
			// the address to sign in with, as the server knows it now
			$("#et_login_link").attr("href", "auth?login_email=" + encodeURIComponent(ret.data.loginEmail));
			$("#et_done").show();
		}).fail(function(jqXHR) {
			console.error(jqXHR);
			$("#but_et").prop("disabled", false);
			var err = (jqXHR.responseJSON || {}).error || {};
			const return_codes = {
				invalid_token: "Ce lien n'est plus valable: il a peut-être déjà servi, ou été remplacé par un plus récent.",
				token_expired: "Ce lien a expiré. Demandez-en un nouveau.",
				email_taken: "Cette adresse est déjà utilisée par un autre compte.",
				missing_field: "Données manquantes.",
				password_too_short: "Le mot de passe est trop court (10 caractères au moins).",
				password_too_long: "Le mot de passe est trop long (72 octets au plus).",
				password_invalid: "Le mot de passe contient un caractère interdit."
			};
			$("#et_error_msg").text(moncycle_store.error_pick(jqXHR, return_codes) || moncycle_store.error_text(jqXHR));
		});
	});
});
