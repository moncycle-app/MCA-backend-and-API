# SPDX-License-Identifier: AGPL-3.0-or-later
"""The OpenAPI file is THE contract between the clients and this server (www_data/api/moncycle_app_open_api.yaml).
These tests read it as text (no YAML library needed) and hold the server to it: every route it documents exists with
the verbs it documents, and every endpoint of the server is documented."""
import os
import re

from moncycle import *

ROOT = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "..", "www_data")
SPEC = open(os.path.join(ROOT, "api", "moncycle_app_open_api.yaml"), encoding="utf-8").read()


def documented_operations():
    """[(verb, path, operationId)] from the `paths:` section."""
    paths = SPEC[SPEC.index("\npaths:\n"):SPEC.index("\ncomponents:\n")]
    found, path, verb = [], None, None
    for line in paths.splitlines():
        if m := re.match(r"^  (/\S*):\s*$", line):
            path = m.group(1)
        elif m := re.match(r"^    (get|post|delete|put|patch):\s*$", line):
            verb = m.group(1)
        elif m := re.match(r"^      operationId:\s*(\S+)", line):
            found.append((verb.upper(), path, m.group(1)))
    return found


class Contract(ApiTest):
    def test_the_file_declares_the_operations_we_expect_to_find(self):
        self.assertGreaterEqual(len(documented_operations()), 29)

    def test_every_operation_has_its_own_operation_id(self):
        ids = [o[2] for o in documented_operations()]
        self.assertEqual(len(ids), len(set(ids)))

    def test_every_documented_route_exists_and_takes_its_verb(self):
        for verb, path, op in documented_operations():
            url = re.sub(r"\{(\w+)\}", lambda m: {"date": "2026-01-01", "id": "999999", "prefix": "zzzzz", "format": "csv"}[m.group(1)], path)
            with self.subTest(op=op):
                r = request(verb, url.lstrip("/"))
                self.assertNotIn(r.status, (405, 500), "%s %s -> %r" % (verb, path, r))
                if "{" not in path:
                    self.assertNotEqual(r.status, 404, "%s %s" % (verb, path))

    def test_every_endpoint_of_the_server_is_documented(self):
        documented = {p.split("/")[1] for _, p, _ in documented_operations()}
        files = {f[:-4] for f in os.listdir(os.path.join(ROOT, "api", "v2")) if f.endswith(".php") and f != "test.php"}
        self.assertEqual(files - documented, set(), "endpoint files with no entry in the contract")
        self.assertEqual(documented - files, set(), "documented routes with no endpoint file")

    def test_a_verb_the_contract_does_not_list_is_refused(self):
        listed = {}
        for verb, path, _ in documented_operations():
            listed.setdefault(path, set()).add(verb)
        for path in ("/account", "/token", "/day/{date}", "/description"):
            for verb in {"GET", "POST", "DELETE"} - listed[path]:
                with self.subTest(path=path, verb=verb):
                    url = path.replace("{date}", "2026-01-01")
                    r = Account().call(verb, url.lstrip("/"), {} if verb == "POST" else None)
                    self.assertEqual(r.status, 405, repr(r))

    def test_the_error_envelope_is_the_one_of_the_contract(self):
        r = request("GET", "account")
        self.assertEqual(sorted(r.json), ["error"])
        self.assertTrue({"code", "message"} <= set(r.error))

    def test_dates_are_rfc_3339_with_a_zone(self):
        a = Account()
        a.post("day/2026-09-20", {})
        for stamp in (a.get("timeline_sync").data["syncTimestamp"], a.get("day/2026-09-20").data["lastWriteDb"], a.get("account").data["inscriptionDate"]):
            self.assertRegex(stamp, r"^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(Z|[+-]\d\d:\d\d)$")
