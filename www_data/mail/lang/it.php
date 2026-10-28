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

// Every word of the mails to users, in Italian (MAIL_LANG; read by mail_t() in lib/mail.php; how to add a language: docs/mail.md).
// A string is plain text or light HTML (<strong>, no attribute) with {name} marks the code fills. The no-break space is written \u{a0}.
// The slots the template escapes (title, greeting, why, button_*...) get plain text here, the ones it keeps (content, ps) may hold HTML.
// The reader is addressed as "tu", in wording that does not depend on the reader's gender. A long date takes no article
// ("in data {day}"): "il" would be wrong before 8 and 11 ("l'8").
return [
	// The frame (mail/layout.html)
	"layout.lang" => "it",
	"layout.signoff" => "A presto,",
	"layout.button_fallback" => "Se il pulsante non funziona, copia questo link nel tuo browser:",
	"layout.footer_auto" => "Questa email è stata inviata automaticamente da MONCYCLE.APP. Hai una domanda? Rispondi semplicemente a questa email.",

	// Words and phrases said in several mails: written once here, used as {@key} (or by the code)
	"app.settings" => "«⚙️\u{a0}Impostazioni»",
	"app.cycles_by_email" => "Cicli via email",
	"action.sign_in" => "Accedi",
	"action.choose_password" => "Scegli una nuova password",
	"eyebrow.security" => "Sicurezza",
	"eyebrow.email" => "Indirizzo email",
	"notice.not_you" => "Non sei tu?",
	"notice.nothing_to_do" => "Se l'hai fatto tu, non devi fare nulla.",
	"notice.ignore" => "Se non l'hai fatto tu, ignora questo messaggio.",
	"contact.reply" => "Rispondi a questa email o scrivici a {contact}.",
	"forgot.password" => "Password dimenticata? {reset}.",
	"forgot.reset_link" => "Scegline una nuova",
	"not_you.password" => "{login}, cambia la password in {@app.settings} e disconnetti gli altri dispositivi.",

	// Shared
	"greeting.named" => "Ciao {name},",
	"greeting.anonymous" => "Ciao,",
	"callout.not_you" => "<strong>{@notice.not_you}</strong> {html}",
	"subject.when" => "{title} ({when})",
	// How a date and a moment are written: PHP date() formats.
	"format.when" => "d/m H:i",
	"format.date" => "d/m/Y",
	"format.date_time" => 'd/m/Y, \o\r\e H:i',
	"format.long_date" => "{day} {month} {year}",
	"date.first" => "1º",
	"months" => ["gennaio", "febbraio", "marzo", "aprile", "maggio", "giugno", "luglio", "agosto", "settembre", "ottobre", "novembre", "dicembre"],
	"lifetime.day" => "{n} giorno",
	"lifetime.days" => "{n} giorni",
	"lifetime.hour" => "{n} ora",
	"lifetime.hours" => "{n} ore",
	"lifetime.minutes" => "{n} minuti",

	// Why a mail came: "Ricevi questa email perché {because}." and the reasons that complete it
	"why.lead" => "Ricevi questa email perché {because}.",
	"why.necessary" => "Ricevere email è necessario per il corretto funzionamento dell'applicazione. Se non desideri più ricevere email da parte nostra, ti chiediamo di non usare più MONCYCLE.APP.",
	"why.account" => "hai un account su MONCYCLE.APP",
	"why.account_created" => "è stato creato un account su MONCYCLE.APP con questo indirizzo email",
	"why.account_left" => "avevi un account su MONCYCLE.APP con questo indirizzo email",
	"why.address_asked" => "qualcuno ha chiesto di usare questo indirizzo email per un account MONCYCLE.APP",
	"why.terms" => "{@why.account} e dobbiamo informarti di questa modifica",
	"why.inactive" => "hai un account inattivo su MONCYCLE.APP; questa email è necessaria per informarti, in conformità con il GDPR, della prossima cancellazione dei tuoi dati in assenza di attività",
	"why.cycle" => "l'invio automatico dei cicli è attivo sul tuo account",

	// The four mails of a link
	"link.valid" => "Questo link è valido per {lifetime}.",
	"link.once" => "Può essere usato una sola volta.",
	"link.unverified" => "Senza conferma, l'account viene eliminato dopo {days} giorni.",
	"link.ps_ignore_account" => "{@notice.not_you} Ignora questo messaggio: il tuo account e la tua password non sono cambiati.",
	"link.ps_ignore_password" => "{@notice.not_you} Ignora questo messaggio: la tua password non è cambiata.",
	"link.ps_ignore_email" => "{@notice.not_you} Ignora questo messaggio: non verrà modificato nulla.",

	"verify_email.preheader" => "Un clic per attivare il tuo account MONCYCLE.APP.",
	"verify_email.eyebrow" => "Ti diamo il benvenuto",
	"verify_email.title" => "Conferma il tuo indirizzo email",
	"verify_email.intro" => "Ti diamo il benvenuto su MONCYCLE.APP! Per attivare il tuo account, conferma il tuo indirizzo email.",
	"verify_email.tip" => "<strong>Suggerimento:</strong> dopo l'accesso, attiva l'autenticazione a più fattori in {@app.settings} per proteggere i tuoi dati.",
	"verify_email.button" => "Conferma il mio indirizzo email",

	"account_exists.preheader" => "È stata richiesta una registrazione con questo indirizzo email.",
	"account_exists.eyebrow" => "Il tuo account",
	"account_exists.title" => "Hai già un account",
	"account_exists.intro" => "Qualcuno ha appena chiesto di creare un account con questo indirizzo email, ma ne hai già uno.",
	"account_exists.forgotten" => "Se la richiesta è tua e non ricordi più la password, puoi sceglierne una nuova.",
	"account_exists.button" => "{@action.choose_password}",

	"reset_password.preheader" => "Il link per scegliere una nuova password.",
	"reset_password.eyebrow" => "Password",
	"reset_password.title" => "{@action.choose_password}",
	"reset_password.intro" => "Hai chiesto di cambiare la password. Per sceglierne una nuova, usa questo pulsante:",
	"reset_password.button" => "{@action.choose_password}",

	"confirm_email.preheader" => "Conferma questo indirizzo email per accedere a MONCYCLE.APP.",
	"confirm_email.eyebrow" => "{@eyebrow.email}",
	"confirm_email.title" => "Conferma il nuovo indirizzo email",
	"confirm_email.intro" => "L'account di <strong>{name}</strong> su MONCYCLE.APP userà questo indirizzo email per l'accesso e per ricevere i cicli.",
	"confirm_email.button" => "{@confirm_email.title}",

	// Security notices
	"password_changed.preheader" => "La password del tuo account è stata appena cambiata.",
	"password_changed.title" => "La tua password è stata cambiata",
	"password_changed.intro" => "La password del tuo account è stata appena cambiata. {@notice.nothing_to_do}",

	"email_change_requested.preheader" => "È stata richiesta la modifica del tuo indirizzo email di accesso.",
	"email_change_requested.title" => "Richiesta di modifica dell'indirizzo email",
	"email_change_requested.intro" => "È stata richiesta la modifica dell'indirizzo email di accesso del tuo account in <strong>{new}</strong>. La modifica sarà effettiva solo dopo la conferma del nuovo indirizzo email.",
	"email_change_requested.not_you" => "Richiedi una nuova password con «Password dimenticata» nella pagina di accesso: in questo modo la richiesta viene annullata. Poi cambia la password.",

	"email_change_taken.preheader" => "Nel tuo account non è stato modificato nulla.",
	"email_change_taken.title" => "Questo indirizzo email è già in uso",
	"email_change_taken.intro" => "Qualcuno ha chiesto di usare questo indirizzo email come indirizzo di accesso di un altro account, ma è già quello del tuo account.",
	"email_change_taken.nothing" => "Non è stato modificato nulla. {@notice.ignore}",

	"email_changed.preheader" => "Questo indirizzo email non riceve più nulla dal tuo account.",
	"email_changed.title" => "Indirizzo email di accesso modificato",
	"email_changed.intro" => "L'indirizzo email di accesso del tuo account ora è <strong>{new}</strong>. Questo indirizzo email non riceve più nulla che riguardi il tuo account.",
	"email_changed.not_you" => "Qualcun altro sta usando il tuo account: {@contact.reply}",

	"notification_emails.preheader" => "Gli indirizzi email che ricevono i tuoi cicli sono cambiati.",
	"notification_emails.title" => "Indirizzi email dei cicli modificati",
	"notification_emails.emptied" => "L'elenco degli indirizzi email del tuo account che ricevono i tuoi cicli via email è stato <strong>svuotato</strong>.",
	"notification_emails.listed" => "Gli indirizzi email del tuo account che ricevono i tuoi cicli via email, insieme al tuo indirizzo email di accesso, ora sono: <strong>{list}</strong>.",

	"new_login.title" => "Nuovo accesso al tuo account",
	"new_login.subject" => "Nuovo accesso, token {prefix}",
	"new_login.intro" => "È appena stato effettuato un nuovo accesso al tuo account.",
	"new_login.fact_date" => "Data",
	"new_login.fact_name" => "Nome",
	"new_login.fact_token" => "Inizio del token",
	"new_login.fact_access" => "Accesso",
	"new_login.fact_until" => "Valido fino al",
	"new_login.fact_agent" => "Browser o app",
	"new_login.access_read_only" => "sola lettura",
	"new_login.access_read_write" => "lettura e scrittura",
	"new_login.agent_unknown" => "sconosciuto",
	"new_login.sessions" => "Lì trovi anche l'elenco dei tuoi accessi.",

	// A quiet account, then an account about to be erased
	"reminder.subject" => "Come stai? ({when})",
	"reminder.preheader" => "La tua tabella ti aspetta, e noi ci siamo se hai bisogno.",
	"reminder.eyebrow" => "Un saluto",
	"reminder.title" => "Va tutto bene?",
	"reminder.quiet" => "Da qualche settimana la tua tabella non riceve nuove osservazioni.",
	"reminder.pause" => "Se hai fatto una pausa, nessun problema: le tue osservazioni sono conservate e puoi riprendere quando vuoi.",
	"reminder.feedback" => "<strong>Il tuo parere ci aiuta.</strong> Una domanda, un'idea, qualcosa nell'applicazione che non ti convince? {@contact.reply} Leggiamo ogni messaggio.",
	"reminder.button" => "Apri la mia tabella",
	"reminder.ps" => "Non vuoi più usare MONCYCLE.APP? Ignora questa email, non ne riceverai altre.",

	"deletion_warning.subject" => "Eliminazione del tuo account: {date}",
	"deletion_warning.preheader" => "Accedi entro {days} giorni per mantenere il tuo account.",
	"deletion_warning.eyebrow" => "Account inattivo",
	"deletion_warning.title" => "Il tuo account sarà presto eliminato",
	"deletion_warning.intro" => "Da molto tempo non rileviamo alcuna attività sul tuo account MONCYCLE.APP.",
	"deletion_warning.warning" => "In conformità con la nostra politica di conservazione dei dati (GDPR), il tuo account e tutti i dati associati saranno <strong>eliminati definitivamente tra {days} giorni</strong> se nel frattempo non verrà rilevata alcuna attività.",
	"deletion_warning.keep" => "Per mantenere il tuo account, ti basta accedere.",
	"deletion_warning.button" => "Accedi",
	"deletion_warning.ps" => "Un problema, hai bisogno di aiuto? {@contact.reply}",

	"terms_update.subject" => "Condizioni d'uso: modifiche in vigore in data {day}",
	"terms_update.preheader" => "Le nuove condizioni entrano in vigore in data {day}.",
	"terms_update.eyebrow" => "Condizioni d'uso",
	"terms_update.title" => "Le nostre condizioni d'uso cambiano",
	"terms_update.intro" => "Stiamo aggiornando le condizioni d'uso di MONCYCLE.APP. Le nuove condizioni entrano in vigore in data <strong>{day}</strong>.",
	"terms_update.accept" => "Continuando a usare MONCYCLE.APP dopo questa data, accetti queste modifiche. Se non le accetti, ti chiediamo di non usare più l'applicazione: puoi eliminare il tuo account dalla pagina {@app.settings}.",
	"terms_update.button" => "Leggi le nuove condizioni",

	// A finished cycle, with its files
	"cycle.subject" => "Il tuo ciclo {@cycle.range} ({@cycle.duration})",
	"cycle.preheader" => "Il tuo ciclo di {n} giorni è terminato: PDF, CSV e NFP in allegato.",
	"cycle.eyebrow" => "Ciclo terminato",
	"cycle.title" => "Il tuo ciclo è terminato",
	"cycle.intro" => "Ecco l'esportazione completa del tuo ciclo, in tre formati, da consultare, condividere o conservare.",
	"cycle.range" => "dal {first} al {last}",
	"cycle.duration" => "{@lifetime.days}",
	"cycle.fact_cycle" => "Ciclo",
	"cycle.fact_duration" => "Durata",
	"cycle.attachments" => "In allegato",
	"cycle.about_pdf" => "La tabella del tuo ciclo, da condividere con la tua insegnante o il tuo insegnante.",
	"cycle.about_csv" => "Le tue osservazioni giorno per giorno, da aprire in LibreOffice o Microsoft Excel per un'analisi più dettagliata.",
	"cycle.about_nfp" => "Il file NFP contiene tutti i dati di questo ciclo in un formato di scambio: conservalo come backup; potrai reimportarlo in moncycle.app da {@app.settings}, sezione «Importa un file NFP». Funziona anche con altre applicazioni, se vuoi trasferire i tuoi dati.",
	"cycle.opt_out" => "Questo invio è automatico. Puoi disattivarlo in qualsiasi momento: apri «Impostazioni», sezione «{@app.cycles_by_email}», poi togli la spunta da «Ricevi ogni ciclo via email».",
	"cycle.manage" => "Gestisci questo invio",
];
