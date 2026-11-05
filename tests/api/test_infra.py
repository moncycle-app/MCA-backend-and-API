# SPDX-License-Identifier: AGPL-3.0-or-later
"""What sits under the endpoints: the width of the ids, the keys the reads count on, how SMTP_URL is read."""
from moncycle import *

ID_COLUMNS = {
    "user_account": ["no_user_account"],
    "user_setting": ["no_user_setting", "no_user_account"],
    "day_timeline": ["no_day", "no_user_account"],
    "auth_token": ["no_auth_token", "no_user_account"],
    "login_attempt_ip": ["no_login_attempt_ip"],
    "day_description": ["no_day_description", "no_user_account"],
    "link_day_timeline_day_description": ["no_day", "no_day_description"],
}


class Ids(ApiTest):
    def test_every_id_of_every_table_is_a_bigint_unsigned(self):
        for table, columns in ID_COLUMNS.items():
            for column in columns:
                found = sql("SELECT c.COLUMN_TYPE AS t FROM information_schema.COLUMNS AS c WHERE c.TABLE_SCHEMA = DATABASE() AND c.TABLE_NAME = ? AND c.COLUMN_NAME = ?", table, column)
                self.assertEqual(found, [{"t": "bigint(20) unsigned"}], "%s.%s" % (table, column))

    def test_no_id_column_was_forgotten(self):
        names = sql("SELECT c.TABLE_NAME AS t, c.COLUMN_NAME AS c FROM information_schema.COLUMNS AS c WHERE c.TABLE_SCHEMA = DATABASE() AND c.COLUMN_NAME LIKE 'no\\_%'")
        self.assertEqual(sorted((n["t"], n["c"]) for n in names), sorted((t, c) for t, cs in ID_COLUMNS.items() for c in cs))

    def test_an_id_too_big_for_the_old_columns_goes_through_the_api_whole(self):
        a = Account()
        big = 5000000000 + random.randrange(1000000000)   # over 2^32
        self.assertEqual(sql("INSERT INTO day_description (no_day_description, no_user_account, name, type) VALUES (?, ?, 'big', 0)", big, a.id), 1)
        self.assertEqual([d["id"] for d in a.get("description").data], [big])
        self.assertEqual(a.post("description/%d" % big, {"name": "bigger"}).data["id"], big)
        self.assertStatus(a.delete("description/%d" % big), 204)
        self.assertEqual(a.get("description").data, [])

    def test_an_id_at_the_limit_of_a_php_int_is_looked_for_and_one_past_it_refused(self):
        a = Account()
        self.assertError(a.delete("description/9223372036854775807"), 404, "not_found")
        self.assertError(a.delete("description/9223372036854775808"), 400, "validation_failed")


class Keys(ApiTest):
    def keys_of(self, table):
        rows = sql("SELECT s.INDEX_NAME AS n, s.COLUMN_NAME AS c FROM information_schema.STATISTICS AS s WHERE s.TABLE_SCHEMA = DATABASE() AND s.TABLE_NAME = ? ORDER BY s.INDEX_NAME, s.SEQ_IN_INDEX", table)
        keys = {}
        for row in rows:
            keys.setdefault(row["n"], []).append(row["c"])
        return keys

    def test_the_reads_of_a_sync_have_their_key(self):
        keys = self.keys_of("day_timeline").values()
        for key in (["no_user_account", "last_write_db", "date_obs"], ["no_user_account", "cycle_1st_day", "date_obs"], ["no_user_account", "observation_paused", "date_obs"]):
            self.assertIn(key, keys)

    def test_no_key_repeats_the_start_of_another(self):
        tables = sql("SELECT t.TABLE_NAME AS t FROM information_schema.TABLES AS t WHERE t.TABLE_SCHEMA = DATABASE()")
        for table in (t["t"] for t in tables):
            keys = list(self.keys_of(table).values())
            for key in keys:
                longer = [k for k in keys if len(k) > len(key) and k[:len(key)] == key]
                self.assertEqual(longer, [], "%s: %s" % (table, key))


class SmtpUrl(ApiTest):
    def settings(self, url):
        return fixture("smtp_settings", url=url)

    def test_smtps_is_implicit_tls_on_465_and_the_login_is_the_sender(self):
        s = self.settings("smtps://bonjour%40moncycle.app:pa%3Bss%40word@mail.example.org")
        self.assertEqual((s["host"], s["port"], s["implicit_tls"]), ("mail.example.org", 465, True))
        self.assertEqual((s["login"], s["password"], s["from"]), ("bonjour@moncycle.app", "pa;ss@word", "bonjour@moncycle.app"))

    def test_smtp_is_starttls_on_587(self):
        s = self.settings("smtp://login@mail.example.org")
        self.assertEqual((s["port"], s["implicit_tls"], s["login"]), (587, False, "login"))

    def test_a_port_and_a_sender_of_their_own(self):
        s = self.settings("smtps://login@mail.example.org:2465?from=hello%40example.org")
        self.assertEqual((s["port"], s["login"], s["from"]), (2465, "login", "hello@example.org"))

    def test_anything_else_is_refused_and_the_refusal_does_not_repeat_the_url(self):
        for url in ("", "http://login:s3cret@mail.example.org", "smtps://", "smtps://login:s3cret@mail@example.org"):
            refused = self.settings(url).get("refused", "")
            self.assertTrue(refused, url)
            self.assertNotIn("s3cret", refused)
