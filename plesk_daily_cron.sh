#!/usr/bin/env bash
# Discover WebOrg exporters without effects; pass --apply to execute them.
set -u -o pipefail

usage() {
    printf 'Usage: %s [--apply | --dry-run] [--] [ROOT]\n' "$0"
    printf 'Default: read-only discovery below /var/www/vhosts (maximum depth 4).\n'
}

apply=false
root=/var/www/vhosts
root_given=false
while (($#)); do
    case "$1" in
        --apply) apply=true ;;
        --dry-run) apply=false ;;
        -h|--help) usage; exit 0 ;;
        --) shift; break ;;
        -*) printf 'Unknown option: %s\n' "$1" >&2; usage >&2; exit 2 ;;
        *)
            if "$root_given"; then usage >&2; exit 2; fi
            root=$1; root_given=true ;;
    esac
    shift
done
if (($#)); then
    if "$root_given" || (($# != 1)); then usage >&2; exit 2; fi
    root=$1
fi
if [[ ! -d "$root" || -L "$root" ]]; then
    printf 'Root must be an existing directory, not a symlink: %q\n' "$root" >&2
    exit 2
fi
# Resolve relative roots before invoking find and changing into exporter dirs.
root=$(cd -- "$root" && pwd -P) || exit 2
candidates=$(mktemp) || exit 1
trap 'rm -f -- "$candidates"' EXIT

# Complete discovery first. Do not perform partial exports after a scan failure.
if ! find "$root" -maxdepth 4 -type f -name index.php -print0 > "$candidates"; then
    printf 'Discovery failed; no exporters executed.\n' >&2
    exit 1
fi
matched=0
succeeded=0
failed=0
while IFS= read -r -d '' index_file; do
    # This identifies an exporter, not a sandbox: --apply requires a trusted root.
    grep -Eq 'function[[:space:]]+getOrGenerateProjectsCache[[:space:]]*\(' "$index_file"
    match_status=$?
    if ((match_status == 1)); then continue; fi
    if ((match_status != 0)); then
        printf 'Cannot inspect: %q\n' "$index_file" >&2
        failed=$((failed + 1)); continue
    fi
    matched=$((matched + 1))
    directory=${index_file%/*}
    if ! "$apply"; then
        printf '[dry-run] Would export: %q\n' "$directory"
        continue
    fi
    printf '[apply] Exporting: %q\n' "$directory"
    if (cd -- "$directory" && php index.php --export); then
        succeeded=$((succeeded + 1))
    else
        printf 'Export failed: %q\n' "$directory" >&2
        failed=$((failed + 1))
    fi
done < "$candidates"
printf 'matched=%d succeeded=%d failed=%d\n' "$matched" "$succeeded" "$failed"
((failed == 0))
