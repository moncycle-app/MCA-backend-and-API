# Deployment

What to put around the app when you host it for real. The first install is in the [README](../README.md#install); the app's
protections are in [security.md](security.md).

- [Reverse proxy](#reverse-proxy)
- [Limits](#limits)
- [Web app and API on one origin, or two](#web-app-and-api-on-one-origin-or-two)
- [Content-Security-Policy](#content-security-policy)
- [Without Docker, in production](#without-docker-in-production)

## Reverse proxy

The Docker image serves plain HTTP and is made to sit behind a reverse proxy. The proxy must:

- **Terminate TLS** and redirect http to https. The image sends `Strict-Transport-Security` itself when the request carries
  `X-Forwarded-Proto: https`: make the proxy set that header. If the proxy already sends HSTS, remove that line from
  [`server_conf/zz-moncycleapp.conf`](../server_conf/zz-moncycleapp.conf) to avoid a duplicate.
- **Rate-limit** `/api/v2/token`, `/api/v2/register`, `/api/v2/recover_password` and `/api/v2/captcha` (a captcha costs a JPEG to draw and is
  kept nowhere). `/api/v2/recover_password` sleeps 1 to 5 s on purpose and holds one of the 30 workers meanwhile.
- **Cap the body at 256 KB** (`client_max_body_size 256k` with nginx), the same as Apache, so that a request that is too large is
  refused at the edge before it costs a worker.
- **Not log query strings or paths.** `GET /api/v2/day/2026-09-20`, `GET /api/v2/day?from=`, `GET /api/v2/timeline_sync?since=` and
  `DELETE /api/v2/token/{prefix}` carry dates and token prefixes, and the dates of someone's observations are sensitive data. The
  image has no Apache access log for that reason; the app's own log names the endpoint (`/api/v2/day`), never the arguments of the URL.
- **Block `/script/`** too (defence in depth: the app already refuses it to anyone but the machine itself).
- **Tell the app who the proxy is.** Set `TRUSTED_PROXIES` (an address or a CIDR range, comma separated) and have the proxy send the
  real client address in `X-Forwarded-For` (`proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;` with nginx).

### `TRUSTED_PROXIES`

Without it every visitor has the proxy's address: the login throttling (`LOGIN_IP_MAX_ATTEMPTS`, 30 failures in 15 minutes per
address) becomes one limit for everyone, so 30 failed logins from anywhere answer 429 to all, and the `ip` of the log is the proxy.

- `X-Forwarded-For` is believed only when the peer is in `TRUSTED_PROXIES`. It is read from its right end, skipping trusted
  addresses, so whatever the client wrote on the left is ignored. From anyone else it is never believed: a visitor who could choose
  their own address would dodge the throttling with a new one at every attempt, or put the failures on somebody else's.
- The value is the address the **container** sees the proxy from, which is not always the one you know it by: a proxy on the host
  reaches a published port from the gateway of the Docker network (`172.x.0.1`), a proxy in another container from that
  container's address. If the `ip` of the log lines shows an address of your Docker network instead of visitors', put that address
  in `TRUSTED_PROXIES` (its network's range, e.g. `172.18.0.0/16`, if it can change).
- A CDN in front of the proxy adds its own address to the chain: list its ranges too, or the first address the app meets from the
  right, the CDN's, is taken for the visitor.
- `APP_SCRIPT_ALLOW` is Apache's own `Require ip` and is unchanged: it still sees the proxy's address.

## Limits

Set in [`server_conf/`](../server_conf/); they are part of the image.

- **Apache:** request body 256 KB, 30 s `Timeout`, 30 workers of about 21 MB each. A request to `/api/` whose `Content-Length` is
  larger gets a `413 file_too_large` in the API's JSON envelope before any handler runs, so PHP never starts and logs nothing.
  (Without that early refusal PHP reads a JSON body into memory: a 20 MB body is read in full and one of 33 MB ends in a
  `memory_limit` fatal error.) `LimitRequestBody` is the same wall for a body sent in chunks, which has no `Content-Length`.
- **PHP:** `memory_limit` 32M (do not lower it without measuring the peak memory of a multi-year export), `max_execution_time` 30,
  `file_uploads` Off.
- **`post_max_size` (256K) is coupled** with `NFP_LIMIT_BODY_BYTES` in [`constants.php`](../www_data/constants.php) and with
  Apache's `LimitRequestBody` and `Content-Length` test: change the three or none. It does not cap a raw JSON body (read from
  `php://input`): that cap is Apache's 256K and, for `/api/v2/import`, the app's own check of the same size.
- **Database connections:** the app keeps its connection open between requests (one per Apache worker, 30 at most per
  container). MariaDB's `max_connections` (151 by default) must cover the workers of every app container plus the cron.
  `php www_data/script/db_perf.php` (command line, read-only) measures the link and checks that this is safe.

## Web app and API on one origin, or two

**The default: one origin.** The container serves the pages and `/api/` from the same host, `APP_URL`. A reverse proxy that routes
`/api` to another backend still shows the browser one origin, so it needs nothing here but `TRUSTED_PROXIES`.

**The image does not serve the web app from another origin than its API.** Every URL in the pages is relative (`api/v2/...`) and the
API sends no CORS header, so a browser page on another origin cannot call it. What is there is the groundwork:

- `APP_URL` and `WEB_APP_ORIGINS` name the browser origins that may *write* (the `Origin` check, see
  [security.md](security.md#writes-from-other-sites)): the origin of `APP_URL`, the ones you list, and the API's own host (the `Host`
  header of the request; if your proxy rewrites it, `APP_URL` is what lets your own pages through).
- `COOKIE_SAMESITE` sets the cookie's `SameSite`. `strict` (the default) is right for pages and API on the same site, sibling
  subdomains included. A web app on **another site** needs `lax` (page navigations only) or `none` (requests from scripts; needs
  `PHP_SECURE_COOKIES=true`) and relies on the browser not blocking third-party cookies: treat that as fragile.
- Clients that are not browsers (a mobile app, a script) send no `Origin`, use `Authorization: Bearer <token>` and work from anywhere.

## Content-Security-Policy

The image sends this policy for the pages it serves (`server_conf/zz-moncycleapp.conf`):

```
default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; connect-src 'self'; frame-src <origins of NEWS_URL and TERMS_OF_SERVICE_URL>;
frame-ancestors 'none'; base-uri 'self'; form-action 'self'; object-src 'none'
```

The news banner and the terms of service are shown in iframes, so `frame-src` lists the origin of each of the two pages (the
image's entrypoint makes it from `NEWS_URL` and `TERMS_OF_SERVICE_URL`; `'none'` when neither is set). An origin, not the page: the
terms page links to its other languages inside the frame. Pages served by anything else need the same policy from their own server,
and a front end that calls an API on another origin adds that origin to its `connect-src`. Swagger UI is one of the image's pages and is covered by the same policy.

## Without Docker, in production

The [README](../README.md#without-docker) shows how to run the app on PHP's built-in server, to try it. For a real site, use a
proper web server (Apache with `mod_php` or PHP-FPM, nginx with PHP-FPM) with `www_data/` as its document root, and make it do
what the Docker image's Apache does. [`server_conf/zz-moncycleapp.conf`](../server_conf/zz-moncycleapp.conf) is the reference.

1. **Serve the pages and the API without their extension.** `/auth` is `auth.html`, `/api/v2/token` is `api/v2/token.php`, and what
   follows the script name is its `PATH_INFO`: `/api/v2/day/2026-03-10` runs `api/v2/day.php` with `PATH_INFO=/2026-03-10`. Apache does it
   with `Options +MultiViews` and `MultiviewsMatch Any`.
2. **Refuse what is not public** (403): `config*.php`, `constants.php`, `composer.json`, `composer.lock`, `*.sql`, `*.md`, `lib/`,
   and `vendor/` except `vendor/components/jquery`, `vendor/chartjs` and `vendor/swagger-api/swagger-ui/dist`.
3. **Keep `script/` for the machine itself.** It holds the cron and the migrations, which are meant to be run from the command
   line.
4. **Cap the body at 256 KB** and set `post_max_size = 256K` (see [Limits](#limits)).
5. **Send the security headers**: the Content-Security-Policy above, `X-Content-Type-Options: nosniff`, `Referrer-Policy: same-origin`
   and, over HTTPS, `Strict-Transport-Security`. Send `Cache-Control: no-store` on `/api/`.
6. **PHP settings** worth keeping from [`server_conf/moncycleapp_php.ini`](../server_conf/moncycleapp_php.ini): `date.timezone = UTC`
   (the app stores UTC), `display_errors = Off` with `log_errors = On`, `expose_php = Off`, `memory_limit = 32M`,
   `max_execution_time = 30`, `file_uploads = Off`, and `open_basedir` set to the app's directory (add the directory of the log file,
   if any, and your secrets directory). The file's `${VAR:-default}` values are filled from the container's environment; outside
   Docker write the values you want.
7. **Run the cron** as the web user, once a day: `30 3 * * * php /path/to/www_data/script/cron.php`. With `DEMO_ENABLED`, also
   `0 * * * * php /path/to/www_data/script/demo_reset.php`.
   See [Cron](../README.md#cron).
8. **Settings** are the `define()` lines of `www_data/config.php`, made from `config.exemple.php` ([Settings](../README.md#settings)).
   Put `config.php` out of reach of the web (rule 2 does that) and readable by the web user only.
