# SPDX-License-Identifier: AGPL-3.0-or-later
"""/description: the account's reusable free-text labels."""
from moncycle import *


class Descriptions(ApiTest):
    def test_create_list_rename_retype_delete(self):
        a = Account()
        r = a.post("description", {"name": "  Stretchy  ", "type": "sensation"})
        self.assertStatus(r, 201)
        did = r.data["id"]
        self.assertEqual((r.data["name"], r.data["type"]), ("stretchy", "sensation"))   # trimmed, lower case
        self.assertEqual(r.headers["Location"], "/api/v2/description/%d" % did)
        self.assertEqual(a.get("description").data, [{"id": did, "name": "stretchy", "type": "sensation", "useCount": 0}])

        r = a.post("description/%d" % did, {"name": "Very Stretchy"})   # partial: the type stays
        self.assertStatus(r, 200)
        self.assertEqual((r.data["name"], r.data["type"]), ("very stretchy", "sensation"))
        self.assertEqual(a.post("description/%d" % did, {"type": "observation"}).data["type"], "observation")

        self.assertStatus(a.delete("description/%d" % did), 204)
        self.assertEqual(a.get("description").data, [])

    def test_a_name_is_unique_whatever_its_case(self):
        a = Account()
        a.post("description", {"name": "sticky", "type": "sensation"})
        self.assertError(a.post("description", {"name": "STICKY", "type": "observation"}), 409, "duplicate_name")

    def test_renaming_onto_another_label_is_refused(self):
        a = Account()
        a.post("description", {"name": "one", "type": "sensation"})
        other = a.post("description", {"name": "two", "type": "sensation"}).data["id"]
        self.assertError(a.post("description/%d" % other, {"name": "one"}), 409, "duplicate_name")

    def test_a_label_used_by_a_day_counts_and_is_shared_by_days(self):
        a = Account()
        a.post("day/2026-09-20", {"freeMucusObservation": ["creamy"]})
        a.post("day/2026-09-21", {"freeMucusObservation": ["creamy"]})   # a free text becomes a label
        label = [d for d in a.get("description").data if d["name"] == "creamy"][0]
        self.assertEqual((label["type"], label["useCount"]), ("observation", 2))

    def test_renaming_a_label_renames_it_on_the_days(self):
        a = Account()
        a.post("day/2026-09-20", {"freeMucusObservation": ["creamy"]})
        did = a.get("description").data[0]["id"]
        a.post("description/%d" % did, {"name": "milky"})
        self.assertEqual(a.get("day/2026-09-20").data["freeMucusObservation"], ["milky"])

    def test_refusals(self):
        a = Account()
        self.assertIssues(a.post("description", {"name": "x", "type": "nope"}), "type")
        self.assertIssues(a.post("description", {"type": "sensation"}), "name")
        self.assertIssues(a.post("description", {"name": "x" * 500, "type": "sensation"}), "name")
        self.assertIssues(a.post("description", {"name": ["x"], "type": "sensation"}), "name")
        self.assertError(a.post("description/999999", {"name": "x"}), 404, "not_found")
        self.assertError(a.delete("description/999999"), 404, "not_found")

    def test_one_account_cannot_touch_the_labels_of_another(self):
        a, b = Account(), Account()
        did = a.post("description", {"name": "mine", "type": "sensation"}).data["id"]
        self.assertError(b.post("description/%d" % did, {"name": "yours"}), 404, "not_found")
        self.assertError(b.delete("description/%d" % did), 404, "not_found")
        self.assertEqual(b.get("description").data, [])
