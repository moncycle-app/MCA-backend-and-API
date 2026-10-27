# API tests

Regression tests for the HTTP API: they call a running server and check what it answers, against the contract
(`www_data/api/moncycle_app_open_api.yaml`). They do not look inside the code, so a refactor that keeps the contract keeps them green.

About 130 tests, 8 seconds, standard library only.

## Run them

You need Docker (with the dev containers of the MCA-Docker-image repository running: the app container, MariaDB) and Python 3.

```sh
tests/run.sh                 # everything
tests/run.sh day token       # only the files whose name contains "day" or "token"
tests/run.sh -v              # one line per test
tests/run.sh --keep          # leave the server up afterwards: http://127.0.0.1:8190
tests/static_checks.sh       # PHP syntax of every file + the layout rules of AGENT.md (no server needed)
```

A green run prints one line per file and `ALL GREEN`; a red one prints, per failed test, the assertion and the answer of the server.

`tests/run.sh` starts a throwaway container `moncycle_test` (and removes it at the end) from the image of the dev container,
serving the same `www_data` on the database `test_db_moncycle` (change it with `MONCYCLE_TEST_DB`, a name starting with `test_`; the database must exist and the DB user needs all privileges on it), rebuilt from `schema.sql` + `demo.sql` at each start. The dev app and
the dev database are not touched, and the SMTP port is dead: no mail leaves. The test server refuses any database whose name does not
start with `test_`.

### Your own server

Anything that serves `www_data` with `tests/support/router.php` as the router, on a `test_*` database, works:

```sh
cd www_data && php -S 127.0.0.1:8190 ../tests/support/router.php     # PHP_CLI_SERVER_WORKERS=8 makes it faster
MONCYCLE_TEST_URL=http://127.0.0.1:8190 tests/run.sh
```

Give it: `DEMO_ENABLED=true`, `CAPTCHA_SECRET` (32+ characters), `TRUSTED_PROXIES=10.0.0.0/8,172.16.0.0/12,192.168.0.0/16`, a dead
SMTP (`SMTP_HOST=127.0.0.1 SMTP_PORT=1`), the default password policy, and a database made by `script/db_init.php`.

## What is where

| File | Covers |
|---|---|
| `api/test_public.py` | `server_info`, `health_check`, `pub_stat`, 401/404/405/415/403 shared by all endpoints |
| `api/test_days.py` | `GET/POST/DELETE /day`: replace-whole-day, validation, ranges, late writes (409) |
| `api/test_descriptions.py` | `/description`: create, rename, retype, delete, uniqueness, isolation between accounts |
| `api/test_sync.py` | `GET /timeline_sync`: `since`, overlap, deletions, cycles |
| `api/test_account.py` | `GET/POST /account`: settings, password, addresses, ETag, deletion |
| `api/test_token.py` | `/token`: sign-in, cookie, captcha after failures, read-only tokens, revoke, logout, demo accounts |
| `api/test_register.py` | `/register`, `/recover_password`, `/verify_email` (links by mail) |
| `api/test_totp.py` | two-factor set-up, sign-in with a code, turning it off |
| `api/test_files.py` | exports (CSV, PDF, NFP), NFP import (dry run, override, refusals), raw data export |
| `api/test_contract.py` | the OpenAPI file against the server: every route and verb, every endpoint documented |
| `api/moncycle.py` | what the tests share: HTTP client, `Account`, assertions |
| `support/router.php` | the test server's router: Apache's MultiViews, and the fixtures under `/_test/` |
| `support/reset_db.php` | empties the `test_*` database before `db_init.php` rebuilds it |
| `static/check_structure.php` | the layout rules of AGENT.md |

## Reading and writing a test

A test is a few lines of what a client does and what it must get:

```python
def test_a_new_day_is_201_with_its_address_then_a_replacement_is_200(self):
    a = Account()                                         # a throwaway account with a session token
    r = a.post("day/2026-09-20", {"stampColor": "Red"})   # POST /api/v2/day/2026-09-20
    self.assertStatus(r, 201)
    self.assertEqual(r.headers["Location"], "/api/v2/day/2026-09-20")
    self.assertStatus(a.post("day/2026-09-20", {"stampColor": "Green"}), 200)
```

- `Account(method=2)` makes a verified account (method 1-4) and a token; `a.get/post/delete(path, body)` call as that account,
  `request(verb, path, body, token=...)` calls as anyone. `r.data` is the `data` of the answer, `r.error` its `error`.
- `assertError(r, status, code)` and `assertIssues(r, "field", ...)` check the failure envelope of the contract.
- Fixtures the API cannot give (`moncycle.py`): `fixture("email_token", ...)` is the link a mail would have held,
  `fixture("totp", secret=...)` the current one-time code, `fixture("captcha", ...)` an answerable captcha, `sql("SELECT ...", params)` a read
  (or an `UPDATE`) of the test database.
- Every test makes its own accounts and never depends on another test or on the order, so the files run side by side.
- Each client call has a random public `X-Forwarded-For`: the sign-in throttling is per address, and one test failing sign-ins
  must not lock out the others.
- A new endpoint or a changed contract: change `www_data/api/moncycle_app_open_api.yaml` first, then add the tests; `test_contract.py` fails
  until the file and the endpoint files agree.
- A test records what the contract says or what the server must keep doing, not an accident. If you meet a behaviour that looks
  wrong, do not write a test that fixes it in place: report it.
