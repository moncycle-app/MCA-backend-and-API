# SPDX-License-Identifier: AGPL-3.0-or-later
"""Accounts made and recovered by mail: POST /register, /recover_password, /verify_email.

No mail leaves the test server (its SMTP port is dead), so a test takes the link's token from a fixture, which is what the
mail would have held.
"""
from concurrent.futures import ThreadPoolExecutor

from moncycle import *


def register_body(email, ip, **extra):
    c = fixture("captcha", purpose="register", email=email, ip=ip)
    body = {"email": email, "password": PASSWORD, "nickname": "R", "birthYear": 1990, "termsAccepted": True,
            "captcha": c["answer"], "captchaToken": c["token"]}
    body.update(extra)
    return body


def account_row(email):
    return sql("SELECT no_user_account AS id, email_verified_at AS verified, terms_accepted_at AS terms FROM user_account WHERE login_email = ?", email)


class Register(ApiTest):
    def test_a_new_address_gets_an_account_that_cannot_sign_in_until_the_link_is_used(self):
        email, ip = unique("reg.") + "@example.invalid", random_ip()
        self.assertStatus(request("POST", "register", register_body(email.upper(), ip), ip=ip), 202)   # stored in lower case
        row = account_row(email)[0]
        self.assertIsNone(row["verified"])
        self.assertIsNotNone(row["terms"])    # the moment the terms were accepted
        self.assertError(request("POST", "token", {"email": email, "password": PASSWORD}, ip=ip), 403, "email_not_verified")

        token = fixture("email_token", userId=int(row["id"]), purpose=1)["token"]
        r = request("POST", "verify_email", {"purpose": "accountCreation", "token": token})
        self.assertEqual((r.status, r.data), (200, {"purpose": "accountCreation", "loginEmail": email}))
        self.assertError(request("POST", "verify_email", {"purpose": "accountCreation", "token": token}), 400, "invalid_token")   # works once
        self.assertStatus(request("POST", "token", {"email": email, "password": PASSWORD}, ip=ip), 201)

    def test_an_address_that_has_an_account_gets_the_same_answer_and_is_not_touched(self):
        a = Account()
        before = sql("SELECT password FROM user_account WHERE login_email = ?", a.email)
        r = request("POST", "register", register_body(a.email, a.ip, password="Other-passw0rd-1"), ip=a.ip)
        self.assertEqual(r.status, 202, repr(r))
        self.assertEqual(sql("SELECT password FROM user_account WHERE login_email = ?", a.email), before)
        self.assertStatus(a.sign_in(), 201)

    def test_the_terms_must_be_accepted(self):
        email, ip = unique("reg.") + "@example.invalid", random_ip()
        self.assertIssues(request("POST", "register", register_body(email, ip, termsAccepted=False), ip=ip), "termsAccepted")
        self.assertEqual(account_row(email), [])

    def test_the_password_follows_the_policy(self):
        email, ip = unique("reg.") + "@example.invalid", random_ip()
        self.assertIssues(request("POST", "register", register_body(email, ip, password="short"), ip=ip), "password")

    def test_without_a_captcha_nothing_is_created(self):
        email, ip = unique("reg.") + "@example.invalid", random_ip()
        body = register_body(email, ip)
        del body["captcha"], body["captchaToken"]
        self.assertIn(request("POST", "register", body, ip=ip).status, (400, 403))
        self.assertEqual(account_row(email), [])

    def test_a_captcha_from_another_network_is_refused(self):
        email, ip = unique("reg.") + "@example.invalid", random_ip()
        body = register_body(email, "198.51.100.7")
        self.assertError(request("POST", "register", body, ip=ip), 403, "captcha_invalid")


def recover(email, ip):
    c = fixture("captcha", purpose="recover", email=email, ip=ip)
    return request("POST", "recover_password", {"email": email, "captcha": c["answer"], "captchaToken": c["token"]}, ip=ip)


class RecoverPassword(ApiTest):
    @classmethod
    def setUpClass(cls):
        # recover_password waits 1 to 5 seconds on purpose (so that its time tells nothing): the three calls run together
        cls.a, cls.ip = Account(), random_ip()
        cls.unknown_email = unique("no.") + "@example.invalid"
        with ThreadPoolExecutor(3) as pool:
            known, unknown = [pool.submit(recover, e, cls.ip) for e in (cls.a.email, cls.unknown_email)]
            cls.known, cls.unknown = known.result(), unknown.result()

    def test_the_answer_does_not_tell_whether_the_account_exists(self):
        self.assertEqual((self.known.status, self.known.json), (self.unknown.status, self.unknown.json))
        self.assertEqual(self.known.status, 200)

    def test_asking_for_a_link_does_not_change_the_password(self):
        self.assertStatus(self.a.sign_in(), 201)

    def test_the_link_chooses_a_new_password_and_the_old_one_stops_working(self):
        a = Account()
        token = fixture("email_token", userId=a.id, purpose=2)["token"]
        weak = request("POST", "verify_email", {"purpose": "resetPassword", "token": token, "newPassword": "weak"})
        self.assertIssues(weak, "newPassword")
        self.assertStatus(request("POST", "verify_email", {"purpose": "resetPassword", "token": token, "newPassword": "Brand-new-passw0rd"}), 200)
        self.assertError(a.sign_in(), 401, "invalid_credentials")
        a.password = "Brand-new-passw0rd"
        self.assertStatus(a.sign_in(), 201)

    def test_a_link_is_for_one_purpose(self):
        a = Account()
        token = fixture("email_token", userId=a.id, purpose=2)["token"]
        self.assertError(request("POST", "verify_email", {"purpose": "accountCreation", "token": token}), 400, "invalid_token")

    def test_a_new_link_replaces_the_old_one(self):
        a = Account()
        old = fixture("email_token", userId=a.id, purpose=2)["token"]
        fixture("email_token", userId=a.id, purpose=2)
        self.assertError(request("POST", "verify_email", {"purpose": "resetPassword", "token": old, "newPassword": "Brand-new-passw0rd"}), 400, "invalid_token")

    def test_an_expired_link(self):
        a = Account()
        token = fixture("email_token", userId=a.id, purpose=2)["token"]
        sql("UPDATE user_account SET email_token_expires_at = NOW() - INTERVAL 1 MINUTE WHERE no_user_account = ?", a.id)
        r = request("POST", "verify_email", {"purpose": "resetPassword", "token": token, "newPassword": "Brand-new-passw0rd"})
        self.assertEqual((r.status, r.error["code"]), (400, "token_expired"), repr(r))


class VerifyEmailInput(ApiTest):
    def test_refusals(self):
        self.assertError(request("POST", "verify_email", {"purpose": "accountCreation", "token": "x"}), 400, "invalid_token")
        self.assertIssues(request("POST", "verify_email", {"purpose": "nope", "token": "x"}), "purpose")
        self.assertIssues(request("POST", "verify_email", {"purpose": "accountCreation"}), "token")
        self.assertIssues(request("POST", "verify_email", {"purpose": "accountCreation", "token": ["x"]}), "token")
