#!/usr/bin/env bash
#
# Builds the ZIP that gets uploaded to wordpress.org/plugins/developers/add/.
#
# The archive contains a single top-level `automateflow/` directory, which is what the
# reviewer's unzip expects and what a user's manual "Upload Plugin" needs in order to land
# the files at wp-content/plugins/automateflow/. Everything listed in .distignore is left
# out, so development tooling never reaches users.
#
set -euo pipefail

SLUG="automateflow"
VERSION="$(sed -n 's/^ \* Version: *//p' "${SLUG}.php" | head -1 | tr -d '[:space:]')"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
OUT="${ROOT}/dist"
STAGE="$(mktemp -d)"
trap 'rm -rf "${STAGE}"' EXIT

if [[ -z "${VERSION}" ]]; then
	echo "Could not read Version from ${SLUG}.php" >&2
	exit 1
fi

# Cross-check the two places the version is declared. They drift silently otherwise, and
# wordpress.org serves updates off readme.txt's Stable tag while WordPress compares the
# header - a mismatch means users are either offered an update that does not exist or never
# offered one that does.
STABLE="$(sed -n 's/^Stable tag: *//p' readme.txt | head -1 | tr -d '[:space:]')"
if [[ "${VERSION}" != "${STABLE}" ]]; then
	echo "Version mismatch: ${SLUG}.php says ${VERSION}, readme.txt Stable tag says ${STABLE}" >&2
	exit 1
fi

mkdir -p "${STAGE}/${SLUG}" "${OUT}"

# --exclude-from expects patterns relative to the source, which is exactly how .distignore
# is written, so the ignore file is the single source of truth for both this and any
# `wp dist-archive` or deploy-action run.
rsync -a --exclude-from="${ROOT}/.distignore" \
	--exclude 'dist' --exclude 'build.sh' \
	"${ROOT}/" "${STAGE}/${SLUG}/"

ZIP="${OUT}/${SLUG}-${VERSION}.zip"
rm -f "${ZIP}"
( cd "${STAGE}" && zip -rq "${ZIP}" "${SLUG}" -x '*.DS_Store' )

echo "Built ${ZIP}"
echo "Version ${VERSION}, $(unzip -l "${ZIP}" | tail -1 | awk '{print $2}') files, $(du -h "${ZIP}" | cut -f1)"
