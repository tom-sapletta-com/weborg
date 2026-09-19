import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

SCRIPT = Path(__file__).resolve().parents[1] / 'plesk_daily_cron.sh'


class PleskCronTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='weborg-cron-')
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.bin = self.root / 'bin'
        self.bin.mkdir()
        self.calls = self.root / 'calls.jsonl'
        fake = self.bin / 'php'
        fake.write_text('''#!/usr/bin/env python3
import json, os, pathlib, sys
cwd = pathlib.Path.cwd()
with open(os.environ['WEBORG_TEST_CALLS'], 'a') as log:
    log.write(json.dumps(str(cwd)) + '\\n')
sys.exit(9 if cwd.name == 'fail' else 0)
''')
        fake.chmod(0o755)
        self.env = {**os.environ, 'PATH': str(self.bin) + os.pathsep + os.environ['PATH'],
                    'WEBORG_TEST_CALLS': str(self.calls)}

    def engine(self, name):
        folder = self.root / name
        folder.mkdir(parents=True)
        (folder / 'index.php').write_text('<?php // WebOrg\nfunction getOrGenerateProjectsCache() {}\n')
        return folder

    def run_script(self, *args):
        return subprocess.run(['bash', str(SCRIPT), *map(str, args)], cwd=self.root,
                              env=self.env, capture_output=True, text=True, timeout=10)

    def recorded(self):
        return [json.loads(x) for x in self.calls.read_text().splitlines()] if self.calls.exists() else []

    def test_default_is_read_only(self):
        self.engine('site')
        result = self.run_script(self.root)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(self.recorded(), [])
        self.assertIn('dry-run', result.stdout)

    def test_missing_root_does_not_fall_back_to_current_directory(self):
        self.engine('site')
        result = self.run_script(self.root / 'missing')
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual(self.recorded(), [])

    def test_apply_preserves_space_and_newline_paths(self):
        sites = [self.engine('site with spaces'), self.engine('site\nnewline')]
        result = self.run_script('--apply', self.root)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertCountEqual(self.recorded(), list(map(str, sites)))
        self.assertIn('succeeded=2', result.stdout)

    def test_failure_is_reported_after_other_exports_continue(self):
        sites = [self.engine('fail'), self.engine('success')]
        result = self.run_script('--apply', self.root)
        self.assertNotEqual(result.returncode, 0)
        self.assertCountEqual(self.recorded(), list(map(str, sites)))
        self.assertIn('failed=1', result.stdout)
        self.assertIn('succeeded=1', result.stdout)

    def test_symlinks_and_unrelated_php_are_not_executed(self):
        valid = self.engine('valid')
        (self.root / 'linked').symlink_to(valid, target_is_directory=True)
        (self.root / 'index.php').symlink_to(valid / 'index.php')
        other = self.root / 'other'; other.mkdir()
        (other / 'index.php').write_text('<?php // not an exporter\n')
        result = self.run_script('--apply', self.root)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(self.recorded(), [str(valid)])

    def test_discovery_failure_prevents_partial_execution(self):
        site = self.engine('site')
        finder = self.bin / 'find'
        finder.write_text('#!/usr/bin/env python3\nimport sys\nsys.stdout.buffer.write(' + repr(os.fsencode(str(site / 'index.php')) + b'\0') + ')\nsys.exit(7)\n')
        finder.chmod(0o755)
        result = self.run_script('--apply', self.root)
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual(self.recorded(), [])

    def test_explicit_dry_run_overrides_apply(self):
        self.engine('site')
        result = self.run_script('--apply', '--dry-run', self.root)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(self.recorded(), [])

    def test_symlink_root_is_rejected(self):
        target = self.engine('site')
        link = self.root / 'root-link'; link.symlink_to(target, target_is_directory=True)
        result = self.run_script('--apply', link)
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual(self.recorded(), [])
