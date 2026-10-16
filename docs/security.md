# Security

How the app protects its users' data, and what the operator has to do. For the setup around the app (reverse proxy, limits,
other origins) see [deployment.md](deployment.md); for what the app writes in its log, [logs.md](logs.md).

- [Tokens and sessions](#tokens-and-sessions)
- [Sign-up, passwords and links by e-mail](#sign-up-passwords-and-links-by-e-mail)
- [Login protection](#login-protection)
- [The captcha](#the-captcha)
- [Writes from other sites](#writes-from-other-sites)
- [Stored text and scripts](#stored-text-and-scripts)
- [What is not public](#what-is-not-public)
- [Personal data and retention](#personal-data-and-retention)

## Tokens and sessions

The only credential is a **token**: a random 256-character string, stored by the server as its SHA-256. The web app keeps it in an
`HttpOnly` cookie; a script or a mobile app sends it as `Authorization: Bearer <token>`.

`POST /api/v2/token` is the sign-in. It always takes the credentials (password, TOTP code, and a captcha once the account asks for
one), so a stolen session cannot make itself another token without the password. It also takes:

| Field | Meaning |
|---|---|
| `name` | The client's own word for itself (256 characters at most, shown as text). Helps the owner find a device, proves nothing. |
| `scope` | `readWrite` (default) or `readOnly`. A read-only token gets `403 read_only_token` on any method but `GET`, `HEAD` and `OPTIONS`; it may still end its own session. |
| `expiresInDays` | 1 to 365. The token dies on that day whatever its use and opens nothing from then on; the cron only erases it. Without it, a token is erased after 40 days unused or a year after its creation. |
| `cookie` | `true`: the session is also set as the `HttpOnly` cookie. Otherwise no cookie is set, so a token made on the settings page cannot replace the session it was made from. |

The token is always in the answer, the web app's included, so a script injected into the page could read it: the
`script-src 'self'` policy (see [Stored text and scripts](#stored-text-and-scripts)) is what stands in the way.

`GET /api/v2/token` lists the account's tokens: the `prefix` (the first 5 characters, kept in the clear and unique per account; the
other 251 characters are known only as a SHA-256), name, scope, creation, last use (written at most every 5 minutes), expiry, and
the user agent and network of the address (`a.b.c.0`, or the first 48 bits of an IPv6 address) at creation and last use.

- `DELETE /api/v2/token` (no body) ends the caller's own session, whatever state its token is in, and clears the cookie.
- `DELETE /api/v2/token/{prefix}` revokes the token that starts with these 5 characters (looked up in the caller's account only: no
  database id is in the API). `DELETE /api/v2/token?others=true` revokes all the others.
- The two demo accounts (a shared account whose password is public) can neither list nor revoke other tokens.

**Every sign-in is announced by mail** to the account's address (the demo accounts excepted): token name, `prefix`, scope,
expiry and user agent, no IP address. The mail is sent before the answer, so a slow SMTP server slows the sign-in down (10 s at
most); a mail that cannot be sent never fails it.

The database keeps the user agent and the address **whole** for as long as the token lives. They are personal data: say so in your
privacy policy. They go with the token when it is revoked, expires, or the account is deleted.

## Sign-up, passwords and links by e-mail

The password is chosen at sign-up (10 to 72 bytes: bcrypt reads 72 and drops the rest, so a longer one is refused). A new account
cannot sign in until its address is proved: `POST /api/v2/token` answers `403 email_not_verified`, but only to someone who gave the
right password (a wrong one, or an unknown address, gets the usual `401 invalid_credentials`, so a sign-in tells nothing about an
account it cannot open), and that refusal counts as no failed attempt.

**One kind of link** proves the address, chooses a password again and changes the login address. It is mailed to the address and
used at `POST /api/v2/verify_email` with `{"purpose": "accountCreation" | "resetPassword" | "changeEmail", "token": ..., "newPassword": ...}`
(`newPassword` goes with `resetPassword` only).

- The link is a page of the app with the purpose and the token in its **fragment** (`APP_URL` + `verify_email#purpose=...&t=...`).
  A fragment reaches no server, log or `Referer`, and a mail scanner that only fetches the link runs no script, so it uses nothing up.
- The token is 32 random bytes; the database keeps its SHA-256 only, with what it is for, when it dies (3 days to verify, 1 hour to
  choose a password, 1 day to change the address) and, for a change of address, the address it leads to.
- **An account has one slot.** A new link replaces the one before it, whatever that one was for. A link is single use and checked
  against its purpose: the link of one action does not do another.
- **Consequence:** anyone who can ask for a password reset can cancel a link that is waiting (a verification, a change of
  address); there is no cooldown. That is accepted: the person asked for a new link gets a mail with one, and a reset link proves
  the address as well. What keeps it from being a flood is the captcha, which is tied to the pending link and so is good for one
  mail, and the throttling per IP address.

`POST /api/v2/register` answers the same `202 {"sent": ...}` whether the address is new or already has an account (verified or not).
It never touches an existing account, whatever the request says; it mails the owner a link to choose a new password instead of the
one to verify. Everything the request can get wrong (a field, the password) is said before the address is looked up, and the
bcrypt hash is made either way.

`POST /api/v2/recover_password` only mails a reset link: **the password stays what it is until the link is used**, so asking for one
locks nobody out. Using a reset link sets the password, proves the address and empties the slot. It does not close the sessions
that are open nor touch the TOTP: a mail tells the owner that the password changed, and they can close the other sessions from the
settings page. `POST /api/v2/recover_password` sleeps 1 to 5 s on purpose (so that its timing does not tell whether the address has an
account) and holds a worker meanwhile: rate-limit it at the edge.

**Account settings.** `POST /api/v2/account` changes every setting. Three of them hand the account or its cycles over, and want the
current password again as `password`:

- `newPassword` is set at once (`logoutOtherDevices` closes the other sessions). It ends a reset link that is waiting and leaves a
  change of address alone.
- `notificationEmails` are the addresses that receive the cycles, reminders and warnings besides the login address (`;` between
  two, 5 at most). It takes effect at once, and a notice goes to the login address.
- `loginEmail` is the login address, which every recovery rests on. It also wants the TOTP `code` when there is one. It only
  *asks*: a link goes to the **new** address and the change happens when it is used. The current address is told; an address that
  another account has gets a mail saying so, no link, and the same answer; the sessions stay open.

The request is checked whole before anything is written, so a refused one changes nothing. A wrong password or code here is not
counted as a failed login. The two demo accounts cannot change these three (`403 demo_account`).

## Login protection

Failed logins are counted per account and decay after `LOGIN_ATTEMPTS_DECAY_MINUTES` (60) without a failure.

| Failures on the account | What happens | Setting |
|---|---|---|
| `LOGIN_CAPTCHA_THRESHOLD` (3) | A captcha is required | `403 captcha_required` |
| `LOGIN_LOCKOUT_THRESHOLD` (15) | A wrong password locks the account; the right one still signs in | `403 account_locked` |
| `LOGIN_IP_MAX_ATTEMPTS` (30) from one IP | Failed logins, and failed captchas of register and recover_password | `429 rate_limited` |

`0` turns a feature off. Behind a reverse proxy, the per-IP limit only works if the app knows the visitors' addresses: set
`TRUSTED_PROXIES` ([deployment.md](deployment.md#reverse-proxy)).

**Two-factor authentication.** Once the password is known, the one-time code is all that is left to guess, so a wrong TOTP code
after a right password counts like a wrong password. Past the lockout threshold an account with a TOTP code refuses even the right
password and code until the failures decay. Whoever knows the password can therefore keep such an account locked while they keep
trying.

A password or TOTP code asked again on an open session (`POST /api/v2/account`, `POST /api/v2/account/deletion`,
`POST /api/v2/totp/confirm`, `POST /api/v2/totp/disable`) counts against the same account counters as a sign-in
(`nb_connection_attempts`, `LOGIN_LOCKOUT_THRESHOLD`, `LOGIN_ATTEMPTS_DECAY_MINUTES`): past the threshold these checks answer
`403 account_locked`, even for the right secret, and the session stays valid. A stolen session can therefore not guess the
password or the code at will. The price is shared with the sign-in: whoever holds the session can also push the owner's next
sign-in towards the captcha, and, with TOTP on, towards the lockout. Turning TOTP on asks for the password
(`POST /api/v2/totp/confirm`), so a session that is not the owner's cannot lock the owner out of the next sign-in.

## The captcha

`POST /api/v2/captcha` gives a picture and a token `<exp>.<mac>`. **The server keeps nothing:** no row, no column, no cookie. The mac
is an HMAC-SHA256, keyed with `CAPTCHA_SECRET`, of:

- the purpose (`register`, `recover` or `login`), the address, the expiry (5 minutes) and the answer;
- the caller's network (the IP cut to /24, or /64 for IPv6);
- a state of the account that its own use changes: for `register` and `recover`, the stored password and the link pending in the
  account's slot (a link made, used or replaced ends the token: one mail per captcha, whether the address has an account or not);
  for `login`, the failure counters (a refused attempt ends it).

A token that is wrong in any way answers one `captcha_invalid`, and the same 403 whether the account exists or not. Of the requests
that arrive together with one token, one passes, so one password is tried per solved captcha. A login that succeeds ends the
failures; a request that reads the account after it finds one that asks for no captcha.

- **Closed by default.** `CAPTCHA_SECRET` empty or under 32 characters answers `503 captcha_unavailable` to everything that needs
  a captcha. Make one with `openssl rand -base64 48`.
- The Docker image makes a random one at start-up when none is given: a restart then voids the challenges in flight and nothing
  else. **Several instances behind one address must share the same `CAPTCHA_SECRET`**, or a token made by one is refused by the other.
- A captcha that fails counts as an attempt against the caller's IP like a failed login (`LOGIN_IP_MAX_ATTEMPTS`); one that
  passes gives its attempt back.

## Writes from other sites

The session cookie is `HttpOnly` and `SameSite=Strict` (`COOKIE_SAMESITE`). On top of that, every write (`POST`,
`DELETE`) that a browser sends is checked against its `Origin` header (or `Sec-Fetch-Site` when there is none): the server's own
host, `APP_URL` and `WEB_APP_ORIGINS` pass; any other page gets `403 cross_origin_refused`, sign-in and password recovery
included. Bodies must be `Content-Type: application/json` (a 415 otherwise), which a form of another site cannot send.

A request with none of these headers (curl, a script, a mobile app) is not a browser's and is not refused.

## Stored text and scripts

What a user can store (the account nickname, a comment, a label, whether typed or imported from a file) is written into the page
as text, never as HTML. The pages are served with a Content-Security-Policy whose `script-src` is `'self'`: no inline script runs.
The only pages from outside are the news banner (`NEWS_URL`) and the terms of service (`TERMS_OF_SERVICE_URL`). Each is shown in an
iframe with `sandbox` (no script, no same origin; links open in a new tab) and `referrerpolicy="no-referrer"`, and the CSP's
`frame-src` allows the origin of those two pages and no other.

## What is not public

- Apache refuses (403) `config*.php`, `constants.php`, `lib/`, `vendor/` (except the three libraries the pages load),
  `composer.*`, `*.sql` and `*.md`.
- `script/` (cron, stats, migrations) answers only to the machine itself: `APP_SCRIPT_ALLOW` (default `127.0.0.1 ::1`) lists the
  IPs allowed in, everyone else gets a 403. Behind a reverse proxy Apache sees the proxy's address, not the caller's, so do not open
  it to an IP unless Apache gets the real client address.
- `disable_functions` and `open_basedir` in [`server_conf/moncycleapp_php.ini`](../server_conf/moncycleapp_php.ini) are defence
  in depth, not a sandbox.
- The app does not write to its disk, but for its log file when you ask for one.

## Personal data and retention

- An account with no activity for `ACCOUNT_INACTIVITY_DELETE_YEARS` (4) years is deleted by the daily cron, after a warning by mail.
  An account whose address was never proved is deleted after `ACCOUNT_UNVERIFIED_DELETE_DAYS` (7) days. Both are in
  [`constants.php`](../www_data/constants.php).
- `POST /api/v2/account/deletion` deletes an account at its owner's request; every other table follows by database cascade.
- `GET /api/v2/all_of_my_data_plz` gives the owner a CSV dump of everything stored about them (passwords, TOTP secrets and tokens are
  masked).
- The log says what happened, to which account and to which row, never the content ([logs.md](logs.md)). It holds IP addresses and
  account ids: keep it only as long as you need it.
