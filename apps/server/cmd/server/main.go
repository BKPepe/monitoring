// Command server je HTTP API monitorovací platformy Blood Kings.
package main

import (
	"context"
	"errors"
	"log/slog"
	"net/http"
	"os"
	"os/signal"
	"syscall"
	"time"

	"github.com/BKPepe/monitoring/apps/server/internal/api"
	"github.com/BKPepe/monitoring/apps/server/internal/config"
	"github.com/BKPepe/monitoring/apps/server/internal/store"
)

func main() {
	if err := run(); err != nil {
		slog.Error("server skončil chybou", "err", err)
		os.Exit(1)
	}
}

func run() error {
	cfg, err := config.Load()
	if err != nil {
		return err
	}

	setupLogging(cfg)

	ctx, stop := signal.NotifyContext(context.Background(), syscall.SIGINT, syscall.SIGTERM)
	defer stop()

	st, err := store.Open(ctx, cfg.DatabaseURL)
	if err != nil {
		return err
	}
	defer st.Close()

	go runMaintenance(ctx, st)

	srv := &http.Server{
		Addr:    cfg.Addr,
		Handler: api.NewServer(cfg, st).Handler(),

		// Timeouty jsou povinné, ne volitelné: bez nich udrží pomalý klient
		// spojení otevřené libovolně dlouho (Slowloris).
		ReadHeaderTimeout: 5 * time.Second,
		ReadTimeout:       30 * time.Second,
		WriteTimeout:      30 * time.Second,
		IdleTimeout:       90 * time.Second,
		MaxHeaderBytes:    1 << 16,
	}

	errCh := make(chan error, 1)
	go func() {
		slog.Info("server naslouchá", "addr", cfg.Addr, "env", cfg.Environment)
		if err := srv.ListenAndServe(); err != nil && !errors.Is(err, http.ErrServerClosed) {
			errCh <- err
		}
	}()

	select {
	case err := <-errCh:
		return err
	case <-ctx.Done():
		slog.Info("ukončuji, dobíhají otevřené požadavky")
	}

	// Rozběhnuté požadavky dostanou čas doběhnout, ať se uprostřed zápisu
	// neutne spojení.
	shutdownCtx, cancel := context.WithTimeout(context.Background(), 20*time.Second)
	defer cancel()

	return srv.Shutdown(shutdownCtx)
}

func setupLogging(cfg *config.Config) {
	level := slog.LevelDebug
	if cfg.IsProduction() {
		level = slog.LevelInfo
	}

	var handler slog.Handler
	if cfg.IsProduction() {
		// Strojově čitelné logy pro sběr; ve vývoji čitelnější text.
		handler = slog.NewJSONHandler(os.Stdout, &slog.HandlerOptions{Level: level})
	} else {
		handler = slog.NewTextHandler(os.Stdout, &slog.HandlerOptions{Level: level})
	}

	slog.SetDefault(slog.New(handler))
}

// runMaintenance uklízí prošlé relace a staré pokusy o přihlášení.
//
// Bez úklidu obě tabulky rostou donekonečna — v původním systému to řešil
// cron, tady stačí goroutina.
func runMaintenance(ctx context.Context, st *store.Store) {
	ticker := time.NewTicker(15 * time.Minute)
	defer ticker.Stop()

	for {
		select {
		case <-ctx.Done():
			return
		case <-ticker.C:
			if n, err := st.PurgeExpiredSessions(ctx); err != nil {
				slog.Error("úklid relací selhal", "err", err)
			} else if n > 0 {
				slog.Debug("smazány prošlé relace", "count", n)
			}

			// Pokusy o přihlášení mají hodnotu jen krátkodobě; 30 dní
			// stačí i na zpětné vyšetřování.
			if _, err := st.PurgeOldLoginAttempts(ctx, 30*24*time.Hour); err != nil {
				slog.Error("úklid pokusů o přihlášení selhal", "err", err)
			}
		}
	}
}
