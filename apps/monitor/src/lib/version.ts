/**
 * The GitHub link for a build version string ("0.1.0 (abc1234)").
 *
 * The version links to the exact commit - one click from a running install
 * tells precisely what is deployed. Shared by the app footer and the public
 * page footer, so the two cannot drift in how they parse the hash.
 */
export function versionCommitUrl(version: string): string {
  const hash = version.match(/\(([0-9a-f]{7,40})\)/)?.[1];
  return hash ? `https://github.com/BKPepe/monitoring/commit/${hash}` : 'https://github.com/BKPepe/monitoring';
}
