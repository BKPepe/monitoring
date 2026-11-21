# Custom Workspace Rules & Operational Guidelines

## Data Integrity & Backend Synchronization
- **No Fabricated Mock Data**: Never insert hardcoded fallback placeholders or fake mock metrics (e.g. "disk full in 9 days", fake domain names like `mc.bloodkings.eu` or `ts.bloodkings.eu`). All frontend components must dynamically query and reflect the exact MySQL database values from `/status/api.php`.
- **Exact Server Identifiers & Metrics**: Always preserve exact asset names (`BloodKings.eu`, `BloodKings.eu discord`, `Donald`, `Minecraft`, `Router - Praha`, `Schlehofer.eu`) and precise parameters (e.g., Donald TeamSpeak 3 on `donald.bloodkings.eu:8200`, Debian 12, agent v3.13.8, exact SLA percentages).
- **Outage Timestamps & Duration**: Outage events and details must explicitly display both the start timestamp (e.g., `21.07.2026 22:30:01`) and calculated duration (`7 dní, 21 hodin, 56 minut`).
- **Live Auto-Refresh**: All audit logs, event tables, and status views must implement automatic background polling (10s auto-refresh) so timestamps advance dynamically with live time.
- **Empirical Verification**: Never claim a feature is "tested" or "working" without empirical runtime verification and confirming all tracked files are committed to Git.
