# SPDX-License-Identifier: AGPL-3.0-or-later
"""The endpoints anyone can call, and what every endpoint owes its caller (envelope, verbs, content type)."""
from moncycle import *


class Public(ApiTest):
    def test_server_info_is_the_first_call_of_a_client(self):
        r = request("GET", "server_info")
        self.assertStatus(r, 200)
        d = r.data
        self.assertEqual(d["app"], "moncycle.app")
        for key in ("appVersion", "maintenanceMode", "registrationEnabled", "demoEnabled", "captchaAvailable", "passwordPolicyRegex", "limits"):
            self.assertIn(key, d)
        self.assertIs(d["maintenanceMode"], False)
        self.assertIs(d["captchaAvailable"], True)
        self.assertEqual(d["limits"]["queryDaysMax"], 100)

    def test_health_check(self):
        r = request("GET", "health_check")
        self.assertStatus(r, 200)
        self.assertEqual(r.data["status"], "oookkk")   # the odd word is the contract's: a probe can tell this server from a proxy answering 200

    def test_pub_stat_has_three_rounded_counters(self):
        r = request("GET", "pub_stat")
        self.assertStatus(r, 200)
        self.assertEqual(sorted(r.data), ["accounts", "cycles", "observations"])

    def test_unknown_endpoint_is_404(self):
        self.assertStatus(request("GET", "nothing_here"), 404)

    def test_endpoints_behind_a_login_refuse_anonymous_calls(self):
        for method, path in [("GET", "account"), ("GET", "timeline_sync"), ("GET", "day/2026-03-10"), ("GET", "description"),
                             ("GET", "token"), ("GET", "export/csv?from=2026-01-01&to=2026-01-31")]:
            with self.subTest(path=path):
                self.assertError(request(method, path), 401, "unauthorized")

    def test_the_gdpr_download_is_refused_to_anonymous_calls(self):
        self.assertStatus(request("GET", "all_of_my_data_plz"), 401)

    def test_a_wrong_token_is_401(self):
        self.assertError(request("GET", "account", token="x" * 64), 401, "unauthorized")

    def test_a_verb_the_address_does_not_take_is_405_with_allow(self):
        r = request("DELETE", "server_info")
        self.assertError(r, 405, "method_not_allowed")
        self.assertIn("GET", r.headers["Allow"])

    def test_a_write_must_be_json(self):
        a = Account()
        r = a.post("account", raw=b"nickname=x", headers={"Content-Type": "application/x-www-form-urlencoded"})
        self.assertError(r, 415, "unsupported_media_type")
        r = a.post("account", raw=b"{", headers={"Content-Type": "application/json"})
        self.assertError(r, 400, "invalid_json")

    def test_a_browser_write_from_a_foreign_origin_is_refused(self):
        a = Account()
        r = a.post("account", {"nickname": "x"}, headers={"Origin": "https://evil.example"})
        self.assertError(r, 403, "cross_origin_refused")

    def test_swagger_page_and_contract_are_served(self):
        self.assertStatus(request("GET", "/api/moncycle_app_open_api.yaml"), 200)
