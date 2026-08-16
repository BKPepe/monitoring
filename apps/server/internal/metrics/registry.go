package metrics

import (
	"errors"
	"fmt"
)

var ErrUnknownMetric = errors.New("neznámá metrika")

type MetricDef struct {
	Key      string
	Column   string
	Unit     string
	Label    string
	Predict  bool // Zda je metrika vhodná pro lineární regresi (hdd, ram, inode_usage)
}

var metricRegistry = map[string]MetricDef{
	"cpu":            {Key: "cpu", Column: "cpu_usage", Unit: "%", Label: "Vytížení CPU", Predict: false},
	"ram":            {Key: "ram", Column: "ram_usage", Unit: "%", Label: "Vytížení RAM", Predict: true},
	"hdd":            {Key: "hdd", Column: "hdd_usage", Unit: "%", Label: "Vytížení disku (HDD)", Predict: true},
	"net":            {Key: "net", Column: "net_usage", Unit: "KB/s", Label: "Síťový provoz", Predict: false},
	"load1":          {Key: "load1", Column: "load_avg_1", Unit: "", Label: "Průměrná zátěž (1 min)", Predict: false},
	"load5":          {Key: "load5", Column: "load_avg_5", Unit: "", Label: "Průměrná zátěž (5 min)", Predict: false},
	"load15":         {Key: "load15", Column: "load_avg_15", Unit: "", Label: "Průměrná zátěž (15 min)", Predict: false},
	"cpu_steal":      {Key: "cpu_steal", Column: "cpu_steal", Unit: "%", Label: "CPU Steal", Predict: false},
	"swap":           {Key: "swap", Column: "swap_usage", Unit: "%", Label: "Vytížení SWAPu", Predict: false},
	"disk_io_read":   {Key: "disk_io_read", Column: "disk_io_read_kbps", Unit: "KB/s", Label: "Čtení z disku", Predict: false},
	"disk_io_write":  {Key: "disk_io_write", Column: "disk_io_write_kbps", Unit: "KB/s", Label: "Zápis na disk", Predict: false},
	"net_errors":     {Key: "net_errors", Column: "net_errors", Unit: "chyb", Label: "Síťové chyby", Predict: false},
	"iowait":         {Key: "iowait", Column: "iowait_pct", Unit: "%", Label: "I/O Wait", Predict: false},
	"inode_usage":    {Key: "inode_usage", Column: "inode_usage_pct", Unit: "%", Label: "Využití inodů", Predict: true},
	"ts_clients":     {Key: "ts_clients", Column: "ts_clients_online", Unit: "online", Label: "TeamSpeak klienti", Predict: false},
	"ts_process_cpu": {Key: "ts_process_cpu", Column: "ts_process_cpu", Unit: "%", Label: "TeamSpeak CPU", Predict: false},
	"ts_process_ram": {Key: "ts_process_ram", Column: "ts_process_ram", Unit: "MB", Label: "TeamSpeak RAM", Predict: false},
	"net_ipv4":       {Key: "net_ipv4", Column: "net_ipv4_kbps", Unit: "KB/s", Label: "IPv4 provoz", Predict: false},
	"net_ipv6":       {Key: "net_ipv6", Column: "net_ipv6_kbps", Unit: "KB/s", Label: "IPv6 provoz", Predict: false},
}

// GetMetricDef navrátí definici metriky nebo chybu ErrUnknownMetric.
func GetMetricDef(key string) (MetricDef, error) {
	def, exists := metricRegistry[key]
	if !exists {
		return MetricDef{}, fmt.Errorf("%w: %s", ErrUnknownMetric, key)
	}
	return def, nil
}

// GetAllMetrics navrátí kopii všech registrovaných metrik.
func GetAllMetrics() map[string]MetricDef {
	res := make(map[string]MetricDef, len(metricRegistry))
	for k, v := range metricRegistry {
		res[k] = v
	}
	return res
}
