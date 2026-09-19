#!/usr/bin/env python3
"""Plan or explicitly update one workflow-based GitHub Pages custom domain."""
import argparse
import ipaddress
import json
import re
import subprocess
import sys

DOMAINS_MAP = {
    'wellmanifest': 'www.wellmanifest.com',
    'autogrammar': 'www.autogrammar.com',
    'urirun-connectors': 'connectors.urirun.com',
    'semcod': 'www.semcod.com',
    'wronai': 'www.wronai.com',
    'oqlos': 'www.oqlos.com',
    'digitaltwin-run': 'www.digitaltwin.run',
    'stream-ware': 'www.streamware.io',
    'bioxfoundry': 'www.bioxfoundry.com',
    'founder-pl': 'www.founder.pl',
    'emllm': 'www.emllm.com',
    'fin-officer': 'www.finofficer.com',
    'tom-sapletta-com': 'weborg.tom-sapletta.com'
}


def normalize_domain(value):
    if not isinstance(value, str):
        raise ValueError("Domain must be a DNS hostname")
    domain = value.lower().removesuffix(".")
    labels = domain.split(".")
    if len(domain) > 253 or len(labels) < 2 or any(
        not re.fullmatch(r"[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?", label)
        for label in labels
    ):
        raise ValueError("Domain must be a DNS hostname without URL, path or whitespace")
    try:
        ipaddress.ip_address(domain)
    except ValueError:
        return domain
    raise ValueError("Use a DNS hostname, not an IP address")


def request(method, repository, domain=None):
    command = ["gh", "api", "--method", method, f"repos/{repository}/pages"]
    if domain is not None:
        command += ["--raw-field", f"cname={domain}"]
    try:
        result = subprocess.run(command, capture_output=True, text=True, timeout=30, check=False)
    except (OSError, subprocess.TimeoutExpired) as exc:
        raise RuntimeError(f"GitHub Pages {method} could not complete; inspect remote state before retrying") from exc
    if result.returncode != 0:
        raise RuntimeError(f"GitHub Pages {method} failed (exit {result.returncode}); inspect remote state before retrying")
    if method != "GET":
        return None
    try:
        data = json.loads(result.stdout)
        if not isinstance(data, dict) or "cname" not in data:
            raise ValueError("Missing Pages state")
        if data["cname"] is not None:
            data["cname"] = normalize_domain(data["cname"])
        return data
    except (ValueError, TypeError) as exc:
        raise RuntimeError("GitHub Pages returned invalid state") from exc


def setup_cname(org_name, custom_domain, *, apply=False, expected_current=None):
    if not isinstance(org_name, str) or not re.fullmatch(r"[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,37}[a-zA-Z0-9])?", org_name):
        raise ValueError("Invalid GitHub organization/user name")
    org_name = org_name.lower()
    domain = normalize_domain(custom_domain)
    repository = f"{org_name}/" + ("weborg" if org_name == "tom-sapletta-com" else "www")
    result = {"repository": repository, "domain": domain, "status": "plan"}
    if not apply:
        return result
    if expected_current is None:
        raise ValueError("--apply requires --expected-current DOMAIN (or none)")
    expected = None if expected_current == "none" else normalize_domain(expected_current)
    before = request("GET", repository)
    if before.get("build_type") != "workflow":
        raise RuntimeError("Only workflow-based Pages sites are supported; no changes made")
    if before["cname"] == domain:
        return {**result, "status": "unchanged"}
    if before["cname"] != expected:
        raise RuntimeError("Current Pages domain differs from --expected-current; no changes made")
    request("PUT", repository, domain)
    after = request("GET", repository)
    if after["cname"] != domain or after.get("build_type") != "workflow":
        raise RuntimeError("Pages update not confirmed by read-back; inspect remote state")
    return {**result, "status": "verified"}


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("organization", nargs="?")
    parser.add_argument("domain", nargs="?")
    parser.add_argument("--all", action="store_true", help="Print all preserved suggestions; never apply in bulk")
    parser.add_argument("--apply", action="store_true", help="Update one Pages site and verify its saved domain")
    parser.add_argument("--expected-current", help="Observed current Pages domain, or none")
    args = parser.parse_args(argv)
    if args.all:
        if args.apply or args.organization or args.domain or args.expected_current is not None:
            parser.error("--all is a plan-only operation; select one organization to apply")
        print(json.dumps([setup_cname(org, domain) for org, domain in DOMAINS_MAP.items()], indent=2))
        return 0
    if not args.organization or not args.domain:
        parser.error("organization and domain are required")
    try:
        result = setup_cname(args.organization, args.domain, apply=args.apply, expected_current=args.expected_current)
    except (ValueError, RuntimeError) as exc:
        print(str(exc), file=sys.stderr)
        return 1
    print(json.dumps(result, indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
