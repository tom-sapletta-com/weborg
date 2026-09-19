import contextlib
import importlib.util
import io
import json
from pathlib import Path
import subprocess
import unittest
from unittest.mock import patch

spec = importlib.util.spec_from_file_location('domain_helper', Path(__file__).resolve().parents[1] / 'setup_cname.py')
helper = importlib.util.module_from_spec(spec)
spec.loader.exec_module(helper)


def response(cname=None, build_type='workflow'):
    return subprocess.CompletedProcess([], 0, json.dumps({'cname': cname, 'build_type': build_type}), '')


class DomainHelperTests(unittest.TestCase):
    def setUp(self):
        self.guard = patch.object(helper, 'open', side_effect=AssertionError('Unexpected file write'), create=True)
        self.guard.start(); self.addCleanup(self.guard.stop)
        self.run = patch.object(helper.subprocess, 'run', side_effect=AssertionError('Unexpected external command'))
        self.command = self.run.start(); self.addCleanup(self.run.stop)

    def test_default_only_returns_plan(self):
        plan = helper.setup_cname('tom-sapletta-com', 'weborg.tom-sapletta.com')
        self.assertEqual(plan['status'], 'plan')
        self.assertEqual(plan['repository'], 'tom-sapletta-com/weborg')
        self.command.assert_not_called()

    def test_invalid_target_is_rejected_before_commands(self):
        for org, domain in [('../bad', 'good.example'), ('valid', 'https://good.example'),
                            ('valid', 'bad.example\nInjected: yes'), ('valid', '127.0.0.1')]:
            with self.subTest(org=org, domain=domain), self.assertRaises(ValueError):
                helper.setup_cname(org, domain)
        self.command.assert_not_called()

    def test_apply_requires_expected_current(self):
        with self.assertRaises(ValueError):
            helper.setup_cname('valid', 'good.example', apply=True)
        self.command.assert_not_called()

    def test_success_is_verified_with_scoped_get_put_get(self):
        self.command.side_effect = [response(), subprocess.CompletedProcess([], 0, '', ''), response('good.example')]
        result = helper.setup_cname('valid', 'good.example', apply=True, expected_current='none')
        self.assertEqual(result['status'], 'verified')
        commands = [c.args[0] for c in self.command.call_args_list]
        self.assertEqual([c[3] for c in commands], ['GET', 'PUT', 'GET'])
        self.assertTrue(all(c[0:2] == ['gh', 'api'] and 'repos/valid/www/pages' in c for c in commands))
        self.assertIn('cname=good.example', commands[1])

    def test_changed_current_domain_stops_before_write(self):
        self.command.side_effect = [response('other.example')]
        with self.assertRaises(RuntimeError):
            helper.setup_cname('valid', 'good.example', apply=True, expected_current='none')
        self.assertEqual(self.command.call_count, 1)

    def test_legacy_pages_build_is_not_mutated(self):
        self.command.side_effect = [response(build_type='legacy')]
        with self.assertRaises(RuntimeError):
            helper.setup_cname('valid', 'good.example', apply=True, expected_current='none')
        self.assertEqual(self.command.call_count, 1)

    def test_failed_update_is_not_reported_as_success(self):
        self.command.side_effect = [response(), subprocess.CompletedProcess([], 1, '', 'request failed')]
        with self.assertRaises(RuntimeError):
            helper.setup_cname('valid', 'good.example', apply=True, expected_current='none')
        self.assertEqual(self.command.call_count, 2)

    def test_readback_mismatch_is_failure(self):
        self.command.side_effect = [response(), subprocess.CompletedProcess([], 0, '', ''), response()]
        with self.assertRaises(RuntimeError):
            helper.setup_cname('valid', 'good.example', apply=True, expected_current='none')

    def test_matching_domain_is_idempotent(self):
        self.command.side_effect = [response('good.example')]
        result = helper.setup_cname('valid', 'good.example', apply=True, expected_current='none')
        self.assertEqual(result['status'], 'unchanged')
        self.assertEqual(self.command.call_count, 1)

    def test_all_is_plan_only_and_rejects_apply(self):
        with contextlib.redirect_stdout(io.StringIO()) as output:
            self.assertEqual(helper.main(['--all']), 0)
        self.assertTrue(all(p['status'] == 'plan' for p in json.loads(output.getvalue())))
        with contextlib.redirect_stderr(io.StringIO()), self.assertRaises(SystemExit):
            helper.main(['--all', '--apply', '--expected-current', 'none'])
        self.command.assert_not_called()

    def test_invalid_preflight_response_stops_before_write(self):
        for text in ('not JSON', '[]', '{}'):
            with self.subTest(text=text):
                self.command.reset_mock()
                self.command.side_effect = [subprocess.CompletedProcess([], 0, text, '')]
                with self.assertRaises(RuntimeError):
                    helper.setup_cname('valid', 'good.example', apply=True, expected_current='none')
                self.assertEqual(self.command.call_count, 1)

    def test_timeout_reports_error_without_retry(self):
        self.command.side_effect = subprocess.TimeoutExpired('gh', 30)
        with self.assertRaises(RuntimeError):
            helper.setup_cname('valid', 'good.example', apply=True, expected_current='none')
        self.assertEqual(self.command.call_count, 1)

    def test_cli_failure_returns_nonzero_and_no_success_json(self):
        self.command.side_effect = [subprocess.CompletedProcess([], 1, '', 'private diagnostic')]
        with contextlib.redirect_stdout(io.StringIO()) as output, contextlib.redirect_stderr(io.StringIO()) as error:
            self.assertEqual(helper.main(['valid', 'good.example', '--apply', '--expected-current', 'none']), 1)
        self.assertEqual(output.getvalue(), '')
        self.assertIn('failed', error.getvalue())
        self.assertNotIn('private diagnostic', error.getvalue())
