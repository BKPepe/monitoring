package security_test

import (
	"strconv"
	"testing"
	"time"

	"github.com/BKPepe/monitoring/apps/server/internal/security"
)

func TestAgentHMACVerification(t *testing.T) {
	agentKey := "0123456789abcdef0123456789abcdef"
	payload := []byte(`{"cpu": 25.5, "ram": 40.2, "hdd": 55.0}`)
	now := time.Now()
	timestampStr := strconv.FormatInt(now.Unix(), 10)

	sig := security.ComputeAgentHMAC(payload, timestampStr, agentKey)

	// Valid signature & fresh timestamp
	err := security.VerifyAgentHMAC(payload, timestampStr, sig, agentKey, now)
	if err != nil {
		t.Fatalf("expected valid HMAC verification, got: %v", err)
	}

	// Invalid signature
	err = security.VerifyAgentHMAC(payload, timestampStr, "invalid_sig", agentKey, now)
	if err == nil {
		t.Fatalf("expected error for invalid signature, got nil")
	}

	// Expired timestamp (replay attack simulation: 10 minutes ago)
	expiredTS := strconv.FormatInt(now.Add(-10*time.Minute).Unix(), 10)
	expiredSig := security.ComputeAgentHMAC(payload, expiredTS, agentKey)
	err = security.VerifyAgentHMAC(payload, expiredTS, expiredSig, agentKey, now)
	if err == nil {
		t.Fatalf("expected error for expired timestamp (replay attack), got nil")
	}
}

func TestSanitizeCommandString(t *testing.T) {
	tests := []struct {
		input    string
		expected string
	}{
		{
			input:    "mysql -u root -pSuperSecret123 database",
			expected: "mysql -u root -p=[REDACTED] database",
		},
		{
			input:    "python script.py --token=abc123xyz --secret MySecretValue",
			expected: "python script.py --token=[REDACTED] --secret=[REDACTED]",
		},
		{
			input:    "curl -H 'Authorization: Bearer secret_bearer_token' https://api.com",
			expected: "curl -H 'Authorization: Bearer [REDACTED]' https://api.com",
		},
		{
			input:    "postgres://admin:Password123@localhost:5432/db",
			expected: "postgres://admin:[REDACTED]@localhost:5432/db",
		},
	}

	for _, tt := range tests {
		result := security.SanitizeCommandString(tt.input)
		if result != tt.expected {
			t.Errorf("SanitizeCommandString(%q) = %q; want %q", tt.input, result, tt.expected)
		}
	}
}
