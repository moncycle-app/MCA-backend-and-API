# SPDX-License-Identifier: AGPL-3.0-or-later
"""Runs tests/api/test_*.py and prints one line per file (or per test with -v), and the detail of failures only.

  python3 tests/run_tests.py [-v] [filter ...]      (tests/run.sh starts the server first)
"""
import os
import sys
import time
import unittest
from concurrent.futures import ThreadPoolExecutor

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, os.path.join(HERE, "api"))

import moncycle   # noqa: E402  (after the path is set)

# Before anything: the server must be a test server (the fixtures of tests/support/router.php) on a test_* database.
# The dev app (port 8003) is neither, and the tests must never run against it.
try:
    probe = moncycle.request("POST", "/_test/sql", {"sql": "SELECT DATABASE() AS d", "params": []})
    database = (probe.json or {}).get("rows", [{}])[0].get("d", "") if probe.status == 200 else None
except Exception as e:
    sys.exit("No test server at %s (%s).\nRun plain `tests/run.sh`: it starts one." % (moncycle.BASE, e))
if not database or not database.startswith("test_"):
    sys.exit("%s is not a test server (no /_test/ fixtures, or not a test_* database: %s).\n"
             "Run plain `tests/run.sh` (no MONCYCLE_TEST_URL): it starts one. For your own server see tests/README.md." % (moncycle.BASE, database))

verbose = "-v" in sys.argv
filters = [a for a in sys.argv[1:] if not a.startswith("-")]
files = sorted(f[:-3] for f in os.listdir(os.path.join(HERE, "api")) if f.startswith("test_") and f.endswith(".py"))
files = [f for f in files if not filters or any(x in f for x in filters)]
if not files:
    sys.exit("no test file matches %s" % filters)


class Result(unittest.TestResult):
    def __init__(self):
        super().__init__()
        self.lines = []

    def addSuccess(self, test):
        super().addSuccess(test)
        self.lines.append("      ok   " + test.id().split(".", 1)[1])

    def addFailure(self, test, err):
        super().addFailure(test, err)
        self.lines.append("      FAIL " + test.id().split(".", 1)[1])

    def addError(self, test, err):
        super().addError(test, err)
        self.lines.append("      ERR  " + test.id().split(".", 1)[1])


def run_file(name):
    """One test file, alone: the files share nothing (each test makes its own accounts), so they run side by side."""
    result = Result()
    t0 = time.time()
    unittest.defaultTestLoader.loadTestsFromName(name).run(result)
    return name, result, time.time() - t0


total = failed = 0
started = time.time()
details = []
with ThreadPoolExecutor(len(files)) as pool:
    for name, result, seconds in pool.map(run_file, files):
        n, bad = result.testsRun, len(result.failures) + len(result.errors)
        total, failed = total + n, failed + bad
        print("%-4s %-28s %3d tests  %.1fs" % ("FAIL" if bad else "ok", name, n, seconds))
        if verbose:
            print("\n".join(result.lines))
        for test, trace in result.failures + result.errors:
            # the last lines of the traceback hold the assertion and the answer; the frames above are noise
            details.append("--- %s\n%s" % (test.id(), "\n".join(trace.strip().splitlines()[-6:])))

if details:
    print("\n" + "\n".join(details))
print("\n%s: %d tests, %d failed, %.1fs" % ("FAILED" if failed else "ALL GREEN", total, failed, time.time() - started))
sys.exit(1 if failed else 0)
