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

// Every word of the mails to users, in French (one file per ACCOUNT_LANGUAGES code; read by mail_t() in lib/mail.php; how to add a language: docs/mail.md).
// A string is plain text or light HTML (<strong>, no attribute) with {name} marks the code fills. The no-break space is written \u{a0}.
// The slots the template escapes (title, greeting, why, button_*...) get plain text here, the ones it keeps (content, ps) may hold HTML.
return [
	// The frame (mail/layout.html)
	"layout.lang" => "fr",
	"layout.signoff" => "À bientôt,",
	"layout.button_fallback" => "Si le bouton ne fonctionne pas, copiez ce lien dans votre navigateur\u{a0}:",
	"layout.footer_auto" => "Ce mail a été envoyé automatiquement par MONCYCLE.APP. Une question\u{a0}? Répondez simplement à ce mail.",

	// Words and phrases said in several mails: written once here, used as {@key} (or by the code)
	"app.settings" => "«\u{a0}⚙️ Réglages\u{a0}»",
	"app.cycles_by_email" => "Cycles par e-mail",
	"action.sign_in" => "Connectez-vous",
	"action.choose_password" => "Choisir un nouveau mot de passe",
	"eyebrow.security" => "Sécurité",
	"eyebrow.email" => "Adresse e-mail",
	"notice.not_you" => "Ce n'est pas vous\u{a0}?",
	"notice.nothing_to_do" => "Si c'est vous, il n'y a rien à faire.",
	"notice.ignore" => "Si ce n'est pas vous, ignorez ce message.",
	"contact.reply" => "Répondez à ce mail ou écrivez-nous à {contact}.",
	"forgot.password" => "Mot de passe oublié\u{a0}? {reset}.",
	"forgot.reset_link" => "Choisissez-en un nouveau",
	"not_you.password" => "{login}, changez votre mot de passe depuis {@app.settings} et déconnectez vos autres appareils.",

	// Shared
	"greeting.named" => "Bonjour {name},",
	"greeting.anonymous" => "Bonjour,",
	"callout.not_you" => "<strong>{@notice.not_you}</strong> {html}",
	"subject.when" => "{title} ({when})",
	// How a date and a moment are written: PHP date() formats.
	"format.when" => "d/m H:i",
	"format.date" => "d/m/Y",
	"format.date_time" => 'd/m/Y \à H:i',
	"format.long_date" => "{day} {month} {year}",
	"date.first" => "1er",
	"months" => ["janvier", "février", "mars", "avril", "mai", "juin", "juillet", "août", "septembre", "octobre", "novembre", "décembre"],
	"lifetime.day" => "{n} jour",
	"lifetime.days" => "{n} jours",
	"lifetime.hour" => "{n} heure",
	"lifetime.hours" => "{n} heures",
	"lifetime.minutes" => "{n} minutes",

	// Why a mail came: "Vous recevez ce mail car {because}." and the reasons that complete it
	"why.lead" => "Vous recevez ce mail car {because}.",
	"why.necessary" => "La réception d'emails est nécessaire au bon fonctionnement de l'application. Si vous ne souhaitez plus recevoir d'emails de notre part, merci de ne plus utiliser MONCYCLE.APP.",
	"why.account" => "vous possédez un compte sur MONCYCLE.APP",
	"why.account_created" => "un compte a été créé sur MONCYCLE.APP avec cette adresse e-mail",
	"why.account_left" => "vous possédiez un compte sur MONCYCLE.APP avec cette adresse e-mail",
	"why.address_asked" => "quelqu'un a demandé à utiliser cette adresse e-mail pour un compte MONCYCLE.APP",
	"why.terms" => "{@why.account} et que nous devons vous informer de ce changement",
	"why.inactive" => "vous possédez un compte inactif sur MONCYCLE.APP; ce mail est nécessaire pour vous informer, conformément au RGPD, de la suppression prochaine de vos données en l'absence d'activité",
	"why.cycle" => "l’envoi automatique des cycles est activé sur votre compte",

	// The four mails of a link
	"link.valid" => "Ce lien est valable {lifetime}.",
	"link.once" => "Il ne peut servir qu'une fois.",
	"link.unverified" => "Sans confirmation, le compte est supprimé au bout de {days} jours.",
	"link.ps_ignore_account" => "{@notice.not_you} Ignorez ce message\u{a0}: votre compte et votre mot de passe n'ont pas changé.",
	"link.ps_ignore_password" => "{@notice.not_you} Ignorez ce message\u{a0}: votre mot de passe n'a pas changé.",
	"link.ps_ignore_email" => "{@notice.not_you} Ignorez ce message\u{a0}: rien ne sera changé.",

	"verify_email.preheader" => "Un clic pour activer votre compte MONCYCLE.APP.",
	"verify_email.eyebrow" => "Bienvenue",
	"verify_email.title" => "Confirmez votre adresse e-mail",
	"verify_email.intro" => "Bienvenue sur MONCYCLE.APP\u{a0}! Pour activer votre compte, confirmez votre adresse e-mail.",
	"verify_email.tip" => "<strong>Astuce\u{a0}:</strong> une fois connecté, activez l'authentification multifacteur dans {@app.settings} pour protéger vos données.",
	"verify_email.button" => "Confirmer mon adresse e-mail",

	"account_exists.preheader" => "Une inscription a été demandée avec cette adresse e-mail.",
	"account_exists.eyebrow" => "Votre compte",
	"account_exists.title" => "Vous avez déjà un compte",
	"account_exists.intro" => "Quelqu'un vient de demander la création d'un compte avec cette adresse e-mail, mais vous en avez déjà un.",
	"account_exists.forgotten" => "Si c'est vous et que vous avez perdu votre mot de passe, vous pouvez en choisir un nouveau.",
	"account_exists.button" => "{@action.choose_password}",

	"reset_password.preheader" => "Le lien pour choisir un nouveau mot de passe.",
	"reset_password.eyebrow" => "Mot de passe",
	"reset_password.title" => "{@action.choose_password}",
	"reset_password.intro" => "Vous avez demandé à changer votre mot de passe. Pour en choisir un nouveau, utilisez ce bouton\u{a0}:",
	"reset_password.button" => "{@action.choose_password}",

	"confirm_email.preheader" => "Confirmez cette adresse e-mail pour vous connecter à MONCYCLE.APP.",
	"confirm_email.eyebrow" => "{@eyebrow.email}",
	"confirm_email.title" => "Confirmez la nouvelle adresse e-mail",
	"confirm_email.intro" => "Le compte de <strong>{name}</strong> sur MONCYCLE.APP va utiliser cette adresse e-mail pour se connecter et recevoir ses cycles.",
	"confirm_email.button" => "{@confirm_email.title}",

	// Security notices
	"password_changed.preheader" => "Le mot de passe de votre compte vient d'être changé.",
	"password_changed.title" => "Votre mot de passe a été changé",
	"password_changed.intro" => "Le mot de passe de votre compte vient d'être changé. {@notice.nothing_to_do}",

	"email_change_requested.preheader" => "Un changement de votre adresse e-mail de connexion a été demandé.",
	"email_change_requested.title" => "Changement d'adresse e-mail demandé",
	"email_change_requested.intro" => "Le changement de l'adresse e-mail de connexion de votre compte vers <strong>{new}</strong> a été demandé. Il ne sera effectif qu'une fois la nouvelle adresse e-mail confirmée.",
	"email_change_requested.not_you" => "Demandez un nouveau mot de passe avec «\u{a0}mot de passe perdu\u{a0}» sur la page de connexion\u{a0}: cela annule la demande. Changez ensuite votre mot de passe.",

	"email_change_taken.preheader" => "Rien n'a été modifié sur votre compte.",
	"email_change_taken.title" => "Cette adresse e-mail est déjà utilisée",
	"email_change_taken.intro" => "Quelqu'un a demandé à utiliser cette adresse e-mail comme adresse e-mail de connexion d'un autre compte, mais c'est déjà celle de votre compte.",
	"email_change_taken.nothing" => "Rien n'a été modifié. {@notice.ignore}",

	"email_changed.preheader" => "Cette adresse e-mail ne reçoit plus rien de votre compte.",
	"email_changed.title" => "Adresse e-mail de connexion changée",
	"email_changed.intro" => "L'adresse e-mail de connexion de votre compte est maintenant <strong>{new}</strong>. Cette adresse e-mail n'a plus de lien avec votre compte.",
	"email_changed.not_you" => "Quelqu'un d'autre utilise votre compte\u{a0}: {@contact.reply}",

	"notification_emails.preheader" => "Les adresses e-mail qui reçoivent vos cycles ont changé.",
	"notification_emails.title" => "Adresses e-mail des cycles modifiées",
	"notification_emails.emptied" => "La liste des adresses e-mail de votre compte qui reçoivent vos cycles par e-mail a été <strong>vidée</strong>.",
	"notification_emails.listed" => "Les adresses e-mail de votre compte qui reçoivent vos cycles par e-mail, avec votre adresse e-mail de connexion, sont maintenant\u{a0}: <strong>{list}</strong>.",

	"new_login.title" => "Nouvelle connexion à votre compte",
	"new_login.subject" => "Nouvelle connexion, jeton {prefix}",
	"new_login.intro" => "Une nouvelle connexion à votre compte vient d'avoir lieu.",
	"new_login.fact_date" => "Date",
	"new_login.fact_name" => "Nom",
	"new_login.fact_token" => "Début du jeton",
	"new_login.fact_access" => "Accès",
	"new_login.fact_until" => "Valable jusqu'au",
	"new_login.fact_agent" => "Navigateur ou application",
	"new_login.access_read_only" => "lecture seule",
	"new_login.access_read_write" => "lecture et écriture",
	"new_login.agent_unknown" => "inconnu",
	"new_login.sessions" => "La liste de vos connexions s'y trouve aussi.",

	// A quiet account, then an account about to be erased
	"reminder.subject" => "Comment allez-vous\u{a0}? ({when})",
	"reminder.preheader" => "Votre tableau vous attend, et nous sommes là si besoin.",
	"reminder.eyebrow" => "Des nouvelles",
	"reminder.title" => "Tout va bien\u{a0}?",
	"reminder.quiet" => "Cela fait quelques semaines que votre tableau n'a pas reçu de nouvelle observation.",
	"reminder.pause" => "Si vous avez fait une pause, aucun souci\u{a0}: vos observations sont conservées et vous pouvez reprendre quand vous le souhaitez.",
	"reminder.feedback" => "<strong>Votre avis nous aide.</strong> Une question, une idée, quelque chose qui ne vous convient pas dans l'application\u{a0}? {@contact.reply} Nous lisons chaque message.",
	"reminder.button" => "Ouvrir mon tableau",
	"reminder.ps" => "Vous ne souhaitez plus utiliser MONCYCLE.APP\u{a0}? Ignorez ce mail, vous n'en recevrez pas d'autre.",

	"deletion_warning.subject" => "Suppression de votre compte le {date}",
	"deletion_warning.preheader" => "Connectez-vous d'ici {days} jours pour conserver votre compte.",
	"deletion_warning.eyebrow" => "Compte inactif",
	"deletion_warning.title" => "Votre compte sera bientôt supprimé",
	"deletion_warning.intro" => "Nous n'avons constaté aucune activité sur votre compte MONCYCLE.APP depuis longtemps.",
	"deletion_warning.warning" => "Conformément à notre politique de conservation des données (RGPD), votre compte et toutes les données associées seront <strong>définitivement supprimés dans {days} jours</strong> si aucune activité n'est détectée d'ici là.",
	"deletion_warning.keep" => "Pour conserver votre compte, il vous suffit de vous connecter.",
	"deletion_warning.button" => "Me connecter",
	"deletion_warning.ps" => "Un problème, besoin d'aide\u{a0}? {@contact.reply}",

	"terms_update.subject" => "Conditions d'utilisation: changement au {day}",
	"terms_update.preheader" => "Les nouvelles conditions entrent en vigueur le {day}.",
	"terms_update.eyebrow" => "Conditions d'utilisation",
	"terms_update.title" => "Nos conditions d'utilisation évoluent",
	"terms_update.intro" => "Nous mettons à jour les conditions générales d'utilisation de MONCYCLE.APP. Les nouvelles conditions entrent en vigueur le <strong>{day}</strong>.",
	"terms_update.accept" => "En continuant à utiliser MONCYCLE.APP après cette date, vous acceptez ces modifications. Si vous ne les acceptez pas, merci de ne plus utiliser l'application\u{a0}: vous pouvez supprimer votre compte depuis la page {@app.settings}.",
	"terms_update.button" => "Lire les nouvelles conditions",

	// A finished cycle, with its files
	"cycle.subject" => "Votre cycle {@cycle.range} ({@cycle.duration})",
	"cycle.preheader" => "Votre cycle de {n} jours est terminé : PDF, CSV et NFP en pièces jointes.",
	"cycle.eyebrow" => "Cycle terminé",
	"cycle.title" => "Votre cycle est terminé",
	"cycle.intro" => "Voici l’export complet de votre cycle, dans trois formats, pour le consulter, le partager ou le conserver.",
	"cycle.range" => "du {first} au {last}",
	"cycle.duration" => "{@lifetime.days}",
	"cycle.fact_cycle" => "Cycle",
	"cycle.fact_duration" => "Durée",
	"cycle.attachments" => "Dans les pièces jointes",
	"cycle.about_pdf" => "Le tableau de votre cycle, à partager avec votre moniteur ou votre instructrice.",
	"cycle.about_csv" => "Vos observations jour par jour, à ouvrir dans LibreOffice ou Microsoft Excel pour une analyse plus détaillée.",
	"cycle.about_nfp" => "Le fichier NFP contient toutes les données de ce cycle dans un format d’échange\u{a0}: conservez-le comme sauvegarde, vous pourrez le réimporter dans moncycle.app depuis {@app.settings}, section «\u{a0}Importer un fichier NFP\u{a0}». Il est également compatible avec d’autres applications si vous souhaitez migrer vos données.",
	"cycle.opt_out" => "Cet envoi est automatique. Vous pouvez le désactiver à tout moment\u{a0}: ouvrez «\u{a0}Réglages\u{a0}», section «\u{a0}{@app.cycles_by_email}\u{a0}», puis décochez «\u{a0}Recevoir chaque cycle par e-mail\u{a0}».",
	"cycle.manage" => "Gérer cet envoi",
];
