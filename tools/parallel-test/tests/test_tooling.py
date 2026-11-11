"""Self-tests of the INFRASTRUCTURE TOOLING using synthetic data only (tmp dir). They never touch the PrimeClassy
repo, a database, PHPUnit or Vitest. Run: python3 -B -m unittest discover -s tests -v"""
import json, sys, tempfile, unittest
import xml.etree.ElementTree as ET
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))
sys.dont_write_bytecode = True
from pctest.aggregate import aggregate
from pctest.discover import php_actual_from_junit_case, php_expected_from_list_xml, reconcile
from pctest.guards import check_worker_env
from pctest.provision import cleanup_sql
from pctest.manifest import assign
from pctest.plan import PathMap, render_phpunit_config


def list_xml(ids):
    ns = "https://xml.phpunit.de/testSuite"
    r = ET.Element("testSuite", {"xmlns": ns}); t = ET.SubElement(r, "tests"); c = ET.SubElement(t, "testClass", {"name": "C", "file": "/x"})
    for i in ids:
        ET.SubElement(c, "testMethod", {"id": i, "name": i.split("::")[-1]})
    return ET.tostring(r, encoding="unicode")


def junit(cases):
    r = ET.Element("testsuites"); s = ET.SubElement(r, "testsuite", {"name": "x"})
    for f, n, status in cases:
        t = ET.SubElement(s, "testcase", {"name": n, "file": f, "class": f.replace("\\","/").rsplit("/",1)[-1], "classname": f.replace("\\","/").rsplit("/",1)[-1], "assertions": "2", "time": "0.1"})
        if status != "pass":
            ET.SubElement(t, status, {"message": "m"})
    return ET.tostring(r, encoding="unicode")


class Agg(unittest.TestCase):
    def setUp(self):
        self.d = Path(tempfile.mkdtemp()); (self.d / "status").mkdir(); (self.d / "junit").mkdir()
        (self.d / "STOPPED.json").write_text('{"run_id":"r","verified_stopped":true}')
        e = lambda f: {"source_file": f, "class": None, "static_test_methods": 1, "has_datasets": False, "count_confidence": "lower_bound"}
        self.m = {"digest": "d", "entries": [e("backend/tests/A.php"), e("backend/tests/B.php")],
                  "counts": {"php_static_test_methods_lower_bound": 2, "frontend_static_cases_lower_bound": 0}}
        self.plan = {"run_id": "r", "repo": "/repo", "manifest_digest": "d", "assignment_problems": [], "filters_applied": [],
                     "jobs": [{"id": "j1", "kind": "phpunit", "phase": "P", "worker": 1, "lane": "parallel", "junit": str(self.d / "junit/1.xml"),
                               "discovery": str(self.d / "disc.xml"), "expected_files": ["backend/tests/A.php", "backend/tests/B.php"]}]}
        (self.d / "disc.xml").write_text(list_xml(["A.php::t", "B.php::t"]))

    def status(self, rc=0):
        (self.d / "status/j1.json").write_text(json.dumps({"job_id": "j1", "aborted": False, "timed_out": False, "exit_code": rc, "duration_seconds": 1, "started_at": "a", "ended_at": "b"}))

    def run_agg(self):
        return aggregate(self.d, self.m, self.plan)["verdict"]

    def test_pass(self):
        self.status(); (self.d / "junit/1.xml").write_text(junit([("/repo/backend/tests/A.php", "t", "pass"), ("/repo/backend/tests/B.php", "t", "pass")]))
        self.assertEqual(self.run_agg(), "PASS")

    def test_windows_recorded_paths_resolve(self):
        self.status(); (self.d / "junit/1.xml").write_text(junit([("C:\\x\\backend\\tests\\A.php", "t", "pass"), ("C:\\x\\backend\\tests\\B.php", "t", "pass")]))
        self.assertEqual(self.run_agg(), "PASS")

    def test_missing_dataset_case_is_incomplete(self):
        (self.d / "disc.xml").write_text(list_xml(["A.php::t", "B.php::t", "B.php::t#1"]))
        self.status(); (self.d / "junit/1.xml").write_text(junit([("/repo/backend/tests/A.php", "t", "pass"), ("/repo/backend/tests/B.php", "t", "pass")]))
        self.assertEqual(self.run_agg(), "INCOMPLETE")

    def test_missing_discovery_is_incomplete(self):
        (self.d / "disc.xml").unlink()
        self.status(); (self.d / "junit/1.xml").write_text(junit([("/repo/backend/tests/A.php", "t", "pass"), ("/repo/backend/tests/B.php", "t", "pass")]))
        self.assertEqual(self.run_agg(), "INCOMPLETE")

    def test_results_cannot_pass_before_termination_is_proven(self):
        self.status()
        (self.d / "junit/1.xml").write_text(junit([("/repo/backend/tests/A.php", "t", "pass"), ("/repo/backend/tests/B.php", "t", "pass")]))
        (self.d / "STOPPED.json").unlink()
        self.assertEqual(self.run_agg(), "INCOMPLETE")

    def test_blocked_run_cannot_be_upgraded_by_offline_aggregation(self):
        self.status()
        (self.d / "junit/1.xml").write_text(junit([("/repo/backend/tests/A.php", "t", "pass"), ("/repo/backend/tests/B.php", "t", "pass")]))
        (self.d / "BLOCKED.json").write_text('{}')
        self.assertEqual(self.run_agg(), "BLOCKED")

    def test_malformed_run_identity_or_boolean_exit_is_incomplete(self):
        self.status()
        (self.d / "junit/1.xml").write_text(junit([("/repo/backend/tests/A.php", "t", "pass"), ("/repo/backend/tests/B.php", "t", "pass")]))
        (self.d / "run.json").write_text('{"run_id":"r","exit_code":false}')
        self.assertEqual(self.run_agg(), "INCOMPLETE")

    def test_malformed_status_is_incomplete(self):
        (self.d / "status/j1.json").write_text("{broken")
        self.assertEqual(self.run_agg(), "INCOMPLETE")

    def test_boolean_exit_code_cannot_be_success(self):
        self.status(False)
        self.assertEqual(self.run_agg(), "INCOMPLETE")

    def test_empty_job_plan_cannot_claim_all_manifest_tests_completed(self):
        self.plan["jobs"] = []
        self.assertEqual(self.run_agg(), "INCOMPLETE")

    def test_filtered_run_is_incomplete(self):
        self.plan["filters_applied"] = ["j1: --filter"]
        self.status(); (self.d / "junit/1.xml").write_text(junit([("/repo/backend/tests/A.php", "t", "pass"), ("/repo/backend/tests/B.php", "t", "pass")]))
        self.assertEqual(self.run_agg(), "INCOMPLETE")

    def test_skip_is_not_plain_pass(self):
        self.status(); (self.d / "junit/1.xml").write_text(junit([("/repo/backend/tests/A.php", "t", "pass"), ("/repo/backend/tests/B.php", "t", "skipped")]))
        self.assertEqual(self.run_agg(), "PASS_WITH_SKIPS")

    def test_missing_file_result_is_incomplete(self):
        self.status(); (self.d / "junit/1.xml").write_text(junit([("/repo/backend/tests/A.php", "t", "pass")]))
        self.assertEqual(self.run_agg(), "INCOMPLETE")

    def test_missing_report_never_passes(self):
        self.status(); self.assertNotIn(self.run_agg(), ("PASS", "PASS_WITH_SKIPS"))

    def test_crash_exit_fails(self):
        self.status(137); (self.d / "junit/1.xml").write_text(junit([("/repo/backend/tests/A.php", "t", "pass"), ("/repo/backend/tests/B.php", "t", "pass")]))
        self.assertEqual(self.run_agg(), "FAIL")

    def test_failure_fails(self):
        self.status(1); (self.d / "junit/1.xml").write_text(junit([("/repo/backend/tests/A.php", "t", "failure"), ("/repo/backend/tests/B.php", "t", "pass")]))
        self.assertEqual(self.run_agg(), "FAIL")

    def test_duplicate_and_unknown_are_incomplete(self):
        self.status(); (self.d / "junit/1.xml").write_text(junit([("/repo/backend/tests/A.php", "t", "pass"), ("/repo/backend/tests/A.php", "t", "pass"),
                                                                  ("/repo/backend/tests/B.php", "t", "pass"), ("/repo/backend/tests/NEW.php", "t", "pass")]))
        self.assertEqual(self.run_agg(), "INCOMPLETE")

    def test_parse_error_is_incomplete(self):
        self.status(); (self.d / "junit/1.xml").write_text("<testsuites><oops")
        self.assertEqual(self.run_agg(), "INCOMPLETE")

    def test_no_status_file_is_incomplete(self):
        self.assertEqual(self.run_agg(), "INCOMPLETE")


class Discovery(unittest.TestCase):
    def test_identity_normalisation_matches_phpunit_formats(self):
        self.assertEqual(php_actual_from_junit_case("A\\T", 'test_x with data set #3'), "A\\T::test_x#3")
        self.assertEqual(php_actual_from_junit_case("A\\T", 'test_x with data set "dev database"'), 'A\\T::test_x"dev database"')
        self.assertEqual(php_actual_from_junit_case("A\\T", "test_plain"), "A\\T::test_plain")
        d = Path(tempfile.mkdtemp()) / "l.xml"
        d.write_text(list_xml(["A\\T::test_x#3", "A\\T::test_x#dev database", "A\\T::test_plain"]))
        self.assertEqual(sorted(php_expected_from_list_xml(d)), sorted(['A\\T::test_x#3', 'A\\T::test_x"dev database"', "A\\T::test_plain"]))

    def test_reconcile_reports_every_difference(self):
        r = reconcile(["a", "b", "b", "c"], ["a", "a", "d"])
        self.assertEqual((r["missing"], r["unexpected"], r["duplicate_expected"], r["duplicate_actual"]), (["b", "c"], ["d"], ["b"], ["a"]))


class Guards(unittest.TestCase):
    def env(self, **o):
        e = {"APP_ENV": "testing", "DB_CONNECTION": "mysql", "DB_HOST": "primeclassy-mariadb", "DB_PORT": "3306", "DB_DATABASE": "primeclassy_testing_w1",
             "DB_USERNAME": "u", "LARAVEL_STORAGE_PATH": "/o/w1/storage", "TMPDIR": "/o/w1/tmp", "PRIMECLASSY_TEST_RUN_ID": "r", "PRIMECLASSY_PARALLEL_WORKER": "1",
             "CACHE_PREFIX": "p", "SESSION_COOKIE": "c", "REDIS_PREFIX": "r", "CACHE_STORE": "array", "SESSION_DRIVER": "array", "QUEUE_CONNECTION": "sync",
             "DB_URL": "", "DATABASE_URL": "", "DB_SOCKET": ""}
        for k in ("SERVICES", "PACKAGES", "CONFIG", "ROUTES", "EVENTS"):
            e[f"APP_{k}_CACHE"] = f"/o/w1/bootstrap-cache/{k.lower()}.php"
        e.update(o)
        return e

    def codes(self, env):
        return {f.code for f in check_worker_env(env, "parallel") if f.level == "error"}

    def test_clean_env_passes(self):
        self.assertEqual(self.codes(self.env()), set())

    def test_url_override_not_blank_is_refused(self):
        self.assertIn("CONNECTION_OVERRIDE_NOT_NEUTRALISED", self.codes(self.env(DB_URL="mysql://x@prod/db")))
        e = self.env(); del e["DB_SOCKET"]
        self.assertIn("CONNECTION_OVERRIDE_NOT_NEUTRALISED", self.codes(e))

    def test_shared_bootstrap_cache_is_refused(self):
        self.assertIn("BOOTSTRAP_CACHE_NOT_ISOLATED", self.codes(self.env(APP_SERVICES_CACHE="/var/www/app/backend/bootstrap/cache/services.php")))


class Provision(unittest.TestCase):
    def test_cleanup_takes_every_lock_before_any_drop(self):
        sql = cleanup_sql(["primeclassy_testing_w1", "primeclassy_testing_w2"])
        first_drop = next(i for i, x in enumerate(sql) if x.startswith("DROP"))
        self.assertTrue(all("GET_LOCK" in x for x in sql[:first_drop]) and first_drop == 4)
        self.assertEqual(sum("parallelrun" in x for x in sql), 2); self.assertEqual(sum("testrun:" in x for x in sql), 2)

    def test_cleanup_refuses_canonical_dev_and_unknown(self):
        for bad in ("primeclassy_testing", "primeclassy_db", "primeclassy_testing_w6"):
            with self.assertRaises(ValueError):
                cleanup_sql(["primeclassy_testing_w1", bad])


class Lifecycle(unittest.TestCase):
    """Source regressions only in this audit; the controller is mocked when Owner executes them."""
    SCHED = {"adapters": {"php": {"mode": "docker-exec", "container": "c"}}, "kill_grace_seconds": 0, "kill_verify_seconds": 0}

    def fake(self, survivors_after_kill):
        import types
        from unittest import mock
        state = {"alive": ["11", "12"], "calls": []}

        def controller(sched, run_id, job_id="", action="scan"):
            state["calls"].append((run_id, job_id, action))
            if action == "KILL" and not survivors_after_kill:
                state["alive"] = []
            return list(state["alive"])
        return state, mock.patch("pctest.runner.process_control", controller), mock.patch("pctest.runner.time.sleep", lambda s: None)

    def test_termination_is_proven_not_assumed(self):
        from pctest import runner
        state, a, b = self.fake(survivors_after_kill=False)
        with a, b:
            self.assertTrue(runner.stop_owned(self.SCHED, "PC_RUN_ID=abcdef012345"))
        self.assertEqual([c[2] for c in state["calls"] if c[2] != "scan"], ["TERM", "KILL"])
        self.assertTrue(all(c[0] == "abcdef012345" for c in state["calls"]))

    def test_survivor_after_kill_is_reported_unstopped(self):
        from pctest import runner
        _state, a, b = self.fake(survivors_after_kill=True)
        with a, b:
            self.assertFalse(runner.stop_owned(self.SCHED, "PC_RUN_ID=abcdef012345"))

    def test_marker_is_validated_so_no_pattern_kill_is_possible(self):
        from pctest import runner
        with self.assertRaises(ValueError):
            runner.owned_pids(self.SCHED, "PC_RUN_ID=abc'; kill -9 1; '")

    def test_job_only_marker_is_not_authority_to_kill_another_run(self):
        from pctest import runner
        with self.assertRaises(ValueError):
            runner.owned_pids(self.SCHED, "PC_JOB_ID=worker-1-phpunit")

    def test_scanner_failure_is_blocked_not_an_empty_process_list(self):
        from pctest import runner
        from unittest import mock
        with mock.patch("pctest.runner.process_control", side_effect=runner.Blocked("scan failed")):
            with self.assertRaises(runner.Blocked):
                runner.stop_owned(self.SCHED, "PC_RUN_ID=abcdef012345")


class Completion(unittest.TestCase):
    def test_blocked_run_does_not_authorize_database_cleanup(self):
        from pctest.common import incomplete_runs
        root = Path(tempfile.mkdtemp())
        directory = root / "backend/storage/framework/testing/parallel/run-abcdef012345"
        directory.mkdir(parents=True)
        (directory / "BLOCKED.json").write_text('{}')
        (directory / "run.json").write_text('{"verdict":"PASS"}')
        self.assertEqual(incomplete_runs(root, "backend/storage/framework/testing/parallel"), ["run-abcdef012345"])
        (directory / "STOPPED.json").write_text('{"run_id":"abcdef012345","verified_stopped":true}')
        self.assertEqual(incomplete_runs(root, "backend/storage/framework/testing/parallel"), [])


class PidfdOwnership(unittest.TestCase):
    def test_reused_pid_is_never_signalled(self):
        import importlib.util
        from unittest import mock
        path = Path(__file__).resolve().parents[1] / "container/process_control.py"
        spec = importlib.util.spec_from_file_location("pc_process_control", path)
        controller = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(controller)
        with mock.patch.object(controller, "owned", return_value=[(77, "old-start")]), \
             mock.patch.object(controller, "identity", return_value=({b"PC_RUN_ID": b"abcdef012345"}, "new-start")), \
             mock.patch.object(controller.os, "pidfd_open", return_value=8, create=True), \
             mock.patch.object(controller.os, "close"), \
             mock.patch.object(controller.signal, "pidfd_send_signal", create=True) as send:
            with self.assertRaises(RuntimeError):
                controller.control("abcdef012345", "", "KILL")
            send.assert_not_called()


class GrantSafety(unittest.TestCase):
    def test_global_and_schema_wildcard_grants_are_refused(self):
        from pctest.runner import unsafe_grants
        for grant in ["GRANT ALL PRIVILEGES ON *.* TO 'u'@'%'", "GRANT SELECT ON `primeclassy_testing`.* TO 'u'@'%'", "GRANT SELECT ON `primeclassy_db`.* TO 'u'@'%'"]:
            self.assertTrue(unsafe_grants([grant]))
        self.assertFalse(unsafe_grants(["GRANT USAGE ON *.* TO 'u'@'%'", r"GRANT SELECT ON `primeclassy\_testing\_w1`.* TO 'u'@'%'"]))


class Sched(unittest.TestCase):
    def test_deterministic_and_complete(self):
        items = [(f"k{i}", float((i * 7) % 13 + 1)) for i in range(40)]
        a, b = assign(items, 5), assign(list(reversed(items)), 5)
        self.assertEqual(a, b); self.assertEqual(set(a), {k for k, _ in items}); self.assertTrue(set(a.values()) <= {1, 2, 3, 4, 5})

    def test_phpunit_config_forces_isolation(self):
        d = Path(tempfile.mkdtemp()); (d / "backend").mkdir()
        (d / "backend/phpunit.xml").write_text('<phpunit><php><env name="DB_DATABASE" value="primeclassy_testing"/><env name="CACHE_STORE" value="array"/><ini name="memory_limit" value="512M"/></php></phpunit>')
        x = ET.fromstring(render_phpunit_config(d, ["backend/tests/A.php"], {"DB_DATABASE": "primeclassy_testing_w2"}, PathMap(d / "backend", "/var/www/app/backend")))
        self.assertEqual(x.find("testsuites/testsuite/file").text, "/var/www/app/backend/tests/A.php")
        self.assertEqual(x.get("bootstrap"), "/var/www/app/backend/vendor/autoload.php")
        envs = {e.get("name"): (e.get("value"), e.get("force")) for e in x.iter("env")}
        self.assertEqual(envs["DB_DATABASE"], ("primeclassy_testing_w2", "true")); self.assertIn("CACHE_STORE", envs)
        self.assertEqual(len(list(x.iter("file"))), 1)


if __name__ == "__main__":
    unittest.main()
