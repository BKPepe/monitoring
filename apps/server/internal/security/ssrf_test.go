package security_test

import (
	"context"
	"net"
	"testing"
	"time"

	"github.com/BKPepe/monitoring/apps/server/internal/security"
)

func TestSSRFProtection(t *testing.T) {
	prohibitedIPs := []string{
		"127.0.0.1",
		"10.0.0.1",
		"172.16.0.1",
		"192.168.1.1",
		"169.254.169.254",
		"::1",
		"fe80::1",
	}

	for _, ipStr := range prohibitedIPs {
		ip := net.ParseIP(ipStr)
		if !security.IsPrivateOrLocalIP(ip) {
			t.Errorf("expected %s to be flagged as prohibited IP", ipStr)
		}
	}

	allowedIPs := []string{
		"8.8.8.8",
		"1.1.1.1",
		"140.82.121.4", // GitHub
	}

	for _, ipStr := range allowedIPs {
		ip := net.ParseIP(ipStr)
		if security.IsPrivateOrLocalIP(ip) {
			t.Errorf("expected %s to be allowed, but was flagged as prohibited", ipStr)
		}
	}
}

func TestValidateTargetHost(t *testing.T) {
	ctx, cancel := context.WithTimeout(context.Background(), 2*time.Second)
	defer cancel()

	// Localhost should fail
	_, err := security.ValidateTargetHost(ctx, "127.0.0.1")
	if err == nil {
		t.Fatalf("expected error validating 127.0.0.1, got nil")
	}

	// Public IP should pass
	ip, err := security.ValidateTargetHost(ctx, "8.8.8.8")
	if err != nil || ip.String() != "8.8.8.8" {
		t.Fatalf("expected 8.8.8.8 to pass validation, got ip %v, err %v", ip, err)
	}
}
