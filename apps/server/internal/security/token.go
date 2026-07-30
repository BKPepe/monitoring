package security

import (
	"crypto/rand"
	"crypto/sha256"
	"crypto/subtle"
	"encoding/base64"
)

// TokenBytes je délka náhodných tokenů (relace, reset hesla).
// 32 bajtů = 256 bitů entropie; uhodnutí je mimo dosah i při útoku na
// celou databázi relací.
const TokenBytes = 32

// NewToken vrátí náhodný token vhodný do cookie nebo URL.
func NewToken() (string, error) {
	buf := make([]byte, TokenBytes)
	if _, err := rand.Read(buf); err != nil {
		return "", err
	}
	return base64.RawURLEncoding.EncodeToString(buf), nil
}

// HashToken vrátí SHA-256 otisk tokenu pro uložení do databáze.
//
// Do databáze se ukládá jen otisk. Kdo získá dump, nezíská platné relace —
// na rozdíl od původního systému, kde stačilo znát obsah session souboru.
//
// Pomalé hashování (argon2) tu není potřeba: token má 256 bitů entropie,
// takže slovníkový útok nedává smysl a rychlost se hodí, protože se
// ověřuje při každém požadavku.
func HashToken(token string) []byte {
	sum := sha256.Sum256([]byte(token))
	return sum[:]
}

// ConstantTimeEqual porovná dva řetězce v konstantním čase.
//
// Obyčejné == skončí na prvním rozdílném bajtu, takže by doba běhu
// prozradila, kolik znaků tokenu útočník uhodl správně.
func ConstantTimeEqual(a, b string) bool {
	return subtle.ConstantTimeCompare([]byte(a), []byte(b)) == 1
}
