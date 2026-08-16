-- 0004_probe_node.sql — podpora pro distribuované uzly (checked_from v monitor_logs).

BEGIN;

ALTER TABLE monitor_logs ADD COLUMN IF NOT EXISTS checked_from TEXT DEFAULT 'Main Server';

INSERT INTO schema_migrations (version) VALUES ('0004_probe_node');

COMMIT;
