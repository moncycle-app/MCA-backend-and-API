# SPDX-License-Identifier: AGPL-3.0-or-later
"""GET /timeline_sync: what changed since the last sync. The server's clock decides, never the client's."""
from moncycle import *


class Sync(ApiTest):
    def test_the_first_sync_has_every_day_the_labels_and_the_cycles(self):
        a = Account()
        a.post("day/2026-09-20", {"cycleFirstDay": True, "freeMucusObservation": ["creamy"]})
        a.post("day/2026-09-25", {"stampColor": "Red"})
        d = a.get("timeline_sync").data
        self.assertEqual(sorted(d), ["allCyclesFirstDay", "allObservationPausedDates", "days", "descriptions", "syncTimestamp"])
        self.assertEqual([x["date"] for x in d["days"]], ["2026-09-20", "2026-09-25"])
        self.assertEqual(d["allCyclesFirstDay"], ["2026-09-20"])
        self.assertEqual([x["name"] for x in d["descriptions"]], ["creamy"])

    def test_since_selects_on_the_server_clock_with_an_overlap(self):
        a = Account()
        a.post("day/2026-09-20", {"stampColor": "Red"})
        self.assertEqual(a.get("timeline_sync?since=2099-01-01T00:00:00Z").data["days"], [])
        self.assertEqual(len(a.get("timeline_sync?since=2000-01-01T00:00:00Z").data["days"]), 1)
        # a day written a moment before `since` is sent again: SYNC_OVERLAP_SECONDS (120) covers a write that was still in flight
        stamp = a.get("timeline_sync").data["syncTimestamp"]
        self.assertEqual(len(a.get("timeline_sync?since=" + stamp).data["days"]), 1)

    def test_the_clock_of_the_client_does_not_decide_what_is_sent(self):
        a = Account()
        a.post("day/2026-09-20", {"stampColor": "Red", "lastWriteClientUtc": "2001-01-01T00:00:00Z"})
        self.assertEqual(len(a.get("timeline_sync?since=2026-01-01T00:00:00Z").data["days"]), 1)

    def test_the_labels_come_whole_in_every_sync_so_that_a_deletion_reaches_a_client(self):
        a = Account()
        a.post("description", {"name": "one", "type": "sensation"})
        stamp = "2000-01-01T00:00:00Z"
        did = a.get("description").data[0]["id"]
        a.delete("description/%d" % did)
        self.assertEqual(a.get("timeline_sync?since=" + stamp).data["descriptions"], [])

    def test_a_cleared_day_comes_back_empty(self):
        a = Account()
        a.post("day/2026-09-20", {"stampColor": "Red"})
        stamp = "2000-01-01T00:00:00Z"
        a.delete("day/2026-09-20")
        days = a.get("timeline_sync?since=" + stamp).data["days"]
        self.assertEqual([(d["date"], d["stampColor"]) for d in days], [("2026-09-20", None)])

    def test_renaming_a_label_makes_its_days_change(self):
        a = Account()
        a.post("day/2026-09-20", {"freeMucusObservation": ["creamy"]})
        sql("UPDATE day_timeline SET last_write_db = last_write_db - INTERVAL 1 DAY WHERE no_user_account = ?", a.id)   # the day is "old"
        stamp = a.get("timeline_sync").data["syncTimestamp"]
        self.assertEqual(a.get("timeline_sync?since=" + stamp).data["days"], [])
        a.post("description/%d" % a.get("description").data[0]["id"], {"name": "milky"})
        days = a.get("timeline_sync?since=" + stamp).data["days"]
        self.assertEqual([d["freeMucusObservation"] for d in days], [["milky"]])

    def test_observation_paused_dates_are_listed(self):
        a = Account()
        a.post("day/2026-09-20", {"cycleFirstDay": True})
        a.post("day/2026-09-27", {"observationPaused": True})
        self.assertEqual(a.get("timeline_sync").data["allObservationPausedDates"], ["2026-09-27"])

    def test_a_bad_since(self):
        self.assertIssues(Account().get("timeline_sync?since=yesterday"), "query.since")
