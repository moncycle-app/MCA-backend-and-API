/* moncycle.app
**
** licence Creative Commons CC BY-NC-SA
**
** https://www.moncycle.app
** https://github.com/moncycle-app/backend-api-web-app
*/

// The captcha of a form (register, sign-in once an account asks for it, lost password). The server keeps nothing: POST
// /api/captcha gives a picture and a signed token for a purpose and an e-mail address, the visitor types what the picture
// says, and the form posts the answer (`captcha`) with the token (`captchaToken`). The token is tied to the address
// and, for a sign-in or a lost password, to the account's state: a new one is asked for whenever the address changes
// and after anything the server refused. The picture is set with .attr("src") and nothing from the server is ever
// written into the page as HTML.

var moncycle_captcha = (function () {
	const JPEG_DATA_URI = /^data:image\/jpeg;base64,[A-Za-z0-9+\/]+={0,2}$/;
	const TOKEN = /^[0-9]{1,12}\.[0-9a-f]{64}$/;
	const TYPING_PAUSE_MS = 500;
	// An address worth asking a picture for: the browser's own check lets "marie@example" through, the server does not (a dot is
	// needed in the domain), and asking for a picture of an address that is still being typed would only draw an error.
	const COMPLETE_ADDRESS = /@[^@]*\.[^@.]+$/;

	const load_errors = {
		captcha_unavailable: "Le captcha est indisponible pour le moment, réessayez plus tard.",
		maintenance: "Le service est en maintenance, réessayez plus tard.",
	};

	// attach(options) wires one captcha field set to its e-mail field. options:
	//   purpose  "register", "recover" or "login"
	//   email    the jQuery input whose value is the address
	//   image    the <img> that shows the picture      token  the hidden input that holds the token
	//   answer   the text input the visitor types in
	//   active   (optional) a function: false while this captcha is not asked for (the sign-in form before an account needs one)
	//   error    (optional) a function(message) told why a picture could not be loaded, and called with "" when there is nothing more
	//            to say (a picture loaded, or an address that is not complete): see message_in()
	// It answers {reload(force), clear()}: reload() asks for a new challenge if the address is a valid one and differs from the
	// one the picture was made for, reload(true) asks anyway (after an error, or a click on the picture).
	function attach(options) {
		let requested = 0;    // the number of the last request: an answer to an older one is dropped
		let loaded_for = "";  // the address of the picture on show
		let timer = null;

		const clear = function () {
			requested++;
			loaded_for = "";
			options.image.removeAttr("src").prop("hidden", true);
			options.token.val("");
			options.answer.val("");
		};

		const reload = function (force) {
			window.clearTimeout(timer);
			const field = options.email[0];
			const email = options.email.val().trim();
			if ((options.active && !options.active()) || email === "" || !field.checkValidity() || !COMPLETE_ADDRESS.test(email)) {
				clear();
				if (options.error) options.error("");
				return;
			}
			if (!force && email === loaded_for) return;

			const mine = ++requested;
			$.ajax({
				type: "POST",
				url: "api/captcha",
				contentType: "application/json",
				data: JSON.stringify({purpose: options.purpose, email: email}),
			}).done(function (ret) {
				if (mine !== requested) return;
				const data = (ret || {}).data || {};
				if (typeof data.image !== "string" || !JPEG_DATA_URI.test(data.image) || typeof data.token !== "string" || !TOKEN.test(data.token)) {
					clear();
					if (options.error) options.error("Le captcha n'a pas pu être chargé.");
					return;
				}
				if (options.error) options.error("");
				loaded_for = email;
				options.image.attr("src", data.image).prop("hidden", false);
				options.token.val(data.token);
				options.answer.val("");
			}).fail(function (jqXHR) {
				if (mine !== requested) return;
				clear();
				const code = moncycle_store.error_code(jqXHR);
				// an address the server finds incomplete is not a failure of the captcha: there is just no picture yet
				if (options.error) options.error(code === "invalid_email" ? "" : load_errors[code] || "Le captcha n'a pas pu être chargé.");
			});
		};

		// typing: wait for a pause; leaving the field: at once
		options.email.on("input", function () {
			window.clearTimeout(timer);
			timer = window.setTimeout(function () { reload(false); }, TYPING_PAUSE_MS);
		});
		options.email.on("change", function () { reload(false); });
		options.image.on("click", function () { reload(true); });

		return {reload: reload, clear: clear};
	}

	// The `error` option for a form that has a place for the message: it writes the message in `element` (as text, never as HTML), and
	// an empty message takes it off again, but only if it is still the one written here (a refusal of the form itself that came since
	// is left alone).
	function message_in(element) {
		let mine = "";
		return function (message) {
			if (message === "") {
				if (mine !== "" && element.text() === mine) element.text("");
				mine = "";
				return;
			}
			mine = message;
			element.text(message);
		};
	}

	return {attach: attach, message_in: message_in};
})();
