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

// Every word of the mails to users, in Portuguese (one file per ACCOUNT_LANGUAGES code; read by mail_t() in lib/mail.php; how to add a language: docs/mail.md).
// A string is plain text or light HTML (<strong>, no attribute) with {name} marks the code fills. The no-break space is written \u{a0}.
// The slots the template escapes (title, greeting, why, button_*...) get plain text here, the ones it keeps (content, ps) may hold HTML.
// Neutral Portuguese, for Portugal and Brazil alike: formal imperatives and third person, no "você" and no "tu", no gerund and no
// "a + infinitive", no clitic placement that differs ("damos-lhe" / "lhe damos"), no word that differs where a plain one exists
// ("apagar", "usar", "app"). Where no neutral word exists the Brazilian one is used, the larger readership: "senha", "arquivo",
// "Configurações", "entrar", "backup". The quotes are “ ” (both). A label quoted from the web app must follow the app's.
return [
	// The frame (mail/layout.html)
	"layout.lang" => "pt",
	"layout.signoff" => "Até breve,",
	"layout.button_fallback" => "Se o botão não funcionar, copie este link para o seu navegador:",
	"layout.footer_auto" => "Este e-mail foi enviado automaticamente pelo MONCYCLE.APP. Alguma dúvida? Basta responder a este e-mail.",

	// Words and phrases said in several mails: written once here, used as {@key} (or by the code)
	"app.settings" => "“⚙️\u{a0}Configurações”",
	"app.cycles_by_email" => "Ciclos por e-mail",
	"action.sign_in" => "Entre",
	"action.choose_password" => "Escolher uma nova senha",
	"eyebrow.security" => "Segurança",
	"eyebrow.email" => "Endereço de e-mail",
	"notice.not_you" => "Não reconhece esta ação?",
	"notice.nothing_to_do" => "Se a ação foi sua, não é necessário fazer nada.",
	"notice.ignore" => "Se a ação não foi sua, ignore esta mensagem.",
	"contact.reply" => "Responda a este e-mail ou envie uma mensagem para {contact}.",
	"forgot.password" => "Esqueceu a senha? {reset}.",
	"forgot.reset_link" => "Escolha uma nova",
	"not_you.password" => "{login}, altere a sua senha em {@app.settings} e desconecte os seus outros dispositivos.",

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

	// Why a mail came: "Este e-mail foi enviado porque {because}." and the reasons that complete it
	"why.lead" => "Este e-mail foi enviado porque {because}.",
	"why.necessary" => "Receber e-mails é necessário para o bom funcionamento do MONCYCLE.APP. Se não deseja mais receber e-mails nossos, pedimos que deixe de usar o MONCYCLE.APP.",
	"why.account" => "tem uma conta no MONCYCLE.APP",
	"why.account_created" => "foi criada uma conta no MONCYCLE.APP com este endereço de e-mail",
	"why.account_left" => "tinha uma conta no MONCYCLE.APP com este endereço de e-mail",
	"why.address_asked" => "alguém pediu para usar este endereço de e-mail em uma conta do MONCYCLE.APP",
	"why.terms" => "{@why.account} e precisamos comunicar esta alteração",
	"why.inactive" => "tem uma conta inativa no MONCYCLE.APP; este e-mail é obrigatório para informar, em conformidade com o RGPD, que os seus dados serão apagados em breve caso não haja atividade",
	"why.cycle" => "o envio automático dos ciclos está ativado na sua conta",

	// The four mails of a link
	"link.valid" => "Este link é válido durante {lifetime}.",
	"link.once" => "Só pode ser usado uma vez.",
	"link.unverified" => "Sem confirmação, a conta é apagada após {days} dias.",
	"link.ps_ignore_account" => "{@notice.not_you} Ignore esta mensagem: a sua conta e a sua senha não foram alteradas.",
	"link.ps_ignore_password" => "{@notice.not_you} Ignore esta mensagem: a sua senha não foi alterada.",
	"link.ps_ignore_email" => "{@notice.not_you} Ignore esta mensagem: nada será alterado.",

	"verify_email.preheader" => "Um clique para ativar a sua conta MONCYCLE.APP.",
	"verify_email.eyebrow" => "Boas-vindas",
	"verify_email.title" => "Confirme o seu endereço de e-mail",
	"verify_email.intro" => "Boas-vindas ao MONCYCLE.APP! Para ativar a sua conta, confirme o seu endereço de e-mail.",
	"verify_email.tip" => "<strong>Dica:</strong> depois de entrar, ative a autenticação multifator em {@app.settings} para proteger os seus dados.",
	"verify_email.button" => "Confirmar o meu endereço de e-mail",

	"account_exists.preheader" => "Foi pedido um cadastro com este endereço de e-mail.",
	"account_exists.eyebrow" => "A sua conta",
	"account_exists.title" => "Já existe uma conta",
	"account_exists.intro" => "Alguém acabou de pedir a criação de uma conta com este endereço de e-mail, mas já existe uma.",
	"account_exists.forgotten" => "Se o pedido foi seu e esqueceu a senha, pode escolher uma nova.",
	"account_exists.button" => "{@action.choose_password}",

	"reset_password.preheader" => "O link para escolher uma nova senha.",
	"reset_password.eyebrow" => "Senha",
	"reset_password.title" => "{@action.choose_password}",
	"reset_password.intro" => "Foi pedido para alterar a sua senha. Para escolher uma nova, use este botão:",
	"reset_password.button" => "{@action.choose_password}",

	"confirm_email.preheader" => "Confirme este endereço de e-mail para entrar no MONCYCLE.APP.",
	"confirm_email.eyebrow" => "{@eyebrow.email}",
	"confirm_email.title" => "Confirme o novo endereço de e-mail",
	"confirm_email.intro" => "A conta de <strong>{name}</strong> no MONCYCLE.APP vai usar este endereço de e-mail para entrar e receber os ciclos.",
	"confirm_email.button" => "{@confirm_email.title}",

	// Security notices
	"password_changed.preheader" => "A senha da sua conta acabou de ser alterada.",
	"password_changed.title" => "A sua senha foi alterada",
	"password_changed.intro" => "A senha da sua conta acabou de ser alterada. {@notice.nothing_to_do}",

	"email_change_requested.preheader" => "Foi pedida a alteração do seu endereço de e-mail de acesso.",
	"email_change_requested.title" => "Pedido de alteração do endereço de e-mail",
	"email_change_requested.intro" => "Foi pedida a alteração do endereço de e-mail de acesso da sua conta para <strong>{new}</strong>. A alteração só terá efeito após a confirmação do novo endereço de e-mail.",
	"email_change_requested.not_you" => "Peça uma nova senha com “Esqueci a senha” na página de acesso: isso cancela o pedido. Depois, altere a sua senha.",

	"email_change_taken.preheader" => "Nada foi alterado na sua conta.",
	"email_change_taken.title" => "Este endereço de e-mail já está em uso",
	"email_change_taken.intro" => "Alguém pediu para usar este endereço de e-mail como endereço de acesso de outra conta, mas ele já é o da sua conta.",
	"email_change_taken.nothing" => "Nada foi alterado. {@notice.ignore}",

	"email_changed.preheader" => "Este endereço de e-mail não recebe mais nada da sua conta.",
	"email_changed.title" => "Endereço de e-mail de acesso alterado",
	"email_changed.intro" => "O endereço de e-mail de acesso da sua conta agora é <strong>{new}</strong>. Este endereço de e-mail não recebe mais nada relacionado com a sua conta.",
	"email_changed.not_you" => "Outra pessoa tem acesso à sua conta: {@contact.reply}",

	"notification_emails.preheader" => "Os endereços de e-mail que recebem os seus ciclos foram alterados.",
	"notification_emails.title" => "Endereços de e-mail dos ciclos alterados",
	"notification_emails.emptied" => "A lista de endereços de e-mail da sua conta que recebem os seus ciclos por e-mail foi <strong>esvaziada</strong>.",
	"notification_emails.listed" => "Os endereços de e-mail da sua conta que recebem os seus ciclos por e-mail, junto com o seu endereço de acesso, agora são: <strong>{list}</strong>.",

	"new_login.title" => "Novo acesso à sua conta",
	"new_login.subject" => "Novo acesso, token {prefix}",
	"new_login.intro" => "Acabou de ocorrer um novo acesso à sua conta.",
	"new_login.fact_date" => "Data",
	"new_login.fact_name" => "Nome",
	"new_login.fact_token" => "Início do token",
	"new_login.fact_access" => "Permissão",
	"new_login.fact_until" => "Válido até",
	"new_login.fact_agent" => "Navegador ou app",
	"new_login.access_read_only" => "apenas leitura",
	"new_login.access_read_write" => "leitura e escrita",
	"new_login.agent_unknown" => "desconhecido",
	"new_login.sessions" => "A lista dos seus acessos também se encontra lá.",

	// A quiet account, then an account about to be erased
	"reminder.subject" => "Como está? ({when})",
	"reminder.preheader" => "O seu gráfico está à sua espera, e estamos aqui se precisar.",
	"reminder.eyebrow" => "Olá de novo",
	"reminder.title" => "Está tudo bem?",
	"reminder.quiet" => "Há algumas semanas o seu gráfico não recebe nenhuma observação nova.",
	"reminder.pause" => "Se fez uma pausa, não há problema: as suas observações estão guardadas e pode retomar quando quiser.",
	"reminder.feedback" => "<strong>A sua opinião nos ajuda.</strong> Uma pergunta, uma ideia, algo no MONCYCLE.APP que não está como gostaria? {@contact.reply} Lemos todas as mensagens.",
	"reminder.button" => "Abrir o meu gráfico",
	"reminder.ps" => "Não quer mais usar o MONCYCLE.APP? Ignore este e-mail, não receberá outro.",

	"deletion_warning.subject" => "A sua conta será apagada em {date}",
	"deletion_warning.preheader" => "Entre nos próximos {days} dias para manter a sua conta.",
	"deletion_warning.eyebrow" => "Conta inativa",
	"deletion_warning.title" => "A sua conta será apagada em breve",
	"deletion_warning.intro" => "Há muito tempo não há nenhuma atividade na sua conta MONCYCLE.APP.",
	"deletion_warning.warning" => "De acordo com a nossa política de conservação de dados (RGPD), a sua conta e todos os dados associados serão <strong>apagados definitivamente dentro de {days} dias</strong> se, até lá, não houver nenhuma atividade.",
	"deletion_warning.keep" => "Para manter a sua conta, basta entrar.",
	"deletion_warning.button" => "Entrar",
	"deletion_warning.ps" => "Algum problema ou precisa de ajuda? {@contact.reply}",

	"terms_update.subject" => "Termos de uso: alterações a partir de {day}",
	"terms_update.preheader" => "Os novos termos entram em vigor no dia {day}.",
	"terms_update.eyebrow" => "Termos de uso",
	"terms_update.title" => "Os nossos termos de uso vão mudar",
	"terms_update.intro" => "Atualizamos os termos de uso do MONCYCLE.APP. Os novos termos entram em vigor no dia <strong>{day}</strong>.",
	"terms_update.accept" => "Ao usar o MONCYCLE.APP depois desta data, aceita estas alterações. Se não as aceitar, pedimos que deixe de usá-lo: pode apagar a sua conta na página {@app.settings}.",
	"terms_update.button" => "Ler os novos termos",

	// A finished cycle, with its files
	"cycle.subject" => "O seu ciclo {@cycle.range} ({@cycle.duration})",
	"cycle.preheader" => "O seu ciclo de {n} dias terminou: PDF, CSV e NFP em anexo.",
	"cycle.eyebrow" => "Ciclo terminado",
	"cycle.title" => "O seu ciclo terminou",
	"cycle.intro" => "Aqui está a exportação completa do seu ciclo, em três formatos, para consultar, enviar ou guardar.",
	"cycle.range" => "de {first} a {last}",
	"cycle.duration" => "{@lifetime.days}",
	"cycle.fact_cycle" => "Ciclo",
	"cycle.fact_duration" => "Duração",
	"cycle.attachments" => "Em anexo",
	"cycle.about_pdf" => "O gráfico do seu ciclo, para mostrar à sua instrutora ou ao seu instrutor.",
	"cycle.about_csv" => "As suas observações dia após dia, para abrir no LibreOffice ou no Microsoft Excel e fazer uma análise mais detalhada.",
	"cycle.about_nfp" => "O arquivo NFP contém todos os dados deste ciclo em um formato de intercâmbio: guarde como backup, pois poderá importá-lo novamente no moncycle.app em {@app.settings}, “Importar um arquivo NFP”. Também funciona com outros apps, se quiser levar os seus dados para outro lugar.",
	"cycle.opt_out" => "Este envio é automático. Pode desativá-lo a qualquer momento: abra “Configurações”, em “{@app.cycles_by_email}”, e desmarque “Receber cada ciclo por e-mail”.",
	"cycle.manage" => "Alterar este envio",
];
