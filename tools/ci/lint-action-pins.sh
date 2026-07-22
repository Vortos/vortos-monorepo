#!/usr/bin/env bash
#
# lint-action-pins.sh — fail CI if any GitHub Actions `uses:` reference is not pinned to a full
# 40-character commit SHA.
#
# WHY: a mutable ref (`@v4`, `@main`, `@latest`) lets the upstream owner — or an attacker who
# compromises that repo or an org member's token — move the tag to malicious code that then executes
# inside our pipeline with our secrets (registry tokens, SSH keys, the age identity). Pinning to an
# immutable commit SHA removes that class of supply-chain attack entirely. Enterprise policy: every
# third-party action is pinned to a commit SHA with a trailing `# vX` comment for readability.
#
# The framework already verifies that its own generated pins EXIST upstream
# (`bin/console pipeline:actions:verify`). This complementary check enforces that EVERY workflow file
# in the repo — including hand-authored ones the emitter does not manage — only ever uses SHA pins.
#
# Usage: tools/ci/lint-action-pins.sh [workflow_dir]   (default: .github/workflows)
# Exit:  0 = all pinned, 1 = at least one unpinned reference (annotated for GitHub).

set -euo pipefail

workflow_dir="${1:-.github/workflows}"
sha_re='^[0-9a-f]{40}$'
fail=0
found=0

if [ ! -d "$workflow_dir" ]; then
  echo "No workflow directory at '${workflow_dir}' — nothing to lint."
  exit 0
fi

while IFS= read -r file; do
  # Extract the value token of each `uses:` KEY (anchored so a `#`-led comment mentioning "uses:"
  # is never matched). The `[^[:space:]#]+` stops at the first space, so a trailing `# vX` comment
  # is excluded from the captured ref. Surrounding quotes are then stripped.
  while IFS= read -r ref; do
    [ -n "$ref" ] || continue
    found=$((found + 1))
    case "$ref" in
      ./*|docker://*) continue ;;   # local composite action / pinned docker image — not a taggable action
    esac
    sha="${ref##*@}"
    if [ "$sha" = "$ref" ] || ! printf '%s' "$sha" | grep -Eq "$sha_re"; then
      echo "::error file=${file}::Unpinned action '${ref}' — pin to a 40-char commit SHA (owner/repo@<sha>  # vX)."
      fail=1
    fi
  done < <(
    grep -oE '^[[:space:]]*-?[[:space:]]*uses:[[:space:]]*[^[:space:]#]+' "$file" \
      | sed -E 's/^.*uses:[[:space:]]*//; s/^["'"'"']//; s/["'"'"']$//'
  )
done < <(find "$workflow_dir" -type f \( -name '*.yml' -o -name '*.yaml' \) | sort)

if [ "$found" -eq 0 ]; then
  echo "No 'uses:' references found under ${workflow_dir}."
fi
if [ "$fail" -ne 0 ]; then
  echo "Action-pin lint FAILED: one or more actions are not pinned to an immutable commit SHA." >&2
  exit 1
fi
echo "Action-pin lint passed: every action under ${workflow_dir} is pinned to a commit SHA."
