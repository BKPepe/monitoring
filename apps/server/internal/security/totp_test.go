package security_test

import (
	"testing"
	"time"

	"github.com/BKPepe/monitoring/apps/server/internal/security"
)

func TestTOTPGenerationAndVerification(t *testing.T) {
	secret, err := security.GenerateTOTPSecret()
	if err != nil || len(secret) == 0 {
		t.Fatalf("expected valid secret, got %s, err %v", secret, err)
	}

	now := time.Now()
	code, err := security.GenerateTOTPCode(secret, now)
	if err != nil || len(code) != 6 {
		t.Fatalf("expected 6-digit TOTP code, got %s, err %v", code, err)
	}

	// Valid code should pass
	if !security.VerifyTOTPCode(secret, code, now) {
		t.Fatalf("expected TOTP verification to succeed for valid code %s", code)
	}

	// Invalid code should fail
	if security.VerifyTOTPCode(secret, "000000", now) && code != "000000" {
		t.Fatalf("expected invalid TOTP code 000000 to fail")
	}

	// Code within 30s window should pass
	past30s := now.Add(-25 * time.Second)
	if !security.VerifyTOTPCode(secret, code, past30s) {
		t.Fatalf("expected TOTP verification to succeed within 30s window")
	}

	// Code outside 3-minute window should fail
	future3m := now.Add(3 * time.Minute)
	if security.VerifyTOTPCode(secret, code, future3m) {
		t.Fatalf("expected TOTP verification to fail for code outside window")
	}
}
