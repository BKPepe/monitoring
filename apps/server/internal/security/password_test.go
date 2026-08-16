package security

import (
	"strings"
	"testing"
)

func TestHashAndVerify(t *testing.T) {
	p := DefaultParams()

	hash, err := HashPassword("SprávnéHeslo123", p)
	if err != nil {
		t.Fatalf("HashPassword: %v", err)
	}

	if !strings.HasPrefix(hash, "$argon2id$") {
		t.Fatalf("hash nemá argon2id prefix: %s", hash)
	}

	needsRehash, err := VerifyPassword("SprávnéHeslo123", hash, p)
	if err != nil {
		t.Fatalf("ověření správného hesla selhalo: %v", err)
	}
	if needsRehash {
		t.Error("čerstvý hash by neměl vyžadovat rehash")
	}

	if _, err := VerifyPassword("ŠpatnéHeslo", hash, p); err != ErrMismatch {
		t.Errorf("špatné heslo mělo vrátit ErrMismatch, vrátilo %v", err)
	}
}

// Dva hashe téhož hesla se musí lišit — jinak by se v databázi daly
// najít účty se stejným heslem.
func TestHashIsSalted(t *testing.T) {
	p := DefaultParams()

	first, err := HashPassword("StejnéHeslo", p)
	if err != nil {
		t.Fatal(err)
	}
	second, err := HashPassword("StejnéHeslo", p)
	if err != nil {
		t.Fatal(err)
	}

	if first == second {
		t.Error("dva hashe téhož hesla jsou shodné — chybí sůl")
	}
}

// Účty zděděné z původní PHP instalace se musí přihlásit a hned si
// vyžádat přepočet na argon2id.
func TestLegacyBcryptIsAcceptedAndFlaggedForRehash(t *testing.T) {
	p := DefaultParams()

	// Vygenerováno PHP: password_hash('TajneHeslo123', PASSWORD_BCRYPT)
	phpHash := "$2y$12$edcID5Rt8kuN6IVAhRWb8ufExoytaL8ZrGLapnCnsdwDR/8cKsSom"

	needsRehash, err := VerifyPassword("TajneHeslo123", phpHash, p)
	if err != nil {
		t.Fatalf("PHP bcrypt hash se neověřil: %v", err)
	}
	if !needsRehash {
		t.Error("zděděný bcrypt musí být označen k přepočtu")
	}

	if _, err := VerifyPassword("spatne", phpHash, p); err != ErrMismatch {
		t.Errorf("špatné heslo u bcryptu mělo vrátit ErrMismatch, vrátilo %v", err)
	}
}

func TestUnknownFormatIsRejected(t *testing.T) {
	p := DefaultParams()

	for _, bad := range []string{
		"",
		"plaintext",
		"$md5$neco",
		"$argon2id$neuplny",
	} {
		if _, err := VerifyPassword("cokoliv", bad, p); err != ErrUnknownFormat {
			t.Errorf("hash %q měl být odmítnut jako neznámý formát, vráceno %v", bad, err)
		}
	}
}

// Slabší parametry musí vést k přepočtu, jinak by se zpřísnění nikdy
// neprojevilo na existujících účtech.
func TestWeakerParamsTriggerRehash(t *testing.T) {
	weak := Argon2idParams{Memory: 8 * 1024, Iterations: 1, Parallelism: 1, SaltLength: 16, KeyLength: 32}

	hash, err := HashPassword("Heslo", weak)
	if err != nil {
		t.Fatal(err)
	}

	needsRehash, err := VerifyPassword("Heslo", hash, DefaultParams())
	if err != nil {
		t.Fatalf("ověření selhalo: %v", err)
	}
	if !needsRehash {
		t.Error("hash se slabšími parametry měl být označen k přepočtu")
	}
}
