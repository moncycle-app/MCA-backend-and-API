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

// Every word of the mails to users, in French (MAIL_LANG; read by mail_t() in lib/mail.php; how to add a language: docs/mail.md).
// A string is plain text or light HTML (<strong>, no attribute) with {name} marks the code fills. The no-break space is written \u{a0}.
// The slots the template escapes (title, greeting, why, button_*...) get plain text here, the ones it keeps (content, ps) may hold HTML.
return [
	// The frame (mail/layout.html)
	"layout.lang" => "fr",
	"layout.signoff" => "À bientôt,",
	"layout.button_fallback" => "Si le bouton ne fonctionne pas, copiez ce lien dans votre navigateur\u{a0}:",
	"layout.footer_auto" => "Ce mail a été envoyé automatiquement par MONCYCLE.APP. Une question\u{a0}? Répondez simplement à ce mail.",

	// Shared
	"greeting.named" => "Bonjour {name},",
	"greeting.anonymous" => "Bonjour,",
	"callout.not_you" => "<strong>Ce n'est pas vous\u{a0}?</strong> {html}",
	// How a date and a moment are written: PHP date() formats.
	"format.when" => "d/m H:i",
	"format.date" => "d/m/Y",
	"format.date_time" => 'd/m/Y \à H:i',
	"date.first" => "1er",
	"months" => ["janvier", "février", "mars", "avril", "mai", "juin", "juillet", "août", "septembre", "octobre", "novembre", "décembre"],
	"lifetime.day" => "{n} jour",
	"lifetime.days" => "{n} jours",
	"lifetime.hour" => "{n} heure",
	"lifetime.hours" => "{n} heures",
	"lifetime.minutes" => "{n} minutes",

	// Why a mail came: "Vous le recevez car {because}." and the reasons that complete it
	"why.default" => "Vous le recevez car {because}. La réception d'emails est nécessaire au bon fonctionnement de l'application. Si vous ne souhaitez plus recevoir d'emails de notre part, merci de ne plus utiliser MONCYCLE.APP.",
	"why.account" => "vous possédez un compte sur MONCYCLE.APP",
	"why.account_created" => "un compte a été créé sur MONCYCLE.APP avec cette adresse",
	"why.account_left" => "vous possédiez un compte sur MONCYCLE.APP avec cette adresse",
	"why.address_asked" => "quelqu'un a demandé à utiliser cette adresse pour un compte MONCYCLE.APP",
	"why.terms" => "vous possédez un compte sur MONCYCLE.APP et que nous devons vous informer de ce changement",
	"why.reminder" => "Vous le recevez car vous possédez un compte sur MONCYCLE.APP.",
	"why.deletion" => "Vous le recevez car vous possédez un compte inactif sur MONCYCLE.APP; ce mail est nécessaire pour vous informer, conformément au RGPD, de la suppression prochaine de vos données en l'absence d'activité.",
	"why.cycle" => "Vous le recevez car l’envoi automatique des cycles est activé sur votre compte.",

	// The four mails of a link
	"link.ps_ignore_account" => "Ce n'est pas vous\u{a0}? Ignorez ce message\u{a0}: votre compte et votre mot de passe n'ont pas changé.",
	"link.ps_ignore_password" => "Ce n'est pas vous\u{a0}? Ignorez ce message\u{a0}: votre mot de passe n'a pas changé.",
	"link.ps_ignore_email" => "Ce n'est pas vous\u{a0}? Ignorez ce message\u{a0}: rien ne sera changé.",
	"link.note_once" => "Ce lien est valable {lifetime} et ne peut servir qu'une fois.",
	"link.note_lifetime" => "Ce lien est valable {lifetime}.",
	"link.note_unverified" => "Ce lien est valable {lifetime}. Sans confirmation, le compte est supprimé au bout de {days} jours.",

	"verify_email.subject" => "Confirmez votre adresse ({when})",
	"verify_email.preheader" => "Un clic pour activer votre compte MONCYCLE.APP.",
	"verify_email.eyebrow" => "Bienvenue",
	"verify_email.title" => "Confirmez votre adresse e-mail",
	"verify_email.intro" => "Bienvenue sur MONCYCLE.APP\u{a0}! Pour activer votre compte, confirmez votre adresse e-mail.",
	"verify_email.tip" => "<strong>Astuce\u{a0}:</strong> une fois connecté, activez l'authentification multifacteur dans «\u{a0}⚙️ Réglages\u{a0}» pour protéger vos données.",
	"verify_email.button" => "Confirmer mon adresse",
	"verify_email.text" => "Bienvenue sur MONCYCLE.APP !\n\nPour activer votre compte, confirmez votre adresse e-mail en ouvrant ce lien :",

	"account_exists.subject" => "Vous avez déjà un compte ({when})",
	"account_exists.preheader" => "Une inscription a été demandée avec cette adresse.",
	"account_exists.eyebrow" => "Votre compte",
	"account_exists.title" => "Vous avez déjà un compte",
	"account_exists.intro" => "Quelqu'un vient de demander la création d'un compte avec cette adresse, mais vous en avez déjà un.",
	"account_exists.forgotten" => "Si c'est vous et que vous avez perdu votre mot de passe, vous pouvez en choisir un nouveau.",
	"account_exists.button" => "Choisir un nouveau mot de passe",
	"account_exists.text" => "Vous avez déjà un compte sur MONCYCLE.APP.\n\nPour choisir un nouveau mot de passe, ouvrez ce lien :",

	"reset_password.subject" => "Nouveau mot de passe ({when})",
	"reset_password.preheader" => "Le lien pour choisir un nouveau mot de passe.",
	"reset_password.eyebrow" => "Mot de passe",
	"reset_password.title" => "Choisissez un nouveau mot de passe",
	"reset_password.intro" => "Vous avez demandé à changer votre mot de passe. Pour en choisir un nouveau, utilisez ce bouton\u{a0}:",
	"reset_password.button" => "Choisir un nouveau mot de passe",
	"reset_password.text" => "Pour choisir un nouveau mot de passe, ouvrez ce lien :",

	"confirm_email.subject" => "Confirmez la nouvelle adresse ({when})",
	"confirm_email.preheader" => "Confirmez cette adresse pour vous connecter à MONCYCLE.APP.",
	"confirm_email.eyebrow" => "Adresse e-mail",
	"confirm_email.title" => "Confirmez la nouvelle adresse",
	"confirm_email.intro" => "Le compte de <strong>{name}</strong> sur MONCYCLE.APP va utiliser cette adresse pour se connecter et recevoir ses cycles.",
	"confirm_email.button" => "Confirmer la nouvelle adresse",
	"confirm_email.text" => "Pour confirmer cette adresse comme adresse de connexion de votre compte MONCYCLE.APP, ouvrez ce lien :",

	// Security notices
	"password_changed.subject" => "Mot de passe changé ({when})",
	"password_changed.preheader" => "Le mot de passe de votre compte vient d'être changé.",
	"password_changed.eyebrow" => "Sécurité",
	"password_changed.title" => "Votre mot de passe a été changé",
	"password_changed.intro" => "Le mot de passe de votre compte vient d'être changé. Si c'est vous, il n'y a rien à faire.",
	"password_changed.not_you" => "{login}, changez votre mot de passe depuis «\u{a0}⚙️ Réglages\u{a0}» et déconnectez vos autres appareils.",
	"password_changed.login" => "Connectez-vous",
	"password_changed.text" => "Le mot de passe de votre compte MONCYCLE.APP vient d'être changé. Si ce n'est pas vous, changez-le et déconnectez vos autres appareils.",

	"email_change_requested.subject" => "Changement d'adresse demandé ({when})",
	"email_change_requested.preheader" => "Un changement de votre adresse de connexion a été demandé.",
	"email_change_requested.eyebrow" => "Sécurité",
	"email_change_requested.title" => "Changement d'adresse demandé",
	"email_change_requested.intro" => "Le changement de l'adresse de connexion de votre compte vers <strong>{new}</strong> a été demandé. Il ne sera effectif qu'une fois la nouvelle adresse confirmée.",
	"email_change_requested.not_you" => "Demandez un nouveau mot de passe avec «\u{a0}mot de passe perdu\u{a0}» sur la page de connexion\u{a0}: cela annule la demande. Changez ensuite votre mot de passe.",
	"email_change_requested.text" => "Le changement de votre adresse de connexion vers {new} a été demandé. Si ce n'est pas vous, utilisez « mot de passe perdu » pour l'annuler.",

	"email_change_taken.subject" => "Adresse déjà utilisée ({when})",
	"email_change_taken.preheader" => "Rien n'a été modifié sur votre compte.",
	"email_change_taken.eyebrow" => "Adresse e-mail",
	"email_change_taken.title" => "Cette adresse est déjà utilisée",
	"email_change_taken.intro" => "Quelqu'un a demandé à utiliser cette adresse comme adresse de connexion d'un autre compte, mais elle est déjà celle de votre compte.",
	"email_change_taken.nothing" => "Rien n'a été modifié. Si ce n'est pas vous, ignorez ce message.",
	"email_change_taken.text" => "Quelqu'un a demandé à utiliser cette adresse pour un autre compte, mais elle est déjà celle de votre compte. Rien n'a changé.",

	"email_changed.subject" => "Adresse de connexion changée ({when})",
	"email_changed.preheader" => "Cette adresse ne reçoit plus rien de votre compte.",
	"email_changed.eyebrow" => "Sécurité",
	"email_changed.title" => "Adresse de connexion changée",
	"email_changed.intro" => "L'adresse de connexion de votre compte est maintenant <strong>{new}</strong>. Cette adresse-ci ne reçoit plus rien de votre compte.",
	"email_changed.not_you" => "Quelqu'un d'autre utilise votre compte\u{a0}: répondez à ce mail ou écrivez-nous à {contact}.",
	"email_changed.text" => "L'adresse de connexion de votre compte MONCYCLE.APP est maintenant {new}. Si ce n'est pas vous, écrivez-nous à {contact}.",

	"notification_emails.subject" => "Adresses des cycles modifiées ({when})",
	"notification_emails.preheader" => "Les adresses qui reçoivent vos cycles ont changé.",
	"notification_emails.eyebrow" => "Cycles par e-mail",
	"notification_emails.title" => "Adresses des cycles modifiées",
	"notification_emails.emptied" => "La liste des adresses de votre compte qui reçoivent vos cycles par e-mail a été <strong>vidée</strong>.",
	"notification_emails.listed" => "Les adresses de votre compte qui reçoivent vos cycles par e-mail, avec votre adresse de connexion, sont maintenant\u{a0}: <strong>{list}</strong>.",
	"notification_emails.not_you" => "{login} et changez votre mot de passe depuis «\u{a0}⚙️ Réglages\u{a0}».",
	"notification_emails.login" => "Connectez-vous",
	"notification_emails.text_emptied" => "La liste des adresses de votre compte qui reçoivent vos cycles a été vidée.",
	"notification_emails.text_listed" => "Les adresses de votre compte qui reçoivent vos cycles, avec votre adresse de connexion, sont maintenant : {list}.",

	"new_login.subject" => "Nouvelle connexion, jeton {prefix}",
	"new_login.preheader" => "Une nouvelle connexion à votre compte vient d'avoir lieu.",
	"new_login.eyebrow" => "Sécurité",
	"new_login.title" => "Nouvelle connexion à votre compte",
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
	"new_login.nothing_to_do" => "Si c'est vous, il n'y a rien à faire.",
	"new_login.not_you" => "{login}, changez votre mot de passe depuis «\u{a0}⚙️ Réglages\u{a0}» et cochez «\u{a0}Déconnecter tous mes autres appareils\u{a0}»\u{a0}: la liste de vos connexions s'y trouve aussi.",
	"new_login.login" => "Connectez-vous",
	"new_login.text" => "Une nouvelle connexion à votre compte MONCYCLE.APP vient d'avoir lieu ({name}, le {when}, début du jeton {prefix}). Si ce n'est pas vous, changez votre mot de passe.",

	// A quiet account, then an account about to be erased
	"reminder.subject" => "Comment allez-vous ? ({when})",
	"reminder.preheader" => "Votre tableau vous attend, et nous sommes là si besoin.",
	"reminder.eyebrow" => "Des nouvelles",
	"reminder.title" => "Tout va bien\u{a0}?",
	"reminder.quiet" => "Cela fait quelques semaines que votre tableau n'a pas reçu de nouvelle observation.",
	"reminder.pause" => "Si vous avez fait une pause, aucun souci\u{a0}: vos observations sont conservées et vous pouvez reprendre quand vous le souhaitez. Mot de passe oublié\u{a0}? {reset}.",
	"reminder.reset_link" => "choisissez-en un nouveau",
	"reminder.feedback" => "<strong>Votre avis nous aide.</strong> Une question, une idée, quelque chose qui ne vous convient pas dans l'application\u{a0}? Répondez simplement à ce mail ou écrivez-nous à {contact}\u{a0}: nous lisons chaque message.",
	"reminder.button" => "Ouvrir mon tableau",
	"reminder.ps" => "Vous ne souhaitez plus utiliser MONCYCLE.APP\u{a0}? Ignorez ce mail, vous n'en recevrez pas d'autre.",
	"reminder.text" => "Cela fait quelques semaines que votre tableau n'a pas reçu de nouvelle observation. Si vous avez fait une pause, aucun souci : vos observations sont conservées et vous pouvez reprendre quand vous le souhaitez.\n\n"
		. "Mot de passe oublié : {reset}\n\n"
		. "Une question, une idée, quelque chose qui ne vous convient pas dans l'application ? Répondez simplement à ce mail ou écrivez-nous à {contact} : nous lisons chaque message.",

	"deletion_warning.subject" => "Suppression de votre compte le {date}",
	"deletion_warning.preheader" => "Connectez-vous d'ici {days} jours pour conserver votre compte.",
	"deletion_warning.eyebrow" => "Compte inactif",
	"deletion_warning.title" => "Votre compte sera bientôt supprimé",
	"deletion_warning.intro" => "Nous n'avons constaté aucune activité sur votre compte MONCYCLE.APP depuis longtemps.",
	"deletion_warning.warning" => "Conformément à notre politique de conservation des données (RGPD), votre compte et toutes les données associées seront <strong>définitivement supprimés dans {days} jours</strong> si aucune activité n'est détectée d'ici là.",
	"deletion_warning.keep" => "Pour conserver votre compte, il vous suffit de vous connecter. Mot de passe oublié\u{a0}? {reset}.",
	"deletion_warning.reset_link" => "Réinitialisez-le",
	"deletion_warning.button" => "Me connecter",
	"deletion_warning.ps" => "Un problème, besoin d'aide\u{a0}? Répondez à ce mail ou écrivez-nous à {contact}.",
	"deletion_warning.text" => "Faute d'activité, votre compte MONCYCLE.APP sera supprimé dans {days} jours. Connectez-vous pour le conserver.",

	"terms_update.subject" => "Conditions d'utilisation: nouveautés au {day}",
	"terms_update.preheader" => "Les nouvelles conditions entrent en vigueur le {day}.",
	"terms_update.eyebrow" => "Conditions d'utilisation",
	"terms_update.title" => "Nos conditions d'utilisation évoluent",
	"terms_update.intro" => "Nous mettons à jour les conditions générales d'utilisation de MONCYCLE.APP. Les nouvelles conditions entrent en vigueur le <strong>{day}</strong>.",
	"terms_update.accept" => "En continuant à utiliser MONCYCLE.APP après cette date, vous acceptez ces modifications. Si vous ne les acceptez pas, merci de ne plus utiliser l'application\u{a0}: vous pouvez supprimer votre compte depuis la page «\u{a0}⚙️ Réglages\u{a0}».",
	"terms_update.button" => "Lire les nouvelles conditions",
	"terms_update.text" => "Les conditions générales d'utilisation de MONCYCLE.APP changent et entrent en vigueur le {day}.\nEn continuant à utiliser l'application après cette date, vous acceptez ces modifications.",

	// A finished cycle, with its files
	"cycle.subject" => "Votre cycle du {first} au {last} ({n} jours)",
	"cycle.preheader" => "Votre cycle de {n} jours est terminé : PDF, CSV et NFP en pièces jointes.",
	"cycle.eyebrow" => "Cycle terminé",
	"cycle.title" => "Votre cycle est terminé",
	"cycle.intro" => "Voici l’export complet de votre cycle, dans trois formats, pour le consulter, le partager ou le conserver.",
	"cycle.range" => "du {first} au {last}",
	"cycle.duration" => "{n} jours",
	"cycle.fact_cycle" => "Cycle",
	"cycle.fact_duration" => "Durée",
	"cycle.attachments" => "Dans les pièces jointes",
	"cycle.about_pdf" => "Le graphique de votre cycle, à partager avec votre moniteur ou votre instructrice.",
	"cycle.about_csv" => "Vos observations jour par jour, à ouvrir dans LibreOffice ou Microsoft Excel pour une analyse plus détaillée.",
	"cycle.about_nfp" => "Le fichier NFP contient toutes les données de ce cycle dans un format d’échange\u{a0}: conservez-le comme sauvegarde, vous pourrez le réimporter dans moncycle.app depuis «\u{a0}⚙️ Réglages\u{a0}», section «\u{a0}Importer un fichier NFP\u{a0}». Il est également compatible avec d’autres applications si vous souhaitez migrer vos données.",
	"cycle.opt_out" => "Cet envoi est automatique. Vous pouvez le désactiver à tout moment\u{a0}: ouvrez «\u{a0}Réglages\u{a0}», section «\u{a0}Cycles par e-mail\u{a0}», puis décochez «\u{a0}Recevoir chaque cycle par e-mail\u{a0}».",
	"cycle.manage" => "Gérer cet envoi",
	"cycle.text" => "Votre cycle est terminé : du {first} au {last}, {n} jours. Voici son export complet, en pièces jointes.\n\n"
		. "- PDF : {pdf}\n- CSV : {csv}\n- NFP : {nfp}",
];
