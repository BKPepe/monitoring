package api

import (
	"net/http"

	"github.com/BKPepe/monitoring/apps/server/internal/config"
	"github.com/BKPepe/monitoring/apps/server/internal/httpx"
	"github.com/BKPepe/monitoring/apps/server/internal/security"
	"github.com/BKPepe/monitoring/apps/server/internal/store"
	"github.com/BKPepe/monitoring/apps/server/internal/ws"
)

type Server struct {
	cfg      *config.Config
	store    *store.Store
	wsHub    *ws.Hub
	hashCost security.Argon2idParams
}

func NewServer(cfg *config.Config, st *store.Store) *Server {
	return &Server{
		cfg:      cfg,
		store:    st,
		wsHub:    ws.NewHub(),
		hashCost: security.DefaultParams(),
	}
}

// Handler sestaví router.
//
// Routování používá vzory z net/http (Go 1.22+) — metoda je součástí vzoru,
// takže se nemůže stát, že se GET handler omylem zavolá i na POST.
func (s *Server) Handler() http.Handler {
	mux := http.NewServeMux()

	// --- Veřejné ---------------------------------------------------------
	mux.HandleFunc("GET /api/v1/health", s.handleHealth)
	mux.HandleFunc("POST /api/v1/auth/login", s.handleLogin)
	mux.HandleFunc("GET /api/v1/auth/session", s.handleSession)
	mux.HandleFunc("POST /api/v1/agent/ingest", s.handleAgentIngest)
	mux.HandleFunc("POST /agent_api.php", s.handleAgentIngest)

	mux.HandleFunc("GET /api/v1/node", s.handleNodeAPI)
	mux.HandleFunc("POST /api/v1/node", s.handleNodeAPI)
	mux.HandleFunc("GET /node_api.php", s.handleNodeAPI)
	mux.HandleFunc("POST /node_api.php", s.handleNodeAPI)

	mux.HandleFunc("GET /api/v1/public_status", s.handlePublicStatus)
	mux.HandleFunc("GET /public_status", s.handlePublicStatus)
	mux.HandleFunc("GET /api/v1/metrics/prometheus", s.handlePrometheusMetrics)
	mux.HandleFunc("GET /metrics.php", s.handlePrometheusMetrics)
	mux.HandleFunc("GET /api/v1/badge", s.handleBadge)
	mux.HandleFunc("GET /badge.php", s.handleBadge)
	mux.HandleFunc("GET /api/v1/widget", s.handleWidget)
	mux.HandleFunc("GET /widget.php", s.handleWidget)
	mux.HandleFunc("GET /api/v1/report", s.handleReport)
	mux.HandleFunc("GET /report.php", s.handleReport)
	mux.HandleFunc("GET /api/v1/setup/status", s.handleSetupStatus)
	mux.HandleFunc("POST /api/v1/setup/install", s.handleSetupInstall)
	mux.Handle("GET /api/v1/ws", s.wsHub)

	// --- Za přihlášením --------------------------------------------------
	authed := func(h http.HandlerFunc) http.Handler {
		return s.requireAuth(h)
	}
	admin := func(h http.HandlerFunc) http.Handler {
		return s.requireAuth(s.requireAdmin(h))
	}

	mux.Handle("POST /api/v1/auth/logout", authed(s.handleLogout))
	mux.Handle("POST /api/v1/auth/logout-all", authed(s.handleLogoutAll))
	mux.Handle("POST /api/v1/auth/password", authed(s.handleChangeOwnPassword))
	mux.Handle("POST /api/v1/auth/totp/setup", authed(s.handleTOTPSetup))
	mux.HandleFunc("POST /api/v1/auth/totp/verify", s.handleTOTPVerify)

	mux.Handle("GET /api/v1/metrics/history", authed(s.handleMetricsHistory))
	mux.Handle("GET /api/v1/metrics/series", authed(s.handleMetricSeries))

	mux.Handle("GET /api/v1/users", admin(s.handleListUsers))
	mux.Handle("POST /api/v1/users", admin(s.handleCreateUser))
	mux.Handle("PATCH /api/v1/users/{id}", admin(s.handleUpdateUser))
	mux.Handle("DELETE /api/v1/users/{id}", admin(s.handleDeleteUser))
	mux.Handle("POST /api/v1/users/{id}/password", admin(s.handleSetUserPassword))

	// Pořadí je podstatné: zotavení z paniky musí být nejblíž handleru,
	// aby zachytilo i paniku v ostatních middleware.
	return httpx.Chain(mux,
		httpx.WithRequestID,
		httpx.WithLogging,
		httpx.WithSecurityHeaders(s.cfg.IsProduction()),
		httpx.WithCORS(s.cfg.AllowedOrigins),
		httpx.WithClientIP(s.cfg.TrustProxy),
		httpx.WithRecovery,
	)
}

func (s *Server) handleHealth(w http.ResponseWriter, r *http.Request) {
	if err := s.store.Pool().Ping(r.Context()); err != nil {
		httpx.Fail(w, http.StatusServiceUnavailable, "database_unavailable",
			"Databáze není dostupná.")
		return
	}
	httpx.JSON(w, http.StatusOK, map[string]string{"status": "ok"})
}
