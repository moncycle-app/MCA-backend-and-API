<?php
/* moncycle.app
**
** licence Creative Commons CC BY-NC-SA
**
** https://www.moncycle.app
** https://github.com/moncycle-app/backend-api-web-app
*/

declare(strict_types=1);

// The mails of the app. A name or an address comes from a user: it is escaped before it reaches the HTML.

use PHPMailer\PHPMailer\PHPMailer;

require_once __DIR__ . "/../vendor/autoload.php";
require_once __DIR__ . "/log.php";

// Sends one mail. $kind names it in the log, which keeps nothing else of it but how many addresses and files (never an address, a
// subject or a body: several hold a link that opens an account). Empty addresses are skipped; $attachments is [file name => content];
// $log_fields is what the caller adds to the log line. True when the SMTP server took it; never throws.
function mail_send(string $kind, array $to, string $subject, string $html, string $text, array $attachments = [], array $log_fields = []): bool {
	$log = ["kind" => $kind, "to" => count(array_filter($to)), "fil" => count($attachments)] + $log_fields;
	try {
		$mail = new PHPMailer();
		$mail->isSMTP();
		$mail->Host       = SMTP_HOST;
		$mail->SMTPAuth   = true;
		$mail->Username   = SMTP_MAIL;
		$mail->Password   = SMTP_PASSWORD;
		$mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
		$mail->Port       = SMTP_PORT;
		$mail->Timeout    = 10;   // seconds; PHPMailer's 300 would hold a worker for minutes on a stalled server
		$mail->CharSet    = 'UTF-8';
		$mail->setFrom(SMTP_MAIL, 'MONCYCLE.APP');

		foreach (array_filter($to) as $address) $mail->addAddress($address, $address);
		$mail->isHTML(true);
		$mail->Subject = $subject;
		$mail->Body = $html;
		$mail->AltBody = $text;
		foreach ($attachments as $file_name => $content) $mail->addStringAttachment($content, $file_name);

		if ($mail->send()) {
			log_event("mail.sent", $log);
			return true;
		}
		log_event("mail.failed", $log + ["msg" => log_scrub($mail->ErrorInfo)]);
		return false;
	} catch (\Throwable $e) {
		log_event("mail.failed", $log + ["msg" => get_class($e)]);
		return false;
	}
}

// The frame of every mail but the cycle's: the greeting, the content, the sign-off, and why it was sent.
function mail_html(string $greeting, string $content, string $why): string {
	return <<<HTML
	<div style='font-family: sans-serif;'>{$greeting}<br />
	<br />
	{$content}
	À bientôt,<br />
	<br />
	<a href='https://www.moncycle.app' style='color: unset; text-decoration:none'>mon<span style='color: #1e824c;font-weight:bold'>cycle</span>.app</a><br />
	<br />
	<p style="color:gray;font-size:.85em;font-style: italic;">Merci d'utiliser MONCYCLE.APP! Ce mail a été envoyé automatiquement, merci de ne pas y répondre. {$why}</p><br />
	</div>
	HTML;
}

// Who gets what the owner is meant to read (the cycles, the reminder, the warning before an erasure): the login address and the
// notification list, once each.
function mail_recipients(array $account): array {
	$list = explode(ACCOUNT_NOTIFICATION_EMAILS_SEPARATOR, $account["notification_emails"] ?? "");
	return array_values(array_unique(array_filter(array_merge([$account["login_email"]], $list))));
}

// $because completes "Vous le recevez car ...".
function mail_why(string $because = "vous possédez un compte sur MONCYCLE.APP"): string {
	return "Vous le recevez car $because. La réception d'emails est nécessaire au bon fonctionnement de l'application. Si vous ne souhaitez plus recevoir d'emails de notre part, merci de ne plus utiliser MONCYCLE.APP.";
}

// A page of the app with the address filled in.
function mail_link(string $email, string $page, string $text): string {
	return "<a style='color: #1e824c' href='" . rtrim(APP_URL, "/") . "/" . $page . "?login_email=" . urlencode($email) . "'>" . $text . "</a>";
}

// The mail of a link (lib/email_token.php), $kind: "verify_email" (a new account; it is erased after ACCOUNT_UNVERIFIED_DELETE_DAYS
// unproved), "account_exists" (a sign-up with an address that has an account: a new password may be chosen), "reset_password", or
// "confirm_email" (to the new address of a change). The link is shown in full as its own text, so that any mail reader lets it be
// copied whole; the token is in its fragment, which no server, log or Referer sees.
function mail_send_link(string $kind, string $email, string $name, string $token): bool {
	$purpose = match ($kind) {
		"verify_email" => EMAIL_TOKEN_PURPOSE_ACCOUNT_CREATION,
		"confirm_email" => EMAIL_TOKEN_PURPOSE_CHANGE_EMAIL,
		default => EMAIL_TOKEN_PURPOSE_RESET_PASSWORD,
	};
	$url = rtrim(APP_URL, "/") . "/verify_email#purpose=" . EMAIL_TOKEN_PURPOSE_NAMES[$purpose] . "&t=" . $token;
	$shown = htmlspecialchars($url);
	$link = "<a style='color: #1e824c; word-break: break-all' href='{$shown}'>{$shown}</a>";
	$minutes = EMAIL_TOKEN_LIFETIME_MINUTES[$purpose];
	$lifetime = match (true) {
		$minutes % 1440 === 0 => ($minutes / 1440) . ($minutes > 1440 ? " jours" : " jour"),
		$minutes % 60 === 0 => ($minutes / 60) . ($minutes > 60 ? " heures" : " heure"),
		default => $minutes . " minutes",
	};
	$name = htmlspecialchars($name);
	$days = ACCOUNT_UNVERIFIED_DELETE_DAYS;

	[$subject, $greeting, $content, $why, $text] = match ($kind) {
		"verify_email" => ['Confirmez votre adresse e-mail', "Bonjour {$name},", <<<HTML
			Bienvenue sur MONCYCLE.APP!<br />
			<br />
			Pour activer votre compte, confirmez votre adresse e-mail en ouvrant ce lien:<br />
			{$link}<br />
			<br />
			Ce lien est valable {$lifetime}. Sans confirmation, le compte est supprimé au bout de {$days} jours.<br />
			<br />
			Une fois connecté, pensez à activer l'authentification multifacteur pour protéger vos données.<br />
			<br />
			HTML, mail_why("un compte a été créé sur MONCYCLE.APP avec cette adresse"),
			"Bienvenue sur MONCYCLE.APP!\n\nPour activer votre compte, confirmez votre adresse e-mail en ouvrant ce lien:\n"],
		"account_exists" => ['Vous avez déjà un compte', "Bonjour {$name},", <<<HTML
			Quelqu'un vient de demander la création d'un compte avec cette adresse, mais vous en avez déjà un.<br />
			<br />
			Si c'est vous et que vous avez perdu votre mot de passe, vous pouvez en choisir un nouveau en ouvrant ce lien (valable {$lifetime}):<br />
			{$link}<br />
			<br />
			Sinon, ignorez ce message: votre compte et votre mot de passe n'ont pas changé.<br />
			<br />
			HTML, mail_why(), "Vous avez déjà un compte sur MONCYCLE.APP.\n\nPour choisir un nouveau mot de passe, ouvrez ce lien:\n"],
		"reset_password" => ['Choisissez un nouveau mot de passe', "Bonjour {$name},", <<<HTML
			Vous avez demandé à changer votre mot de passe. Pour en choisir un nouveau, ouvrez ce lien:<br />
			{$link}<br />
			<br />
			Ce lien est valable {$lifetime} et ne peut servir qu'une fois.<br />
			<br />
			Si ce n'est pas vous, ignorez ce message: votre mot de passe n'a pas changé.<br />
			<br />
			HTML, mail_why(), "Pour choisir un nouveau mot de passe, ouvrez ce lien:\n"],
		"confirm_email" => ['Confirmez votre nouvelle adresse e-mail', "Bonjour,", <<<HTML
			Le compte de {$name} sur MONCYCLE.APP va utiliser cette adresse pour se connecter et recevoir ses cycles.<br />
			<br />
			Pour le confirmer, ouvrez ce lien (valable {$lifetime}):<br />
			{$link}<br />
			<br />
			Sinon, ignorez ce message: rien ne sera changé.<br />
			<br />
			HTML, mail_why("quelqu'un a demandé à utiliser cette adresse pour un compte MONCYCLE.APP"),
			"Pour confirmer cette adresse comme adresse de connexion de votre compte moncycle.app, ouvrez ce lien:\n"],
	};
	return mail_send($kind, [$email], $subject, mail_html($greeting, $content, $why), $text . $url);
}

// A password replaced by a link: the sessions already open stay open, so this mail is how the owner learns of it.
function mail_send_password_changed(string $email, string $name): bool {
	$name = htmlspecialchars($name);
	$login = mail_link($email, "auth", "connectez-vous");
	$content = <<<HTML
	Le mot de passe de votre compte vient d'être changé.<br />
	<br />
	Si ce n'est pas vous, {$login}, changez votre mot de passe depuis la page "👨‍💻 Mon compte" et déconnectez vos autres appareils.<br />
	<br />
	HTML;
	return mail_send('password_changed', [$email], 'Votre mot de passe a changé', mail_html("Bonjour {$name},", $content, mail_why()),
		'Le mot de passe de votre compte moncycle.app vient d\'être changé. Si ce n\'est pas vous, changez-le et déconnectez vos autres appareils.');
}

// To the current address: a change to $new_email was asked for, and happens once the new address is confirmed.
function mail_send_email_change_requested(string $email, string $new_email, string $name): bool {
	$name = htmlspecialchars($name);
	$new = htmlspecialchars($new_email);
	$content = <<<HTML
	Le changement de l'adresse de connexion de votre compte vers <b>{$new}</b> a été demandé. Il ne sera effectif qu'une fois la nouvelle adresse confirmée.<br />
	<br />
	Si ce n'est pas vous, demandez un nouveau mot de passe avec « mot de passe perdu » sur la page de connexion: cela annule la demande. Changez ensuite votre mot de passe.<br />
	<br />
	HTML;
	return mail_send('email_change_requested', [$email], 'Changement de votre adresse de connexion demandé', mail_html("Bonjour {$name},", $content, mail_why()),
		'Le changement de votre adresse de connexion vers ' . $new_email . ' a été demandé. Si ce n\'est pas vous, utilisez « mot de passe perdu » pour l\'annuler.');
}

// To an address that has an account, asked for another one: nothing changes, and nobody is told which.
function mail_send_email_change_taken(string $email): bool {
	$content = <<<HTML
	Quelqu'un a demandé à utiliser cette adresse comme adresse de connexion d'un autre compte, mais elle est déjà celle de votre compte.<br />
	<br />
	Rien n'a été modifié. Si ce n'est pas vous, ignorez ce message.<br />
	<br />
	HTML;
	return mail_send('email_change_taken', [$email], 'Cette adresse est déjà utilisée', mail_html("Bonjour,", $content, mail_why()),
		'Quelqu\'un a demandé à utiliser cette adresse pour un autre compte, mais elle est déjà celle de votre compte. Rien n\'a changé.');
}

// To the address the account just left.
function mail_send_email_changed(string $email, string $new_email, string $name): bool {
	$name = htmlspecialchars($name);
	$new = htmlspecialchars($new_email);
	$content = <<<HTML
	L'adresse de connexion de votre compte est maintenant <b>{$new}</b>. Cette adresse-ci ne reçoit plus rien de votre compte.<br />
	<br />
	Si ce n'est pas vous, quelqu'un d'autre utilise votre compte: contactez-nous depuis <a style='color: #1e824c' href='https://www.moncycle.app'>moncycle.app</a>.<br />
	<br />
	HTML;
	return mail_send('email_changed', [$email], 'L\'adresse de votre compte a changé',
		mail_html("Bonjour {$name},", $content, mail_why("vous possédiez un compte sur MONCYCLE.APP avec cette adresse")),
		'L\'adresse de connexion de votre compte moncycle.app est maintenant ' . $new_email . '.');
}

// To the login address: the addresses that receive every cycle changed ("" = emptied), which whoever holds the account must see.
function mail_send_notification_emails_changed(string $email, string $new_list): bool {
	$listed = str_replace(ACCOUNT_NOTIFICATION_EMAILS_SEPARATOR, ", ", $new_list);
	$shown = htmlspecialchars($listed);
	$what = $new_list === ""
		? "La liste des adresses de votre compte qui reçoivent vos cycles par e-mail a été <b>vidée</b>."
		: "Les adresses de votre compte qui reçoivent vos cycles par e-mail, avec votre adresse de connexion, sont maintenant: <b>{$shown}</b>.";
	$login = mail_link($email, "auth", "connectez-vous");
	$content = <<<HTML
	{$what}<br />
	Si ce n'est pas vous, {$login} et changez votre mot de passe depuis la page "👨‍💻 Mon compte".<br />
	<br />
	HTML;
	return mail_send('notification_emails', [$email], 'Les adresses qui reçoivent vos cycles ont changé', mail_html("Bonjour,", $content, mail_why()),
		$new_list === ""
			? 'La liste des adresses de votre compte qui reçoivent vos cycles a été vidée.'
			: 'Les adresses de votre compte qui reçoivent vos cycles, avec votre adresse de connexion, sont maintenant: ' . $listed . '.');
}

// To the account's own address at every sign-in, a browser's or a custom token's: a custom token outlives the session it is made from,
// and a stolen password is first used to sign in. The name and the user agent are the client's own words (escaped); no address, as a
// mail crosses servers in clear. $prefix (shown on the account page too) tells which connection of the list this is. $expires_at:
// "Y-m-d H:i:s" UTC, null for the rule of the cron.
function mail_send_new_login(array $account, string $name, string $prefix, string $scope, ?string $user_agent, ?string $expires_at): bool {
	$email = $account["login_email"];
	$account_name = htmlspecialchars($account["name"]);
	$token_name = htmlspecialchars($name);
	$token_prefix = htmlspecialchars($prefix);
	$agent = is_null($user_agent) ? "inconnu" : htmlspecialchars(mb_substr($user_agent, 0, MAIL_USER_AGENT_CHARS));
	$access = $scope === "readOnly" ? "lecture seule" : "lecture et écriture";
	$until = is_null($expires_at) ? "" : "<br />Valable jusqu'au <b>" . gmdate("d/m/Y", strtotime($expires_at . " UTC")) . "</b>";
	$login = mail_link($email, "auth", "connectez-vous");
	$content = <<<HTML
	Une nouvelle connexion à votre compte vient d'avoir lieu.<br />
	<br />
	Nom: <b>{$token_name}</b><br />
	Début du jeton: <b><code>{$token_prefix}</code></b><br />
	Accès: {$access}{$until}<br />
	Navigateur ou application: {$agent}<br />
	<br />
	Si c'est vous, il n'y a rien à faire. Sinon, {$login}, changez votre mot de passe depuis la page "👨‍💻 Mon compte" et cochez "Déconnecter tous mes autres appareils": la liste de vos connexions s'y trouve aussi.<br />
	<br />
	HTML;
	return mail_send('new_login', [$email], 'Nouvelle connexion à votre compte', mail_html("Bonjour {$account_name},", $content, mail_why()),
		"Une nouvelle connexion à votre compte moncycle.app vient d'avoir lieu ($name, début du jeton {$prefix}). Si ce n'est pas vous, changez votre mot de passe.");
}

function mail_send_reminder(array $account): bool {
	$name = htmlspecialchars($account["name"]);
	$email = $account["login_email"];
	$reset = mail_link($email, "register", "Réinitialisez votre mot de passe");
	$login = mail_link($email, "auth", "C'est par ici");
	$content = <<<HTML
	Cela fait un moment qu'il n'y a pas eu d'activité dans votre tableau.<br />
	<br />
	Tout va bien? Comment pouvons-nous vous aider?<br />
	<ol type='a'>
		<li>Vous avez perdu votre mot de passe?<br />{$reset}</li>
		<li style="margin-top: 10px">L'application ne vous plaît pas?<br /><a style='color: #1e824c' href='https://forms.gle/aA3GrFHAAx8SFdd47'>Dîtes-nous tout</a></li>
		<li style="margin-top: 10px">Vous souhaitez simplement vous connecter?<br />{$login}</li>
		<li style="margin-top: 10px">Un problème? Besoin d'aide?<br />Envoyez-nous un mail à <a style='color: #1e824c' href='mailto:bonjour@moncycle.app'>bonjour@moncycle.app</a></li>
		<li style="margin-top: 10px">Vous ne souhaitez plus utiliser MONCYCLE.APP?<br />Ignorez ce mail, vous n'en recevrez plus d'autre.</li>
	</ol>
	HTML;
	$why = "Vous le recevez car vous possédez un compte sur MONCYCLE.APP. La réception d'emails est nécessaire au bon fonctionnement de l'application. Si vous ne souhaitez plus recevoir d'emails de notre part, ignorez ce mail, vous n'en recevrez plus d'autre.";
	return mail_send('reminder', mail_recipients($account), "Comment allez-vous?", mail_html("Bonjour {$name},", $content, $why),
		"Cela fait longtemps que l'on ne vous a pas vu sur moncycle.app, tout va bien?");
}

function mail_send_deletion_warning(array $account, int $days): bool {
	$name = htmlspecialchars($account["name"]);
	$email = $account["login_email"];
	$login = mail_link($email, "auth", "connectez-vous");
	$reset = mail_link($email, "register", "Réinitialisez-le");
	$content = <<<HTML
	Nous n'avons constaté aucune activité sur votre compte MONCYCLE.APP depuis longtemps.<br />
	<br />
	Conformément à notre politique de conservation des données (RGPD), votre compte et toutes les données associées seront <b>définitivement supprimés dans {$days} jours</b> si aucune activité n'est détectée d'ici là.<br />
	<br />
	Pour conserver votre compte, il vous suffit de vous connecter:<br />
	{$login}<br />
	<br />
	Mot de passe oublié?<br />{$reset}<br />
	<br />
	Un problème? Besoin d'aide? Envoyez-nous un mail à <a style='color: #1e824c' href='mailto:bonjour@moncycle.app'>bonjour@moncycle.app</a><br />
	<br />
	HTML;
	$why = "Vous le recevez car vous possédez un compte inactif sur MONCYCLE.APP; ce mail est nécessaire pour vous informer, conformément au RGPD, de la suppression prochaine de vos données en l'absence d'activité.";
	return mail_send('deletion_warning', mail_recipients($account), "Votre compte moncycle.app va être supprimé dans $days jours",
		mail_html("Bonjour {$name},", $content, $why),
		"Faute d'activité, votre compte moncycle.app sera supprimé dans $days jours. Connectez-vous pour le conserver.");
}

// ---------------------------------------------------------------------------
// The mail of a finished cycle: a layout of its own (a card, the cycle in figures, a row per attachment), and no "mails are needed"
// footer, since the user can turn this one off.
// ---------------------------------------------------------------------------

// What each attachment is for, and how to turn the mail off as the account page words it: shared by the HTML and the text.
function mail_cycle_words(): array {
	return [
		"pdf" => "Le graphique de votre cycle, à partager avec votre moniteur ou votre instructrice.",
		"csv" => "Vos observations jour par jour, à ouvrir dans LibreOffice ou Microsoft Excel pour une analyse plus détaillée.",
		"nfp" => "Le fichier NFP contient toutes les données de ce cycle dans un format d’échange : conservez-le comme sauvegarde, vous pourrez le réimporter dans moncycle.app depuis « Mon compte », section « Importer mes données ». Il est également compatible avec d’autres applications si vous souhaitez migrer vos données.",
		"opt_out" => "Cet envoi est automatique. Vous pouvez le désactiver à tout moment : ouvrez « Mon compte », section « Exporter mes données », puis décochez « Recevoir automatiquement chaque cycle par e-mail ».",
	];
}

// An escaped sentence with French typography: no line break inside « », nor before a colon.
function mail_cycle_typography(string $text): string {
	return str_replace(["« ", " »", " :"], ["«&nbsp;", "&nbsp;»", "&nbsp;:"], htmlspecialchars($text));
}

// One attachment: a badge with its format, and what it is for ($about escaped by the caller).
function mail_cycle_file(string $format, string $color, string $tint, string $about): string {
	return <<<HTML
	<tr>
		<td width="52" valign="top" style="padding:0 14px 18px 0;">
			<div style="width:52px;line-height:30px;text-align:center;border-radius:8px;background:{$tint};color:{$color};font-size:12px;font-weight:700;letter-spacing:.6px;">{$format}</div>
		</td>
		<td valign="top" style="padding:0 0 18px 0;font-size:14px;line-height:21px;color:#3d4a42;">{$about}</td>
	</tr>
	HTML;
}

function mail_cycle_html(string $name, string $first_day, string $last_day, int $nb_days): string {
	$name = htmlspecialchars($name);
	$account_url = APP_URL . "account";
	$words = mail_cycle_words();
	$files = mail_cycle_file("PDF", "#b03a2e", "#fbeceb", mail_cycle_typography($words["pdf"]))
		. mail_cycle_file("CSV", "#1e824c", "#e6f4ec", mail_cycle_typography($words["csv"]))
		. mail_cycle_file("NFP", "#3949ab", "#eaecf8", mail_cycle_typography($words["nfp"]));
	$opt_out = mail_cycle_typography($words["opt_out"]);
	$wordmark = "mon<span style='color:#1e824c;'>cycle</span>.app";
	return <<<HTML
	<!DOCTYPE html>
	<html lang="fr">
	<head>
		<meta charset="utf-8" />
		<meta name="viewport" content="width=device-width, initial-scale=1" />
		<meta name="color-scheme" content="light only" />
		<meta name="supported-color-schemes" content="light only" />
		<title>Votre cycle est terminé</title>
	</head>
	<body style="margin:0;padding:0;background:#f1f5f2;">
	<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:#f1f5f2;font-size:1px;line-height:1px;">Votre cycle de {$nb_days} jours est terminé : PDF, CSV et NFP en pièces jointes.</div>
	<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#f1f5f2" style="background:#f1f5f2;">
	<tr><td align="center" style="padding:28px 12px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
		<table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:600px;">
			<tr><td style="padding:0 6px 14px 6px;font-size:20px;font-weight:700;letter-spacing:-.2px;color:#1b2a21;">{$wordmark}</td></tr>
			<tr><td bgcolor="#ffffff" style="background:#ffffff;border:1px solid #e1e9e3;border-radius:16px;overflow:hidden;">
				<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
					<tr><td height="6" bgcolor="#1e824c" style="height:6px;line-height:6px;font-size:0;background:#1e824c;">&nbsp;</td></tr>
					<tr><td style="padding:32px 32px 6px 32px;">
						<div style="font-size:12px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:#1e824c;">Cycle terminé</div>
						<div style="padding-top:8px;font-size:24px;line-height:30px;font-weight:700;color:#1b2a21;">Bonjour {$name},</div>
						<div style="padding-top:10px;font-size:15px;line-height:23px;color:#3d4a42;">Voici l’export complet de votre cycle, dans trois formats, pour le consulter, le partager ou le conserver.</div>
					</td></tr>
					<tr><td style="padding:22px 32px 6px 32px;">
						<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#eef7f1" style="background:#eef7f1;border-radius:12px;">
							<tr>
								<td style="padding:18px 22px;">
									<div style="font-size:12px;letter-spacing:.8px;text-transform:uppercase;color:#5b7566;">Cycle</div>
									<div style="padding-top:3px;font-size:17px;line-height:23px;font-weight:600;color:#17301f;">du {$first_day} au {$last_day}</div>
								</td>
								<td align="right" style="padding:18px 22px;">
									<div style="font-size:34px;line-height:36px;font-weight:700;color:#1e824c;">{$nb_days}</div>
									<div style="font-size:12px;letter-spacing:.8px;text-transform:uppercase;color:#5b7566;">jours</div>
								</td>
							</tr>
						</table>
					</td></tr>
					<tr><td style="padding:26px 32px 0 32px;">
						<div style="padding-bottom:16px;font-size:16px;font-weight:700;color:#1b2a21;">Dans les pièces jointes</div>
						<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
							{$files}
						</table>
					</td></tr>
					<tr><td style="padding:6px 32px 8px 32px;">
						<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#f6f7f6" style="background:#f6f7f6;border-radius:12px;">
							<tr><td style="padding:16px 20px;font-size:13px;line-height:20px;color:#4b574f;">
								{$opt_out}
								<div style="padding-top:12px;"><a href="{$account_url}" style="display:inline-block;padding:9px 16px;border-radius:8px;background:#1e824c;color:#ffffff;font-size:13px;font-weight:600;text-decoration:none;">Gérer cet envoi</a></div>
							</td></tr>
						</table>
					</td></tr>
					<tr><td style="padding:22px 32px 30px 32px;font-size:15px;line-height:23px;color:#3d4a42;">À bientôt sur <a href="https://www.moncycle.app" style="color:#1b2a21;font-weight:700;text-decoration:none;">{$wordmark}</a></td></tr>
				</table>
			</td></tr>
			<tr><td style="padding:16px 10px 0 10px;font-size:12px;line-height:18px;color:#7a867f;text-align:center;">
				Ce mail a été envoyé automatiquement par MONCYCLE.APP, merci de ne pas y répondre.<br />
				Vous le recevez car l’envoi automatique des cycles est activé sur votre compte.
			</td></tr>
		</table>
	</td></tr>
	</table>
	</body>
	</html>
	HTML;
}

function mail_cycle_text(string $name, string $first_day, string $last_day, int $nb_days): string {
	$account_url = APP_URL . "account";
	$words = mail_cycle_words();
	return "Bonjour {$name},\n\n"
		. "Votre cycle est terminé : du {$first_day} au {$last_day}, {$nb_days} jours. Voici son export complet, en pièces jointes.\n\n"
		. "- PDF : {$words["pdf"]}\n"
		. "- CSV : {$words["csv"]}\n"
		. "- NFP : {$words["nfp"]}\n\n"
		. "{$words["opt_out"]}\n{$account_url}\n\n"
		. "À bientôt sur moncycle.app\n\n"
		. "Ce mail a été envoyé automatiquement par MONCYCLE.APP, merci de ne pas y répondre. Vous le recevez car l'envoi automatique des cycles est activé sur votre compte.";
}

// The export of a finished cycle (a row of db_select_cycles_finished()), with $files: [file name => content].
function mail_send_cycle(array $account, string $first_day, string $last_day, int $nb_days, array $files, int $rows_read): bool {
	return mail_send('cycle', mail_recipients($account), "Votre cycle du $first_day au $last_day ($nb_days jours)",
		mail_cycle_html($account["name"], $first_day, $last_day, $nb_days),
		mail_cycle_text($account["name"], $first_day, $last_day, $nb_days), $files, ["n" => $rows_read]);
}
