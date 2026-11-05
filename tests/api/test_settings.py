# SPDX-License-Identifier: AGPL-3.0-or-later
"""The optional settings and the keys of third parties (user_setting): GET/POST /account."""
from moncycle import *


def setting(a, name):
    return a.get("account").data["settings"][name]


def account_with_a_cycle(method=2):
    a = Account(method=method)
    a.post("day/2026-09-20", {"cycleFirstDay": True, "stampColor": "Red"})
    a.post("day/2026-09-21", {"stampColor": "Green"})
    return a


class OptionalSettings(ApiTest):
    def test_every_optional_setting_is_listed_with_its_default_even_when_not_set(self):
        s = Account().get("account").data["settings"]
        self.assertEqual(sorted(s), ["autoMailExport", "dateOrder", "dayBackgroundColor", "notificationEmails", "temperatureUnit", "timelineAscending"])
        self.assertEqual(s["dateOrder"], {"value": "DAY_FIRST", "isSet": False, "default": "DAY_FIRST"})
        self.assertEqual(s["timelineAscending"], {"value": True, "isSet": False, "default": True})
        self.assertEqual(s["autoMailExport"], {"value": False, "isSet": False, "default": False})
        self.assertEqual(s["autoMailExport"], {"value": False, "isSet": False, "default": False})
        self.assertEqual(s["dayBackgroundColor"], {"value": True, "isSet": False, "default": True})
        self.assertEqual(s["temperatureUnit"], {"value": "C", "isSet": False, "default": "C"})

    def test_a_setting_sent_is_written_and_only_that_one(self):
        a = Account()
        r = a.post("account", {"temperatureUnit": "F"})
        self.assertStatus(r, 200)
        self.assertEqual(r.json["meta"]["fieldsUpdated"], ["temperatureUnit"])
        self.assertEqual(setting(a, "temperatureUnit"), {"value": "F", "isSet": True, "default": "C"})
        self.assertEqual(setting(a, "timelineAscending")["isSet"], False)
        self.assertEqual(a.post("account", {"dayBackgroundColor": False}).data["settings"]["temperatureUnit"]["value"], "F")

    def test_a_setting_sent_as_it_already_is_changes_nothing(self):
        a = Account()
        a.post("account", {"autoMailExport": True})
        self.assertEqual(a.post("account", {"autoMailExport": True}).json["meta"]["fieldsUpdated"], [])

    def test_null_puts_a_setting_back_to_its_default(self):
        a = Account()
        a.post("account", {"timelineAscending": False})
        self.assertEqual(setting(a, "timelineAscending")["value"], False)
        r = a.post("account", {"timelineAscending": None})
        self.assertEqual(r.json["meta"]["fieldsUpdated"], ["timelineAscending"])
        self.assertEqual(setting(a, "timelineAscending"), {"value": True, "isSet": False, "default": True})

    def test_a_wrong_value_is_refused_with_every_problem(self):
        a = Account()
        self.assertIssues(a.post("account", {"timelineAscending": "yes", "temperatureUnit": "K", "dateOrder": 3, "dayBackgroundColor": 1}),
                          "timelineAscending", "temperatureUnit", "dateOrder", "dayBackgroundColor")
        self.assertEqual(setting(a, "temperatureUnit")["isSet"], False)

    def test_a_refused_request_writes_nothing(self):
        a = Account()
        self.assertStatus(a.post("account", {"temperatureUnit": "F", "dateOrder": "nope"}), 400)
        self.assertEqual(setting(a, "temperatureUnit")["isSet"], False)

    def test_date_format_is_no_setting_any_more(self):
        a = Account()
        self.assertIssues(a.post("account", {"dateFormat": "MDY_SLASH"}), "dateFormat")
        self.assertNotIn("dateFormat", a.get("account").data["settings"])

    def test_the_date_order_does_not_follow_the_language(self):
        a = Account()
        a.post("account", {"language": "EN"})
        self.assertEqual(setting(a, "dateOrder"), {"value": "DAY_FIRST", "isSet": False, "default": "DAY_FIRST"})
        a.post("account", {"dateOrder": "MONTH_FIRST"})
        a.post("account", {"language": "FR"})
        self.assertEqual(setting(a, "dateOrder"), {"value": "MONTH_FIRST", "isSet": True, "default": "DAY_FIRST"})
        a.post("account", {"dateOrder": None})
        self.assertEqual(setting(a, "dateOrder")["isSet"], False)

    def test_a_date_order_that_is_not_one_is_refused(self):
        a = Account()
        self.assertIssues(a.post("account", {"dateOrder": "DMY_SLASH"}), "dateOrder")
        self.assertIssues(a.post("account", {"dateOrder": 1}), "dateOrder")
        self.assertEqual(setting(a, "dateOrder")["isSet"], False)

    def test_sponsor_is_gone(self):
        self.assertIssues(Account().post("account", {"sponsor": True}), "sponsor")

    def test_the_old_flat_names_of_the_answer_are_gone(self):
        d = Account().get("account").data
        for name in ("sponsor", "timelineAscending", "autoMailExport", "notificationEmails"):
            self.assertNotIn(name, d)

    def test_the_settings_block_cannot_be_sent_back(self):
        self.assertIssues(Account().post("account", {"settings": {}}), "settings")


class CustomSettings(ApiTest):
    def test_a_key_is_kept_with_its_number_or_its_text(self):
        a = Account()
        r = a.post("account", {"customSettings": {"myapp.theme": "dark", "myapp.size": 3}})
        self.assertStatus(r, 200)
        self.assertEqual(sorted(r.json["meta"]["fieldsUpdated"]), ["customSettings.myapp.size", "customSettings.myapp.theme"])
        self.assertEqual(a.get("account").data["customSettings"], {"myapp.size": 3, "myapp.theme": "dark"})

    def test_only_the_keys_sent_change_and_null_deletes_a_key(self):
        a = Account()
        a.post("account", {"customSettings": {"a": "1", "b": 2}})
        a.post("account", {"customSettings": {"a": None, "c": "x"}})
        self.assertEqual(a.get("account").data["customSettings"], {"b": 2, "c": "x"})

    def test_there_is_no_key_by_default(self):
        self.assertEqual(Account().get("account").data["customSettings"], {})

    def test_a_key_cannot_reach_a_setting_of_the_app(self):
        a = Account()
        a.post("account", {"customSettings": {"totp_state": 3, "timeline_asc": 0}})
        d = a.get("account").data
        self.assertEqual((d["totpState"], d["settings"]["timelineAscending"]["isSet"]), ("unknown", False))

    def test_a_bad_key_or_value_is_refused(self):
        a = Account()
        self.assertIssues(a.post("account", {"customSettings": {"Bad Key": "x", "ok": True, "f": 1.5, "long": "x" * 256, "big": 2 ** 40}}),
                          "customSettings.Bad Key", "customSettings.ok", "customSettings.f", "customSettings.long", "customSettings.big")
        self.assertIssues(a.post("account", {"customSettings": ["a"]}), "customSettings")
        self.assertEqual(a.get("account").data["customSettings"], {})

    def test_the_number_of_keys_is_limited(self):
        a = Account()
        limit = request("GET", "server_info").data["limits"]["customSettingsMax"]
        self.assertStatus(a.post("account", {"customSettings": {"k%d" % i: i for i in range(limit)}}), 200)
        self.assertIssues(a.post("account", {"customSettings": {"one_more": 1}}), "customSettings")
        self.assertStatus(a.post("account", {"customSettings": {"k0": None, "one_more": 1}}), 200)

    def test_the_keys_are_each_account_own(self):
        a, b = Account(), Account()
        a.post("account", {"customSettings": {"k": "mine"}})
        self.assertEqual(b.get("account").data["customSettings"], {})


class Exports(ApiTest):
    def test_the_csv_has_iso_dates_and_follows_the_temperature_unit(self):
        a = Account(method=4)
        a.post("day/2026-09-20", {"temperature": 36.5, "cycleFirstDay": True})
        a.post("account", {"temperatureUnit": "F"})
        r = a.get("export/csv?from=2026-09-20&to=2026-09-20")
        self.assertStatus(r, 200)
        text = r.body.decode("utf-8")
        self.assertIn("2026-09-20", text)
        self.assertNotIn("09/20/26", text)
        self.assertIn("97,7", text)   # the decimal comma of the ";" separator

    def test_the_date_order_does_not_change_the_csv_nor_the_nfp_file(self):
        a = account_with_a_cycle()
        a.post("account", {"dateOrder": "MONTH_FIRST"})
        self.assertIn("2026-09-20", a.get("export/csv?from=2026-09-01&to=2026-09-30").body.decode("utf-8-sig"))
        self.assertEqual(a.get("export/nfp?from=2026-09-01&to=2026-09-30").json["cycles"][0]["cycleStartDate"], "2026-09-20")

    def test_the_pdf_is_made_in_both_date_orders_and_every_method(self):
        for method in (2, 4):
            a = account_with_a_cycle(method)
            for order in ("DAY_FIRST", "MONTH_FIRST"):
                a.post("account", {"dateOrder": order})
                r = a.get("export/pdf?from=2026-09-01&to=2026-09-30")
                self.assertStatus(r, 200)
                self.assertTrue(r.body.startswith(b"%PDF"))
