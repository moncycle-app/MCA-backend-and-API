# SPDX-License-Identifier: AGPL-3.0-or-later
"""The files: GET /export/{format} (nfp, csv, pdf), POST /import (an NFP file), GET /all_of_my_data_plz."""
import json

from moncycle import *

FROM_TO = "?from=2026-09-01&to=2026-09-30"


def account_with_a_cycle(method=2):
    a = Account(method=method)
    a.post("day/2026-09-20", {"cycleFirstDay": True, "stampColor": "Red", "freeMucusObservation": ["creamy"], "comment": "first day"})
    a.post("day/2026-09-21", {"stampColor": "Green", "temperature": 36.5, "temperatureTime": "07:00:00"} if method < 3 else {"stampColor": "Green", "codifiedMucusObservation": "CK"})
    return a


class Export(ApiTest):
    @classmethod
    def setUpClass(cls):
        cls.a = account_with_a_cycle()

    def test_csv(self):
        r = self.a.get("export/csv" + FROM_TO)
        self.assertStatus(r, 200)
        self.assertTrue(r.headers["Content-Type"].startswith("text/csv"))
        self.assertIn("attachment", r.headers["Content-Disposition"])
        text = r.body.decode("utf-8-sig")
        self.assertIn("2026-09-20", text)
        self.assertIn("first day", text)

    def test_pdf(self):
        r = self.a.get("export/pdf" + FROM_TO)
        self.assertStatus(r, 200)
        self.assertEqual(r.headers["Content-Type"], "application/pdf")
        self.assertTrue(r.body.startswith(b"%PDF"))

    def test_pdf_of_the_fertilitycare_chart(self):
        b = account_with_a_cycle(method=3)
        self.assertTrue(b.get("export/pdf" + FROM_TO).body.startswith(b"%PDF"))

    def test_nfp_has_the_cycles_and_the_days(self):
        f = self.a.get("export/nfp" + FROM_TO).json
        self.assertEqual(f["schemaVersion"], "1.0")
        self.assertEqual(f["fileInformation"]["sourceApp"], "moncycle.app")
        self.assertEqual(f["cycles"][0]["cycleStartDate"], "2026-09-20")
        self.assertEqual(f["cycles"][0]["days"][0]["stampColor"], "Red")

    def test_anonymous_leaves_the_identity_out(self):
        f = self.a.get("export/nfp%s&anonymous=true" % FROM_TO).json
        self.assertNotIn(self.a.email, json.dumps(f))

    def test_inline_nfp_is_shown_instead_of_downloaded(self):
        self.assertIn("attachment", self.a.get("export/nfp" + FROM_TO).headers["Content-Disposition"])
        self.assertNotIn("attachment", self.a.get("export/nfp%s&inline=true" % FROM_TO).headers.get("Content-Disposition", ""))

    def test_refusals(self):
        self.assertIssues(self.a.get("export/xls" + FROM_TO), "path.format")
        self.assertIssues(self.a.get("export/csv"), "query.from", "query.to")
        self.assertIssues(self.a.get("export/csv?from=2026-09-30&to=2026-09-01"), "query.from")
        self.assertIssues(self.a.get("export/csv?from=2026-02-30&to=2026-09-01"), "query.from")

    def test_the_raw_data_is_a_csv_of_everything_the_account_holds(self):
        r = self.a.get("all_of_my_data_plz")
        self.assertStatus(r, 200)
        self.assertIn("csv", r.headers["Content-Type"])
        self.assertIn(self.a.email, r.body.decode("utf-8-sig"))


class Import(ApiTest):
    def test_a_file_this_app_exports_imports_back_the_same_days(self):
        src = account_with_a_cycle()
        f = src.get("export/nfp" + FROM_TO).json
        dst = Account()
        r = dst.post("import", f)
        self.assertStatus(r, 200)
        self.assertEqual((r.data["dryRun"], r.data["daysCreated"]), (False, ["2026-09-20", "2026-09-21"]))
        for date in ("2026-09-20", "2026-09-21"):
            want, got = src.get("day/" + date).data, dst.get("day/" + date).data
            for key in ("stampColor", "comment", "temperature", "freeMucusObservation", "cycleFirstDay"):
                self.assertEqual(got[key], want[key], (date, key))

    def test_a_dry_run_writes_nothing(self):
        f = account_with_a_cycle().get("export/nfp" + FROM_TO).json
        dst = Account()
        r = dst.post("import?dryRun=true", f)
        self.assertEqual((r.status, r.data["dryRun"], len(r.data["daysCreated"])), (200, True, 2))
        self.assertIsNone(dst.get("day/2026-09-20").data["stampColor"])

    def test_days_the_account_has_are_kept_unless_override(self):
        f = account_with_a_cycle().get("export/nfp" + FROM_TO).json
        dst = Account()
        dst.post("day/2026-09-20", {"stampColor": "White", "comment": "mine"})
        r = dst.post("import", f)
        self.assertEqual((r.data["daysSkipped"], r.data["daysCreated"]), (["2026-09-20"], ["2026-09-21"]))
        self.assertEqual(dst.get("day/2026-09-20").data["comment"], "mine")
        r = dst.post("import?override=true", f)
        self.assertEqual(r.data["daysOverwritten"], ["2026-09-20", "2026-09-21"])
        self.assertEqual(dst.get("day/2026-09-20").data["comment"], "first day")

    def test_the_identity_and_the_method_of_the_account_are_not_changed_by_a_file(self):
        f = account_with_a_cycle().get("export/nfp" + FROM_TO).json
        f["userInformation"]["email"] = "someone.else@example.org"
        dst = Account(method=4)
        method = dst.get("account").data["method"]
        dst.post("import", f)
        d = dst.get("account").data
        self.assertEqual((d["loginEmail"], d["method"]), (dst.email, method))

    def test_a_file_that_is_not_an_nfp_file(self):
        a = Account()
        self.assertError(a.post("import", {"foo": 1}), 400, "missing_schema_version")
        self.assertStatus(a.post("import", raw=b"[]", headers={"Content-Type": "application/json"}), 400)

    def test_a_file_that_breaks_the_nfp_schema_is_refused_with_its_issues_and_nothing_is_written(self):
        f = account_with_a_cycle().get("export/nfp" + FROM_TO).json
        f["cycles"][0]["days"][0]["stampColor"] = "Pink"
        dst = Account()
        r = dst.post("import", f)
        self.assertError(r, 400, "invalid_nfp_structure")
        self.assertIn("cycles[0].days[0].stampColor", r.error["details"]["issues"][0])
        self.assertIsNone(dst.get("day/2026-09-21").data["stampColor"])

    def test_a_body_over_the_limit(self):
        r = Account().post("import", raw=b'{"x":"' + b"a" * (11 * 1024 * 1024) + b'"}', headers={"Content-Type": "application/json"})
        self.assertEqual(r.status, 413, repr(r)[:200])
