# SPDX-License-Identifier: AGPL-3.0-or-later
"""/token: sign in, the sessions of an account (list, scope, revoke, log out), the captcha after failures, the demo accounts."""
from moncycle import *


class SignIn(ApiTest):
    def test_the_right_password_gives_a_token_that_works(self):
        a = Account()
        r = a.sign_in(name="backup script")
        self.assertStatus(r, 201)
        self.assertEqual(len(r.data["token"]), 256)
        self.assertEqual(r.data["token"][:5], r.data["prefix"])
        self.assertStatus(request("GET", "account", token=r.data["token"]), 200)

    def test_the_address_is_not_case_sensitive(self):
        a = Account()
        r = request("POST", "token", {"email": "  " + a.email.upper() + " ", "password": a.password}, ip=a.ip)
        self.assertStatus(r, 201)

    def test_a_wrong_password_and_an_unknown_address_look_the_same(self):
        a = Account()
        wrong = a.sign_in(password="wrong")
        nobody = request("POST", "token", {"email": unique("no.") + "@example.invalid", "password": "x"})
        self.assertError(wrong, 401, "invalid_credentials")
        self.assertEqual((wrong.status, wrong.error["code"], wrong.error["message"]), (nobody.status, nobody.error["code"], nobody.error["message"]))

    def test_a_cookie_only_when_asked(self):
        a = Account()
        self.assertIsNone(a.sign_in().headers.get("Set-Cookie"))
        cookie = a.sign_in(cookie=True).headers.get("Set-Cookie")
        self.assertIn("MONCYCLEAPP_TOKEN=", cookie)
        self.assertIn("HttpOnly", cookie)

    def test_the_cookie_signs_a_call_in(self):
        a = Account()
        cookie = a.sign_in(cookie=True).headers["Set-Cookie"].split(";")[0]
        self.assertStatus(request("GET", "account", headers={"Cookie": cookie}), 200)

    def test_refusals(self):
        self.assertIssues(request("POST", "token", {"email": "not an address", "password": "x"}), "email")
        self.assertIssues(request("POST", "token", {"email": "a@example.org", "password": ["x"]}), "password")
        self.assertIssues(request("POST", "token", {"email": "a@example.org", "password": "x", "scope": "root"}), "scope")
        self.assertIssues(request("POST", "token", {"email": "a@example.org", "password": "x", "expiresInDays": 100000}), "expiresInDays")


class Captcha(ApiTest):
    def test_after_three_failures_the_account_wants_a_captcha_even_for_the_right_password(self):
        a = Account()
        for _ in range(3):
            self.assertError(a.sign_in(password="wrong"), 401, "invalid_credentials")
        self.assertError(a.sign_in(), 403, "captcha_required")

    def test_a_solved_captcha_lets_the_right_password_in(self):
        a = Account()
        for _ in range(3):
            a.sign_in(password="wrong")
        c = fixture("captcha", purpose="login", email=a.email, ip=a.ip)
        self.assertStatus(a.sign_in(captcha=c["answer"], captchaToken=c["token"]), 201)

    def test_a_wrong_answer_is_refused(self):
        a = Account()
        for _ in range(3):
            a.sign_in(password="wrong")
        c = fixture("captcha", purpose="login", email=a.email, ip=a.ip)
        self.assertError(a.sign_in(captcha="nope", captchaToken=c["token"]), 403, "captcha_required")   # asked again

    def test_a_captcha_is_for_one_purpose_and_one_address(self):
        a = Account()
        for _ in range(3):
            a.sign_in(password="wrong")
        for purpose, email in (("register", a.email), ("login", "other@example.invalid")):
            c = fixture("captcha", purpose=purpose, email=email, ip=a.ip)
            self.assertEqual(a.sign_in(captcha=c["answer"], captchaToken=c["token"]).status, 403)

    def test_the_challenge_is_an_image_and_a_token(self):
        r = request("POST", "captcha", {"purpose": "login", "email": "a@example.invalid"})
        self.assertStatus(r, 200)
        self.assertTrue(r.data["image"].startswith("data:image/"))
        self.assertRegex(r.data["token"], r"^[0-9]+\.[0-9a-f]{64}$")

    def test_the_challenge_refuses_an_unknown_purpose(self):
        self.assertIssues(request("POST", "captcha", {"purpose": "other", "email": "a@example.invalid"}), "purpose")


class Sessions(ApiTest):
    def test_the_list_shows_every_token_without_the_token_itself(self):
        a = Account()
        a.sign_in(name="cli")
        tokens = a.get("token").data
        self.assertEqual(len(tokens), 2)
        for t in tokens:
            self.assertNotIn("token", t)
        self.assertEqual([t["current"] for t in tokens].count(True), 1)
        self.assertIn("cli", [t["name"] for t in tokens])

    def test_a_read_only_token_reads_and_cannot_write(self):
        a = Account()
        tok = a.sign_in(scope="readOnly").data["token"]
        self.assertStatus(request("GET", "account", token=tok), 200)
        self.assertError(request("POST", "account", {"nickname": "x"}, token=tok), 403, "read_only_token")
        self.assertError(request("POST", "day/2026-09-20", {}, token=tok), 403, "read_only_token")
        self.assertError(request("POST", "totp", {}, token=tok), 403, "read_only_token")

    def test_revoking_one_token(self):
        a = Account()
        other = a.sign_in().data
        self.assertEqual(a.delete("token/" + other["prefix"]).data, {"revoked": 1})
        self.assertError(request("GET", "account", token=other["token"]), 401, "unauthorized")
        self.assertError(a.delete("token/" + other["prefix"]), 404, "not_found")

    def test_revoking_every_other_token_keeps_this_one(self):
        a = Account()
        mine = a.sign_in().data["token"]
        a.sign_in()
        r = request("DELETE", "token?others=true", token=mine)
        self.assertEqual(r.data, {"revoked": 2})    # the account's first token and the one just made
        self.assertStatus(request("GET", "account", token=mine), 200)
        self.assertError(a.get("account"), 401, "unauthorized")

    def test_logging_out_is_204_and_ends_the_session_and_is_harmless_twice(self):
        a = Account()
        self.assertStatus(a.delete("token"), 204)
        self.assertError(a.get("account"), 401, "unauthorized")
        self.assertStatus(request("DELETE", "token"), 204)

    def test_a_token_does_not_reach_the_sessions_of_another_account(self):
        a, b = Account(), Account()
        self.assertError(b.delete("token/" + a.sign_in().data["prefix"]), 404, "not_found")


class Demo(ApiTest):
    def test_the_two_public_demo_accounts_sign_in_and_keep_their_password(self):
        for email in ("demo.bl@moncycle.app", "demo.fc@moncycle.app"):
            with self.subTest(email=email):
                r = request("POST", "token", {"email": email, "password": "demo"})
                self.assertStatus(r, 201)
                tok = r.data["token"]
                self.assertStatus(request("GET", "account", token=tok), 200)
                r = request("POST", "account", {"password": "demo", "newPassword": "Another-passw0rd"}, token=tok)
                self.assertError(r, 403, "demo_account")   # its password is public
