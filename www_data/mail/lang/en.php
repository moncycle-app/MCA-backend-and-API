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

// Every word of the mails to users, in English (MAIL_LANG; read by mail_t() in lib/mail.php; how to add a language: docs/mail.md).
// A string is plain text or light HTML (<strong>, no attribute) with {name} marks the code fills. The no-break space is written \u{a0}.
// The slots the template escapes (title, greeting, why, button_*...) get plain text here, the ones it keeps (content, ps) may hold HTML.
return [
	// The frame (mail/layout.html)
	"layout.lang" => "en",
	"layout.signoff" => "See you soon,",
	"layout.button_fallback" => "If the button does not work, copy this link into your browser:",
	"layout.footer_auto" => "This mail was sent automatically by MONCYCLE.APP. A question? Just reply to this mail.",

	// Words and phrases said in several mails: written once here, used as {@key} (or by the code)
	"app.settings" => "“⚙️\u{a0}Settings”",
	"app.cycles_by_email" => "Cycles by email",
	"action.sign_in" => "Sign in",
	"action.choose_password" => "Choose a new password",
	"eyebrow.security" => "Security",
	"eyebrow.email" => "Email address",
	"notice.not_you" => "Not you?",
	"notice.nothing_to_do" => "If it was you, there is nothing to do.",
	"notice.ignore" => "If it was not you, ignore this message.",
	"contact.reply" => "Reply to this mail or write to us at {contact}.",
	"forgot.password" => "Forgot your password? {reset}.",
	"forgot.reset_link" => "Choose a new one",
	"not_you.password" => "{login}, change your password in {@app.settings} and sign out your other devices.",

	// Shared
	"greeting.named" => "Hello {name},",
	"greeting.anonymous" => "Hello,",
	"callout.not_you" => "<strong>{@notice.not_you}</strong> {html}",
	"subject.when" => "{title} ({when})",
	// How a date and a moment are written: PHP date() formats.
	"format.when" => "j M H:i",
	"format.date" => "j M Y",
	"format.date_time" => 'j M Y \a\t H:i',
	"format.long_date" => "{day} {month} {year}",
	"date.first" => "1",
	"months" => ["January", "February", "March", "April", "May", "June", "July", "August", "September", "October", "November", "December"],
	"lifetime.day" => "{n} day",
	"lifetime.days" => "{n} days",
	"lifetime.hour" => "{n} hour",
	"lifetime.hours" => "{n} hours",
	"lifetime.minutes" => "{n} minutes",

	// Why a mail came: "You are receiving this mail because {because}." and the reasons that complete it
	"why.lead" => "You are receiving this mail because {because}.",
	"why.necessary" => "Receiving emails is necessary for the application to work properly. If you no longer wish to receive emails from us, please stop using MONCYCLE.APP.",
	"why.account" => "you have an account on MONCYCLE.APP",
	"why.account_created" => "an account was created on MONCYCLE.APP with this email address",
	"why.account_left" => "you had an account on MONCYCLE.APP with this email address",
	"why.address_asked" => "someone asked to use this email address for a MONCYCLE.APP account",
	"why.terms" => "{@why.account} and we must inform you of this change",
	"why.inactive" => "you have an inactive account on MONCYCLE.APP; this mail is required to inform you, in accordance with the GDPR, of the upcoming deletion of your data if there is no activity",
	"why.cycle" => "the automatic sending of cycles is turned on for your account",

	// The four mails of a link
	"link.valid" => "This link is valid for {lifetime}.",
	"link.once" => "It can only be used once.",
	"link.unverified" => "Without confirmation, the account is deleted after {days} days.",
	"link.ps_ignore_account" => "{@notice.not_you} Ignore this message: your account and your password have not changed.",
	"link.ps_ignore_password" => "{@notice.not_you} Ignore this message: your password has not changed.",
	"link.ps_ignore_email" => "{@notice.not_you} Ignore this message: nothing will be changed.",

	"verify_email.preheader" => "One click to activate your MONCYCLE.APP account.",
	"verify_email.eyebrow" => "Welcome",
	"verify_email.title" => "Confirm your email address",
	"verify_email.intro" => "Welcome to MONCYCLE.APP! To activate your account, confirm your email address.",
	"verify_email.tip" => "<strong>Tip:</strong> once signed in, turn on multi-factor authentication in {@app.settings} to protect your data.",
	"verify_email.button" => "Confirm my email address",

	"account_exists.preheader" => "A sign-up was requested with this email address.",
	"account_exists.eyebrow" => "Your account",
	"account_exists.title" => "You already have an account",
	"account_exists.intro" => "Someone just asked to create an account with this email address, but you already have one.",
	"account_exists.forgotten" => "If it was you and you have lost your password, you can choose a new one.",
	"account_exists.button" => "{@action.choose_password}",

	"reset_password.preheader" => "The link to choose a new password.",
	"reset_password.eyebrow" => "Password",
	"reset_password.title" => "{@action.choose_password}",
	"reset_password.intro" => "You asked to change your password. To choose a new one, use this button:",
	"reset_password.button" => "{@action.choose_password}",

	"confirm_email.preheader" => "Confirm this email address to sign in to MONCYCLE.APP.",
	"confirm_email.eyebrow" => "{@eyebrow.email}",
	"confirm_email.title" => "Confirm the new email address",
	"confirm_email.intro" => "The account of <strong>{name}</strong> on MONCYCLE.APP will use this email address to sign in and receive its cycles.",
	"confirm_email.button" => "{@confirm_email.title}",

	// Security notices
	"password_changed.preheader" => "The password of your account has just been changed.",
	"password_changed.title" => "Your password has been changed",
	"password_changed.intro" => "The password of your account has just been changed. {@notice.nothing_to_do}",

	"email_change_requested.preheader" => "A change of your sign-in email address was requested.",
	"email_change_requested.title" => "Email address change requested",
	"email_change_requested.intro" => "A change of your account's sign-in email address to <strong>{new}</strong> was requested. It will only take effect once the new email address is confirmed.",
	"email_change_requested.not_you" => "Ask for a new password with “Forgot password” on the sign-in page: this cancels the request. Then change your password.",

	"email_change_taken.preheader" => "Nothing was changed on your account.",
	"email_change_taken.title" => "This email address is already in use",
	"email_change_taken.intro" => "Someone asked to use this email address as the sign-in email address of another account, but it is already the one of your account.",
	"email_change_taken.nothing" => "Nothing was changed. {@notice.ignore}",

	"email_changed.preheader" => "This email address no longer receives anything from your account.",
	"email_changed.title" => "Sign-in email address changed",
	"email_changed.intro" => "The sign-in email address of your account is now <strong>{new}</strong>. This email address no longer receives anything related to your account.",
	"email_changed.not_you" => "Someone else is using your account: {@contact.reply}",

	"notification_emails.preheader" => "The email addresses that receive your cycles have changed.",
	"notification_emails.title" => "Cycle email addresses changed",
	"notification_emails.emptied" => "The list of your account's email addresses that receive your cycles by email has been <strong>emptied</strong>.",
	"notification_emails.listed" => "The email addresses of your account that receive your cycles by email, together with your sign-in email address, are now: <strong>{list}</strong>.",

	"new_login.title" => "New sign-in to your account",
	"new_login.subject" => "New sign-in, token {prefix}",
	"new_login.intro" => "A new sign-in to your account has just taken place.",
	"new_login.fact_date" => "Date",
	"new_login.fact_name" => "Name",
	"new_login.fact_token" => "Token start",
	"new_login.fact_access" => "Access",
	"new_login.fact_until" => "Valid until",
	"new_login.fact_agent" => "Browser or app",
	"new_login.access_read_only" => "read only",
	"new_login.access_read_write" => "read and write",
	"new_login.agent_unknown" => "unknown",
	"new_login.sessions" => "The list of your sign-ins is there too.",

	// A quiet account, then an account about to be erased
	"reminder.subject" => "How are you? ({when})",
	"reminder.preheader" => "Your chart is waiting for you, and we are here if you need us.",
	"reminder.eyebrow" => "Checking in",
	"reminder.title" => "Is everything all right?",
	"reminder.quiet" => "Your chart has not received a new observation for a few weeks.",
	"reminder.pause" => "If you took a break, no worries: your observations are kept and you can pick up again whenever you like.",
	"reminder.feedback" => "<strong>Your feedback helps us.</strong> A question, an idea, something in the application that does not suit you? {@contact.reply} We read every message.",
	"reminder.button" => "Open my chart",
	"reminder.ps" => "You no longer wish to use MONCYCLE.APP? Ignore this mail, you will not receive another one.",

	"deletion_warning.subject" => "Deletion of your account on {date}",
	"deletion_warning.preheader" => "Sign in within {days} days to keep your account.",
	"deletion_warning.eyebrow" => "Inactive account",
	"deletion_warning.title" => "Your account will soon be deleted",
	"deletion_warning.intro" => "We have not seen any activity on your MONCYCLE.APP account for a long time.",
	"deletion_warning.warning" => "In accordance with our data retention policy (GDPR), your account and all its data will be <strong>permanently deleted in {days} days</strong> if no activity is detected by then.",
	"deletion_warning.keep" => "To keep your account, simply sign in.",
	"deletion_warning.button" => "Sign me in",
	"deletion_warning.ps" => "A problem, need help? {@contact.reply}",

	"terms_update.subject" => "Terms of use: changes on {day}",
	"terms_update.preheader" => "The new terms take effect on {day}.",
	"terms_update.eyebrow" => "Terms of use",
	"terms_update.title" => "Our terms of use are changing",
	"terms_update.intro" => "We are updating the terms of use of MONCYCLE.APP. The new terms take effect on <strong>{day}</strong>.",
	"terms_update.accept" => "By continuing to use MONCYCLE.APP after this date, you accept these changes. If you do not accept them, please stop using the application: you can delete your account from the {@app.settings} page.",
	"terms_update.button" => "Read the new terms",

	// A finished cycle, with its files
	"cycle.subject" => "Your cycle {@cycle.range} ({@cycle.duration})",
	"cycle.preheader" => "Your {n}-day cycle is over: PDF, CSV and NFP attached.",
	"cycle.eyebrow" => "Cycle over",
	"cycle.title" => "Your cycle is over",
	"cycle.intro" => "Here is the full export of your cycle, in three formats, to view, share or keep.",
	"cycle.range" => "from {first} to {last}",
	"cycle.duration" => "{@lifetime.days}",
	"cycle.fact_cycle" => "Cycle",
	"cycle.fact_duration" => "Length",
	"cycle.attachments" => "In the attachments",
	"cycle.about_pdf" => "The chart of your cycle, to share with your instructor.",
	"cycle.about_csv" => "Your observations day by day, to open in LibreOffice or Microsoft Excel for a more detailed analysis.",
	"cycle.about_nfp" => "The NFP file holds all the data of this cycle in an interchange format: keep it as a backup, you can import it again into moncycle.app from {@app.settings}, section “Import an NFP file”. It also works with other applications if you wish to move your data.",
	"cycle.opt_out" => "This mail is sent automatically. You can turn it off at any time: open “Settings”, section “{@app.cycles_by_email}”, then untick “Receive each cycle by email”.",
	"cycle.manage" => "Manage this mail",
];
