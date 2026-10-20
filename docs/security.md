# Security

How the app protects its users' data, and what the operator has to do. For the setup around the app (reverse proxy, limits, other
origins) see [deployment.md](deployment.md); for what the app writes in its log, [logs.md](logs.md).

- [Tokens and sessions](#tokens-and-sessions)
- [Sign-up, passwords and links by e-mail](#sign-up-passwords-and-links-by-e-mail)
- [Login protection](#login-protection)
- [The captcha](#the-captcha)
- [Writes from other sites](#writes-from-other-sites)
- [Stored text and scripts](#stored-text-and-scripts)
- [What is not public](#what-is-not-public)
- [Personal data and retention](#personal-data-and-retention)

## Tokens and sessions

The only credential is a **token**: a random 256-character string, stored as its SHA-256. The web app keeps it in an `HttpOnly`
cookie; a script or mobile app sends it as `Authorization: Bearer <token>`.

`POST /api/v2/token` is the sign-in. It always checks the credentials (password, TOTP code, captcha once the account asks for one), so
a stolen session cannot make itself another token without the password. Options:

| Field | Meaning |
|---|---|
| `name` | The client's own word for the token (256 characters, shown as text). Helps the owner find a device, proves nothing. |
| `scope` | `readWrite` (default) or `readOnly`. A read-only token gets `403 read_only_token` on any method but `GET`, `HEAD`, `OPTIONS`; it may still end its own session. |
| `expiresInDays` | 1 to 365. The token dies that day whatever its use. Without it: erased after 40 days unused or a year after creation. |
| `cookie` | `true` also sets the `HttpOnly` cookie. Otherwise none, so a token made on the settings page cannot replace its own session. |

The token is always in the answer, so a script injected into the page could read it: the `script-src 'self'` policy
([Stored text and scripts](#stored-text-and-scripts)) is what stands in the way.

- `GET /api/v2/token` lists the account's tokens: `prefix` (first 5 characters, unique per account), name, scope, creation, last use
  (written at most every 5 minutes), expiry, and the user agent and network of the address (`a.b.c.0`, or the first 48 bits of IPv6).
- `DELETE /api/v2/token` ends the caller's own session. `DELETE /api/v2/token/{prefix}` revokes one token of the caller's account.
  `DELETE /api/v2/token?others=true` revokes all the others. No body.
- The two demo accounts (a shared account with a public password) can neither list nor revoke other tokens.

**Every sign-in is announced by mail** to the account's address (demo accounts excepted): token name, `prefix`, scope, expiry, user
agent, no IP address. A slow SMTP server slows the sign-in (10 s at most); a mail that cannot be sent never fails it.

The database keeps the user agent and the address **whole** while the token lives. They are personal data: say so in your privacy
policy. They go with the token when it is revoked, expires, or the account is deleted.

## Sign-up, passwords and links by e-mail

**Passwords** are chosen at sign-up: 10 to 128 characters by default (`PASSWORD_POLICY_REGEX` sets the least; 128 is the most). Lengths
count Unicode characters, not bytes. A password typed to sign in or to confirm an action is not held to the policy and may be up to
1024 characters. The request body is capped at 256 KB.

**Hashing.** Every new hash is Argon2id (`sec_password_hash()`, options `PASSWORD_HASH_OPTIONS`: 46 MiB, 4 passes, about 238 ms on the
production VPS). Accounts with an older bcrypt hash keep signing in; once a sign-in has fully passed (TOTP included), the hash is
made again as Argon2id, so nobody has to change a password. That upgrade writes nothing on a refused sign-in, only replaces the hash
that was checked, leaves sessions and TOTP alone, and is skipped for a bcrypt password longer than 72 bytes (bcrypt ignores the rest,
so rehashing could lock the owner out).

**A refused sign-in tells nothing by its time.** Hash checks take different times (bcrypt 10: 85 ms, bcrypt 12: 324 ms, Argon2id:
238 ms), and an unknown address is checked against a dummy hash. To keep the time from telling whether an account exists, every
refusal that looked at the account (wrong password, unknown address, locked, disabled, not verified, TOTP missing or wrong) is
answered no sooner than `AUTH_LOGIN_REFUSAL_MIN_MS` (400 ms) after the request began. A success is not held. Refusals that do not
look at the account (throttling, captcha, sign-in off) answer at once, so a flood of them holds no worker. If you change the hash
options, measure on your server, raise the floor above the slowest check, and make the dummy hash and the `demo.sql` hashes again.
Each check holds its 46 MiB for its time: up to 30 at once is about 1.4 GiB, so rate-limit the sign-in at the edge.

**A new account cannot sign in until its address is proved**: `403 email_not_verified`, said only to someone who gave the right
password (a wrong one still gets `401 invalid_credentials`), and counted as no failed attempt.

**One kind of link** proves the address, chooses a password again and changes the login address. It is mailed and used at
`POST /api/v2/verify_email` with `{"purpose": "accountCreation" | "resetPassword" | "changeEmail", "token": ..., "newPassword": ...}`
(`newPassword` for `resetPassword` only).

- The link is a page of the app with the purpose and token in its **fragment** (`APP_URL` + `verify_email#purpose=...&t=...`). A
  fragment reaches no server, log or `Referer`, and a mail scanner that only fetches the link runs no script, so it uses nothing up.
- The token is 32 random bytes; the database keeps its SHA-256, its purpose, its expiry (3 days to verify, 5 hours to reset a
  password, 1 day to change the address) and, for a change of address, the new address.
- **An account has one slot**: a new link replaces the previous one whatever it was for. A link works once and only for its purpose.
- Consequence: anyone who can ask for a password reset can cancel a pending link, with no cooldown. Accepted: the owner gets a new
  link by mail, and a reset link proves the address too. The captcha (good for one mail) and the per-IP throttling stop floods.

`POST /api/v2/register` answers the same `202` whether the address is new or has an account, and never touches an existing
account: its owner gets a mail with a reset link instead. Every mistake in the request is reported before the address is looked up,
and the password is hashed either way.

`POST /api/v2/recover_password` only mails a reset link: **the password stays as it is until the link is used**. Using it sets the
password and proves the address; it does not close open sessions or touch the TOTP (a mail tells the owner, who can close sessions
from the settings page). It sleeps 1 to 5 s on purpose, holding a worker: rate-limit it at the edge.

**Account settings.** `POST /api/v2/account` changes every setting. Three want the current password again as `password`:

- `newPassword`: set at once (`logoutOtherDevices` closes the other sessions). It cancels a waiting reset link, not a waiting change of address.
- `notificationEmails`: addresses that also receive cycles, reminders and warnings (`;` between, 5 at most). Effective at once; a notice goes to the login address.
- `loginEmail`: the address every recovery rests on; also wants the TOTP `code` if set. It only *asks*: a link goes to the **new**
  address and the change happens when it is used. The current address is told; an address another account has gets a mail saying so
  (same answer); sessions stay open.

A request is checked whole before anything is written. The two demo accounts cannot change these three (`403 demo_account`).

## Login protection

Failed logins are counted per account and forgotten after `LOGIN_ATTEMPTS_DECAY_MINUTES` (60) without a failure.

| Failures | What happens | Answer |
|---|---|---|
| `LOGIN_CAPTCHA_THRESHOLD` (3) on the account | A captcha is required | `403 captcha_required` |
| `LOGIN_LOCKOUT_THRESHOLD` (15) on the account | A wrong password locks the account; the right one still signs in | `403 account_locked` |
| `LOGIN_IP_MAX_ATTEMPTS` (30) from one IP | Failed logins, and failed captchas of register and recover_password | `429 rate_limited` |

`0` turns a feature off. Behind a reverse proxy the per-IP limit works only if the app knows visitors' addresses: set
`TRUSTED_PROXIES` ([deployment.md](deployment.md#reverse-proxy)).

**Two-factor authentication.** Once the password is known only the code is left to guess, so a wrong code counts like a wrong
password, and past the lockout threshold such an account refuses even the right password and code until the failures fade. Whoever
knows the password can therefore keep it locked by trying.

A password or code asked again on an open session (`POST /api/v2/account`, `account/deletion`, `totp/confirm`, `totp/disable`) counts
against the same counters: past the threshold these checks answer `403 account_locked`, even for the right secret, and the session
stays valid. So a stolen session cannot guess the password or code at will; the price is that it can also push the owner's next
sign-in towards the captcha or the lockout. Turning TOTP on asks for the password, so a foreign session cannot lock the owner out.

## The captcha

`POST /api/v2/captcha` gives a picture and a token `<exp>.<mac>`. **The server keeps nothing**: no row, no cookie. The mac is an
HMAC-SHA256 keyed with `CAPTCHA_SECRET` over:

- the purpose (`register`, `recover`, `login`), the address, the expiry (5 minutes) and the answer;
- the caller's network (IP cut to /24, or /64 for IPv6);
- a state of the account that its own use changes: for `register` and `recover`, the stored password and the pending link (a link
  made, used or replaced ends the token: one mail per captcha); for `login`, the failure counters (a refused attempt ends it).

Any wrong token answers `captcha_invalid`, whether the account exists or not. Of requests that arrive together with one token, one
passes, so one password is tried per solved captcha.

- **Closed by default.** A `CAPTCHA_SECRET` empty or under 32 characters answers `503 captcha_unavailable` to everything that needs a
  captcha. Make one with `openssl rand -base64 48`.
- The Docker image makes a random one at start-up when none is given: a restart voids the challenges in flight, nothing else.
  **Several instances behind one address must share the same `CAPTCHA_SECRET`.**
- A failed captcha counts as an attempt against the caller's IP (`LOGIN_IP_MAX_ATTEMPTS`); a passed one gives it back.

## Writes from other sites

The session cookie is `HttpOnly` and `SameSite=Strict` (`COOKIE_SAMESITE`). Every write (`POST`, `DELETE`) a browser sends is also
checked against its `Origin` header (or `Sec-Fetch-Site` when there is none): the server's own host, `APP_URL` and `WEB_APP_ORIGINS`
pass; any other page gets `403 cross_origin_refused`, sign-in and password recovery included. Bodies must be
`Content-Type: application/json` (else 415), which a form of another site cannot send. A request with none of these headers (curl,
script, mobile app) is not a browser's and is not refused.

## Stored text and scripts

What a user can store (nickname, comment, label, typed or imported) is written into the page as text, never as HTML. The
Content-Security-Policy has `script-src 'self'`: no inline script runs.

- The terms of service (`TERMS_OF_SERVICE_URL`) are the one page from outside: shown in an iframe with `sandbox` (no script, no same
  origin; links open in a new tab) and `referrerpolicy="no-referrer"`; the CSP's `frame-src` allows its origin only.
- The news banner (`NEWS_URL`) is data: the browser fetches the JSON (no cookie, no referrer; `connect-src` allows that origin only),
  refuses anything but JSON under 16 KB, keeps only the strings it expects with a length cap, and draws them as text. The link must
  be https and opens with `noopener noreferrer`. Any failure hides the banner and goes to the browser console.

## What is not public

- Apache refuses (403) `config*.php`, `constants.php`, `lib/`, `vendor/` (except the three libraries the pages load), `composer.*`,
  `*.sql` and `*.md`.
- `script/` (cron, stats, migrations) answers only to the machine itself: `APP_SCRIPT_ALLOW` (default `127.0.0.1 ::1`). Behind a
  reverse proxy Apache sees the proxy's address, so do not open it to an IP unless Apache gets the real client address.
- `disable_functions` and `open_basedir` in [`server_conf/moncycleapp_php.ini`](../server_conf/moncycleapp_php.ini) are defence in
  depth, not a sandbox.
- The app writes nothing to its disk, except its log file if you ask for one.

## Personal data and retention

- An account with no activity for `ACCOUNT_INACTIVITY_DELETE_YEARS` (4) years is deleted by the daily cron, after a warning by mail.
  An account whose address was never proved is deleted after `ACCOUNT_UNVERIFIED_DELETE_DAYS` (7) days
  ([`constants.php`](../www_data/constants.php)).
- `POST /api/v2/account/deletion` deletes an account at its owner's request, with everything it owns.
- `GET /api/v2/all_of_my_data_plz` gives the owner a CSV of everything stored about them (passwords, TOTP secrets and tokens masked).
- The log says what happened, to which account and row, never the content ([logs.md](logs.md)). It holds IP addresses and account
  ids: keep it only as long as you need it.
