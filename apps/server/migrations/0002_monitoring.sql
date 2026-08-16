-- 0002_monitoring.sql — monitorovací doména, metriky, logy a Remote Actions.

BEGIN;

CREATE TABLE monitors (
    id                      BIGINT      GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    name                    TEXT        NOT NULL,
    type                    TEXT        NOT NULL DEFAULT 'vps',
    target                  TEXT        NOT NULL DEFAULT '',
    status                  TEXT        NOT NULL DEFAULT 'unknown',
    agent_key               TEXT        NOT NULL UNIQUE,
    cpu_threshold           DOUBLE PRECISION NOT NULL DEFAULT 90.0,
    ram_threshold           DOUBLE PRECISION NOT NULL DEFAULT 95.0,
    hdd_threshold           DOUBLE PRECISION NOT NULL DEFAULT 90.0,
    monitored_processes     TEXT,
    remote_actions_enabled  BOOLEAN     NOT NULL DEFAULT false,
    allowed_actions         TEXT,
    maintenance_start       TIMESTAMPTZ,
    maintenance_end         TIMESTAMPTZ,
    maintenance_description TEXT,
    last_checked            TIMESTAMPTZ,
    last_status_change      TIMESTAMPTZ,
    last_details            JSONB       NOT NULL DEFAULT '{}'::jsonb,
    created_at              TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at              TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX monitors_agent_key_idx ON monitors (agent_key);
CREATE INDEX monitors_status_idx    ON monitors (status);

CREATE TABLE vps_metrics (
    id                  BIGINT      GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    monitor_id          BIGINT      NOT NULL REFERENCES monitors(id) ON DELETE CASCADE,
    recorded_at         TIMESTAMPTZ NOT NULL DEFAULT now(),
    cpu_usage           DOUBLE PRECISION,
    ram_usage           DOUBLE PRECISION,
    hdd_usage           DOUBLE PRECISION,
    net_usage           DOUBLE PRECISION,
    load_avg_1          DOUBLE PRECISION,
    load_avg_5          DOUBLE PRECISION,
    load_avg_15         DOUBLE PRECISION,
    cpu_steal           DOUBLE PRECISION,
    swap_usage          DOUBLE PRECISION,
    disk_io_read_kbps   DOUBLE PRECISION,
    disk_io_write_kbps  DOUBLE PRECISION,
    net_errors          BIGINT,
    ts_clients_online   INT,
    ts_clients_max      INT,
    ts_process_cpu      DOUBLE PRECISION,
    ts_process_ram      DOUBLE PRECISION,
    iowait_pct          DOUBLE PRECISION,
    inode_usage_pct     DOUBLE PRECISION,
    zombie_count        INT,
    fork_rate           INT,
    temperature_c       DOUBLE PRECISION,
    wifi_clients_total  INT,
    conntrack_pct       DOUBLE PRECISION,
    net_ipv4_kbps       DOUBLE PRECISION,
    net_ipv6_kbps       DOUBLE PRECISION
);

CREATE INDEX vps_metrics_monitor_recorded_idx ON vps_metrics (monitor_id, recorded_at DESC);

CREATE TABLE monitor_logs (
    id              BIGINT      GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    monitor_id      BIGINT      NOT NULL REFERENCES monitors(id) ON DELETE CASCADE,
    status          TEXT        NOT NULL,
    response_time   INT         NOT NULL DEFAULT 0,
    error_message   TEXT,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX monitor_logs_monitor_created_idx ON monitor_logs (monitor_id, created_at DESC);

CREATE TABLE agent_actions (
    id              BIGINT      GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    monitor_id      BIGINT      NOT NULL REFERENCES monitors(id) ON DELETE CASCADE,
    action_type     TEXT        NOT NULL,
    status          TEXT        NOT NULL DEFAULT 'pending',
    result_message  TEXT,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    executed_at     TIMESTAMPTZ
);

CREATE INDEX agent_actions_monitor_status_idx ON agent_actions (monitor_id, status);

CREATE TABLE monitor_interface_traffic (
    id                  BIGINT      GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    monitor_id          BIGINT      NOT NULL REFERENCES monitors(id) ON DELETE CASCADE,
    iface               TEXT        NOT NULL,
    date                DATE        NOT NULL,
    rx_bytes_total      DOUBLE PRECISION NOT NULL DEFAULT 0,
    tx_bytes_total      DOUBLE PRECISION NOT NULL DEFAULT 0,
    rx_packets_total    BIGINT      NOT NULL DEFAULT 0,
    tx_packets_total    BIGINT      NOT NULL DEFAULT 0,
    last_rx_bytes       DOUBLE PRECISION NOT NULL DEFAULT 0,
    last_tx_bytes       DOUBLE PRECISION NOT NULL DEFAULT 0,
    last_rx_packets     BIGINT      NOT NULL DEFAULT 0,
    last_tx_packets     BIGINT      NOT NULL DEFAULT 0,
    updated_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT monitor_iface_date_key UNIQUE (monitor_id, iface, date)
);

CREATE TABLE settings (
    key         TEXT PRIMARY KEY,
    value       TEXT NOT NULL,
    updated_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TRIGGER monitors_set_updated_at
    BEFORE UPDATE ON monitors
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();

CREATE TRIGGER monitor_interface_traffic_set_updated_at
    BEFORE UPDATE ON monitor_interface_traffic
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();

CREATE TRIGGER settings_set_updated_at
    BEFORE UPDATE ON settings
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();

INSERT INTO schema_migrations (version) VALUES ('0002_monitoring');

COMMIT;
