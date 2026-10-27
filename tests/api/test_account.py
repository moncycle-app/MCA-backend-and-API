# SPDX-License-Identifier: AGPL-3.0-or-later
"""GET/POST /account and POST /account/deletion: the settings, the password, the addresses."""
from moncycle import *


class Settings(ApiTest):
    def test_the_account_as_the_contract_describes_it(self):
        a = Account(method=3, nickname="MB")
        d = a.get("account").data
        self.assertEqual((d["userId"], d["loginEmail"], d["nickname"], d["method"]), (a.id, a.email, "MB", "fertilityCare"))
        self.assertEqual((d["temperatureTracking"], d["totpState"], d["autoMailExport"], d["oldestDay"]), (False, "unknown", True, None))

    def test_a_setting_changes_alone_and_the_answer_says_what_changed(self):
        a = Account()
        r = a.post("account", {"nickname": "New", "timelineAscending": False})
        self.assertStatus(r, 200)
        self.assertEqual(sorted(r.json["meta"]["fieldsUpdated"]), ["nickname", "timelineAscending"])
        d = a.get("account").data
        self.assertEqual((d["nickname"], d["timelineAscending"], d["method"]), ("New", False, "cervicalMucus"))

    def test_an_empty_body_is_valid_and_changes_nothing(self):
        r = Account().post("account", {})
        self.assertStatus(r, 200)
        self.assertEqual(r.json["meta"]["fieldsUpdated"], [])

    def test_the_method_and_the_temperature_flag_are_independent(self):
        a = Account(method=2)
        a.post("account", {"method": "fertilityCare"})
        d = a.get("account").data
        self.assertEqual((d["method"], d["temperatureTracking"]), ("fertilityCare", False))
        a.post("account", {"temperatureTracking": True})
        d = a.get("account").data
        self.assertEqual((d["method"], d["temperatureTracking"]), ("fertilityCare", True))

    def test_refusals_name_the_field(self):
        a = Account()
        self.assertIssues(a.post("account", {"foo": 1}), "foo")
        self.assertIssues(a.post("account", {"method": "zzz"}), "method")
        self.assertIssues(a.post("account", {"nickname": ""}), "nickname")
        self.assertIssues(a.post("account", {"nickname": ["x"]}), "nickname")
        self.assertIssues(a.post("account", {"birthYear": 1800}), "birthYear")
        self.assertIssues(a.post("account", {"userId": 1}), "userId")      # read-only fields are refused

    def test_etag_gives_a_304_until_the_account_changes(self):
        a = Account()
        etag = a.get("account").headers["ETag"]
        self.assertStatus(a.get("account", headers={"If-None-Match": etag}), 304)
        a.post("account", {"nickname": "Other"})
        self.assertStatus(a.get("account", headers={"If-None-Match": etag}), 200)

    def test_the_clock_of_the_client_is_kept_with_the_settings(self):
        a = Account()
        a.post("account", {"nickname": "x", "lastWriteClientUtc": "2026-10-01T10:00:00Z"})
        self.assertEqual(a.get("account").data["lastWriteClientUtc"], "2026-10-01T10:00:00Z")


class Password(ApiTest):
    def test_the_current_password_is_needed_and_must_be_right(self):
        a = Account()
        self.assertIssues(a.post("account", {"newPassword": "Another-passw0rd"}), "password")
        self.assertError(a.post("account", {"password": "wrong", "newPassword": "Another-passw0rd"}), 401, "invalid_password")

    def test_the_new_password_follows_the_policy(self):
        a = Account()
        r = a.post("account", {"password": a.password, "newPassword": "short"})
        self.assertIssues(r, "newPassword")
        self.assertEqual(r.error["details"]["issues"][0]["code"], "password_too_weak")

    def test_changing_it_and_signing_in_with_the_new_one(self):
        a = Account()
        r = a.post("account", {"password": a.password, "newPassword": "Another-passw0rd"})
        self.assertEqual(r.json["meta"]["fieldsUpdated"], ["password"])
        self.assertError(a.sign_in(), 401, "invalid_credentials")
        a.password = "Another-passw0rd"
        self.assertStatus(a.sign_in(), 201)

    def test_logging_out_the_other_devices(self):
        a = Account()
        other = a.sign_in().data["token"]
        r = a.post("account", {"password": a.password, "newPassword": "Another-passw0rd", "logoutOtherDevices": True})
        self.assertGreaterEqual(r.json["meta"]["loggedOutDevices"], 1)
        self.assertError(request("GET", "account", token=other), 401, "unauthorized")
        self.assertStatus(a.get("account"), 200)    # this session stays

    def test_the_other_devices_stay_when_not_asked(self):
        a = Account()
        other = a.sign_in().data["token"]
        a.post("account", {"password": a.password, "newPassword": "Another-passw0rd"})
        self.assertStatus(request("GET", "account", token=other), 200)


class Addresses(ApiTest):
    def test_notification_addresses_want_the_password_and_are_stored_lower_case(self):
        a = Account()
        self.assertIssues(a.post("account", {"notificationEmails": "x@example.org"}), "password")
        a.post("account", {"password": a.password, "notificationEmails": "Partner@Example.org;mum@example.org;partner@example.org"})
        self.assertEqual(a.get("account").data["notificationEmails"], "partner@example.org;mum@example.org")   # no duplicate
        a.post("account", {"password": a.password, "notificationEmails": ""})
        self.assertIn(a.get("account").data["notificationEmails"], (None, ""))

    def test_at_most_five_notification_addresses(self):
        a = Account()
        six = ";".join("p%d@example.org" % i for i in range(6))
        self.assertIssues(a.post("account", {"password": a.password, "notificationEmails": six}), "notificationEmails")

    def test_the_login_address_changes_only_when_the_link_to_the_new_one_is_used(self):
        a = Account()
        new = unique("new.") + "@example.invalid"
        r = a.post("account", {"password": a.password, "loginEmail": new})
        self.assertStatus(r, 200)
        self.assertIn("loginEmailLinkSent", r.json["meta"])
        self.assertEqual(a.get("account").data["loginEmail"], a.email)      # not yet
        token = fixture("email_token", userId=a.id, purpose=3, newEmail=new)["token"]
        self.assertStatus(request("POST", "verify_email", {"purpose": "changeEmail", "token": token}), 200)
        self.assertEqual(a.get("account").data["loginEmail"], new)
        a.email = new
        self.assertStatus(a.sign_in(), 201)


class Deletion(ApiTest):
    def test_the_account_goes_with_its_days_and_tokens(self):
        a = Account()
        a.post("day/2026-09-20", {"stampColor": "Red"})
        self.assertError(a.post("account/deletion", {"password": "wrong"}), 401, "invalid_password")
        self.assertStatus(a.get("account"), 200)
        self.assertStatus(a.post("account/deletion", {"password": a.password}), 204)
        self.assertError(a.get("account"), 401, "unauthorized")
        self.assertEqual(sql("SELECT COUNT(*) AS n FROM day_timeline WHERE no_user_account = ?", a.id)[0]["n"], 0)
        self.assertError(a.sign_in(), 401, "invalid_credentials")

    def test_the_password_is_required(self):
        self.assertIssues(Account().post("account/deletion", {}), "password")
