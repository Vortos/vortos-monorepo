#!/usr/bin/env bash
#
# Publish one package directory of this monorepo to its own read-only split repository.
#
# WHY THIS EXISTS RATHER THAN A THIRD-PARTY ACTION
#
# This replaced danharrin/monorepo-split-github-action, a Docker action whose Dockerfile begins
# `FROM php:8.1-cli-alpine`. The runner therefore had to build a container image — and reach Docker
# Hub — once per package, fifty times per release. Under that fan-out Docker Hub throttles, and the
# job dies with:
#
#   DeadlineExceeded: php:8.1-cli-alpine: failed to resolve source metadata ...
#   Head "https://registry-1.docker.io/v2/library/php/manifests/8.1-cli-alpine": i/o timeout
#
# It failed releases repeatedly and never once reproduced on a re-run. Three mitigations were tried
# and none of them fixed it:
#
#   * pre-pulling the base image      — BuildKit still issues a registry HEAD when the image is
#                                       already in the local store, and that HEAD is what times out.
#   * DOCKER_BUILDKIT=0 at step, job, — none reach the runner's internal "Build container for
#     and $GITHUB_ENV level             action use" step, whose environment is fixed when the runner
#                                       process starts, not per-step.
#   * max-parallel on the matrix      — reduced the burst and bought three clean releases, then
#                                       failed again on the fourth. A mitigation, not a fix.
#
# The actual fix is to stop needing the registry. Splitting a directory into another repository is
# git, not PHP: no image, no Docker Hub, no third-party action holding a token that can push to
# ~50 repositories. This runs in seconds and cannot be rate-limited by a registry.
#
# BEHAVIOUR
#
# Adds ONE commit on top of the target branch containing the current contents of the package
# directory. It never rewrites the split repo's history and never force-pushes a branch, so the
# published history is append-only exactly as before. If the contents are unchanged, no commit is
# made — but a tag is still published, because a release must tag every package even when that
# package did not change in it (a missing tag makes `composer update` resolve to a version that
# does not exist).
#
# Required environment:
#   SPLIT_TOKEN      PAT with push access to the target repository
#   PKG_DIR          package directory, relative to the monorepo root
#   TARGET_REPO      owner/name of the split repository
#   TARGET_BRANCH    branch to publish to
#   TARGET_TAG       tag to publish, or empty for a branch push

set -euo pipefail

: "${SPLIT_TOKEN:?SPLIT_TOKEN is required}"
: "${PKG_DIR:?PKG_DIR is required}"
: "${TARGET_REPO:?TARGET_REPO is required}"
: "${TARGET_BRANCH:?TARGET_BRANCH is required}"
TARGET_TAG="${TARGET_TAG:-}"

ROOT="${GITHUB_WORKSPACE:-$(pwd)}"
SRC="$ROOT/$PKG_DIR"

[ -d "$SRC" ] || { echo "package directory does not exist: $PKG_DIR" >&2; exit 1; }

# Author and message come from the monorepo commit being published, so the split history reads the
# same as the source history rather than being attributed to CI.
AUTHOR_NAME="$(git -C "$ROOT" log -1 --pretty=%an)"
AUTHOR_EMAIL="$(git -C "$ROOT" log -1 --pretty=%ae)"
SUBJECT="$(git -C "$ROOT" log -1 --pretty=%B)"
SOURCE_SHA="$(git -C "$ROOT" rev-parse HEAD)"

WORK="$(mktemp -d)"

# Leave the directory before deleting it, and never let cleanup decide the job's fate.
#
# The first version was `trap 'rm -rf "$WORK"' EXIT`, and the script cd's INTO $WORK to do its work.
# Removing your own working directory leaves `rm` unable to unlink it — "cannot remove '.git':
# Directory not empty" — and a failing EXIT trap sets the script's exit status, so a split that had
# already completed correctly reported failure. It printed "no content change" and then exited 1.
trap 'cd / 2>/dev/null || true; rm -rf "$WORK" 2>/dev/null || true' EXIT

# The token is placed in the remote URL of a throwaway clone that is deleted on exit, and is never
# written into the repository's own config that gets pushed.
REMOTE="https://x-access-token:${SPLIT_TOKEN}@github.com/${TARGET_REPO}.git"

git init -q "$WORK"
cd "$WORK"
git remote add origin "$REMOTE"

# Full fetch, not --depth=1: pushing from a shallow repository is rejected by some server
# configurations, and split repositories are small enough that depth buys nothing.
if git fetch -q origin "$TARGET_BRANCH" 2>/dev/null; then
    git checkout -q -b "$TARGET_BRANCH" FETCH_HEAD
    # Clear the tree so files deleted in the monorepo are deleted in the split too. `git add -A`
    # below stages the removals; without this a deleted file would linger forever.
    find . -mindepth 1 -maxdepth 1 ! -name '.git' -exec rm -rf {} +
else
    echo "branch $TARGET_BRANCH does not exist in $TARGET_REPO yet; creating it"
    git checkout -q -b "$TARGET_BRANCH"
fi

cp -a "$SRC/." .

git add -A

if git diff --cached --quiet; then
    echo "no content change for $TARGET_REPO"
else
    git -c "user.name=$AUTHOR_NAME" -c "user.email=$AUTHOR_EMAIL" \
        commit -q -m "$SUBJECT" -m "Split from $SOURCE_SHA"
    git push -q origin "$TARGET_BRANCH"
    echo "pushed $TARGET_REPO@$TARGET_BRANCH"
fi

if [ -n "$TARGET_TAG" ]; then
    # -f so re-running a release is idempotent rather than failing on an existing tag; the branch
    # itself is still never force-pushed.
    git tag -f "$TARGET_TAG"
    git push -q -f origin "refs/tags/$TARGET_TAG"
    echo "pushed tag $TARGET_TAG to $TARGET_REPO"
fi
