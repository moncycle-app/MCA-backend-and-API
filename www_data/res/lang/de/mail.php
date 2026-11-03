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

// Every word of the mails to users, in German (one file per ACCOUNT_LANGUAGES code; read by mail_t() in lib/mail.php; how to add a language: docs/mail.md).
// A string is plain text or light HTML (<strong>, no attribute) with {name} marks the code fills. The no-break space is written \u{a0}.
// The slots the template escapes (title, greeting, why, button_*...) get plain text here, the ones it keeps (content, ps) may hold HTML.
// The reader is addressed as "Sie". The why.* reasons are subordinate clauses after "weil": their verb comes last.
return [
	// The frame (mail/layout.html)
	"layout.lang" => "de",
	"layout.signoff" => "Bis bald,",
	"layout.button_fallback" => "Falls die Schaltfläche nicht funktioniert, kopieren Sie diesen Link in Ihren Browser:",
	"layout.footer_auto" => "Diese E-Mail wurde automatisch von MONCYCLE.APP versendet. Eine Frage? Antworten Sie einfach auf diese E-Mail.",

	// Words and phrases said in several mails: written once here, used as {@key} (or by the code)
	"app.settings" => "„⚙️\u{a0}Einstellungen“",
	"app.cycles_by_email" => "Zyklen per E-Mail",
	"action.sign_in" => "Melden Sie sich an",
	"action.choose_password" => "Neues Passwort festlegen",
	"eyebrow.security" => "Sicherheit",
	"eyebrow.email" => "E-Mail-Adresse",
	"notice.not_you" => "Waren Sie das nicht?",
	"notice.nothing_to_do" => "Wenn Sie das waren, müssen Sie nichts tun.",
	"notice.ignore" => "Wenn Sie das nicht waren, ignorieren Sie diese Nachricht.",
	"contact.reply" => "Antworten Sie auf diese E-Mail oder schreiben Sie uns an {contact}.",
	"forgot.password" => "Passwort vergessen? {reset}.",
	"forgot.reset_link" => "Legen Sie ein neues fest",
	"not_you.password" => "{login}, ändern Sie Ihr Passwort unter {@app.settings} und melden Sie Ihre anderen Geräte ab.",

	// Shared
	"greeting.named" => "Hallo {name},",
	"greeting.anonymous" => "Guten Tag,",
	"callout.not_you" => "<strong>{@notice.not_you}</strong> {html}",
	"subject.when" => "{title} ({when})",
	// How a date and a moment are written: PHP date() formats.
	"format.when" => "d.m. H:i",
	"format.date" => "d.m.Y",
	"format.date_time" => 'd.m.Y \u\m H:i \U\h\r',
	"format.long_date" => "{day}. {month} {year}",
	"date.first" => "1",
	"months" => ["Januar", "Februar", "März", "April", "Mai", "Juni", "Juli", "August", "September", "Oktober", "November", "Dezember"],
	"lifetime.day" => "{n} Tag",
	"lifetime.days" => "{n} Tage",
	"lifetime.hour" => "{n} Stunde",
	"lifetime.hours" => "{n} Stunden",
	"lifetime.minutes" => "{n} Minuten",

	// Why a mail came: "Sie erhalten diese E-Mail, weil {because}." and the reasons that complete it
	"why.lead" => "Sie erhalten diese E-Mail, weil {because}.",
	"why.necessary" => "Der Empfang von E-Mails ist für das ordnungsgemäße Funktionieren der App erforderlich. Wenn Sie keine E-Mails mehr von uns erhalten möchten, nutzen Sie MONCYCLE.APP bitte nicht mehr.",
	"why.account" => "Sie ein Konto bei MONCYCLE.APP haben",
	"why.account_created" => "mit dieser E-Mail-Adresse ein Konto bei MONCYCLE.APP erstellt wurde",
	"why.account_left" => "Sie mit dieser E-Mail-Adresse ein Konto bei MONCYCLE.APP hatten",
	"why.address_asked" => "jemand angefragt hat, diese E-Mail-Adresse für ein Konto bei MONCYCLE.APP zu verwenden",
	"why.terms" => "{@why.account} und wir Sie über diese Änderung informieren müssen",
	"why.inactive" => "Sie ein inaktives Konto bei MONCYCLE.APP haben; diese E-Mail ist gemäß DSGVO erforderlich, um Sie über die bevorstehende Löschung Ihrer Daten bei ausbleibender Aktivität zu informieren",
	"why.cycle" => "der automatische Versand der Zyklen für Ihr Konto aktiviert ist",

	// The four mails of a link
	"link.valid" => "Dieser Link ist {lifetime} gültig.",
	"link.once" => "Er kann nur einmal verwendet werden.",
	"link.unverified" => "Ohne Bestätigung wird das Konto nach {days} Tagen gelöscht.",
	"link.ps_ignore_account" => "{@notice.not_you} Ignorieren Sie diese Nachricht: Ihr Konto und Ihr Passwort wurden nicht geändert.",
	"link.ps_ignore_password" => "{@notice.not_you} Ignorieren Sie diese Nachricht: Ihr Passwort wurde nicht geändert.",
	"link.ps_ignore_email" => "{@notice.not_you} Ignorieren Sie diese Nachricht: Es wird nichts geändert.",

	"verify_email.preheader" => "Ein Klick, um Ihr MONCYCLE.APP-Konto zu aktivieren.",
	"verify_email.eyebrow" => "Willkommen",
	"verify_email.title" => "Bestätigen Sie Ihre E-Mail-Adresse",
	"verify_email.intro" => "Willkommen bei MONCYCLE.APP! Um Ihr Konto zu aktivieren, bestätigen Sie bitte Ihre E-Mail-Adresse.",
	"verify_email.tip" => "<strong>Tipp:</strong> Aktivieren Sie nach der Anmeldung unter {@app.settings} die Multi-Faktor-Authentifizierung, um Ihre Daten zu schützen.",
	"verify_email.button" => "Meine E-Mail-Adresse bestätigen",

	"account_exists.preheader" => "Mit dieser E-Mail-Adresse wurde eine Registrierung angefragt.",
	"account_exists.eyebrow" => "Ihr Konto",
	"account_exists.title" => "Sie haben bereits ein Konto",
	"account_exists.intro" => "Jemand hat gerade angefragt, mit dieser E-Mail-Adresse ein Konto zu erstellen, aber Sie haben bereits eines.",
	"account_exists.forgotten" => "Wenn Sie das waren und Ihr Passwort nicht mehr wissen, können Sie ein neues festlegen.",
	"account_exists.button" => "{@action.choose_password}",

	"reset_password.preheader" => "Der Link, um ein neues Passwort festzulegen.",
	"reset_password.eyebrow" => "Passwort",
	"reset_password.title" => "{@action.choose_password}",
	"reset_password.intro" => "Sie haben angefragt, Ihr Passwort zu ändern. Um ein neues festzulegen, verwenden Sie diese Schaltfläche:",
	"reset_password.button" => "{@action.choose_password}",

	"confirm_email.preheader" => "Bestätigen Sie diese E-Mail-Adresse, um sich bei MONCYCLE.APP anzumelden.",
	"confirm_email.eyebrow" => "{@eyebrow.email}",
	"confirm_email.title" => "Neue E-Mail-Adresse bestätigen",
	"confirm_email.intro" => "Das Konto von <strong>{name}</strong> bei MONCYCLE.APP wird diese E-Mail-Adresse für die Anmeldung und den Empfang der Zyklen verwenden.",
	"confirm_email.button" => "{@confirm_email.title}",

	// Security notices
	"password_changed.preheader" => "Das Passwort Ihres Kontos wurde soeben geändert.",
	"password_changed.title" => "Ihr Passwort wurde geändert",
	"password_changed.intro" => "Das Passwort Ihres Kontos wurde soeben geändert. {@notice.nothing_to_do}",

	"email_change_requested.preheader" => "Eine Änderung Ihrer Anmelde-E-Mail-Adresse wurde angefragt.",
	"email_change_requested.title" => "Änderung der E-Mail-Adresse angefragt",
	"email_change_requested.intro" => "Es wurde angefragt, die Anmelde-E-Mail-Adresse Ihres Kontos in <strong>{new}</strong> zu ändern. Die Änderung wird erst wirksam, wenn die neue E-Mail-Adresse bestätigt ist.",
	"email_change_requested.not_you" => "Fordern Sie auf der Anmeldeseite über „Passwort vergessen“ ein neues Passwort an: Dadurch wird die Anfrage aufgehoben. Ändern Sie anschließend Ihr Passwort.",

	"email_change_taken.preheader" => "An Ihrem Konto wurde nichts geändert.",
	"email_change_taken.title" => "Diese E-Mail-Adresse wird bereits verwendet",
	"email_change_taken.intro" => "Jemand hat angefragt, diese E-Mail-Adresse als Anmelde-E-Mail-Adresse eines anderen Kontos zu verwenden, aber sie ist bereits die Ihres Kontos.",
	"email_change_taken.nothing" => "Es wurde nichts geändert. {@notice.ignore}",

	"email_changed.preheader" => "Diese E-Mail-Adresse erhält nichts mehr zu Ihrem Konto.",
	"email_changed.title" => "Anmelde-E-Mail-Adresse geändert",
	"email_changed.intro" => "Die Anmelde-E-Mail-Adresse Ihres Kontos lautet jetzt <strong>{new}</strong>. Diese E-Mail-Adresse erhält nichts mehr, was Ihr Konto betrifft.",
	"email_changed.not_you" => "Jemand anderes verwendet Ihr Konto: {@contact.reply}",

	"notification_emails.preheader" => "Die E-Mail-Adressen, die Ihre Zyklen erhalten, wurden geändert.",
	"notification_emails.title" => "E-Mail-Adressen für die Zyklen geändert",
	"notification_emails.emptied" => "Die Liste der E-Mail-Adressen Ihres Kontos, die Ihre Zyklen per E-Mail erhalten, wurde <strong>geleert</strong>.",
	"notification_emails.listed" => "Die E-Mail-Adressen Ihres Kontos, die Ihre Zyklen per E-Mail erhalten, sind zusammen mit Ihrer Anmelde-E-Mail-Adresse jetzt: <strong>{list}</strong>.",

	"new_login.title" => "Neue Anmeldung bei Ihrem Konto",
	"new_login.subject" => "Neue Anmeldung, Token {prefix}",
	"new_login.intro" => "Soeben hat eine neue Anmeldung bei Ihrem Konto stattgefunden.",
	"new_login.fact_date" => "Datum",
	"new_login.fact_name" => "Name",
	"new_login.fact_token" => "Token-Anfang",
	"new_login.fact_access" => "Zugriff",
	"new_login.fact_until" => "Gültig bis",
	"new_login.fact_agent" => "Browser oder App",
	"new_login.access_read_only" => "nur lesen",
	"new_login.access_read_write" => "lesen und schreiben",
	"new_login.agent_unknown" => "unbekannt",
	"new_login.sessions" => "Dort finden Sie auch die Liste Ihrer Anmeldungen.",

	// A quiet account, then an account about to be erased
	"reminder.subject" => "Wie geht es Ihnen? ({when})",
	"reminder.preheader" => "Ihr Zyklusblatt wartet auf Sie, und wir sind für Sie da, wenn Sie uns brauchen.",
	"reminder.eyebrow" => "Wir melden uns",
	"reminder.title" => "Ist alles in Ordnung?",
	"reminder.quiet" => "Ihr Zyklusblatt hat seit einigen Wochen keine neue Beobachtung erhalten.",
	"reminder.pause" => "Wenn Sie eine Pause eingelegt haben, kein Problem: Ihre Beobachtungen bleiben gespeichert, und Sie können jederzeit weitermachen.",
	"reminder.feedback" => "<strong>Ihre Meinung hilft uns.</strong> Eine Frage, eine Idee, etwas in der App, das Ihnen nicht zusagt? {@contact.reply} Wir lesen jede Nachricht.",
	"reminder.button" => "Mein Zyklusblatt öffnen",
	"reminder.ps" => "Sie möchten MONCYCLE.APP nicht mehr nutzen? Ignorieren Sie diese E-Mail, Sie erhalten keine weitere.",

	"deletion_warning.subject" => "Löschung Ihres Kontos am {date}",
	"deletion_warning.preheader" => "Melden Sie sich innerhalb von {days} Tagen an, um Ihr Konto zu behalten.",
	"deletion_warning.eyebrow" => "Inaktives Konto",
	"deletion_warning.title" => "Ihr Konto wird bald gelöscht",
	"deletion_warning.intro" => "Wir haben seit Langem keine Aktivität in Ihrem MONCYCLE.APP-Konto festgestellt.",
	"deletion_warning.warning" => "Gemäß unserer Richtlinie zur Datenaufbewahrung (DSGVO) werden Ihr Konto und alle zugehörigen Daten <strong>in {days} Tagen endgültig gelöscht</strong>, wenn bis dahin keine Aktivität festgestellt wird.",
	"deletion_warning.keep" => "Um Ihr Konto zu behalten, melden Sie sich einfach an.",
	"deletion_warning.button" => "Anmelden",
	"deletion_warning.ps" => "Ein Problem, brauchen Sie Hilfe? {@contact.reply}",

	"terms_update.subject" => "Nutzungsbedingungen: Änderungen zum {day}",
	"terms_update.preheader" => "Die neuen Bedingungen treten am {day} in Kraft.",
	"terms_update.eyebrow" => "Nutzungsbedingungen",
	"terms_update.title" => "Unsere Nutzungsbedingungen ändern sich",
	"terms_update.intro" => "Wir aktualisieren die Nutzungsbedingungen von MONCYCLE.APP. Die neuen Bedingungen treten am <strong>{day}</strong> in Kraft.",
	"terms_update.accept" => "Wenn Sie MONCYCLE.APP nach diesem Datum weiter nutzen, akzeptieren Sie diese Änderungen. Wenn Sie sie nicht akzeptieren, nutzen Sie die App bitte nicht mehr: Sie können Ihr Konto auf der Seite {@app.settings} löschen.",
	"terms_update.button" => "Neue Bedingungen lesen",

	// A finished cycle, with its files
	"cycle.subject" => "Ihr Zyklus {@cycle.range} ({@cycle.duration})",
	"cycle.preheader" => "Ihr {n}-tägiger Zyklus ist beendet: PDF, CSV und NFP im Anhang.",
	"cycle.eyebrow" => "Zyklus beendet",
	"cycle.title" => "Ihr Zyklus ist beendet",
	"cycle.intro" => "Hier ist der vollständige Export Ihres Zyklus in drei Formaten, zum Ansehen, Teilen oder Aufbewahren.",
	"cycle.range" => "vom {first} bis zum {last}",
	"cycle.duration" => "{@lifetime.days}",
	"cycle.fact_cycle" => "Zyklus",
	"cycle.fact_duration" => "Dauer",
	"cycle.attachments" => "Im Anhang",
	"cycle.about_pdf" => "Das Zyklusblatt, um es mit Ihrer Beraterin oder Ihrem Berater zu teilen.",
	"cycle.about_csv" => "Ihre Beobachtungen Tag für Tag, zum Öffnen in LibreOffice oder Microsoft Excel für eine genauere Auswertung.",
	"cycle.about_nfp" => "Die NFP-Datei enthält alle Daten dieses Zyklus in einem Austauschformat: Bewahren Sie sie als Sicherung auf; Sie können sie unter {@app.settings}, Bereich „NFP-Datei importieren“, wieder in moncycle.app importieren. Sie funktioniert auch mit anderen Apps, falls Sie Ihre Daten übertragen möchten.",
	"cycle.opt_out" => "Dieser Versand erfolgt automatisch. Sie können ihn jederzeit deaktivieren: Öffnen Sie „Einstellungen“, Bereich „{@app.cycles_by_email}“, und entfernen Sie das Häkchen bei „Jeden Zyklus per E-Mail erhalten“.",
	"cycle.manage" => "Diesen Versand verwalten",
];
