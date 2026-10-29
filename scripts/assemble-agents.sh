#!/usr/bin/env bash
# Copies the agents from the `agents` submodule into DEST and refuses a copy
# that the fleet would not take.
#
#   scripts/assemble-agents.sh DEST
#
# deploy-status.yml runs it on apps/status before the upload, release.yml on
# the package's status/ and on the release assets, so the file list and these
# checks exist once, for both. Only the deploy also waits for the agents
# repo's tests on the pinned commit (deploy-status.yml). Every
# self-updating agent fetches these files from status/: a missing or empty
# one would show only as a broken self-update in the field, and one whose
# last line is not "# bk-agent-end <its AGENT_VERSION>" is refused by every
# agent.
set -euo pipefail

if [ "$#" -ne 1 ] || [ ! -d "$1" ]; then
  echo "usage: $0 DEST (an existing directory)" >&2
  exit 2
fi
command -v php > /dev/null || { echo "::error::php is needed to read AGENT_VERSION the way the server does"; exit 1; }
dest=${1%/}
src="$(cd "$(dirname "$0")/.." && pwd)/agents/vps-agent"

# The four files bk_agent_files() in functions.php serves to the agents, and
# the compose file the Docker install guide downloads next to agent.py.
agents=(agent.sh agent.py agent.ps1 agent_openwrt.sh)
files=("${agents[@]}" docker-compose.agent.yml)

for f in "${files[@]}"; do
  cp "$src/$f" "$dest/$f"
done
for f in "${files[@]}"; do
  if [ ! -s "$dest/$f" ]; then
    echo "::error file=agents/vps-agent/$f::missing or empty after the copy (is the agents submodule checked out?)"
    exit 1
  fi
done

# AGENT_VERSION as the server reads it from the served file. The regex is the
# one of bk_get_agent_latest_version() in functions.php, character for
# character (run_tests.php fails when the two differ).
agent_version() {
  php -- "$1" << 'PHPEOF'
<?php
if (preg_match('/\$?AGENT_VERSION\s*=\s*["\']([0-9][0-9A-Za-z.\-]*)["\']/', (string)file_get_contents($argv[1]), $m)) {
    echo $m[1];
}
PHPEOF
}

# Every agent refuses an update whose last line is not
# "# bk-agent-end <offered version>", and the server offers what it reads from
# the served file, so the two must agree in the copy. The shell agents' own
# rule decides: `tail -n 1`, where a CR or a blank last line fails.
fail=0
for f in "${agents[@]}"; do
  v=$(agent_version "$dest/$f")
  last=$(tail -n 1 "$dest/$f")
  if [ -n "$v" ] && [ "$last" = "# bk-agent-end $v" ]; then
    echo "$f: $v"
  else
    echo "::error file=agents/vps-agent/$f::the last line must be '# bk-agent-end ${v:-<no AGENT_VERSION>}', it is '${last//$'\r'/\\r}'. Every agent would refuse this file."
    fail=1
  fi
done
exit "$fail"
