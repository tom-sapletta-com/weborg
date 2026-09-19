# Ticket 003: safe Plesk export discovery

Issue: https://github.com/tom-sapletta-com/weborg/issues/3
Recovered file: `plesk_daily_cron.sh` from local commit `0e1a79f`.
Accepted base: `9c7320ad8d8944d4870b7a60856efe9dfa5b7cd2`.
Owner: Codex, isolated `ticket-003--safe-plesk-cron` worktree.
Authorization: user requested sequential repairs, testing, push and merge.
Scope: cron helper, offline subprocess tests, this documentation and shell lint.

The old helper fell back to the current directory after an invalid root,
split filenames at newlines, and reported success despite failed exports.
The recovered helper defaults to read-only discovery, rejects missing/symlink
roots, discovers regular files without following symlinks, and preserves paths.
Discovery must complete successfully before any exporter runs. Export failures
are counted while subsequent exports continue; the final exit status is nonzero
if inspection or any export failed. A successful discovery with no matches is
not an export and reports `matched=0 succeeded=0 failed=0`.

Preview a known root:

```sh
bash plesk_daily_cron.sh /var/www/vhosts
```

Execute after reviewing the preview:

```sh
bash plesk_daily_cron.sh --apply /var/www/vhosts
```

`--apply` executes each discovered PHP file as the invoking user. The root and
its contents must be trusted. The signature check is identification, not a
sandbox. The helper does not install a crontab; an existing scheduled invocation
must explicitly include `--apply` to retain execution behavior. Exporter errors
are preserved as failures rather than a success banner. It relies on the deployed
exporter's own write/error semantics; this ticket does not deploy that exporter.

Validation: eight subprocess regressions use disposable directories and a fake
PHP executable to cover preview, missing root, newline/space paths, mixed export
success/failure, symlinks, discovery failure, dry-run precedence and symlink root.
Existing offline export and HTTPS transport tests remain part of CI. No real
Plesk export, remote command or cron installation was performed.

CNAME and setup_cname.py remain preserved in the original local commit for
separate reconciliation. Publication and merge are tracked by the PR and local
recovery ledger; they are separate from installation on a Plesk host.
