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

// Every word of the mails to users, in Portuguese (MAIL_LANG; read by mail_t() in lib/mail.php; how to add a language: docs/mail.md).
// A string is plain text or light HTML (<strong>, no attribute) with {name} marks the code fills. The no-break space is written \u{a0}.
// The slots the template escapes (title, greeting, why, button_*...) get plain text here, the ones it keeps (content, ps) may hold HTML.
// European Portuguese (pt-PT, 1990 spelling agreement): "palavra-passe", "ficheiro", "Definições". The reader is addressed in the
// third person without "você", in wording that does not depend on the reader's gender ("por si", "lhe").
return [
	// The frame (mail/layout.html)
	"layout.lang" => "pt",
	"layout.signoff" => "Até breve,",
	"layout.button_fallback" => "Se o botão não funcionar, copie este link para o seu navegador:",
	"layout.footer_auto" => "Este e-mail foi enviado automaticamente pelo MONCYCLE.APP. Tem alguma pergunta? Basta responder a este e-mail.",

	// Words and phrases said in several mails: written once here, used as {@key} (or by the code)
	"app.settings" => "«⚙️\u{a0}Definições»",
	"app.cycles_by_email" => "Ciclos por e-mail",
	"action.sign_in" => "Inicie sessão",
	"action.choose_password" => "Escolher uma nova palavra-passe",
	"eyebrow.security" => "Segurança",
	"eyebrow.email" => "Endereço de e-mail",
	"notice.not_you" => "Não reconhece esta ação?",
	"notice.nothing_to_do" => "Se foi feita por si, não precisa de fazer nada.",
	"notice.ignore" => "Se não foi feita por si, ignore esta mensagem.",
	"contact.reply" => "Responda a este e-mail ou escreva-nos para {contact}.",
	"forgot.password" => "Esqueceu-se da palavra-passe? {reset}.",
	"forgot.reset_link" => "Escolha uma nova",
	"not_you.password" => "{login}, altere a sua palavra-passe em {@app.settings} e termine a sessão nos seus outros dispositivos.",

	// Shared
	"greeting.named" => "Olá {name},",
	"greeting.anonymous" => "Olá,",
	"callout.not_you" => "<strong>{@notice.not_you}</strong> {html}",
	"subject.when" => "{title} ({when})",
	// How a date and a moment are written: PHP date() formats.
	"format.when" => "d/m H:i",
	"format.date" => "d/m/Y",
	"format.date_time" => 'd/m/Y \à\s H:i',
	"format.long_date" => "{day} de {month} de {year}",
	"date.first" => "1",
	"months" => ["janeiro", "fevereiro", "março", "abril", "maio", "junho", "julho", "agosto", "setembro", "outubro", "novembro", "dezembro"],
	"lifetime.day" => "{n} dia",
	"lifetime.days" => "{n} dias",
	"lifetime.hour" => "{n} hora",
	"lifetime.hours" => "{n} horas",
	"lifetime.minutes" => "{n} minutos",

	// Why a mail came: "Recebe este e-mail porque {because}." and the reasons that complete it
	"why.lead" => "Recebe este e-mail porque {because}.",
	"why.necessary" => "A receção de e-mails é necessária para o bom funcionamento da aplicação. Se já não pretende receber e-mails da nossa parte, pedimos-lhe que deixe de utilizar o MONCYCLE.APP.",
	"why.account" => "tem uma conta no MONCYCLE.APP",
	"why.account_created" => "foi criada uma conta no MONCYCLE.APP com este endereço de e-mail",
	"why.account_left" => "tinha uma conta no MONCYCLE.APP com este endereço de e-mail",
	"why.address_asked" => "alguém pediu para utilizar este endereço de e-mail numa conta do MONCYCLE.APP",
	"why.terms" => "{@why.account} e temos de lhe comunicar esta alteração",
	"why.inactive" => "tem uma conta inativa no MONCYCLE.APP; este e-mail é obrigatório para lhe comunicar, em conformidade com o RGPD, a próxima eliminação dos seus dados caso não haja atividade",
	"why.cycle" => "o envio automático dos ciclos está ativado na sua conta",

	// The four mails of a link
	"link.valid" => "Este link é válido durante {lifetime}.",
	"link.once" => "Só pode ser utilizado uma vez.",
	"link.unverified" => "Sem confirmação, a conta é eliminada ao fim de {days} dias.",
	"link.ps_ignore_account" => "{@notice.not_you} Ignore esta mensagem: a sua conta e a sua palavra-passe não foram alteradas.",
	"link.ps_ignore_password" => "{@notice.not_you} Ignore esta mensagem: a sua palavra-passe não foi alterada.",
	"link.ps_ignore_email" => "{@notice.not_you} Ignore esta mensagem: nada será alterado.",

	"verify_email.preheader" => "Um clique para ativar a sua conta MONCYCLE.APP.",
	"verify_email.eyebrow" => "Boas-vindas",
	"verify_email.title" => "Confirme o seu endereço de e-mail",
	"verify_email.intro" => "Damos-lhe as boas-vindas ao MONCYCLE.APP! Para ativar a sua conta, confirme o seu endereço de e-mail.",
	"verify_email.tip" => "<strong>Dica:</strong> depois de iniciar sessão, ative a autenticação multifator em {@app.settings} para proteger os seus dados.",
	"verify_email.button" => "Confirmar o meu endereço de e-mail",

	"account_exists.preheader" => "Foi pedido um registo com este endereço de e-mail.",
	"account_exists.eyebrow" => "A sua conta",
	"account_exists.title" => "Já tem uma conta",
	"account_exists.intro" => "Alguém acabou de pedir a criação de uma conta com este endereço de e-mail, mas já tem uma.",
	"account_exists.forgotten" => "Se o pedido foi feito por si e perdeu a palavra-passe, pode escolher uma nova.",
	"account_exists.button" => "{@action.choose_password}",

	"reset_password.preheader" => "O link para escolher uma nova palavra-passe.",
	"reset_password.eyebrow" => "Palavra-passe",
	"reset_password.title" => "{@action.choose_password}",
	"reset_password.intro" => "Pediu para alterar a sua palavra-passe. Para escolher uma nova, utilize este botão:",
	"reset_password.button" => "{@action.choose_password}",

	"confirm_email.preheader" => "Confirme este endereço de e-mail para iniciar sessão no MONCYCLE.APP.",
	"confirm_email.eyebrow" => "{@eyebrow.email}",
	"confirm_email.title" => "Confirme o novo endereço de e-mail",
	"confirm_email.intro" => "A conta de <strong>{name}</strong> no MONCYCLE.APP vai utilizar este endereço de e-mail para iniciar sessão e receber os ciclos.",
	"confirm_email.button" => "{@confirm_email.title}",

	// Security notices
	"password_changed.preheader" => "A palavra-passe da sua conta acabou de ser alterada.",
	"password_changed.title" => "A sua palavra-passe foi alterada",
	"password_changed.intro" => "A palavra-passe da sua conta acabou de ser alterada. {@notice.nothing_to_do}",

	"email_change_requested.preheader" => "Foi pedida a alteração do seu endereço de e-mail de início de sessão.",
	"email_change_requested.title" => "Pedido de alteração do endereço de e-mail",
	"email_change_requested.intro" => "Foi pedida a alteração do endereço de e-mail de início de sessão da sua conta para <strong>{new}</strong>. A alteração só terá efeito depois de o novo endereço de e-mail ser confirmado.",
	"email_change_requested.not_you" => "Peça uma nova palavra-passe com «Esqueceu-se da palavra-passe?» na página de início de sessão: isso cancela o pedido. Depois, altere a sua palavra-passe.",

	"email_change_taken.preheader" => "Nada foi alterado na sua conta.",
	"email_change_taken.title" => "Este endereço de e-mail já está a ser utilizado",
	"email_change_taken.intro" => "Alguém pediu para utilizar este endereço de e-mail como endereço de início de sessão de outra conta, mas já é o endereço da sua conta.",
	"email_change_taken.nothing" => "Nada foi alterado. {@notice.ignore}",

	"email_changed.preheader" => "Este endereço de e-mail já não recebe nada da sua conta.",
	"email_changed.title" => "Endereço de e-mail de início de sessão alterado",
	"email_changed.intro" => "O endereço de e-mail de início de sessão da sua conta passou a ser <strong>{new}</strong>. Este endereço de e-mail já não recebe nada relacionado com a sua conta.",
	"email_changed.not_you" => "Outra pessoa está a utilizar a sua conta: {@contact.reply}",

	"notification_emails.preheader" => "Os endereços de e-mail que recebem os seus ciclos foram alterados.",
	"notification_emails.title" => "Endereços de e-mail dos ciclos alterados",
	"notification_emails.emptied" => "A lista de endereços de e-mail da sua conta que recebem os seus ciclos por e-mail foi <strong>esvaziada</strong>.",
	"notification_emails.listed" => "Os endereços de e-mail da sua conta que recebem os seus ciclos por e-mail, juntamente com o seu endereço de início de sessão, passaram a ser: <strong>{list}</strong>.",

	"new_login.title" => "Novo início de sessão na sua conta",
	"new_login.subject" => "Novo início de sessão, token {prefix}",
	"new_login.intro" => "Acabou de ocorrer um novo início de sessão na sua conta.",
	"new_login.fact_date" => "Data",
	"new_login.fact_name" => "Nome",
	"new_login.fact_token" => "Início do token",
	"new_login.fact_access" => "Acesso",
	"new_login.fact_until" => "Válido até",
	"new_login.fact_agent" => "Navegador ou aplicação",
	"new_login.access_read_only" => "só de leitura",
	"new_login.access_read_write" => "leitura e escrita",
	"new_login.agent_unknown" => "desconhecido",
	"new_login.sessions" => "A lista dos seus inícios de sessão também se encontra aí.",

	// A quiet account, then an account about to be erased
	"reminder.subject" => "Como está? ({when})",
	"reminder.preheader" => "O seu gráfico está à sua espera, e estamos aqui se precisar.",
	"reminder.eyebrow" => "Olá de novo",
	"reminder.title" => "Está tudo bem?",
	"reminder.quiet" => "Há algumas semanas que o seu gráfico não recebe nenhuma observação nova.",
	"reminder.pause" => "Se fez uma pausa, não há problema: as suas observações estão guardadas e pode retomar quando quiser.",
	"reminder.feedback" => "<strong>A sua opinião ajuda-nos.</strong> Uma pergunta, uma ideia, algo na aplicação que não lhe agrada? {@contact.reply} Lemos todas as mensagens.",
	"reminder.button" => "Abrir o meu gráfico",
	"reminder.ps" => "Já não pretende utilizar o MONCYCLE.APP? Ignore este e-mail, não receberá outro.",

	"deletion_warning.subject" => "Eliminação da sua conta em {date}",
	"deletion_warning.preheader" => "Inicie sessão nos próximos {days} dias para manter a sua conta.",
	"deletion_warning.eyebrow" => "Conta inativa",
	"deletion_warning.title" => "A sua conta vai ser eliminada em breve",
	"deletion_warning.intro" => "Há muito tempo que não registamos qualquer atividade na sua conta MONCYCLE.APP.",
	"deletion_warning.warning" => "De acordo com a nossa política de conservação de dados (RGPD), a sua conta e todos os dados associados serão <strong>eliminados definitivamente dentro de {days} dias</strong> se, até lá, não for detetada qualquer atividade.",
	"deletion_warning.keep" => "Para manter a sua conta, basta iniciar sessão.",
	"deletion_warning.button" => "Iniciar sessão",
	"deletion_warning.ps" => "Algum problema ou precisa de ajuda? {@contact.reply}",

	"terms_update.subject" => "Termos de utilização: alterações a partir de {day}",
	"terms_update.preheader" => "Os novos termos entram em vigor a {day}.",
	"terms_update.eyebrow" => "Termos de utilização",
	"terms_update.title" => "Os nossos termos de utilização vão mudar",
	"terms_update.intro" => "Estamos a atualizar os termos de utilização do MONCYCLE.APP. Os novos termos entram em vigor a <strong>{day}</strong>.",
	"terms_update.accept" => "Ao continuar a utilizar o MONCYCLE.APP após esta data, aceita estas alterações. Se não as aceitar, pedimos-lhe que deixe de utilizar a aplicação: pode eliminar a sua conta na página {@app.settings}.",
	"terms_update.button" => "Ler os novos termos",

	// A finished cycle, with its files
	"cycle.subject" => "O seu ciclo {@cycle.range} ({@cycle.duration})",
	"cycle.preheader" => "O seu ciclo de {n} dias terminou: PDF, CSV e NFP em anexo.",
	"cycle.eyebrow" => "Ciclo terminado",
	"cycle.title" => "O seu ciclo terminou",
	"cycle.intro" => "Eis a exportação completa do seu ciclo, em três formatos, para consultar, partilhar ou guardar.",
	"cycle.range" => "de {first} a {last}",
	"cycle.duration" => "{@lifetime.days}",
	"cycle.fact_cycle" => "Ciclo",
	"cycle.fact_duration" => "Duração",
	"cycle.attachments" => "Em anexo",
	"cycle.about_pdf" => "O gráfico do seu ciclo, para partilhar com a sua instrutora ou o seu instrutor.",
	"cycle.about_csv" => "As suas observações dia a dia, para abrir no LibreOffice ou no Microsoft Excel e fazer uma análise mais detalhada.",
	"cycle.about_nfp" => "O ficheiro NFP contém todos os dados deste ciclo num formato de intercâmbio: guarde-o como cópia de segurança; pode voltar a importá-lo para o moncycle.app em {@app.settings}, secção «Importar um ficheiro NFP». Também funciona com outras aplicações, se quiser transferir os seus dados.",
	"cycle.opt_out" => "Este envio é automático. Pode desativá-lo a qualquer momento: abra «Definições», secção «{@app.cycles_by_email}», e desmarque «Receber cada ciclo por e-mail».",
	"cycle.manage" => "Gerir este envio",
];
