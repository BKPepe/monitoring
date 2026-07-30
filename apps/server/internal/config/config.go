// Package config načítá a ověřuje nastavení serveru.
package config

import (
	"encoding/hex"
	"fmt"
	"os"
	"strconv"
	"strings"
	"time"
)

type Config struct {
	Addr        string
	DatabaseURL string

	// Klíč pro šifrování TOTP secretů v databázi (32 bajtů, hex).
	SecretKey []byte

	// Origins povolené pro CORS s přihlašovací cookie. Prázdné = jen
	// same-origin, což je výchozí a nejbezpečnější stav.
	AllowedOrigins []string

	// Cookie jen přes HTTPS. Vypnout smí výhradně lokální vývoj.
	SecureCookies bool

	// Věřit hlavičce X-Forwarded-For. Zapnout jen tehdy, když před aplikací
	// opravdu stojí reverzní proxy — jinak si útočník podvrhne vlastní IP
	// a obejde omezení počtu pokusů o přihlášení.
	TrustProxy bool

	SessionTTL     time.Duration
	SessionIdleTTL time.Duration

	// Ochrana přihlašování.
	LoginMaxAttempts int
	LoginWindow      time.Duration
	LoginLockout     time.Duration

	Environment string
}

func Load() (*Config, error) {
	loadDotEnv()

	cfg := &Config{
		Addr:        envOr("BK_ADDR", ":8090"),
		DatabaseURL: os.Getenv("BK_DATABASE_URL"),
		Environment: envOr("BK_ENV", "development"),
	}

	if cfg.DatabaseURL == "" {
		return nil, fmt.Errorf("BK_DATABASE_URL je povinný (např. postgres://user:pass@host:5432/db)")
	}

	// Ve výchozím stavu zapnuté. Vypnout jde jen výslovně a jen mimo produkci —
	// jinak by cookie odešla i po nešifrovaném spojení.
	cfg.SecureCookies = envOr("BK_SECURE_COOKIES", "1") == "1"
	cfg.TrustProxy = os.Getenv("BK_TRUST_PROXY") == "1"
	if !cfg.SecureCookies && cfg.Environment == "production" {
		return nil, fmt.Errorf("BK_SECURE_COOKIES=0 není v produkci povolené")
	}

	key := os.Getenv("BK_SECRET_KEY")
	if key == "" {
		return nil, fmt.Errorf("BK_SECRET_KEY je povinný (64 hex znaků; vygenerujte: openssl rand -hex 32)")
	}
	decoded, err := hex.DecodeString(key)
	if err != nil || len(decoded) != 32 {
		return nil, fmt.Errorf("BK_SECRET_KEY musí být přesně 64 hex znaků (32 bajtů)")
	}
	cfg.SecretKey = decoded

	if origins := os.Getenv("BK_ALLOWED_ORIGINS"); origins != "" {
		for _, o := range strings.Split(origins, ",") {
			if trimmed := strings.TrimSpace(o); trimmed != "" {
				cfg.AllowedOrigins = append(cfg.AllowedOrigins, trimmed)
			}
		}
	}

	if cfg.SessionTTL, err = durationEnv("BK_SESSION_TTL_HOURS", 12, time.Hour); err != nil {
		return nil, err
	}
	if cfg.SessionIdleTTL, err = durationEnv("BK_SESSION_IDLE_MINUTES", 120, time.Minute); err != nil {
		return nil, err
	}
	if cfg.LoginWindow, err = durationEnv("BK_LOGIN_WINDOW_MINUTES", 15, time.Minute); err != nil {
		return nil, err
	}
	if cfg.LoginLockout, err = durationEnv("BK_LOGIN_LOCKOUT_MINUTES", 15, time.Minute); err != nil {
		return nil, err
	}

	cfg.LoginMaxAttempts, err = intEnv("BK_LOGIN_MAX_ATTEMPTS", 5)
	if err != nil {
		return nil, err
	}

	return cfg, nil
}

func (c *Config) IsProduction() bool { return c.Environment == "production" }

func envOr(key, fallback string) string {
	if v := os.Getenv(key); v != "" {
		return v
	}
	return fallback
}

func intEnv(key string, fallback int) (int, error) {
	raw := os.Getenv(key)
	if raw == "" {
		return fallback, nil
	}
	v, err := strconv.Atoi(raw)
	if err != nil || v <= 0 {
		return 0, fmt.Errorf("%s musí být kladné celé číslo", key)
	}
	return v, nil
}

func durationEnv(key string, fallback int, unit time.Duration) (time.Duration, error) {
	v, err := intEnv(key, fallback)
	if err != nil {
		return 0, err
	}
	return time.Duration(v) * unit, nil
}

func loadDotEnv() {
	for _, filename := range []string{".env", "config.env"} {
		data, err := os.ReadFile(filename)
		if err != nil {
			continue
		}
		lines := strings.Split(string(data), "\n")
		for _, line := range lines {
			line = strings.TrimSpace(line)
			if line == "" || strings.HasPrefix(line, "#") {
				continue
			}
			parts := strings.SplitN(line, "=", 2)
			if len(parts) == 2 {
				key := strings.TrimSpace(parts[0])
				val := strings.TrimSpace(parts[1])
				val = strings.Trim(val, `"'`)
				if os.Getenv(key) == "" {
					_ = os.Setenv(key, val)
				}
			}
		}
	}
}
