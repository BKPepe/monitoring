package store

import (
	"context"
	"crypto/rand"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"strings"
	"time"
)

type Monitor struct {
	ID                     int64          `json:"id"`
	Name                   string         `json:"name"`
	Type                   string         `json:"type"`
	Target                 string         `json:"target"`
	Status                 string         `json:"status"`
	AgentKey               string         `json:"agent_key"`
	CPUThreshold           float64        `json:"cpu_threshold"`
	RAMThreshold           float64        `json:"ram_threshold"`
	HDDThreshold           float64        `json:"hdd_threshold"`
	MonitoredProcesses     string         `json:"monitored_processes,omitempty"`
	RemoteActionsEnabled   bool           `json:"remote_actions_enabled"`
	AllowedActions         string         `json:"allowed_actions,omitempty"`
	MaintenanceStart       *time.Time     `json:"maintenance_start,omitempty"`
	MaintenanceEnd         *time.Time     `json:"maintenance_end,omitempty"`
	MaintenanceDescription string         `json:"maintenance_description,omitempty"`
	LastChecked            *time.Time     `json:"last_checked,omitempty"`
	LastStatusChange       *time.Time     `json:"last_status_change,omitempty"`
	LastDetails            map[string]any `json:"last_details"`
	CreatedAt              time.Time      `json:"created_at"`
	UpdatedAt              time.Time      `json:"updated_at"`
}

type AgentAction struct {
	ID            int64      `json:"id"`
	MonitorID     int64      `json:"monitor_id"`
	ActionType    string     `json:"action_type"`
	Status        string     `json:"status"`
	ResultMessage string     `json:"result_message,omitempty"`
	CreatedAt     time.Time  `json:"created_at"`
	ExecutedAt    *time.Time `json:"executed_at,omitempty"`
}

type InterfaceItem struct {
	Iface     string  `json:"iface"`
	RxBytes   float64 `json:"rx_bytes"`
	TxBytes   float64 `json:"tx_bytes"`
	RxPackets int64   `json:"rx_packets"`
	TxPackets int64   `json:"tx_packets"`
}

type ActionResultItem struct {
	ActionID int64  `json:"action_id"`
	Status   string `json:"status"`
	Message  string `json:"message"`
}

type IngestParams struct {
	AgentKey         string
	CPU              *float64
	RAM              *float64
	HDD              *float64
	Net              *float64
	Load1            *float64
	Load5            *float64
	Load15           *float64
	CPUSteal         *float64
	Swap             *float64
	DiskIORead       *float64
	DiskIOWrite      *float64
	NetErrors        *int64
	IOWait           *float64
	InodeUsage       *float64
	ForkRate         *int64
	Temperature      *float64
	ZombieCount      *int64
	WifiClients      *int64
	ConntrackPct     *float64
	NetIPv4Kbps      *float64
	NetIPv6Kbps      *float64
	TS3ClientsOnline *int64
	TS3ClientsMax    *int64
	TS3ProcessCPU    *float64
	TS3ProcessRAM    *float64
	Hostname         string
	Kernel           string
	WANIPv4          string
	Interfaces       []InterfaceItem
	Details          map[string]any
	ActionResult     *ActionResultItem
}

func (s *Store) GetSetting(ctx context.Context, key string) (string, error) {
	var val string
	err := s.pool.QueryRow(ctx, `SELECT value FROM settings WHERE key = $1`, key).Scan(&val)
	if err != nil {
		return "", normalizeErr(err)
	}
	return val, nil
}

func (s *Store) GetMonitorByAgentKey(ctx context.Context, agentKey string) (*Monitor, error) {
	query := `
		SELECT id, name, type, target, status, agent_key, cpu_threshold, ram_threshold, hdd_threshold,
		       COALESCE(monitored_processes, ''), remote_actions_enabled, COALESCE(allowed_actions, ''),
		       maintenance_start, maintenance_end, COALESCE(maintenance_description, ''),
		       last_checked, last_status_change, last_details, created_at, updated_at
		FROM monitors
		WHERE agent_key = $1
		LIMIT 1`

	var m Monitor
	var lastDetailsJSON []byte

	err := s.pool.QueryRow(ctx, query, agentKey).Scan(
		&m.ID, &m.Name, &m.Type, &m.Target, &m.Status, &m.AgentKey,
		&m.CPUThreshold, &m.RAMThreshold, &m.HDDThreshold,
		&m.MonitoredProcesses, &m.RemoteActionsEnabled, &m.AllowedActions,
		&m.MaintenanceStart, &m.MaintenanceEnd, &m.MaintenanceDescription,
		&m.LastChecked, &m.LastStatusChange, &lastDetailsJSON,
		&m.CreatedAt, &m.UpdatedAt,
	)
	if err != nil {
		return nil, normalizeErr(err)
	}

	m.LastDetails = make(map[string]any)
	if len(lastDetailsJSON) > 0 {
		_ = json.Unmarshal(lastDetailsJSON, &m.LastDetails)
	}

	return &m, nil
}

func (s *Store) RegisterAgent(ctx context.Context, name string, agentType string) (*Monitor, error) {
	keyBytes := make([]byte, 16)
	if _, err := rand.Read(keyBytes); err != nil {
		return nil, fmt.Errorf("generování agent_key: %w", err)
	}
	agentKey := hex.EncodeToString(keyBytes)

	if name == "" {
		name = fmt.Sprintf("VPS Agent %s", time.Now().Format("2006-01-02 15:04"))
	}
	if agentType != "openwrt" {
		agentType = "vps"
	}

	query := `
		INSERT INTO monitors (name, type, target, status, agent_key, cpu_threshold, ram_threshold, hdd_threshold)
		VALUES ($1, $2, 'Local VPS Agent', 'unknown', $3, 90, 90, 95)
		RETURNING id, name, type, target, status, agent_key, cpu_threshold, ram_threshold, hdd_threshold,
		          COALESCE(monitored_processes, ''), remote_actions_enabled, COALESCE(allowed_actions, ''),
		          maintenance_start, maintenance_end, COALESCE(maintenance_description, ''),
		          last_checked, last_status_change, last_details, created_at, updated_at`

	var m Monitor
	var lastDetailsJSON []byte

	err := s.pool.QueryRow(ctx, query, name, agentType, agentKey).Scan(
		&m.ID, &m.Name, &m.Type, &m.Target, &m.Status, &m.AgentKey,
		&m.CPUThreshold, &m.RAMThreshold, &m.HDDThreshold,
		&m.MonitoredProcesses, &m.RemoteActionsEnabled, &m.AllowedActions,
		&m.MaintenanceStart, &m.MaintenanceEnd, &m.MaintenanceDescription,
		&m.LastChecked, &m.LastStatusChange, &lastDetailsJSON,
		&m.CreatedAt, &m.UpdatedAt,
	)
	if err != nil {
		return nil, normalizeErr(err)
	}

	m.LastDetails = make(map[string]any)
	if len(lastDetailsJSON) > 0 {
		_ = json.Unmarshal(lastDetailsJSON, &m.LastDetails)
	}

	return &m, nil
}

func (s *Store) SaveAgentIngest(ctx context.Context, m *Monitor, params IngestParams) (*Monitor, *AgentAction, error) {
	tx, err := s.pool.Begin(ctx)
	if err != nil {
		return nil, nil, fmt.Errorf("zahájení transakce: %w", err)
	}
	defer func() { _ = tx.Rollback(ctx) }()

	now := time.Now()

	// 1. Zpracování výsledků akce (pokud agent vrací action_result)
	if params.ActionResult != nil && params.ActionResult.ActionID > 0 {
		_, _ = tx.Exec(ctx, `
			UPDATE agent_actions
			SET status = $1, result_message = $2, executed_at = $3
			WHERE id = $4 AND monitor_id = $5`,
			params.ActionResult.Status, params.ActionResult.Message, now, params.ActionResult.ActionID, m.ID,
		)
	}

	// 2. Vyhodnocení stavu (process check & maintenance)
	newStatus := "up"
	var errorMsg *string

	// Kontrola požadovaných procesů
	var missingProcesses []string
	if m.MonitoredProcesses != "" {
		monitoredList := strings.Split(m.MonitoredProcesses, ",")
		runningProcs, _ := params.Details["processes"].([]any)
		runningSet := make(map[string]bool)
		for _, rp := range runningProcs {
			if sVal, ok := rp.(string); ok {
				runningSet[strings.TrimSpace(sVal)] = true
			}
		}

		for _, mp := range monitoredList {
			cleanMP := strings.TrimSpace(mp)
			if cleanMP != "" && !runningSet[cleanMP] {
				missingProcesses = append(missingProcesses, cleanMP)
			}
		}
	}

	if len(missingProcesses) > 0 {
		newStatus = "down"
		msg := "Chybí běžící proces: " + strings.Join(missingProcesses, ", ")
		errorMsg = &msg
	}

	// Kontrola plánované údržby
	if m.MaintenanceStart != nil && m.MaintenanceEnd != nil {
		if now.After(*m.MaintenanceStart) && now.Before(*m.MaintenanceEnd) {
			newStatus = "maintenance"
			msg := m.MaintenanceDescription
			if msg == "" {
				msg = "Plánovaná údržba"
			}
			errorMsg = &msg
		}
	}

	// 3. Mergování last_details
	mergedDetails := make(map[string]any)
	for k, v := range m.LastDetails {
		mergedDetails[k] = v
	}
	for k, v := range params.Details {
		mergedDetails[k] = v
	}
	mergedDetails["agent_last_seen"] = now.Unix()
	mergedDetails["missing_processes"] = missingProcesses

	detailsJSON, err := json.Marshal(mergedDetails)
	if err != nil {
		return nil, nil, fmt.Errorf("serializace details JSON: %w", err)
	}

	// Auto target update pokud je prázdný
	targetVal := m.Target
	if (m.Type == "vps" || m.Type == "openwrt") && strings.TrimSpace(m.Target) == "" || m.Target == "Local VPS Agent" {
		if m.Type == "openwrt" && params.WANIPv4 != "" {
			targetVal = params.WANIPv4
		} else if params.Hostname != "" {
			targetVal = params.Hostname
		}
	}

	// Update monitor záznamu
	statusChanged := m.Status != newStatus
	if statusChanged {
		_, err = tx.Exec(ctx, `
			UPDATE monitors
			SET status = $1, target = $2, last_checked = $3, last_status_change = $3, last_details = $4
			WHERE id = $5`,
			newStatus, targetVal, now, detailsJSON, m.ID,
		)
	} else {
		_, err = tx.Exec(ctx, `
			UPDATE monitors
			SET target = $1, last_checked = $2, last_details = $3
			WHERE id = $4`,
			targetVal, now, detailsJSON, m.ID,
		)
	}
	if err != nil {
		return nil, nil, fmt.Errorf("aktualizace monitoru: %w", err)
	}

	// 4. Vložení metrik do vps_metrics
	_, err = tx.Exec(ctx, `
		INSERT INTO vps_metrics (
			monitor_id, recorded_at, cpu_usage, ram_usage, hdd_usage, net_usage,
			load_avg_1, load_avg_5, load_avg_15, cpu_steal, swap_usage,
			disk_io_read_kbps, disk_io_write_kbps, net_errors,
			ts_clients_online, ts_clients_max, ts_process_cpu, ts_process_ram,
			iowait_pct, inode_usage_pct, zombie_count, fork_rate, temperature_c,
			wifi_clients_total, conntrack_pct, net_ipv4_kbps, net_ipv6_kbps
		) VALUES (
			$1, $2, $3, $4, $5, $6,
			$7, $8, $9, $10, $11,
			$12, $13, $14,
			$15, $16, $17, $18,
			$19, $20, $21, $22, $23,
			$24, $25, $26, $27
		)`,
		m.ID, now, params.CPU, params.RAM, params.HDD, params.Net,
		params.Load1, params.Load5, params.Load15, params.CPUSteal, params.Swap,
		params.DiskIORead, params.DiskIOWrite, params.NetErrors,
		params.TS3ClientsOnline, params.TS3ClientsMax, params.TS3ProcessCPU, params.TS3ProcessRAM,
		params.IOWait, params.InodeUsage, params.ZombieCount, params.ForkRate, params.Temperature,
		params.WifiClients, params.ConntrackPct, params.NetIPv4Kbps, params.NetIPv6Kbps,
	)
	if err != nil {
		return nil, nil, fmt.Errorf("zápis metrik: %w", err)
	}

	// 5. Vložení logu kontroly do monitor_logs
	_, err = tx.Exec(ctx, `
		INSERT INTO monitor_logs (monitor_id, status, response_time, error_message, created_at)
		VALUES ($1, $2, 0, $3, $4)`,
		m.ID, newStatus, errorMsg, now,
	)
	if err != nil {
		return nil, nil, fmt.Errorf("zápis logu kontroly: %w", err)
	}

	// 6. Aktualizace síťových rozhraní (delta kumulativního provozu)
	todayStr := now.Format("2006-01-02")
	for _, ifitem := range params.Interfaces {
		ifname := strings.TrimSpace(ifitem.Iface)
		if ifname == "" || ifname == "lo" || ifname == "ifb0" || ifname == "ifb1" {
			continue
		}

		var prevRxB, prevTxB float64
		var prevRxP, prevTxP int64

		row := tx.QueryRow(ctx, `
			SELECT last_rx_bytes, last_tx_bytes, last_rx_packets, last_tx_packets
			FROM monitor_interface_traffic
			WHERE monitor_id = $1 AND iface = $2
			ORDER BY date DESC LIMIT 1`, m.ID, ifname)

		errRow := row.Scan(&prevRxB, &prevTxB, &prevRxP, &prevTxP)

		dRxB := ifitem.RxBytes
		dTxB := ifitem.TxBytes
		dRxP := ifitem.RxPackets
		dTxP := ifitem.TxPackets

		if errRow == nil {
			if ifitem.RxBytes >= prevRxB {
				dRxB = ifitem.RxBytes - prevRxB
			}
			if ifitem.TxBytes >= prevTxB {
				dTxB = ifitem.TxBytes - prevTxB
			}
			if ifitem.RxPackets >= prevRxP {
				dRxP = ifitem.RxPackets - prevRxP
			}
			if ifitem.TxPackets >= prevTxP {
				dTxP = ifitem.TxPackets - prevTxP
			}
		}

		_, _ = tx.Exec(ctx, `
			INSERT INTO monitor_interface_traffic (
				monitor_id, iface, date, rx_bytes_total, tx_bytes_total, rx_packets_total, tx_packets_total,
				last_rx_bytes, last_tx_bytes, last_rx_packets, last_tx_packets, updated_at
			) VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11, $12)
			ON CONFLICT (monitor_id, iface, date) DO UPDATE SET
				rx_bytes_total = monitor_interface_traffic.rx_bytes_total + EXCLUDED.rx_bytes_total,
				tx_bytes_total = monitor_interface_traffic.tx_bytes_total + EXCLUDED.tx_bytes_total,
				rx_packets_total = monitor_interface_traffic.rx_packets_total + EXCLUDED.rx_packets_total,
				tx_packets_total = monitor_interface_traffic.tx_packets_total + EXCLUDED.tx_packets_total,
				last_rx_bytes = EXCLUDED.last_rx_bytes,
				last_tx_bytes = EXCLUDED.last_tx_bytes,
				last_rx_packets = EXCLUDED.last_rx_packets,
				last_tx_packets = EXCLUDED.last_tx_packets,
				updated_at = EXCLUDED.updated_at`,
			m.ID, ifname, todayStr, dRxB, dTxB, dRxP, dTxP,
			ifitem.RxBytes, ifitem.TxBytes, ifitem.RxPackets, ifitem.TxPackets, now,
		)
	}

	// 7. Kontrola nevyřízené Remote Action
	var pendingAction *AgentAction
	if m.RemoteActionsEnabled && strings.TrimSpace(m.AllowedActions) != "" {
		allowedList := strings.Split(m.AllowedActions, ",")
		allowedMap := make(map[string]bool)
		for _, a := range allowedList {
			allowedMap[strings.TrimSpace(a)] = true
		}

		var act AgentAction
		var actCreatedAt time.Time
		var actExecutedAt *time.Time
		rowAct := tx.QueryRow(ctx, `
			SELECT id, monitor_id, action_type, status, COALESCE(result_message, ''), created_at, executed_at
			FROM agent_actions
			WHERE monitor_id = $1 AND status = 'pending'
			ORDER BY id ASC LIMIT 1`, m.ID)

		if errAct := rowAct.Scan(&act.ID, &act.MonitorID, &act.ActionType, &act.Status, &act.ResultMessage, &actCreatedAt, &actExecutedAt); errAct == nil {
			if allowedMap[act.ActionType] {
				act.CreatedAt = actCreatedAt
				act.ExecutedAt = actExecutedAt
				pendingAction = &act

				_, _ = tx.Exec(ctx, `UPDATE agent_actions SET status = 'sent' WHERE id = $1`, act.ID)
			}
		}
	}

	if err := tx.Commit(ctx); err != nil {
		return nil, nil, fmt.Errorf("potvrzení transakce: %w", err)
	}

	// Aktualizace lokálního objektu monitoru pro vrácení
	m.Target = targetVal
	m.Status = newStatus
	m.LastChecked = &now
	if statusChanged {
		m.LastStatusChange = &now
	}
	m.LastDetails = mergedDetails

	return m, pendingAction, nil
}
