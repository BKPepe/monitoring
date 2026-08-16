package api_test

import (
	"bytes"
	"context"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"os"
	"testing"
	"time"

	"github.com/BKPepe/monitoring/apps/server/internal/api"
	"github.com/BKPepe/monitoring/apps/server/internal/config"
	"github.com/BKPepe/monitoring/apps/server/internal/security"
	"github.com/BKPepe/monitoring/apps/server/internal/store"
)

// Testy běží proti skutečnému PostgreSQL — chování jako unikátní indexy,
// výčtové typy nebo CHECK omezení se s atrapou databáze ověřit nedá.
//
//	BK_TEST_DATABASE_URL=postgres://localhost/bloodkings_test go test ./...
func testStore(t *testing.T) *store.Store {
	t.Helper()

	url := os.Getenv("BK_TEST_DATABASE_URL")
	if url == "" {
		t.Skip("BK_TEST_DATABASE_URL není nastavené — přeskakuji integrační testy")
	}

	st, err := store.Open(context.Background(), url)
	if err != nil {
		t.Fatalf("připojení k testovací databázi: %v", err)
	}
	t.Cleanup(st.Close)

	// Čistý stav před každým testem. TRUNCATE ... CASCADE smaže i relace
	// a auditní záznamy navázané cizím klíčem.
	if _, err := st.Pool().Exec(context.Background(),
		`TRUNCATE users, sessions, login_attempts, audit_log, monitors, vps_metrics, monitor_logs, agent_actions, monitor_interface_traffic, settings RESTART IDENTITY CASCADE`); err != nil {
		t.Fatalf("čištění databáze: %v", err)
	}

	return st
}

func testServer(t *testing.T, st *store.Store) http.Handler {
	t.Helper()

	cfg := &config.Config{
		Environment:      "test",
		SecureCookies:    false,
		SessionTTL:       time.Hour,
		SessionIdleTTL:   30 * time.Minute,
		LoginMaxAttempts: 5,
		LoginWindow:      15 * time.Minute,
		LoginLockout:     15 * time.Minute,
	}

	return api.NewServer(cfg, st).Handler()
}

// seedUser založí účet s heslem zahashovaným stejně jako v produkci.
func seedUser(t *testing.T, st *store.Store, username, password, role string) *store.User {
	t.Helper()

	hash, err := security.HashPassword(password, security.DefaultParams())
	if err != nil {
		t.Fatal(err)
	}

	u, err := st.CreateUser(context.Background(), store.NewUser{
		Username:     username,
		Email:        username + "@example.test",
		Role:         role,
		PasswordHash: hash,
	})
	if err != nil {
		t.Fatalf("vytvoření testovacího účtu: %v", err)
	}
	return u
}

type client struct {
	t       *testing.T
	handler http.Handler
	cookies []*http.Cookie
	csrf    string
}

func newClient(t *testing.T, h http.Handler) *client {
	return &client{t: t, handler: h}
}

func (c *client) do(method, path string, body any) *httptest.ResponseRecorder {
	c.t.Helper()

	var buf bytes.Buffer
	if body != nil {
		if err := json.NewEncoder(&buf).Encode(body); err != nil {
			c.t.Fatal(err)
		}
	}

	req := httptest.NewRequest(method, path, &buf)
	req.Header.Set("Content-Type", "application/json")
	for _, ck := range c.cookies {
		req.AddCookie(ck)
	}
	if c.csrf != "" {
		req.Header.Set("X-CSRF-Token", c.csrf)
	}

	rec := httptest.NewRecorder()
	c.handler.ServeHTTP(rec, req)

	// Cookies z odpovědi si klient ponechá, aby se choval jako prohlížeč.
	if cookies := rec.Result().Cookies(); len(cookies) > 0 {
		c.cookies = append(c.cookies, cookies...)
	}

	return rec
}

func (c *client) login(username, password string) *httptest.ResponseRecorder {
	c.t.Helper()

	rec := c.do(http.MethodPost, "/api/v1/auth/login", map[string]string{
		"username": username,
		"password": password,
	})

	var resp struct {
		CSRFToken string `json:"csrfToken"`
	}
	_ = json.Unmarshal(rec.Body.Bytes(), &resp)
	c.csrf = resp.CSRFToken

	return rec
}

// --- Přihlašování --------------------------------------------------------

func TestLoginSuccess(t *testing.T) {
	st := testStore(t)
	seedUser(t, st, "admin", "SuperTajneHeslo123", store.RoleAdmin)

	c := newClient(t, testServer(t, st))
	rec := c.login("admin", "SuperTajneHeslo123")

	if rec.Code != http.StatusOK {
		t.Fatalf("očekáván 200, přišlo %d: %s", rec.Code, rec.Body)
	}
	if c.csrf == "" {
		t.Error("odpověď neobsahovala CSRF token")
	}

	// Session cookie musí být HttpOnly, jinak ji přečte XSS.
	var found bool
	for _, ck := range rec.Result().Cookies() {
		if ck.Name == "bk_session" {
			found = true
			if !ck.HttpOnly {
				t.Error("session cookie není HttpOnly")
			}
			if ck.SameSite != http.SameSiteStrictMode {
				t.Error("session cookie nemá SameSite=Strict")
			}
		}
	}
	if !found {
		t.Error("session cookie chybí")
	}
}

func TestLoginWrongPasswordIsRejected(t *testing.T) {
	st := testStore(t)
	seedUser(t, st, "admin", "SuperTajneHeslo123", store.RoleAdmin)

	c := newClient(t, testServer(t, st))
	rec := c.login("admin", "spatneHeslo")

	if rec.Code != http.StatusUnauthorized {
		t.Fatalf("očekáván 401, přišlo %d", rec.Code)
	}
}

// Odpověď na neexistující účet musí být k nerozeznání od špatného hesla,
// jinak API prozradí, která jména existují.
func TestLoginDoesNotRevealWhetherUserExists(t *testing.T) {
	st := testStore(t)
	seedUser(t, st, "admin", "SuperTajneHeslo123", store.RoleAdmin)

	h := testServer(t, st)

	wrongPassword := newClient(t, h).login("admin", "spatneHeslo")
	noSuchUser := newClient(t, h).login("neexistuje", "spatneHeslo")

	if wrongPassword.Code != noSuchUser.Code {
		t.Errorf("různé stavové kódy: %d vs %d", wrongPassword.Code, noSuchUser.Code)
	}
	if wrongPassword.Body.String() != noSuchUser.Body.String() {
		t.Errorf("různé odpovědi:\n%s\n%s", wrongPassword.Body, noSuchUser.Body)
	}
}

func TestLoginLockoutAfterRepeatedFailures(t *testing.T) {
	st := testStore(t)
	seedUser(t, st, "admin", "SuperTajneHeslo123", store.RoleAdmin)

	h := testServer(t, st)

	for i := 0; i < 5; i++ {
		newClient(t, h).login("admin", "spatneHeslo")
	}

	// Šestý pokus musí být zablokovaný — a to i se správným heslem,
	// jinak by limit šlo obejít prostým uhodnutím.
	rec := newClient(t, h).login("admin", "SuperTajneHeslo123")
	if rec.Code != http.StatusTooManyRequests {
		t.Fatalf("očekáván 429, přišlo %d: %s", rec.Code, rec.Body)
	}
}

func TestDisabledAccountCannotLogIn(t *testing.T) {
	st := testStore(t)
	u := seedUser(t, st, "vypnuty", "SuperTajneHeslo123", store.RoleViewer)

	if _, err := st.UpdateUser(context.Background(), u.ID, store.UserUpdate{
		Username: u.Username, Email: u.Email, Role: u.Role, IsActive: false,
	}); err != nil {
		t.Fatal(err)
	}

	rec := newClient(t, testServer(t, st)).login("vypnuty", "SuperTajneHeslo123")
	if rec.Code != http.StatusUnauthorized {
		t.Fatalf("deaktivovaný účet se přihlásil: %d %s", rec.Code, rec.Body)
	}
}

// --- Autorizace ----------------------------------------------------------

func TestUsersEndpointRequiresAuth(t *testing.T) {
	st := testStore(t)

	rec := newClient(t, testServer(t, st)).do(http.MethodGet, "/api/v1/users", nil)
	if rec.Code != http.StatusUnauthorized {
		t.Fatalf("očekáván 401, přišlo %d", rec.Code)
	}
}

func TestNonAdminCannotListUsers(t *testing.T) {
	st := testStore(t)
	seedUser(t, st, "divak", "SuperTajneHeslo123", store.RoleViewer)

	c := newClient(t, testServer(t, st))
	c.login("divak", "SuperTajneHeslo123")

	rec := c.do(http.MethodGet, "/api/v1/users", nil)
	if rec.Code != http.StatusForbidden {
		t.Fatalf("očekáván 403, přišlo %d: %s", rec.Code, rec.Body)
	}
}

// Zápis bez CSRF tokenu musí selhat, i když je uživatel přihlášený.
func TestMutationWithoutCSRFIsRejected(t *testing.T) {
	st := testStore(t)
	seedUser(t, st, "admin", "SuperTajneHeslo123", store.RoleAdmin)

	c := newClient(t, testServer(t, st))
	c.login("admin", "SuperTajneHeslo123")
	c.csrf = "" // simuluje požadavek z cizí stránky

	rec := c.do(http.MethodPost, "/api/v1/users", map[string]string{
		"username": "novy", "email": "novy@example.test",
		"role": "viewer", "password": "SuperTajneHeslo123",
	})

	if rec.Code != http.StatusForbidden {
		t.Fatalf("očekáván 403, přišlo %d: %s", rec.Code, rec.Body)
	}
}

// --- Správa uživatelů ----------------------------------------------------

func TestCreateUser(t *testing.T) {
	st := testStore(t)
	seedUser(t, st, "admin", "SuperTajneHeslo123", store.RoleAdmin)

	c := newClient(t, testServer(t, st))
	c.login("admin", "SuperTajneHeslo123")

	rec := c.do(http.MethodPost, "/api/v1/users", map[string]any{
		"username": "novacek",
		"email":    "novacek@example.test",
		"role":     "editor",
		"password": "JinéTajnéHeslo456",
	})

	if rec.Code != http.StatusCreated {
		t.Fatalf("očekáván 201, přišlo %d: %s", rec.Code, rec.Body)
	}

	// Odpověď nikdy nesmí obsahovat hash hesla.
	if bytes.Contains(rec.Body.Bytes(), []byte("password")) {
		t.Errorf("odpověď obsahuje pole s heslem: %s", rec.Body)
	}
}

func TestCreateUserRejectsShortPassword(t *testing.T) {
	st := testStore(t)
	seedUser(t, st, "admin", "SuperTajneHeslo123", store.RoleAdmin)

	c := newClient(t, testServer(t, st))
	c.login("admin", "SuperTajneHeslo123")

	rec := c.do(http.MethodPost, "/api/v1/users", map[string]any{
		"username": "kratke", "email": "kratke@example.test",
		"role": "viewer", "password": "krátké",
	})

	if rec.Code != http.StatusUnprocessableEntity {
		t.Fatalf("očekáván 422, přišlo %d: %s", rec.Code, rec.Body)
	}
}

func TestCreateUserRejectsInvalidRole(t *testing.T) {
	st := testStore(t)
	seedUser(t, st, "admin", "SuperTajneHeslo123", store.RoleAdmin)

	c := newClient(t, testServer(t, st))
	c.login("admin", "SuperTajneHeslo123")

	rec := c.do(http.MethodPost, "/api/v1/users", map[string]any{
		"username": "podvod", "email": "podvod@example.test",
		"role": "superadmin", "password": "SuperTajneHeslo123",
	})

	if rec.Code != http.StatusUnprocessableEntity {
		t.Fatalf("neplatná role prošla: %d %s", rec.Code, rec.Body)
	}
}

func TestCannotDeleteSelf(t *testing.T) {
	st := testStore(t)
	admin := seedUser(t, st, "admin", "SuperTajneHeslo123", store.RoleAdmin)

	c := newClient(t, testServer(t, st))
	c.login("admin", "SuperTajneHeslo123")

	rec := c.do(http.MethodDelete, "/api/v1/users/"+itoa(admin.ID), nil)
	if rec.Code != http.StatusUnprocessableEntity {
		t.Fatalf("smazání vlastního účtu mělo selhat: %d %s", rec.Code, rec.Body)
	}
}

// Poslední správce nesmí zmizet, jinak zůstane instalace bez přístupu.
func TestCannotRemoveLastAdmin(t *testing.T) {
	st := testStore(t)
	seedUser(t, st, "admin", "SuperTajneHeslo123", store.RoleAdmin)
	other := seedUser(t, st, "druhy", "SuperTajneHeslo123", store.RoleAdmin)

	c := newClient(t, testServer(t, st))
	c.login("admin", "SuperTajneHeslo123")

	// Odebrat práva druhému správci jde — první pořád zbývá.
	rec := c.do(http.MethodPatch, "/api/v1/users/"+itoa(other.ID), map[string]any{
		"username": other.Username, "email": other.Email, "role": "viewer",
	})
	if rec.Code != http.StatusOK {
		t.Fatalf("degradace druhého správce selhala: %d %s", rec.Code, rec.Body)
	}

	// Poslední správce si práva odebrat nesmí.
	rec = c.do(http.MethodPatch, "/api/v1/users/"+itoa(1), map[string]any{
		"username": "admin", "email": "admin@example.test", "role": "viewer",
	})
	if rec.Code != http.StatusUnprocessableEntity {
		t.Fatalf("poslední správce si odebral práva: %d %s", rec.Code, rec.Body)
	}
}

func TestDeactivatingUserRevokesSessions(t *testing.T) {
	st := testStore(t)
	seedUser(t, st, "admin", "SuperTajneHeslo123", store.RoleAdmin)
	victim := seedUser(t, st, "obet", "SuperTajneHeslo123", store.RoleEditor)

	h := testServer(t, st)

	// Oběť se přihlásí a relace funguje.
	victimClient := newClient(t, h)
	victimClient.login("obet", "SuperTajneHeslo123")
	if rec := victimClient.do(http.MethodGet, "/api/v1/auth/session", nil); rec.Code != http.StatusOK {
		t.Fatalf("session nefunguje: %d", rec.Code)
	}

	// Admin účet deaktivuje.
	adminClient := newClient(t, h)
	adminClient.login("admin", "SuperTajneHeslo123")
	rec := adminClient.do(http.MethodPatch, "/api/v1/users/"+itoa(victim.ID), map[string]any{
		"username": victim.Username, "email": victim.Email,
		"role": victim.Role, "isActive": false,
	})
	if rec.Code != http.StatusOK {
		t.Fatalf("deaktivace selhala: %d %s", rec.Code, rec.Body)
	}

	// Relace oběti musí okamžitě přestat platit.
	rec = victimClient.do(http.MethodGet, "/api/v1/users", nil)
	if rec.Code == http.StatusOK {
		t.Error("deaktivovaný uživatel má stále platnou relaci")
	}
}

func TestDuplicateUsernameIsRejected(t *testing.T) {
	st := testStore(t)
	seedUser(t, st, "admin", "SuperTajneHeslo123", store.RoleAdmin)

	c := newClient(t, testServer(t, st))
	c.login("admin", "SuperTajneHeslo123")

	// Jiná velikost písmen musí být také považována za duplicitu.
	rec := c.do(http.MethodPost, "/api/v1/users", map[string]any{
		"username": "ADMIN", "email": "jiny@example.test",
		"role": "viewer", "password": "SuperTajneHeslo123",
	})

	if rec.Code != http.StatusUnprocessableEntity {
		t.Fatalf("duplicitní jméno prošlo: %d %s", rec.Code, rec.Body)
	}
}

func TestLogoutInvalidatesSession(t *testing.T) {
	st := testStore(t)
	seedUser(t, st, "admin", "SuperTajneHeslo123", store.RoleAdmin)

	c := newClient(t, testServer(t, st))
	c.login("admin", "SuperTajneHeslo123")

	if rec := c.do(http.MethodPost, "/api/v1/auth/logout", nil); rec.Code != http.StatusOK {
		t.Fatalf("odhlášení selhalo: %d %s", rec.Code, rec.Body)
	}

	if rec := c.do(http.MethodGet, "/api/v1/users", nil); rec.Code != http.StatusUnauthorized {
		t.Errorf("relace platí i po odhlášení: %d", rec.Code)
	}
}

func itoa(v int64) string {
	return string(json.RawMessage(jsonNumber(v)))
}

func jsonNumber(v int64) []byte {
	b, _ := json.Marshal(v)
	return b
}
