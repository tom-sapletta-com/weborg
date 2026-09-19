import json
import os
from pathlib import Path
import re
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]


class ExportTests(unittest.TestCase):
    def export(self, fixture, prior=False):
        # Exercise the full exporter with only its HTTP boundary replaced.
        # Real network transport is independently covered by http_transport.php.
        with tempfile.TemporaryDirectory(prefix="weborg-export-") as directory:
            root = Path(directory)
            source = (ROOT / "index.php").read_text()
            start = source.index("function httpGetJson(")
            end = source.index("function fetchGitHubOrgRepos(", start)
            data = json.dumps(fixture).replace("\\", "\\\\").replace("'", "\\'")
            boundary = "function httpGetJson($url) { return json_decode('" + data + "', true); }\n"
            (root / "index.php").write_text(source[:start] + boundary + source[end:])
            for name in ("docs", "tests"):
                (root / name).mkdir()
                (root / name / "README.md").write_text("Not a Git repository")
            if prior:
                (root / "index.html").write_text("previous HTML")
                (root / "cache_fixture.json").write_text("previous cache")
            env = {**os.environ, "GITHUB_REPOSITORY": "fixture/weborg", "GITHUB_TOKEN": ""}
            result = subprocess.run(["php", "index.php", "--export"], cwd=root, env=env,
                                    capture_output=True, text=True, timeout=10)
            return result, (root / "index.html").read_text() if (root / "index.html").exists() else None, (
                (root / "cache_fixture.json").read_text() if (root / "cache_fixture.json").exists() else None)

    def test_api_projects_export_without_docs_or_tests_directories(self):
        result, html, cache = self.export([{"name": "demo", "description": "A project", "html_url": "https://github.com/fixture/demo"}])
        self.assertEqual(result.returncode, 0, result.stderr)
        projects = json.loads(cache)["projects"]
        self.assertEqual(list(projects), ["demo"])
        self.assertIn("SERVER_DATA", html)
        embedded = json.loads(re.search(r"const SERVER_DATA = (.*);", html).group(1))
        self.assertEqual(embedded["projects"]["demo"]["github_url"], "https://github.com/fixture/demo")

    def test_api_failure_preserves_existing_files(self):
        result, html, cache = self.export(None, prior=True)
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual(html, "previous HTML")
        self.assertEqual(cache, "previous cache")

    def test_successful_empty_repository_list_is_not_an_api_failure(self):
        result, html, cache = self.export([])
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(json.loads(cache)["total_projects"], 0)
