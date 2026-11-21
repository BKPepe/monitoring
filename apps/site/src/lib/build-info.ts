/**
 * Which commit a deployed page was built from, for the footer. Read once at
 * build time: the CI variables first (GitHub Actions, Cloudflare Pages), then
 * git itself. When none answers the footer shows no hash instead of a
 * made-up one.
 */
import { execFileSync } from 'node:child_process';

export interface BuildInfo {
  /** Full commit hash, or null when the build could not tell. */
  commit: string | null;
  /** Build date, YYYY-MM-DD (UTC). */
  date: string;
}

function gitHead(): string | null {
  try {
    return execFileSync('git', ['rev-parse', 'HEAD'], { encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'] }).trim();
  } catch {
    return null;
  }
}

function readBuildInfo(): BuildInfo {
  const fromEnv = process.env.GITHUB_SHA ?? process.env.CF_PAGES_COMMIT_SHA ?? null;
  const commit = fromEnv ?? gitHead();
  return {
    commit: commit && /^[0-9a-f]{7,40}$/i.test(commit) ? commit.toLowerCase() : null,
    date: new Date().toISOString().slice(0, 10),
  };
}

export const BUILD: BuildInfo = readBuildInfo();
