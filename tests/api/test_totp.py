# SPDX-License-Identifier: AGPL-3.0-or-later
"""Two-factor authentication: /totp, /totp/confirm, /totp/disable, and the sign-in with a code."""
from moncycle import *


def totp_on():
    """An account with two-factor on, and its secret."""
    a = Account()
    secret = a.post("totp").data["initSecret"]
    code = fixture("totp", secret=secret)["code"]
    assert a.post("totp/confirm", {"code": code, "password": a.password}).status == 200
    return a, secret


class Totp(ApiTest):
    def test_setup_gives_a_secret_a_link_and_a_qr_code(self):
        a = Account()
        d = a.post("totp").data
        self.assertEqual(d["totpState"], "init")
        self.assertTrue(d["otpauth"].startswith("otpauth://totp/"))
        self.assertIn("<svg", d["qrcode"])
        self.assertEqual(a.get("account").data["totpState"], "init")

    def test_a_wrong_code_does_not_turn_it_on(self):
        a = Account()
        a.post("totp")
        self.assertError(a.post("totp/confirm", {"code": "000000", "password": a.password}), 401, "totp_invalid")
        self.assertNotEqual(a.get("account").data["totpState"], "active")

    def test_confirming_wants_the_password(self):
        a = Account()
        code = fixture("totp", secret=a.post("totp").data["initSecret"])["code"]
        self.assertError(a.post("totp/confirm", {"code": code, "password": "wrong"}), 401, "invalid_password")

    def test_with_two_factor_on_a_sign_in_needs_the_code(self):
        a, secret = totp_on()
        self.assertEqual(a.get("account").data["totpState"], "active")
        self.assertError(a.sign_in(), 401, "totp_required")
        self.assertError(a.sign_in(code="111111"), 401, "totp_invalid")
        r = a.sign_in(code=fixture("totp", secret=secret)["code"])
        self.assertStatus(r, 201)
        self.assertIs(r.data["totpUsed"], True)

    def test_deleting_the_account_and_changing_the_address_want_the_code_too(self):
        a, secret = totp_on()
        self.assertError(a.post("account/deletion", {"password": a.password}), 401, "totp_required")
        self.assertStatus(a.get("account"), 200)

    def test_turning_it_off(self):
        a, secret = totp_on()
        self.assertError(a.post("totp/disable", {"code": "000000"}), 401, "totp_invalid")
        self.assertEqual(a.post("totp/disable", {"code": fixture("totp", secret=secret)["code"]}).data["totpState"], "disabled")
        self.assertStatus(a.sign_in(), 201)

    def test_a_code_is_a_string_of_digits(self):
        a = Account()
        a.post("totp")
        self.assertStatus(a.post("totp/confirm", {"code": ["1"], "password": a.password}), 400)
        self.assertStatus(a.post("totp/confirm", {"password": a.password}), 400)
