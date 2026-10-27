# SPDX-License-Identifier: AGPL-3.0-or-later
"""GET/POST/DELETE /day: writing a day replaces it whole, reading it back, what is refused, which write wins."""
from moncycle import *

D = "2026-09-20"


class WriteAndRead(ApiTest):
    @classmethod
    def setUpClass(cls):
        cls.a = Account(method=2)

    def test_a_new_day_is_201_with_its_address_then_a_replacement_is_200(self):
        a = Account()
        r = a.post("day/" + D, {"stampColor": "Red", "comment": "cramps"})
        self.assertStatus(r, 201)
        self.assertEqual(r.headers["Location"], "/api/v2/day/" + D)
        self.assertEqual((r.data["date"], r.data["stampColor"], r.data["comment"]), (D, "Red", "cramps"))
        self.assertStatus(a.post("day/" + D, {"stampColor": "Green"}), 200)

    def test_a_write_replaces_the_whole_day(self):
        a = Account()
        a.post("day/" + D, {"stampColor": "Red", "comment": "x", "sexUnion": True, "temperature": 36.5, "freeMucusObservation": ["creamy"]})
        a.post("day/" + D, {"stampColor": "Green"})
        d = a.get("day/" + D).data
        self.assertEqual(d["stampColor"], "Green")
        self.assertEqual((d["comment"], d["sexUnion"], d["temperature"], d["freeMucusObservation"]), ("", False, None, []))

    def test_a_day_comes_back_as_it_was_written(self):
        a = Account()
        sent = {"stampColor": "White", "stampBaby": True, "sexUnion": True, "counterStart": 3, "temperature": 36.55, "temperatureTime": "07:30:00",
                "freeMucusSensation": ["wet"], "freeMucusObservation": ["clear", "stretchy"], "comment": "ok"}
        a.post("day/" + D, sent)
        got = a.get("day/" + D).data
        for key, value in sent.items():
            self.assertEqual(got[key], value, key)

    def test_a_day_nothing_is_stored_for_is_empty_not_404(self):
        d = Account().get("day/2026-01-01").data
        self.assertEqual((d["date"], d["stampColor"], d["comment"], d["lastWriteDb"]), ("2026-01-01", None, "", None))

    def test_the_first_day_of_a_cycle_gives_the_cycle_day_of_the_following_days(self):
        a = Account()
        a.post("day/2026-09-20", {"cycleFirstDay": True})
        a.post("day/2026-09-23", {"stampColor": "Green"})
        d = a.get("day/2026-09-23").data
        self.assertEqual((d["cycleStartDate"], d["cycleDay"]), ("2026-09-20", 4))

    def test_the_fields_the_server_computes_are_accepted_and_dropped(self):
        a = Account()
        r = a.post("day/" + D, {"date": D, "cycleDay": 99, "cycleStartDate": "2000-01-01", "lastWriteDb": "2000-01-01T00:00:00Z", "stampColor": "Red"})
        self.assertStatus(r, 201)
        self.assertNotEqual(r.data["cycleDay"], 99)

    def test_fertilitycare_codes(self):
        b = Account(method=3)
        b.post("day/" + D, {"stampColor": "Green", "codifiedMucusObservation": "CK", "codifiedArrow": "Up"})
        d = b.get("day/" + D).data
        self.assertEqual((d["codifiedMucusObservation"], d["codifiedArrow"]), ("CK", "Up"))

    def test_clearing_a_day(self):
        a = Account()
        a.post("day/" + D, {"stampColor": "Red", "comment": "x"})
        self.assertStatus(a.delete("day/" + D), 204)
        d = a.get("day/" + D).data
        self.assertEqual((d["stampColor"], d["comment"]), (None, ""))

    def test_accounts_do_not_see_each_others_days(self):
        a, b = Account(), Account()
        a.post("day/" + D, {"stampColor": "Red"})
        self.assertIsNone(b.get("day/" + D).data["stampColor"])


class Reading(ApiTest):
    @classmethod
    def setUpClass(cls):
        cls.a = Account()
        for day, color in [("2026-09-20", "Red"), ("2026-09-21", "Green"), ("2026-09-25", "White")]:
            cls.a.post("day/" + day, {"stampColor": color})

    def test_a_range_lists_the_days_that_exist_oldest_first(self):
        days = self.a.get("day?from=2026-09-20&to=2026-09-30").data
        self.assertEqual([d["date"] for d in days], ["2026-09-20", "2026-09-21", "2026-09-25"])

    def test_a_list_of_dates_answers_every_date_asked(self):
        days = self.a.get("day?dates=2026-09-20,2026-09-22").data
        self.assertEqual([(d["date"], d["stampColor"]) for d in days], [("2026-09-20", "Red"), ("2026-09-22", None)])

    def test_at_most_100_days_at_a_time(self):
        self.assertStatus(self.a.get("day?from=2026-01-01&to=2026-04-10"), 200)    # 100 days
        self.assertIssues(self.a.get("day?from=2026-01-01&to=2026-04-11"), "query.to")   # 101

    def test_dates_and_a_range_do_not_mix(self):
        self.assertIssues(self.a.get("day?dates=2026-09-20&from=2026-09-20&to=2026-09-21"), "query.dates")


class Refusals(ApiTest):
    @classmethod
    def setUpClass(cls):
        cls.a = Account()

    def test_every_problem_is_listed_not_just_the_first(self):
        r = self.a.post("day/" + D, {"stampColor": "Pink", "temperature": "hot", "comment": "x" * 5000, "stampColour": "Red"})
        self.assertIssues(r, "stampColor", "temperature", "comment", "stampColour")
        codes = {i["field"]: i["code"] for i in r.error["details"]["issues"]}
        self.assertEqual(codes, {"stampColor": "invalid_value", "temperature": "wrong_type", "comment": "too_long", "stampColour": "unknown_field"})

    def test_a_number_out_of_range(self):
        self.assertIssues(self.a.post("day/" + D, {"temperature": 100}), "temperature")
        self.assertIssues(self.a.post("day/" + D, {"counterStart": 300}), "counterStart")

    def test_a_list_an_object_or_a_number_where_a_string_is_expected_is_a_400_never_a_500(self):
        for field, value in [("stampColor", ["Red"]), ("stampColor", {"a": 1}), ("stampColor", 5), ("comment", ["x"]), ("freeMucusObservation", "creamy"),
                             ("codifiedArrow", ["Up"]), ("temperatureTime", 12), ("sexUnion", "yes")]:
            with self.subTest(field=field, value=value):
                self.assertStatus(self.a.post("day/" + D, {field: value}), 400)

    def test_a_date_that_is_not_in_the_calendar(self):
        for date in ("2026-02-30", "hello", "2026-13-01", "26-09-20"):
            with self.subTest(date=date):
                self.assertIssues(self.a.get("day/" + date), "path.date")
                self.assertIssues(self.a.post("day/" + date, {}), "path.date")

    def test_the_date_of_the_body_must_be_the_one_of_the_address(self):
        self.assertIssues(self.a.post("day/2026-09-23", {"date": "2026-09-22"}), "date")

    def test_there_is_no_put(self):
        self.assertError(self.a.call("PUT", "day/" + D, {}), 405, "method_not_allowed")

    def test_a_delete_takes_no_body(self):
        self.assertStatus(self.a.call("DELETE", "day/" + D, {"x": 1}), 400)


class LateWrites(ApiTest):
    """The write made last wins, by the client's own clock (lastWriteClientUtc)."""

    def test_an_older_write_is_refused_with_409(self):
        a = Account()
        a.post("day/" + D, {"stampColor": "Red", "lastWriteClientUtc": "2026-10-01T10:00:00Z"})
        r = a.post("day/" + D, {"stampColor": "Green", "lastWriteClientUtc": "2026-10-01T09:59:59Z"})
        self.assertError(r, 409, "stale_write")
        self.assertEqual(a.get("day/" + D).data["stampColor"], "Red")

    def test_the_same_moment_goes_through_so_a_write_sent_twice_is_harmless(self):
        a = Account()
        body = {"stampColor": "Red", "lastWriteClientUtc": "2026-10-01T10:00:00Z"}
        self.assertStatus(a.post("day/" + D, body), 201)
        self.assertStatus(a.post("day/" + D, body), 200)

    def test_a_newer_write_wins(self):
        a = Account()
        a.post("day/" + D, {"stampColor": "Red", "lastWriteClientUtc": "2026-10-01T10:00:00Z"})
        self.assertStatus(a.post("day/" + D, {"stampColor": "Green", "lastWriteClientUtc": "2026-10-01T10:00:01Z"}), 200)
        self.assertEqual(a.get("day/" + D).data["stampColor"], "Green")

    def test_a_clear_is_a_write_too(self):
        a = Account()
        a.post("day/" + D, {"stampColor": "Red", "lastWriteClientUtc": "2026-10-01T10:00:00Z"})
        self.assertError(a.delete("day/%s?lastWriteClientUtc=2026-10-01T09:00:00Z" % D), 409, "stale_write")
        self.assertStatus(a.delete("day/%s?lastWriteClientUtc=2026-10-01T11:00:00Z" % D), 204)

    def test_a_clock_far_in_the_future_does_not_shut_the_other_devices_out(self):
        a = Account()
        a.post("day/" + D, {"stampColor": "Red", "lastWriteClientUtc": "2099-01-01T00:00:00Z"})
        self.assertStatus(a.post("day/" + D, {"stampColor": "Green", "lastWriteClientUtc": "2026-10-27T12:00:00Z"}), 200)
