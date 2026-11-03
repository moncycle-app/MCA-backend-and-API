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

// Every word of the mails to users, in Spanish (one file per ACCOUNT_LANGUAGES code; read by mail_t() in lib/mail.php; how to add a language: docs/mail.md).
// A string is plain text or light HTML (<strong>, no attribute) with {name} marks the code fills. The no-break space is written \u{a0}.
// The slots the template escapes (title, greeting, why, button_*...) get plain text here, the ones it keeps (content, ps) may hold HTML.
// The reader is addressed as "tú", in wording that does not depend on the reader's gender.
return [
	// The frame (mail/layout.html)
	"layout.lang" => "es",
	"layout.signoff" => "Hasta pronto,",
	"layout.button_fallback" => "Si el botón no funciona, copia este enlace en tu navegador:",
	"layout.footer_auto" => "Este correo ha sido enviado automáticamente por MONCYCLE.APP. ¿Alguna pregunta? Simplemente responde a este correo.",

	// Words and phrases said in several mails: written once here, used as {@key} (or by the code)
	"app.settings" => "«⚙️\u{a0}Ajustes»",
	"app.cycles_by_email" => "Ciclos por correo",
	"action.sign_in" => "Inicia sesión",
	"action.choose_password" => "Elegir una nueva contraseña",
	"eyebrow.security" => "Seguridad",
	"eyebrow.email" => "Dirección de correo",
	"notice.not_you" => "¿No has sido tú?",
	"notice.nothing_to_do" => "Si has sido tú, no tienes que hacer nada.",
	"notice.ignore" => "Si no has sido tú, ignora este mensaje.",
	"contact.reply" => "Responde a este correo o escríbenos a {contact}.",
	"forgot.password" => "¿Has olvidado tu contraseña? {reset}.",
	"forgot.reset_link" => "Elige una nueva",
	"not_you.password" => "{login}, cambia tu contraseña en {@app.settings} y cierra la sesión en tus otros dispositivos.",

	// Shared
	"greeting.named" => "Hola, {name}:",
	"greeting.anonymous" => "Hola:",
	"callout.not_you" => "<strong>{@notice.not_you}</strong> {html}",
	"subject.when" => "{title} ({when})",
	// How a date and a moment are written: PHP date() formats.
	"format.when" => "d/m H:i",
	"format.date" => "d/m/Y",
	"format.date_time" => 'd/m/Y \a \l\a\s H:i',
	"format.long_date" => "{day} de {month} de {year}",
	"date.first" => "1",
	"months" => ["enero", "febrero", "marzo", "abril", "mayo", "junio", "julio", "agosto", "septiembre", "octubre", "noviembre", "diciembre"],
	"lifetime.day" => "{n} día",
	"lifetime.days" => "{n} días",
	"lifetime.hour" => "{n} hora",
	"lifetime.hours" => "{n} horas",
	"lifetime.minutes" => "{n} minutos",

	// Why a mail came: "Recibes este correo porque {because}." and the reasons that complete it
	"why.lead" => "Recibes este correo porque {because}.",
	"why.necessary" => "Recibir correos es necesario para el buen funcionamiento de la aplicación. Si ya no deseas recibir correos nuestros, te rogamos que dejes de usar MONCYCLE.APP.",
	"why.account" => "tienes una cuenta en MONCYCLE.APP",
	"why.account_created" => "se ha creado una cuenta en MONCYCLE.APP con esta dirección de correo",
	"why.account_left" => "tenías una cuenta en MONCYCLE.APP con esta dirección de correo",
	"why.address_asked" => "alguien ha solicitado usar esta dirección de correo para una cuenta de MONCYCLE.APP",
	"why.terms" => "{@why.account} y debemos informarte de este cambio",
	"why.inactive" => "tienes una cuenta inactiva en MONCYCLE.APP; este correo es obligatorio para informarte, de acuerdo con el RGPD, de la próxima eliminación de tus datos si no hay actividad",
	"why.cycle" => "el envío automático de los ciclos está activado en tu cuenta",

	// The four mails of a link
	"link.valid" => "Este enlace es válido durante {lifetime}.",
	"link.once" => "Solo se puede usar una vez.",
	"link.unverified" => "Sin confirmación, la cuenta se elimina al cabo de {days} días.",
	"link.ps_ignore_account" => "{@notice.not_you} Ignora este mensaje: tu cuenta y tu contraseña no han cambiado.",
	"link.ps_ignore_password" => "{@notice.not_you} Ignora este mensaje: tu contraseña no ha cambiado.",
	"link.ps_ignore_email" => "{@notice.not_you} Ignora este mensaje: no se cambiará nada.",

	"verify_email.preheader" => "Un clic para activar tu cuenta de MONCYCLE.APP.",
	"verify_email.eyebrow" => "Te damos la bienvenida",
	"verify_email.title" => "Confirma tu dirección de correo",
	"verify_email.intro" => "¡Te damos la bienvenida a MONCYCLE.APP! Para activar tu cuenta, confirma tu dirección de correo.",
	"verify_email.tip" => "<strong>Consejo:</strong> después de iniciar sesión, activa la autenticación multifactor en {@app.settings} para proteger tus datos.",
	"verify_email.button" => "Confirmar mi dirección de correo",

	"account_exists.preheader" => "Se ha solicitado un registro con esta dirección de correo.",
	"account_exists.eyebrow" => "Tu cuenta",
	"account_exists.title" => "Ya tienes una cuenta",
	"account_exists.intro" => "Alguien acaba de solicitar la creación de una cuenta con esta dirección de correo, pero ya tienes una.",
	"account_exists.forgotten" => "Si has sido tú y has perdido tu contraseña, puedes elegir una nueva.",
	"account_exists.button" => "{@action.choose_password}",

	"reset_password.preheader" => "El enlace para elegir una nueva contraseña.",
	"reset_password.eyebrow" => "Contraseña",
	"reset_password.title" => "{@action.choose_password}",
	"reset_password.intro" => "Has solicitado cambiar tu contraseña. Para elegir una nueva, usa este botón:",
	"reset_password.button" => "{@action.choose_password}",

	"confirm_email.preheader" => "Confirma esta dirección de correo para iniciar sesión en MONCYCLE.APP.",
	"confirm_email.eyebrow" => "{@eyebrow.email}",
	"confirm_email.title" => "Confirma la nueva dirección de correo",
	"confirm_email.intro" => "La cuenta de <strong>{name}</strong> en MONCYCLE.APP usará esta dirección de correo para iniciar sesión y recibir sus ciclos.",
	"confirm_email.button" => "{@confirm_email.title}",

	// Security notices
	"password_changed.preheader" => "La contraseña de tu cuenta se acaba de cambiar.",
	"password_changed.title" => "Se ha cambiado tu contraseña",
	"password_changed.intro" => "La contraseña de tu cuenta se acaba de cambiar. {@notice.nothing_to_do}",

	"email_change_requested.preheader" => "Se ha solicitado un cambio de tu dirección de correo de inicio de sesión.",
	"email_change_requested.title" => "Cambio de dirección de correo solicitado",
	"email_change_requested.intro" => "Se ha solicitado cambiar la dirección de correo de inicio de sesión de tu cuenta a <strong>{new}</strong>. El cambio solo será efectivo cuando se confirme la nueva dirección de correo.",
	"email_change_requested.not_you" => "Solicita una nueva contraseña con «¿Has olvidado tu contraseña?» en la página de inicio de sesión: así se cancela la solicitud. Después, cambia tu contraseña.",

	"email_change_taken.preheader" => "No se ha cambiado nada en tu cuenta.",
	"email_change_taken.title" => "Esta dirección de correo ya está en uso",
	"email_change_taken.intro" => "Alguien ha solicitado usar esta dirección de correo como dirección de inicio de sesión de otra cuenta, pero ya es la de tu cuenta.",
	"email_change_taken.nothing" => "No se ha cambiado nada. {@notice.ignore}",

	"email_changed.preheader" => "Esta dirección de correo ya no recibe nada de tu cuenta.",
	"email_changed.title" => "Dirección de inicio de sesión cambiada",
	"email_changed.intro" => "La dirección de correo de inicio de sesión de tu cuenta es ahora <strong>{new}</strong>. Esta dirección de correo ya no recibe nada relacionado con tu cuenta.",
	"email_changed.not_you" => "Otra persona está usando tu cuenta: {@contact.reply}",

	"notification_emails.preheader" => "Las direcciones de correo que reciben tus ciclos han cambiado.",
	"notification_emails.title" => "Direcciones de correo de los ciclos modificadas",
	"notification_emails.emptied" => "La lista de direcciones de correo de tu cuenta que reciben tus ciclos por correo se ha <strong>vaciado</strong>.",
	"notification_emails.listed" => "Las direcciones de correo de tu cuenta que reciben tus ciclos por correo, junto con tu dirección de inicio de sesión, son ahora: <strong>{list}</strong>.",

	"new_login.title" => "Nuevo inicio de sesión en tu cuenta",
	"new_login.subject" => "Nuevo inicio de sesión, token {prefix}",
	"new_login.intro" => "Acaba de producirse un nuevo inicio de sesión en tu cuenta.",
	"new_login.fact_date" => "Fecha",
	"new_login.fact_name" => "Nombre",
	"new_login.fact_token" => "Inicio del token",
	"new_login.fact_access" => "Acceso",
	"new_login.fact_until" => "Válido hasta",
	"new_login.fact_agent" => "Navegador o aplicación",
	"new_login.access_read_only" => "solo lectura",
	"new_login.access_read_write" => "lectura y escritura",
	"new_login.agent_unknown" => "desconocido",
	"new_login.sessions" => "Allí también encontrarás la lista de tus inicios de sesión.",

	// A quiet account, then an account about to be erased
	"reminder.subject" => "¿Cómo estás? ({when})",
	"reminder.preheader" => "Tu gráfica te espera, y estamos aquí si nos necesitas.",
	"reminder.eyebrow" => "Hola de nuevo",
	"reminder.title" => "¿Va todo bien?",
	"reminder.quiet" => "Hace unas semanas que tu gráfica no recibe ninguna observación nueva.",
	"reminder.pause" => "Si te has tomado un descanso, no te preocupes: tus observaciones se conservan y puedes retomarlas cuando quieras.",
	"reminder.feedback" => "<strong>Tu opinión nos ayuda.</strong> ¿Una pregunta, una idea, algo de la aplicación que no te convence? {@contact.reply} Leemos todos los mensajes.",
	"reminder.button" => "Abrir mi gráfica",
	"reminder.ps" => "¿Ya no quieres usar MONCYCLE.APP? Ignora este correo, no recibirás ningún otro.",

	"deletion_warning.subject" => "Eliminación de tu cuenta el {date}",
	"deletion_warning.preheader" => "Inicia sesión en los próximos {days} días para conservar tu cuenta.",
	"deletion_warning.eyebrow" => "Cuenta inactiva",
	"deletion_warning.title" => "Tu cuenta se eliminará pronto",
	"deletion_warning.intro" => "Hace mucho tiempo que no vemos ninguna actividad en tu cuenta de MONCYCLE.APP.",
	"deletion_warning.warning" => "De acuerdo con nuestra política de conservación de datos (RGPD), tu cuenta y todos sus datos se <strong>eliminarán definitivamente dentro de {days} días</strong> si no se detecta ninguna actividad antes.",
	"deletion_warning.keep" => "Para conservar tu cuenta, solo tienes que iniciar sesión.",
	"deletion_warning.button" => "Iniciar sesión",
	"deletion_warning.ps" => "¿Tienes algún problema o necesitas ayuda? {@contact.reply}",

	"terms_update.subject" => "Condiciones de uso: cambios el {day}",
	"terms_update.preheader" => "Las nuevas condiciones entran en vigor el {day}.",
	"terms_update.eyebrow" => "Condiciones de uso",
	"terms_update.title" => "Nuestras condiciones de uso cambian",
	"terms_update.intro" => "Estamos actualizando las condiciones de uso de MONCYCLE.APP. Las nuevas condiciones entran en vigor el <strong>{day}</strong>.",
	"terms_update.accept" => "Si sigues usando MONCYCLE.APP después de esta fecha, aceptas estos cambios. Si no los aceptas, te rogamos que dejes de usar la aplicación: puedes eliminar tu cuenta desde la página {@app.settings}.",
	"terms_update.button" => "Leer las nuevas condiciones",

	// A finished cycle, with its files
	"cycle.subject" => "Tu ciclo {@cycle.range} ({@cycle.duration})",
	"cycle.preheader" => "Tu ciclo de {n} días ha terminado: PDF, CSV y NFP adjuntos.",
	"cycle.eyebrow" => "Ciclo terminado",
	"cycle.title" => "Tu ciclo ha terminado",
	"cycle.intro" => "Aquí tienes la exportación completa de tu ciclo, en tres formatos, para consultarla, compartirla o conservarla.",
	"cycle.range" => "del {first} al {last}",
	"cycle.duration" => "{@lifetime.days}",
	"cycle.fact_cycle" => "Ciclo",
	"cycle.fact_duration" => "Duración",
	"cycle.attachments" => "En los archivos adjuntos",
	"cycle.about_pdf" => "La gráfica de tu ciclo, para compartirla con tu instructora o instructor.",
	"cycle.about_csv" => "Tus observaciones día a día, para abrirlas en LibreOffice o Microsoft Excel y hacer un análisis más detallado.",
	"cycle.about_nfp" => "El archivo NFP contiene todos los datos de este ciclo en un formato de intercambio: guárdalo como copia de seguridad; podrás volver a importarlo en moncycle.app desde {@app.settings}, sección «Importar un archivo NFP». También funciona con otras aplicaciones, si quieres trasladar tus datos.",
	"cycle.opt_out" => "Este envío es automático. Puedes desactivarlo en cualquier momento: abre «Ajustes», sección «{@app.cycles_by_email}», y desmarca «Recibir cada ciclo por correo».",
	"cycle.manage" => "Gestionar este envío",
];
