-- 0005_incidents_integrations.sql — incidenty, aktualizace a správa incidentního stavu.

BEGIN;

CREATE TABLE incidents (
    id          BIGINT      GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    monitor_id  BIGINT      REFERENCES monitors(id) ON DELETE SET NULL,
    title       TEXT        NOT NULL,
    status      TEXT        NOT NULL DEFAULT 'investigating', -- ('investigating', 'identified', 'monitoring', 'resolved')
    impact      TEXT        NOT NULL DEFAULT 'minor',         -- ('none', 'minor', 'major', 'critical')
    created_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
    resolved_at TIMESTAMPTZ
);

CREATE INDEX incidents_status_created_idx ON incidents (status, created_at DESC);

CREATE TABLE incident_updates (
    id          BIGINT      GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    incident_id BIGINT      NOT NULL REFERENCES incidents(id) ON DELETE CASCADE,
    message     TEXT        NOT NULL,
    status      TEXT        NOT NULL,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX incident_updates_incident_created_idx ON incident_updates (incident_id, created_at DESC);

INSERT INTO schema_migrations (version) VALUES ('0005_incidents_integrations');

COMMIT;
