package security

import (
	"crypto/hmac"
	"crypto/sha256"
	"encoding/hex"
	"fmt"
	"math"
	"strconv"
	"strings"
	"time"
)

const (
	// MaxAllowedTimestampDelta defines maximum time skew allowed for HMAC signatures (5 minutes).
	MaxAllowedTimestampDelta = 300 * time.Second
)

// ComputeAgentHMAC computes a SHA-256 HMAC for the given raw payload body and timestamp string using agentKey.
func ComputeAgentHMAC(payload []byte, timestampStr string, agentKey string) string {
	mac := hmac.New(sha256.New, []byte(agentKey))
	mac.Write([]byte(timestampStr))
	mac.Write([]byte("."))
	mac.Write(payload)
	return hex.EncodeToString(mac.Sum(nil))
}

// VerifyAgentHMAC verifies that signature matches the HMAC of payload + timestamp and checks timestamp freshness.
func VerifyAgentHMAC(payload []byte, timestampHeader string, signatureHeader string, agentKey string, now time.Time) error {
	if strings.TrimSpace(timestampHeader) == "" || strings.TrimSpace(signatureHeader) == "" {
		return fmt.Errorf("chybí HMAC hlavičky (X-Agent-Timestamp nebo X-Agent-Signature)")
	}

	ts, err := strconv.ParseInt(timestampHeader, 10, 64)
	if err != nil {
		return fmt.Errorf("neplatný formát časového razítka")
	}

	reqTime := time.Unix(ts, 0)
	delta := now.Sub(reqTime)
	if time.Duration(math.Abs(float64(delta))) > MaxAllowedTimestampDelta {
		return fmt.Errorf("časové razítko mimo povolené okno (replay protection)")
	}

	expectedSig := ComputeAgentHMAC(payload, timestampHeader, agentKey)
	if !hmac.Equal([]byte(strings.ToLower(signatureHeader)), []byte(expectedSig)) {
		return fmt.Errorf("neplatný HMAC podpis")
	}

	return nil
}

// SignRemoteAction computes HMAC signature for a remote action sent from server to agent.
// Format: action={actionType}|ts={timestamp}|nonce={nonce}
func SignRemoteAction(actionType string, timestamp int64, nonce string, agentKey string) string {
	sigPayload := fmt.Sprintf("action=%s|ts=%d|nonce=%s", actionType, timestamp, nonce)
	mac := hmac.New(sha256.New, []byte(agentKey))
	mac.Write([]byte(sigPayload))
	return hex.EncodeToString(mac.Sum(nil))
}
