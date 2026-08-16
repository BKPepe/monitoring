package probes

import (
	"context"
	"sync"
	"time"
)

type Target struct {
	ID     int64  `json:"id"`
	Type   string `json:"type"`
	Target string `json:"target"`
	Status string `json:"status"`
}

// RunProbePool spustí měření seznamu cílů souběžně přes worker pool.
func RunProbePool(ctx context.Context, targets []Target, maxConcurrency int) []ProbeResult {
	if maxConcurrency <= 0 {
		maxConcurrency = 20
	}

	results := make([]ProbeResult, len(targets))
	jobs := make(chan int, len(targets))

	var wg sync.WaitGroup
	workersCount := maxConcurrency
	if workersCount > len(targets) {
		workersCount = len(targets)
	}

	for i := 0; i < workersCount; i++ {
		wg.Add(1)
		go func() {
			defer wg.Done()
			for idx := range jobs {
				t := targets[idx]
				results[idx] = ExecuteProbe(ctx, t)
			}
		}()
	}

	for idx := range targets {
		jobs <- idx
	}
	close(jobs)

	wg.Wait()
	return results
}

// ExecuteProbe vybere a spustí správný typ sondy podle t.Type.
func ExecuteProbe(ctx context.Context, t Target) ProbeResult {
	probeCtx, cancel := context.WithTimeout(ctx, 10*time.Second)
	defer cancel()

	switch t.Type {
	case "web":
		return CheckHTTP(probeCtx, t.ID, t.Target, 5*time.Second)
	case "port":
		return CheckSocket(probeCtx, t.ID, t.Target, 80, 5*time.Second)
	case "minecraft":
		return CheckMinecraft(probeCtx, t.ID, t.Target, 25565, 5*time.Second)
	case "teamspeak":
		return CheckTeamSpeak(probeCtx, t.ID, t.Target, 10011, 5*time.Second)
	case "discord":
		return CheckDiscord(probeCtx, t.ID, t.Target, 5*time.Second)
	case "cpanel":
		return CheckCPanel(probeCtx, t.ID, t.Target, 5*time.Second)
	default:
		// Agentní typy (vps, openwrt) se netestují přes síťovou sondu
		return ProbeResult{
			MonitorID: t.ID,
			Status:    t.Status,
		}
	}
}
