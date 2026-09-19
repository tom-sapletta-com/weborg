# Ticket 005: explicit GitHub Pages domain updates

Issue: https://github.com/tom-sapletta-com/weborg/issues/5
Source recovery: setup_cname.py from preserved commit 0e1a79f.
Owner: Codex, isolated ticket-005--safe-pages-domain worktree.
Authorization: user requested sequential repairs, tests, push and merge.
Scope: helper, offline API tests and usage notes; no domain activation.

The previous script changed files in hardcoded local checkouts, committed and
pushed them, then claimed success even if Git or Pages requests failed. This
helper plans by default and never writes local files, invokes Git, or publishes
changes in other repositories. Preserved organization/domain mappings are
suggestions only; `--all` prints those plans without network requests.

For a workflow-based Pages site, the domain is configured through the Pages API;
GitHub ignores CNAME files in custom Actions publishing workflows:
https://docs.github.com/en/pages/configuring-a-custom-domain-for-your-github-pages-site/troubleshooting-custom-domains-and-github-pages

Preview (no effects):

```sh
python3 setup_cname.py tom-sapletta-com weborg.tom-sapletta.com
python3 setup_cname.py --all
```

After verifying domain ownership, intended DNS routing and current Pages state,
authorized operators can explicitly update one site:

```sh
python3 setup_cname.py OWNER desired.example --apply --expected-current none
```

Use the observed old hostname instead of `none` when replacing a domain. The
helper checks that the site uses workflow publishing and the current domain
matches before PUT; then it verifies the saved domain with a fresh GET. A site
already using the desired domain returns `unchanged` without PUT. Errors,
timeouts, invalid JSON, state mismatches and unconfirmed read-back return a
nonzero exit status. The update endpoint is documented here:
https://docs.github.com/en/rest/pages/pages#update-information-about-a-github-pages-site

This is an observation guard, not atomic compare-and-swap: GitHub does not bind
this update to the preceding read. Avoid concurrent Pages administrators and
inspect state after an ambiguous failure before retrying. `verified` confirms
only the saved Pages domain, not DNS propagation, a certificate or site health.
The helper neither configures DNS nor relaxes HTTPS settings. Branch-based
publishing needs a separate CNAME/source workflow and is rejected by this tool.

## Current domain reconciliation

Read-only observation on 2026-09-19: weborg Pages uses workflow publishing,
`cname=null`, HTTPS enforced, at https://tom-sapletta-com.github.io/weborg/.
The old local CNAME contains weborg.tom-sapletta.com; its DNS resolves to
78.47.106.64. No Pages or DNS setting was changed. The CNAME file is obsolete as
a deployment carrier for this workflow and remains preserved in original Git
history; publishing it would not activate the domain.

## Validation

Thirteen mocked API/CLI tests cover effect-free planning, input validation,
expected-state checks, scoped GET/PUT/GET, legacy build rejection, API errors,
read-back mismatch, idempotency, bulk-plan limits, malformed JSON and timeouts.
Mocks block any unexpected command or original file write. Existing exporter,
cron and HTTPS regressions also run in CI. No real domain update is used as a test.
