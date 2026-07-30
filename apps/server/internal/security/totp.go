package security

import (
	"crypto/hmac"
	"crypto/rand"
	"crypto/sha1"
	"encoding/base32"
	"encoding/binary"
	"fmt"
	"math"
	"strings"
	"time"
)

// GenerateTOTPSecret vygeneruje nový 20-bajtový náhodný secret kódovaný v Base32.
func GenerateTOTPSecret() (string, error) {
	buf := make([]byte, 20)
	if _, err := rand.Read(buf); err != nil {
		return "", fmt.Errorf("generování TOTP secretu selhalo: %w", err)
	}
	return base32.StdEncoding.WithPadding(base32.NoPadding).EncodeToString(buf), nil
}

// GenerateTOTPCode spočítá 6-místný TOTP kód pro daný secret a timestamp (RFC 6238).
func GenerateTOTPCode(secret string, t time.Time) (string, error) {
	key, err := base32.StdEncoding.WithPadding(base32.NoPadding).DecodeString(strings.ToUpper(strings.TrimSpace(secret)))
	if err != nil {
		return "", fmt.Errorf("neplatný base32 TOTP secret: %w", err)
	}

	counter := uint64(t.Unix() / 30)
	buf := make([]byte, 8)
	binary.BigEndian.PutUint64(buf, counter)

	mac := hmac.New(sha1.New, key)
	mac.Write(buf)
	hash := mac.Sum(nil)

	offset := hash[len(hash)-1] & 0x0f
	truncated := binary.BigEndian.Uint32(hash[offset:offset+4]) & 0x7fffffff
	code := truncated % uint32(math.Pow10(6))

	return fmt.Sprintf("%06d", code), nil
}

// VerifyTOTPCode ověří, zda kód odpovídá secretu s časovou tolerancí (±1 okno po 30 sekundách).
func VerifyTOTPCode(secret string, code string, now time.Time) bool {
	code = strings.TrimSpace(code)
	if len(code) != 6 {
		return false
	}

	// Kontrola okna: předchozí (now-30s), aktuální (now), následující (now+30s)
	for i := -1; i <= 1; i++ {
		t := now.Add(time.Duration(i*30) * time.Second)
		expectedCode, err := GenerateTOTPCode(secret, t)
		if err == nil && expectedCode == code {
			return true
		}
	}

	return false
}
