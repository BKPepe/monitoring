// Package security obsahuje kryptografické primitivy aplikace: hesla,
// tokeny relací a porovnávání citlivých hodnot.
//
// Vše je na jednom místě schválně. Ve starém systému byla práce s hesly
// rozeseta po admin.php, functions.php i api souborech a nešlo jednoduše
// zjistit, jaká pravidla vlastně platí.
package security

import (
	"crypto/rand"
	"crypto/subtle"
	"encoding/base64"
	"errors"
	"fmt"
	"runtime"
	"strings"

	"golang.org/x/crypto/argon2"
	"golang.org/x/crypto/bcrypt"
)

var (
	ErrMismatch      = errors.New("heslo nesouhlasí")
	ErrUnknownFormat = errors.New("neznámý formát hashe")
)

// Argon2idParams jsou parametry pro odvození klíče.
//
// Hodnoty vychází z doporučení OWASP (2. varianta: 19 MiB, 2 iterace).
// Paměťová náročnost je hlavní obrana proti útoku na GPU — proto se
// nesnižuje ani na slabším hostingu; radši se sníží paralelismus.
type Argon2idParams struct {
	Memory      uint32 // v KiB
	Iterations  uint32
	Parallelism uint8
	SaltLength  uint32
	KeyLength   uint32
}

func DefaultParams() Argon2idParams {
	parallelism := uint8(runtime.NumCPU())
	if parallelism > 4 {
		parallelism = 4
	}
	if parallelism == 0 {
		parallelism = 1
	}

	return Argon2idParams{
		Memory:      19 * 1024,
		Iterations:  2,
		Parallelism: parallelism,
		SaltLength:  16,
		KeyLength:   32,
	}
}

// HashPassword vytvoří argon2id hash ve formátu PHC.
func HashPassword(password string, p Argon2idParams) (string, error) {
	salt := make([]byte, p.SaltLength)
	if _, err := rand.Read(salt); err != nil {
		return "", fmt.Errorf("generování soli: %w", err)
	}

	key := argon2.IDKey([]byte(password), salt, p.Iterations, p.Memory, p.Parallelism, p.KeyLength)

	return fmt.Sprintf("$argon2id$v=%d$m=%d,t=%d,p=%d$%s$%s",
		argon2.Version, p.Memory, p.Iterations, p.Parallelism,
		base64.RawStdEncoding.EncodeToString(salt),
		base64.RawStdEncoding.EncodeToString(key),
	), nil
}

// VerifyPassword ověří heslo proti hashi.
//
// Vrací needsRehash=true, pokud hash pochází ze staršího algoritmu nebo
// slabších parametrů. Volající ho pak má po úspěšném přihlášení přepsat —
// jinak by účty zděděné z původního systému zůstaly na bcryptu navždy.
func VerifyPassword(password, encoded string, p Argon2idParams) (needsRehash bool, err error) {
	switch {
	case strings.HasPrefix(encoded, "$argon2id$"):
		return verifyArgon2id(password, encoded, p)

	case strings.HasPrefix(encoded, "$2a$"),
		strings.HasPrefix(encoded, "$2b$"),
		strings.HasPrefix(encoded, "$2y$"):
		// Zděděné hashe z původní PHP instalace. Podpora existuje jen kvůli
		// jednorázové migraci účtů — po prvním přihlášení se hash přepíše.
		if err := bcrypt.CompareHashAndPassword([]byte(encoded), []byte(password)); err != nil {
			return false, ErrMismatch
		}
		return true, nil

	default:
		return false, ErrUnknownFormat
	}
}

func verifyArgon2id(password, encoded string, want Argon2idParams) (bool, error) {
	parts := strings.Split(encoded, "$")
	if len(parts) != 6 {
		return false, ErrUnknownFormat
	}

	var version int
	if _, err := fmt.Sscanf(parts[2], "v=%d", &version); err != nil {
		return false, ErrUnknownFormat
	}
	if version != argon2.Version {
		return false, ErrUnknownFormat
	}

	var got Argon2idParams
	if _, err := fmt.Sscanf(parts[3], "m=%d,t=%d,p=%d",
		&got.Memory, &got.Iterations, &got.Parallelism); err != nil {
		return false, ErrUnknownFormat
	}

	salt, err := base64.RawStdEncoding.DecodeString(parts[4])
	if err != nil {
		return false, ErrUnknownFormat
	}
	expected, err := base64.RawStdEncoding.DecodeString(parts[5])
	if err != nil {
		return false, ErrUnknownFormat
	}

	actual := argon2.IDKey([]byte(password), salt,
		got.Iterations, got.Memory, got.Parallelism, uint32(len(expected)))

	// Časově konstantní porovnání — délka a obsah se nesmí prozradit
	// dobou běhu.
	if subtle.ConstantTimeCompare(expected, actual) != 1 {
		return false, ErrMismatch
	}

	// Parametry se časem zesilují; slabší hash se po přihlášení přepočítá.
	needsRehash := got.Memory < want.Memory ||
		got.Iterations < want.Iterations ||
		uint32(len(expected)) < want.KeyLength

	return needsRehash, nil
}

// DummyVerify spálí srovnatelný čas jako skutečné ověření.
//
// Bez toho by přihlášení neexistujícím jménem odpovědělo znatelně rychleji
// než existujícím, a útočník by si tak vytvořil seznam platných účtů.
func DummyVerify(p Argon2idParams) {
	argon2.IDKey([]byte("dummy"), make([]byte, p.SaltLength),
		p.Iterations, p.Memory, p.Parallelism, p.KeyLength)
}
