package api_test

import (
	"context"
	"encoding/json"
	"net/http"
	"testing"
	"time"

	"github.com/BKPepe/monitoring/apps/server/internal/security"
)

func TestTOTPFlowAndPurge(t *testing.T) {
	st := testStore(t)
	handler := testServer(t, st)
	cli := newClient(t, handler)

	// Seed user and login
	_ = seedUser(t, st, "totpuser", "Password123456!", "admin")
	loginResp := cli.do("POST", "/api/v1/auth/login", map[string]string{
		"username": "totpuser",
		"password": "Password123456!",
	})
	if loginResp.Code != http.StatusOK {
		t.Fatalf("expected HTTP 200 on login, got %d", loginResp.Code)
	}

	// 1. Test TOTP Setup
	setupResp := cli.do("POST", "/api/v1/auth/totp/setup", nil)
	if setupResp.Code != http.StatusOK {
		t.Fatalf("expected HTTP 200 on totp setup, got %d: %s", setupResp.Code, setupResp.Body.String())
	}

	var setupData struct {
		Secret string `json:"secret"`
		URI    string `json:"uri"`
	}
	if err := json.Unmarshal(setupResp.Body.Bytes(), &setupData); err != nil {
		t.Fatalf("unmarshal setup response: %v", err)
	}
	if setupData.Secret == "" || setupData.URI == "" {
		t.Fatalf("expected valid secret and URI, got: %+v", setupData)
	}

	// 2. Test TOTP Verify with valid code
	validCode, err := security.GenerateTOTPCode(setupData.Secret, time.Now())
	if err != nil {
		t.Fatalf("generate valid totp code: %v", err)
	}

	verifyResp := cli.do("POST", "/api/v1/auth/totp/verify", map[string]string{
		"secret": setupData.Secret,
		"code":   validCode,
	})
	if verifyResp.Code != http.StatusOK {
		t.Fatalf("expected HTTP 200 on totp verify, got %d: %s", verifyResp.Code, verifyResp.Body.String())
	}

	// 3. Test Privacy Data Purge Engine
	stats, err := st.PurgeOldData(context.Background())
	if err != nil {
		t.Fatalf("purge old data failed: %v", err)
	}
	t.Logf("Purging completed successfully: %+v", stats)
}
