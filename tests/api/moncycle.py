# SPDX-License-Identifier: AGPL-3.0-or-later
"""What the tests share: a small HTTP client, throwaway accounts, and a few assertions that print the answer when they fail.

Only the standard library. The server is the one at MONCYCLE_TEST_URL (tests/run.sh starts it); it runs on a database
named test_*, with a dead SMTP port, and offers the fixtures of tests/support/router.php under /_test/.
"""
import json
import os
import random
import string
import unittest
import urllib.error
import urllib.request

BASE = os.environ.get("MONCYCLE_TEST_URL", "http://127.0.0.1:8190").rstrip("/")
PASSWORD = "Test-passw0rd-Longer"   # matches the default password policy


class Response:
    def __init__(self, status, headers, body):
        self.status = status
        self.headers = headers
        self.body = body

    @property
    def json(self):
        return json.loads(self.body) if self.body else None

    @property
    def data(self):
        """The `data` of a success answer."""
        return self.json["data"]

    @property
    def error(self):
        """The `error` of a failure answer: {"code", "message", "details"?}."""
        return self.json["error"]

    def __repr__(self):
        return "HTTP %d %s" % (self.status, self.body[:400].decode("utf-8", "replace"))


class _NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None


_opener = urllib.request.build_opener(_NoRedirect)


def random_ip():
    """A client address of its own: the server throttles sign-ins per address and trusts X-Forwarded-For from the test network."""
    return "203.%d.%d.%d" % (random.randint(1, 250), random.randint(1, 250), random.randint(1, 250))   # a public range: the private ones are the proxies


def request(method, path, body=None, token=None, headers=None, raw=None, ip=None):
    """One call. `path` is what follows /api/v2/ (or starts with / for anything else). `body` is sent as JSON; `raw` is sent as is."""
    url = BASE + (path if path.startswith("/") else "/api/v2/" + path)
    h = {"X-Forwarded-For": ip or random_ip()}
    data = raw
    if body is not None:
        data = json.dumps(body).encode()
        h["Content-Type"] = "application/json"
    if token:
        h["Authorization"] = "Bearer " + token
    h.update(headers or {})
    req = urllib.request.Request(url, data=data, method=method, headers=h)
    try:
        with _opener.open(req, timeout=30) as r:
            return Response(r.status, r.headers, r.read())
    except urllib.error.HTTPError as e:
        return Response(e.code, e.headers, e.read())


def fixture(name, **body):
    """A fixture of the test server (tests/support/router.php)."""
    r = request("POST", "/_test/" + name, body)
    assert r.status == 200, r
    return r.json


def sql(statement, *params):
    """Rows of a SELECT (a list of dicts), or the number of rows an INSERT/UPDATE/DELETE touched."""
    r = fixture("sql", sql=statement, params=list(params))
    return r.get("rows", r.get("rowCount"))


def unique(prefix="t"):
    return prefix + "".join(random.choices(string.ascii_lowercase + string.digits, k=10))


class Account:
    """A verified account with a read-write token. method: 1-4 (see `method` in the contract: 1 and 2 cervical mucus,
    3 FertilityCare, 4 FertilityCare + temperature)."""

    def __init__(self, method=2, password=PASSWORD, nickname="Test"):
        self.email = unique("t.") + "@example.invalid"
        self.password = password
        self.ip = random_ip()
        made = fixture("account", email=self.email, password=password, method=method, nickname=nickname)
        self.id, self.token = made["userId"], made["token"]

    def call(self, method, path, body=None, **kw):
        kw.setdefault("ip", self.ip)
        return request(method, path, body, token=self.token, **kw)

    def get(self, path, **kw):
        return self.call("GET", path, **kw)

    def post(self, path, body=None, **kw):
        if body is None and "raw" not in kw:
            body = {}
        return self.call("POST", path, body, **kw)

    def delete(self, path, **kw):
        return self.call("DELETE", path, **kw)

    def sign_in(self, **extra):
        """POST /token with this account's address and password."""
        return request("POST", "token", dict({"email": self.email, "password": self.password}, **extra), ip=self.ip)


class ApiTest(unittest.TestCase):
    maxDiff = None

    def assertStatus(self, r, status):
        self.assertEqual(r.status, status, repr(r))

    def assertError(self, r, status, code):
        """A failure in the envelope of the contract: {"error": {"code", "message"}}."""
        self.assertEqual((r.status, (r.json or {}).get("error", {}).get("code")), (status, code), repr(r))
        self.assertIn("message", r.error)

    def assertIssues(self, r, *fields):
        """A 400 validation_failed whose issues name exactly these fields."""
        self.assertError(r, 400, "validation_failed")
        self.assertEqual(sorted(i["field"] for i in r.error["details"]["issues"]), sorted(fields), repr(r))
