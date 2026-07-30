package httpx

import (
	"context"
	"crypto/rand"
	"encoding/hex"
	"log/slog"
	"net"
	"net/http"
	"net/netip"
	"slices"
	"strings"
	"time"
)

type ctxKey int

const (
	ctxRequestID ctxKey = iota
	ctxClientIP
)

// RequestID vrátí identifikátor požadavku pro korelaci logů.
func RequestID(ctx context.Context) string {
	id, _ := ctx.Value(ctxRequestID).(string)
	return id
}

// ClientIP vrátí adresu klienta, pokud se ji podařilo určit.
func ClientIP(ctx context.Context) *netip.Addr {
	ip, _ := ctx.Value(ctxClientIP).(*netip.Addr)
	return ip
}

// Chain skládá middleware zleva doprava (první v seznamu je vnější).
func Chain(h http.Handler, mws ...func(http.Handler) http.Handler) http.Handler {
	for i := len(mws) - 1; i >= 0; i-- {
		h = mws[i](h)
	}
	return h
}

// WithRequestID přidělí požadavku identifikátor a vrátí ho v hlavičce.
func WithRequestID(next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		buf := make([]byte, 8)
		_, _ = rand.Read(buf)
		id := hex.EncodeToString(buf)

		w.Header().Set("X-Request-Id", id)
		next.ServeHTTP(w, r.WithContext(context.WithValue(r.Context(), ctxRequestID, id)))
	})
}

// WithClientIP zjistí adresu klienta.
//
// trustProxy se zapíná jen tehdy, když před aplikací opravdu stojí reverzní
// proxy. Jinak by si útočník mohl poslat vlastní X-Forwarded-For a obejít
// tím omezení počtu pokusů o přihlášení.
func WithClientIP(trustProxy bool) func(http.Handler) http.Handler {
	return func(next http.Handler) http.Handler {
		return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
			var addr *netip.Addr

			if trustProxy {
				if fwd := r.Header.Get("X-Forwarded-For"); fwd != "" {
					// První položka je původní klient; zbytek jsou proxy.
					first := strings.TrimSpace(strings.Split(fwd, ",")[0])
					if parsed, err := netip.ParseAddr(first); err == nil {
						addr = &parsed
					}
				}
			}

			if addr == nil {
				if host, _, err := net.SplitHostPort(r.RemoteAddr); err == nil {
					if parsed, err := netip.ParseAddr(host); err == nil {
						addr = &parsed
					}
				}
			}

			next.ServeHTTP(w, r.WithContext(context.WithValue(r.Context(), ctxClientIP, addr)))
		})
	}
}

// WithSecurityHeaders nastaví hlavičky, které v původním systému chyběly.
func WithSecurityHeaders(isProduction bool) func(http.Handler) http.Handler {
	return func(next http.Handler) http.Handler {
		return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
			h := w.Header()
			h.Set("X-Content-Type-Options", "nosniff")
			h.Set("X-Frame-Options", "DENY")
			h.Set("Referrer-Policy", "no-referrer")
			// API nikdy nevrací HTML, takže stačí zakázat úplně všechno.
			h.Set("Content-Security-Policy", "default-src 'none'; frame-ancestors 'none'")
			h.Set("Cross-Origin-Resource-Policy", "same-origin")
			h.Set("Permissions-Policy", "geolocation=(), camera=(), microphone=()")
			// Odpovědi obsahují osobní údaje — nikdy do sdílené cache.
			h.Set("Cache-Control", "no-store")

			if isProduction {
				h.Set("Strict-Transport-Security", "max-age=63072000; includeSubDomains")
			}

			next.ServeHTTP(w, r)
		})
	}
}

// WithCORS povoluje jen výslovně uvedené origins.
//
// Wildcard tu není a být nemůže: odpovědi jsou za přihlášením a prohlížeč
// `*` v kombinaci s credentials stejně odmítne.
func WithCORS(allowed []string) func(http.Handler) http.Handler {
	return func(next http.Handler) http.Handler {
		return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
			origin := r.Header.Get("Origin")

			if origin != "" && slices.Contains(allowed, origin) {
				h := w.Header()
				h.Set("Access-Control-Allow-Origin", origin)
				h.Set("Access-Control-Allow-Credentials", "true")
				h.Set("Access-Control-Allow-Headers", "Content-Type, X-CSRF-Token")
				h.Set("Access-Control-Allow-Methods", "GET, POST, PATCH, DELETE, OPTIONS")
				h.Set("Access-Control-Max-Age", "86400")
				h.Add("Vary", "Origin")
			}

			if r.Method == http.MethodOptions {
				w.WriteHeader(http.StatusNoContent)
				return
			}

			next.ServeHTTP(w, r)
		})
	}
}

// statusRecorder zachytí stavový kód pro log.
type statusRecorder struct {
	http.ResponseWriter
	status int
}

func (s *statusRecorder) WriteHeader(code int) {
	s.status = code
	s.ResponseWriter.WriteHeader(code)
}

// WithLogging zapisuje strukturovaný log ke každému požadavku.
func WithLogging(next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		start := time.Now()
		rec := &statusRecorder{ResponseWriter: w, status: http.StatusOK}

		next.ServeHTTP(rec, r)

		slog.Info("request",
			"method", r.Method,
			"path", r.URL.Path,
			"status", rec.status,
			"duration_ms", time.Since(start).Milliseconds(),
			"request_id", RequestID(r.Context()),
		)
	})
}

// WithRecovery zabrání tomu, aby panika v jednom požadavku shodila server.
func WithRecovery(next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		defer func() {
			if rec := recover(); rec != nil {
				slog.Error("panika v handleru",
					"panic", rec,
					"path", r.URL.Path,
					"request_id", RequestID(r.Context()),
				)
				Fail(w, http.StatusInternalServerError, "internal_error", "Došlo k neočekávané chybě.")
			}
		}()

		next.ServeHTTP(w, r)
	})
}
