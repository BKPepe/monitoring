-- 0003_metric_rollups.sql — denní rollupy metrik pro dlouhodobou historii bez zpomalení DB.

BEGIN;

CREATE TABLE metric_daily_rollups (
    id          BIGINT      GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    monitor_id  BIGINT      NOT NULL REFERENCES monitors(id) ON DELETE CASCADE,
    metric_name TEXT        NOT NULL,
    date        DATE        NOT NULL,
    avg_val     DOUBLE PRECISION NOT NULL,
    max_val     DOUBLE PRECISION NOT NULL,
    min_val     DOUBLE PRECISION NOT NULL,
    p95_val     DOUBLE PRECISION NOT NULL,
    sample_count INT        NOT NULL DEFAULT 0,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT metric_daily_rollups_key UNIQUE (monitor_id, metric_name, date)
);

CREATE INDEX metric_daily_rollups_search_idx ON metric_daily_rollups (monitor_id, metric_name, date DESC);

INSERT INTO schema_migrations (version) VALUES ('0003_metric_rollups');

COMMIT;
