# The mail template

`layout.html` is the frame of every mail sent by the app: header (logo and wordmark), a card with the message, an optional
button, the sign-off, and a footer telling why the mail came. It is written for mail readers, not browsers: tables for the layout,
inline styles for the light theme, and a `<style>` block that only adds the dark theme and the narrow-screen rules on top of them.
A reader that drops the `<style>` block still shows the full light mail.

## Tags

A small subset of Mustache, so that the renderer stays a few lines of PHP:

| Tag | Meaning |
|---|---|
| `{{name}}` | the value of `name`, **HTML-escaped** by the renderer (`htmlspecialchars`). Every text from a user or a client goes here. |
| `{{{name}}}` | the value of `name`, inserted **as is**: HTML built by the code, never a raw user text. |
| `{{#name}} ... {{/name}}` | the block is kept when `name` is set and not empty, removed otherwise. Sections are never nested (one regex pass renders them); a name may open several sections. |

A tag with no value renders as the empty string.

## Contact

The contact address is `hello@tech4fertility.org` (the footer of the template), and every mail is sent with it as `Reply-To`.
`bonjour@moncycle.app` is no longer read: it must not appear in a mail.

## Slots

| Slot | Kind | Required | What |
|---|---|---|---|
| `subject` | text | yes | the subject, also the `<title>` |
| `preheader` | text | yes | the line a mailbox shows after the subject (hidden in the mail itself); one sentence, not a repeat of the subject |
| `site_url` | text | yes | `https://www.moncycle.app` (logo, sign-off, footer) |
| `eyebrow` | text | no | the small green label above the title: "Nouvelle connexion", "Cycle terminé" |
| `title` | text | no | the heading of the mail |
| `greeting` | text | yes | "Bonjour Alice," / "Bonjour," |
| `content` | HTML | yes | the message, made of the components below |
| `button_url` | text | no | the main action; with it come the button and, under it, the same link written in full to be copied |
| `button_label` | text | with `button_url` | "Confirmer mon adresse" |
| `button_note` | text | with `button_url` | one line under the button: "Ce lien est valable 24 heures." |
| `ps` | HTML | no | a small line after the sign-off |
| `why` | text | yes | why the mail came: "Vous le recevez car ..." (`mail_why()`) |

## Components of `content`

The classes are what the dark theme hooks on; the inline styles are the light theme. Copy them as they are.

Paragraph (the last one of `content` keeps its bottom margin, it spaces the button):

```html
<p style="margin:0 0 16px 0;">Text.</p>
```

Link in the text:

```html
<a class="mc-link" href="..." style="color:#178048;text-decoration:underline;">connectez-vous</a>
```

Facts, a label and a value per row (a sign-in, a cycle, an address change):

```html
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:4px 0 20px 0;"><tr>
	<td class="mc-panel" bgcolor="#f4f8f5" style="background:#f4f8f5;border:1px solid #e3ebe5;border-radius:12px;padding:8px 18px;">
		<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
			<tr>
				<td class="mc-fact-label mc-muted" width="42%" style="padding:8px 12px 8px 0;font-size:14px;line-height:20px;color:#5f6e65;">Date</td>
				<td class="mc-fact-value mc-heading" style="padding:8px 0;font-size:14px;line-height:20px;font-weight:600;color:#14231a;">27/10/2026 à 14:32</td>
			</tr>
		</table>
	</td>
</tr></table>
```

A token prefix or any short code: `<code class="mc-code" style="background:#e9efeb;color:#14231a;border-radius:6px;padding:2px 6px;font-family:Menlo,Consolas,monospace;font-size:13px;">a1b2c3</code>`

Callout, `mc-callout-info` (green, a tip) or `mc-callout-warn` (amber: "if this is not you", an erasure ahead):

```html
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:4px 0 20px 0;"><tr>
	<td class="mc-callout-warn" bgcolor="#fff7e0" style="background:#fff7e0;border-left:4px solid #e0a800;border-radius:8px;padding:14px 18px;font-size:15px;line-height:23px;color:#5a4300;">
		<strong>Ce n'est pas vous&nbsp;?</strong> ...
	</td>
</tr></table>
```

Info variant: class `mc-callout-info`, `bgcolor="#eaf8f0"`, `background:#eaf8f0;border-left:4px solid #32be73;color:#14402a`.

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
