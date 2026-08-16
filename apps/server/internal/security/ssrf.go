package security

import (
	"context"
	"errors"
	"fmt"
	"net"
	"net/http"
	"time"
)

var (
	ErrProhibitedIP = errors.New("SSRF ochrana: přístup na zakázanou IP adresu byl zablokován")
)

var privateIPBlocks []*net.IPNet

func init() {
	cidrs := []string{
		"127.0.0.0/8",    // IPv4 loopback
		"10.0.0.0/8",     // RFC1918 Private
		"172.16.0.0/12",  // RFC1918 Private
		"192.168.0.0/16", // RFC1918 Private
		"169.254.0.0/16", // Link-local / Cloud metadata (e.g. 169.254.169.254)
		"::1/128",        // IPv6 loopback
		"fe80::/10",      // IPv6 link-local
		"fc00::/7",       // IPv6 unique local
	}
	for _, cidr := range cidrs {
		_, block, err := net.ParseCIDR(cidr)
		if err == nil {
			privateIPBlocks = append(privateIPBlocks, block)
		}
	}
}

// IsPrivateOrLocalIP vrací true, pokud je IP adresa v zakázaném privátním nebo loopback rozsahu.
func IsPrivateOrLocalIP(ip net.IP) bool {
	if ip == nil || ip.IsLoopback() || ip.IsLinkLocalUnicast() || ip.IsLinkLocalMulticast() || ip.IsUnspecified() {
		return true
	}
	for _, block := range privateIPBlocks {
		if block.Contains(ip) {
			return true
		}
	}
	return false
}

// ValidateTargetHost přeloží doménové jméno na IP a ověří, zda žádná z nalezenných IP neporušuje SSRF pravidla.
func ValidateTargetHost(ctx context.Context, host string) (net.IP, error) {
	// Odstranění portu, pokud je přítomen
	hostOnly, _, err := net.SplitHostPort(host)
	if err == nil {
		host = hostOnly
	}

	ip := net.ParseIP(host)
	if ip != nil {
		if IsPrivateOrLocalIP(ip) {
			return nil, fmt.Errorf("%w: %s", ErrProhibitedIP, ip.String())
		}
		return ip, nil
	}

	resolver := net.DefaultResolver
	ips, err := resolver.LookupIPAddr(ctx, host)
	if err != nil {
		return nil, fmt.Errorf("překlad domény %s selhal: %w", host, err)
	}
	if len(ips) == 0 {
		return nil, fmt.Errorf("žádná IP adresa nenalezena pro doménu %s", host)
	}

	for _, ipAddr := range ips {
		if IsPrivateOrLocalIP(ipAddr.IP) {
			return nil, fmt.Errorf("%w: %s pro doménu %s", ErrProhibitedIP, ipAddr.IP.String(), host)
		}
	}

	return ips[0].IP, nil
}

// SafeHTTPClient vrací HTTP klient chráněný proti SSRF útokům s nastaveným časovým limitem.
func SafeHTTPClient(timeout time.Duration) *http.Client {
	dialer := &net.Dialer{
		Timeout:   timeout,
		KeepAlive: 30 * time.Second,
	}

	transport := &http.Transport{
		DialContext: func(ctx context.Context, network, addr string) (net.Conn, error) {
			host, port, err := net.SplitHostPort(addr)
			if err != nil {
				return nil, err
			}

			ip, err := ValidateTargetHost(ctx, host)
			if err != nil {
				return nil, err
			}

			targetAddr := net.JoinHostPort(ip.String(), port)
			return dialer.DialContext(ctx, network, targetAddr)
		},
		ResponseHeaderTimeout: timeout,
		TLSHandshakeTimeout:   timeout,
	}

	return &http.Client{
		Timeout:   timeout,
		Transport: transport,
	}
}
