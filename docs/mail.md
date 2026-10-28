# The mails to users

Every mail to a user (`lib/mail.php`) is made of three things, each in its place:

| What | Where | Holds |
|---|---|---|
| the frame | `www_data/mail/layout.html`, `layout.txt` | the HTML and CSS (header, card, button, footer, dark theme) and the plain-text part (greeting, body, link, sign-off, footer). **No word of any language.** |
| the words | `www_data/mail/lang/<language>.php` (`fr.php`) | every text a user reads: subjects, titles, sentences, labels, date formats. **The only file to translate.** |
| the code | `www_data/lib/mail.php` | which mail says what, with which values. **No word a user reads.** |

The weekly report of the team (`lib/weekly_report.php`) is not one of them: it has a page of its own.

`tests/mail_preview/run.sh` renders every mail to `tests/mail_preview/out/index.html` (each in light, dark and phone width, with its
subject, addresses and text part), sends nothing and touches no database. It also checks that the language file and the code agree:
every key a mail asks for exists, every key of the file is asked for. Run it after any change to a mail or to the layout.

## The template

`layout.html` is the frame of every mail: header (logo and wordmark), a card with the message, an optional button, the sign-off, and a
footer telling why the mail came. It is written for mail readers, not browsers: tables for the layout, inline styles for the light
theme, and a `<style>` block that only adds the dark theme and the narrow-screen rules on top of them. A reader that drops the
`<style>` block still shows the full light mail.

`mail_render()` fills it; `mail_send()` calls it when it is given the slots of a mail rather than a page of its own. The comments of
the file are stripped, Outlook's conditional ones kept.

### Tags

A small subset of Mustache:

| Tag | Meaning |
|---|---|
| `{{name}}` | the value of `name`, **HTML-escaped** by the renderer (`htmlspecialchars`). Every text from a user or a client goes here. |
| `{{{name}}}` | the value of `name`, inserted **as is**: HTML built by the code, never a raw user text. |
| `{{#name}} ... {{/name}}` | the block is kept when `name` is set and not empty, removed otherwise. Sections are never nested (one regex pass renders them); a name may open several sections. |

A tag with no value renders as the empty string. The values are read in one pass, after the sections: a value holding `{{why}}` (a
nickname can) stays as it is.

### Slots

| Slot | Kind | Required | What |
|---|---|---|---|
| `subject` | text | yes | the subject, also the `<title>` (`mail_send()` adds it) |
| `preheader` | text | yes | the line a mailbox shows after the subject (hidden in the mail itself); one sentence, not a repeat of the subject |
| `eyebrow` | text | no | the small green label above the title |
| `title` | text | no | the heading of the mail |
| `greeting` | text | yes | "Bonjour Alice," (the nickname as it is: the template escapes it) |
| `content` | HTML | yes | the message, made of the components below |
| `button_url` | text | no | the main action; with it come the button and, under it, the same link written in full to be copied |
| `button_label`, `button_note` | text | with `button_url` | the button, and one line under it |
| `ps` | HTML | no | a small line after the sign-off |
| `why` | text | yes | why the mail came: "Vous le recevez car ..." |
| `site_url`, `contact_email` | text | | `MAIL_SITE_URL`, `MAIL_REPLY_TO`: added by `mail_render()` |
| `lang`, `signoff`, `button_fallback`, `footer_auto` | text | | the words of the frame (`layout.*` of the language file): added by `mail_render()` |

### Components of `content`

Made by `lib/mail.php`, never written by hand: their classes are what the dark theme hooks on, their inline styles the light theme.

| Function | What |
|---|---|
| `mail_p($html)` | a paragraph |
| `mail_a($url, $html)`, `mail_link($email, $page, $text)`, `mail_contact()` | a link; a page of the app with the address filled in; the team's address |
| `mail_facts([$label => $html])` | a panel of label / value rows (a sign-in, a cycle); stacked on a phone |
| `mail_callout("info" \| "warn", $html)`, `mail_not_you($html)` | a green tip, an amber warning; the "this is not you" warning of the security mails |
| `mail_code($text)` | a short code, a token prefix |

### The plain-text part

Every mail also carries a plain-text alternative, made by the same `mail_render()` from `layout.txt` (`MAIL_LAYOUT_TEXT`), with the same
slots, nothing escaped, and one blank line between blocks. The mail gives no text of its own: `mail_text_from_html()` makes it from the
`content` slot (a paragraph or a row on its own line, the cells of a row joined by `: `, a link as `label (url)`), so that no sentence
is written twice; the frame adds the greeting, the button as its label and link, the note, the PS, the sign-off and the footer. `ps`
is HTML and is made text the same way, or is the `ps_text` the mail gives (the cycle mail, whose PS links to a page).
`mail_send()` takes a `$text` only for a page of its own (the weekly report).

### Contact

Every mail is sent with `Reply-To: MAIL_REPLY_TO` (`hello@tech4fertility.org`, `constants.php`), and the footer gives that address: an
answer to a mail reaches the team.

## The words, and a new language

`mail_t($key, $vars)` reads a string of `mail/lang/<MAIL_LANG>.php` (a PHP file that returns `[key => string]`) and replaces its `{name}`
marks. Rules for the file:

- A key is `<mail>.<part>` (`reminder.title`), or `layout.*`, `why.*`, `format.*`, `greeting.*`, `lifetime.*`, `app.*`, `action.*`, `eyebrow.*`,
  `notice.*`, `contact.*`, `forgot.*`, `not_you.*` for what several mails share.
- A word or a sentence said twice is written once and used as `{@other.key}` (`mail_t()` replaces it by that string, then fills the
  `{name}` marks): the name of a settings page, "Not you?", the way to reach the team. A subject is the title and the moment
  (`subject.when`), unless a mail needs its own (`<mail>.subject`).
- A string is plain text, or light HTML (`<strong>`, no attribute) for the slots the template keeps (`content`, `ps`). The slots it
  escapes (`title`, `greeting`, `why`, `button_*`) get plain text. 
- The no-break space is written `\u{a0}` (before `?` `:` `!` and inside « », in French).
- A value the code passes in is escaped by the code for an HTML string, and raw for a slot the template escapes.
- The date formats (`format.*`), the month names and the plural forms (`lifetime.*`) are words too: they are in the file.

To add a language: copy `fr.php` to `<code>.php`, translate the values (keep the keys and the `{marks}`), set `MAIL_LANG` in
`constants.php`, run `tests/mail_preview/run.sh`. The language is one for the whole server today: choosing it per account is a later
step (an account setting, then `mail_t()` and `mail_render()` taking the language).

The mails reuse words the web app shows («⚙️ Réglages», «Cycles par e-mail»): a translation must follow the app's.

## Colours

`#32be73` is the brand: the band on top of the card, the wordmark, the button. White text on it does not read (contrast 2.4:1), so the
button text is the dark green `#06301a` (6:1), in both themes. On a light background a link or a small text is `#178048` (5:1);
on the dark card it is `#5fd696` (9:1).

| | Light | Dark |
|---|---|---|
| page | `#eef3ef` | `#0c120f` |
| card | `#ffffff`, border `#e0e8e2` | `#161f1a`, border `#26332b` |
| heading | `#14231a` | `#eef5f0` |
| text | `#3b4a41` | `#c4d1c8` |
| muted | `#5f6e65` | `#8b9a90` |
| link | `#178048` | `#5fd696` |
| panel | `#f4f8f5` | `#1e2923` |

Dark mode: Apple Mail, iOS Mail, Outlook for Mac/iOS, Thunderbird follow `prefers-color-scheme`; Outlook.com and the Outlook apps
take the `[data-ogsc]` / `[data-ogsb]` rules; the Gmail apps ignore both and invert the colours themselves. Outlook for Windows
(Word engine) shows the light theme with square corners.
