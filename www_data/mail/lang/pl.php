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

// Every word of the mails to users, in Polish (MAIL_LANG; read by mail_t() in lib/mail.php; how to add a language: docs/mail.md).
// A string is plain text or light HTML (<strong>, no attribute) with {name} marks the code fills. The no-break space is written \u{a0}.
// The slots the template escapes (title, greeting, why, button_*...) get plain text here, the ones it keeps (content, ps) may hold HTML.
// The reader is addressed as "Ty" (capitalised, as in a letter), and never in the past tense of the second person, which would
// need the reader's gender. The months are in the genitive ("1 grudnia 2026"); hours and minutes are abbreviated ("5 godz.", "30 min"):
// the code has one plural form, Polish needs three.
return [
	// The frame (mail/layout.html)
	"layout.lang" => "pl",
	"layout.signoff" => "Do zobaczenia,",
	"layout.button_fallback" => "Jeśli przycisk nie działa, skopiuj ten link do przeglądarki:",
	"layout.footer_auto" => "Ta wiadomość została wysłana automatycznie przez MONCYCLE.APP. Masz pytanie? Po prostu odpowiedz na tę wiadomość.",

	// Words and phrases said in several mails: written once here, used as {@key} (or by the code)
	"app.settings" => "„⚙️\u{a0}Ustawienia”",
	"app.cycles_by_email" => "Cykle e-mailem",
	"action.sign_in" => "Zaloguj się",
	"action.choose_password" => "Ustaw nowe hasło",
	"eyebrow.security" => "Bezpieczeństwo",
	"eyebrow.email" => "Adres e-mail",
	"notice.not_you" => "To nie Ty?",
	"notice.nothing_to_do" => "Jeśli to Ty, nie musisz nic robić.",
	"notice.ignore" => "Jeśli to nie Ty, zignoruj tę wiadomość.",
	"contact.reply" => "Odpowiedz na tę wiadomość lub napisz do nas na adres {contact}.",
	"forgot.password" => "Nie pamiętasz hasła? {reset}.",
	"forgot.reset_link" => "Ustaw nowe",
	"not_you.password" => "{login}, zmień hasło na stronie {@app.settings} i wyloguj pozostałe urządzenia.",

	// Shared
	"greeting.named" => "Dzień dobry, {name},",
	"greeting.anonymous" => "Dzień dobry,",
	"callout.not_you" => "<strong>{@notice.not_you}</strong> {html}",
	"subject.when" => "{title} ({when})",
	// How a date and a moment are written: PHP date() formats.
	"format.when" => "d.m H:i",
	"format.date" => "d.m.Y",
	"format.date_time" => 'd.m.Y, \g\o\d\z. H:i',
	"format.long_date" => "{day} {month} {year}",
	"date.first" => "1",
	"months" => ["stycznia", "lutego", "marca", "kwietnia", "maja", "czerwca", "lipca", "sierpnia", "września", "października", "listopada", "grudnia"],
	"lifetime.day" => "{n} dzień",
	"lifetime.days" => "{n} dni",
	"lifetime.hour" => "{n} godz.",
	"lifetime.hours" => "{n} godz.",
	"lifetime.minutes" => "{n} min",

	// Why a mail came: "Otrzymujesz tę wiadomość, ponieważ {because}." and the reasons that complete it
	"why.lead" => "Otrzymujesz tę wiadomość, ponieważ {because}.",
	"why.necessary" => "Otrzymywanie wiadomości e-mail jest niezbędne do prawidłowego działania aplikacji. Jeśli nie chcesz już otrzymywać od nas wiadomości, prosimy o zaprzestanie korzystania z MONCYCLE.APP.",
	"why.account" => "masz konto w MONCYCLE.APP",
	"why.account_created" => "z tym adresem e-mail utworzono konto w MONCYCLE.APP",
	"why.account_left" => "z tym adresem e-mail było powiązane konto w MONCYCLE.APP",
	"why.address_asked" => "ktoś poprosił o użycie tego adresu e-mail dla konta w MONCYCLE.APP",
	"why.terms" => "{@why.account} i musimy poinformować Cię o tej zmianie",
	"why.inactive" => "masz nieaktywne konto w MONCYCLE.APP; ta wiadomość jest wymagana, aby zgodnie z RODO poinformować Cię o zbliżającym się usunięciu Twoich danych w przypadku braku aktywności",
	"why.cycle" => "na Twoim koncie jest włączona automatyczna wysyłka cykli",

	// The four mails of a link
	"link.valid" => "Ten link jest ważny przez {lifetime}.",
	"link.once" => "Można go użyć tylko raz.",
	"link.unverified" => "Bez potwierdzenia konto zostanie usunięte po {days} dniach.",
	"link.ps_ignore_account" => "{@notice.not_you} Zignoruj tę wiadomość: Twoje konto i hasło nie zostały zmienione.",
	"link.ps_ignore_password" => "{@notice.not_you} Zignoruj tę wiadomość: Twoje hasło nie zostało zmienione.",
	"link.ps_ignore_email" => "{@notice.not_you} Zignoruj tę wiadomość: nic nie zostanie zmienione.",

	"verify_email.preheader" => "Jedno kliknięcie, aby aktywować konto w MONCYCLE.APP.",
	"verify_email.eyebrow" => "Witamy",
	"verify_email.title" => "Potwierdź swój adres e-mail",
	"verify_email.intro" => "Witamy w MONCYCLE.APP! Aby aktywować konto, potwierdź swój adres e-mail.",
	"verify_email.tip" => "<strong>Wskazówka:</strong> po zalogowaniu włącz uwierzytelnianie wieloskładnikowe na stronie {@app.settings}, aby chronić swoje dane.",
	"verify_email.button" => "Potwierdź adres e-mail",

	"account_exists.preheader" => "Ktoś chce założyć konto z tym adresem e-mail.",
	"account_exists.eyebrow" => "Twoje konto",
	"account_exists.title" => "Masz już konto",
	"account_exists.intro" => "Ktoś właśnie poprosił o utworzenie konta z tym adresem e-mail, ale masz już konto.",
	"account_exists.forgotten" => "Jeśli to Ty i nie pamiętasz hasła, możesz ustawić nowe.",
	"account_exists.button" => "{@action.choose_password}",

	"reset_password.preheader" => "Link do ustawienia nowego hasła.",
	"reset_password.eyebrow" => "Hasło",
	"reset_password.title" => "{@action.choose_password}",
	"reset_password.intro" => "Otrzymaliśmy prośbę o zmianę Twojego hasła. Aby ustawić nowe, użyj tego przycisku:",
	"reset_password.button" => "{@action.choose_password}",

	"confirm_email.preheader" => "Potwierdź ten adres e-mail, aby logować się do MONCYCLE.APP.",
	"confirm_email.eyebrow" => "{@eyebrow.email}",
	"confirm_email.title" => "Potwierdź nowy adres e-mail",
	"confirm_email.intro" => "Konto <strong>{name}</strong> w MONCYCLE.APP będzie używać tego adresu e-mail do logowania i otrzymywania cykli.",
	"confirm_email.button" => "{@confirm_email.title}",

	// Security notices
	"password_changed.preheader" => "Hasło do Twojego konta zostało właśnie zmienione.",
	"password_changed.title" => "Twoje hasło zostało zmienione",
	"password_changed.intro" => "Hasło do Twojego konta zostało właśnie zmienione. {@notice.nothing_to_do}",

	"email_change_requested.preheader" => "Złożono prośbę o zmianę Twojego adresu e-mail do logowania.",
	"email_change_requested.title" => "Prośba o zmianę adresu e-mail",
	"email_change_requested.intro" => "Złożono prośbę o zmianę adresu e-mail do logowania na Twoim koncie na <strong>{new}</strong>. Zmiana nastąpi dopiero po potwierdzeniu nowego adresu e-mail.",
	"email_change_requested.not_you" => "Poproś o nowe hasło za pomocą opcji „Nie pamiętam hasła” na stronie logowania: to anuluje prośbę. Następnie zmień hasło.",

	"email_change_taken.preheader" => "Na Twoim koncie nic nie zostało zmienione.",
	"email_change_taken.title" => "Ten adres e-mail jest już używany",
	"email_change_taken.intro" => "Ktoś poprosił o użycie tego adresu e-mail jako adresu do logowania na innym koncie, ale jest to już adres Twojego konta.",
	"email_change_taken.nothing" => "Nic nie zostało zmienione. {@notice.ignore}",

	"email_changed.preheader" => "Ten adres e-mail nie otrzymuje już nic z Twojego konta.",
	"email_changed.title" => "Zmieniono adres e-mail do logowania",
	"email_changed.intro" => "Adres e-mail do logowania na Twoim koncie to teraz <strong>{new}</strong>. Ten adres e-mail nie otrzymuje już nic, co dotyczy Twojego konta.",
	"email_changed.not_you" => "Ktoś inny korzysta z Twojego konta: {@contact.reply}",

	"notification_emails.preheader" => "Zmieniły się adresy e-mail, na które wysyłane są Twoje cykle.",
	"notification_emails.title" => "Zmieniono adresy e-mail dla cykli",
	"notification_emails.emptied" => "Lista adresów e-mail Twojego konta, na które wysyłane są Twoje cykle, została <strong>wyczyszczona</strong>.",
	"notification_emails.listed" => "Adresy e-mail Twojego konta, na które wysyłane są Twoje cykle, razem z adresem e-mail do logowania, to teraz: <strong>{list}</strong>.",

	"new_login.title" => "Nowe logowanie do Twojego konta",
	"new_login.subject" => "Nowe logowanie, token {prefix}",
	"new_login.intro" => "Właśnie nastąpiło nowe logowanie do Twojego konta.",
	"new_login.fact_date" => "Data",
	"new_login.fact_name" => "Nazwa",
	"new_login.fact_token" => "Początek tokenu",
	"new_login.fact_access" => "Dostęp",
	"new_login.fact_until" => "Ważny do",
	"new_login.fact_agent" => "Przeglądarka lub aplikacja",
	"new_login.access_read_only" => "tylko odczyt",
	"new_login.access_read_write" => "odczyt i zapis",
	"new_login.agent_unknown" => "nieznana",
	"new_login.sessions" => "Znajdziesz tam też listę swoich logowań.",

	// A quiet account, then an account about to be erased
	"reminder.subject" => "Co słychać? ({when})",
	"reminder.preheader" => "Twoja karta czeka, a my jesteśmy tu, jeśli nas potrzebujesz.",
	"reminder.eyebrow" => "Co u Ciebie?",
	"reminder.title" => "Czy wszystko w porządku?",
	"reminder.quiet" => "Od kilku tygodni na Twojej karcie nie pojawiła się żadna nowa obserwacja.",
	"reminder.pause" => "Jeśli robisz sobie przerwę, nic nie szkodzi: Twoje obserwacje są zachowane i możesz wrócić, kiedy zechcesz.",
	"reminder.feedback" => "<strong>Twoja opinia nam pomaga.</strong> Masz pytanie, pomysł albo coś w aplikacji Ci nie odpowiada? {@contact.reply} Czytamy każdą wiadomość.",
	"reminder.button" => "Otwórz moją kartę",
	"reminder.ps" => "Nie chcesz już korzystać z MONCYCLE.APP? Zignoruj tę wiadomość, nie otrzymasz kolejnej.",

	"deletion_warning.subject" => "Usunięcie Twojego konta: {date}",
	"deletion_warning.preheader" => "Zaloguj się w ciągu {days} dni, aby zachować konto.",
	"deletion_warning.eyebrow" => "Nieaktywne konto",
	"deletion_warning.title" => "Twoje konto wkrótce zostanie usunięte",
	"deletion_warning.intro" => "Od dłuższego czasu nie odnotowaliśmy żadnej aktywności na Twoim koncie w MONCYCLE.APP.",
	"deletion_warning.warning" => "Zgodnie z naszą polityką przechowywania danych (RODO) Twoje konto i wszystkie powiązane z nim dane zostaną <strong>trwale usunięte za {days} dni</strong>, jeśli do tego czasu nie zostanie odnotowana żadna aktywność.",
	"deletion_warning.keep" => "Aby zachować konto, wystarczy się zalogować.",
	"deletion_warning.button" => "Zaloguj się",
	"deletion_warning.ps" => "Masz problem lub potrzebujesz pomocy? {@contact.reply}",

	"terms_update.subject" => "Warunki korzystania: zmiany od {day}",
	"terms_update.preheader" => "Nowe warunki wchodzą w życie {day}.",
	"terms_update.eyebrow" => "Warunki korzystania",
	"terms_update.title" => "Zmieniamy nasze warunki korzystania",
	"terms_update.intro" => "Aktualizujemy warunki korzystania z MONCYCLE.APP. Nowe warunki wchodzą w życie <strong>{day}</strong>.",
	"terms_update.accept" => "Korzystając z MONCYCLE.APP po tej dacie, akceptujesz te zmiany. Jeśli ich nie akceptujesz, prosimy o zaprzestanie korzystania z aplikacji: konto możesz usunąć na stronie {@app.settings}.",
	"terms_update.button" => "Przeczytaj nowe warunki",

	// A finished cycle, with its files
	"cycle.subject" => "Twój cykl {@cycle.range} ({@cycle.duration})",
	"cycle.preheader" => "Twój {n}-dniowy cykl dobiegł końca: PDF, CSV i NFP w załącznikach.",
	"cycle.eyebrow" => "Cykl zakończony",
	"cycle.title" => "Twój cykl dobiegł końca",
	"cycle.intro" => "Oto pełny eksport Twojego cyklu w trzech formatach: do przeglądania, udostępniania lub przechowywania.",
	"cycle.range" => "od {first} do {last}",
	"cycle.duration" => "{@lifetime.days}",
	"cycle.fact_cycle" => "Cykl",
	"cycle.fact_duration" => "Długość",
	"cycle.attachments" => "W załącznikach",
	"cycle.about_pdf" => "Karta Twojego cyklu do udostępnienia instruktorce lub instruktorowi.",
	"cycle.about_csv" => "Twoje obserwacje dzień po dniu, do otwarcia w LibreOffice lub Microsoft Excel w celu dokładniejszej analizy.",
	"cycle.about_nfp" => "Plik NFP zawiera wszystkie dane tego cyklu w formacie wymiany: zachowaj go jako kopię zapasową; możesz go ponownie zaimportować do moncycle.app na stronie {@app.settings}, w sekcji „Importuj plik NFP”. Działa też z innymi aplikacjami, jeśli chcesz przenieść swoje dane.",
	"cycle.opt_out" => "Ta wysyłka jest automatyczna. Możesz ją w każdej chwili wyłączyć: otwórz „Ustawienia”, sekcję „{@app.cycles_by_email}”, a następnie odznacz „Otrzymuj każdy cykl e-mailem”.",
	"cycle.manage" => "Zarządzaj tą wysyłką",
];
