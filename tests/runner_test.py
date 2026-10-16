"""Regression checks for run.sh's exit codes and diagnostic logs, without running the CMS."""
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest


class RunnerTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix="faros-runner-")
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        (self.root / "tests/js/node_modules/jsdom").mkdir(parents=True)
        (self.root / "bin").mkdir()
        shutil.copy(Path(__file__).with_name("run.sh"), self.root / "tests/run.sh")
        stub = """#!/usr/bin/env bash
if [[ "$1" == scripts/check-blocks.php ]]; then
  echo 'Checked content: 0 problems'
  if [[ "${RUNNER_CASE:-}" == content-exit ]]; then exit 7; fi
  exit 0
fi
echo 'ok   stub assertion'
if [[ "$1" == tests/unit/blocks.php || "$1" == tests/js/serializer.test.js ]]; then
  case "${RUNNER_CASE:-}" in
    failed-assertion) echo 'FAIL assertion'; echo 'ALL PASSED'; exit 0 ;;
    missing-summary) exit 0 ;;
    error-exit)
      echo 'ALL PASSED'
      for i in {1..40}; do echo "diagnostic $i"; done
      echo 'stderr diagnostic' >&2
      exit 7 ;;
  esac
fi
echo 'ALL PASSED'
"""
        for name in ("php", "node"):
            path = self.root / "bin" / name
            path.write_text(stub)
            path.chmod(0o755)
        npm = self.root / "bin/npm"
        npm.write_text("#!/usr/bin/env bash\necho 'npm unavailable' >&2\nexit 23\n")
        npm.chmod(0o755)

    def run_suite(self, mode="unit", case=""):
        env = os.environ.copy()
        env.update(PATH=str(self.root / "bin") + os.pathsep + env["PATH"],
                   FAROS_TEST_LOG_DIR=str(self.root / "logs"), RUNNER_CASE=case)
        return subprocess.run(["bash", str(self.root / "tests/run.sh"), mode],
                              env=env, capture_output=True, text=True, timeout=30)

    def test_success(self):
        result = self.run_suite()
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertIn("ALL GOOD", result.stdout)

    def test_errors_cannot_pass_even_with_success_text(self):
        for mode, case in (("unit", "error-exit"), ("js", "error-exit"),
                           ("unit", "failed-assertion"), ("unit", "missing-summary"),
                           ("unit", "content-exit")):
            with self.subTest(mode=mode, case=case):
                result = self.run_suite(mode, case)
                self.assertEqual(result.returncode, 1, result.stdout + result.stderr)
                self.assertIn("FAILED:", result.stdout)
                self.assertNotIn("ALL GOOD", result.stdout)

    def test_log_keeps_output_beyond_console_excerpt_and_stderr(self):
        result = self.run_suite(case="error-exit")
        self.assertEqual(result.returncode, 1)
        log = (self.root / "logs/unit-blocks.log").read_text()
        self.assertIn("diagnostic 40", log)
        self.assertIn("stderr diagnostic", log)
        self.assertNotIn("diagnostic 40", result.stdout)

    def test_npm_failure_keeps_diagnostics(self):
        shutil.rmtree(self.root / "tests/js/node_modules")
        result = self.run_suite("js")
        self.assertEqual(result.returncode, 1, result.stdout + result.stderr)
        self.assertIn("npm unavailable", (self.root / "logs/js-npm-install.log").read_text())

    def test_http_port_failure_stops_before_running_tests(self):
        python = self.root / "bin/python3"
        python.write_text("#!/usr/bin/env bash\nif [[ \"$1\" == -c ]]; then exit 11; fi\nexit 0\n")
        python.chmod(0o755)
        result = self.run_suite("http")
        self.assertEqual(result.returncode, 1, result.stdout + result.stderr)
        self.assertIn("FAILED: HTTP port", result.stdout)
        self.assertNotIn("HTTP tests (temporary copy", result.stdout)


if __name__ == "__main__":
    unittest.main()
