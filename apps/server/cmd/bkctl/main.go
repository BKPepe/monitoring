// Command bkctl jsou správcovské příkazy k serveru.
//
// Existuje hlavně kvůli založení prvního účtu: čerstvá instalace nemá
// žádného uživatele a přihlásit se tedy nedá. Původní systém to řešil
// výchozím heslem v dokumentaci (admin / BloodKingsAdmin123!), což je
// přesně ten druh věci, který zůstane nezměněný na produkci.
package main

import (
	"bufio"
	"context"
	"errors"
	"fmt"
	"os"
	"strings"
	"syscall"

	"github.com/BKPepe/monitoring/apps/server/internal/security"
	"github.com/BKPepe/monitoring/apps/server/internal/store"
	"golang.org/x/term"
)

func main() {
	if len(os.Args) < 2 {
		usage()
		os.Exit(2)
	}

	if err := run(os.Args[1], os.Args[2:]); err != nil {
		fmt.Fprintln(os.Stderr, "chyba:", err)
		os.Exit(1)
	}
}

func usage() {
	fmt.Fprintln(os.Stderr, `bkctl — správcovské příkazy

Použití:
  bkctl create-admin <jméno> <e-mail>   Založí administrátorský účet
  bkctl reset-password <jméno>          Nastaví nové heslo existujícímu účtu
  bkctl purge-data                      Skartuje stará data (Privacy Data Purge)
  bkctl migrate-mysql <mysql_dsn>       Přenese data z původní MySQL databáze

Databáze se bere z BK_DATABASE_URL. Heslo se zadává interaktivně —
nepředává se argumentem, aby se neuložilo do historie shellu.`)
}

func run(cmd string, args []string) error {
	loadDotEnv()
	dsn := os.Getenv("BK_DATABASE_URL")
	if dsn == "" {
		return errors.New("BK_DATABASE_URL není nastavené. Vytvořte soubor .env s přístupem k PostgreSQL (např. BK_DATABASE_URL=postgres://user:pass@127.0.0.1:5432/db) nebo ho předejte: BK_DATABASE_URL=... ./bkctl migrate-mysql")
	}

	ctx := context.Background()
	st, err := store.Open(ctx, dsn)
	if err != nil {
		return err
	}
	defer st.Close()

	switch cmd {
	case "create-admin":
		if len(args) != 2 {
			usage()
			return errors.New("očekávám jméno a e-mail")
		}
		return createAdmin(ctx, st, args[0], args[1])

	case "reset-password":
		if len(args) != 1 {
			usage()
			return errors.New("očekávám jméno účtu")
		}
		return resetPassword(ctx, st, args[0])

	case "purge-data":
		stats, err := st.PurgeOldData(ctx)
		if err != nil {
			return err
		}
		fmt.Printf("Skartování dat dokončeno:\n - vps_metrics: %d\n - monitor_logs: %d\n - audit_log: %d\n - sessions: %d\n",
			stats.MetricsPurged, stats.LogsPurged, stats.AuditPurged, stats.SessionsPurged)
		return nil

	case "migrate-mysql":
		dsn := "auto"
		if len(args) == 1 {
			dsn = args[0]
		}
		return importLegacyMySQL(ctx, st, dsn)

	default:
		usage()
		return fmt.Errorf("neznámý příkaz %q", cmd)
	}
}

func createAdmin(ctx context.Context, st *store.Store, username, email string) error {
	password, err := readPassword()
	if err != nil {
		return err
	}

	hash, err := security.HashPassword(password, security.DefaultParams())
	if err != nil {
		return err
	}

	user, err := st.CreateUser(ctx, store.NewUser{
		Username:     username,
		Email:        email,
		Role:         store.RoleAdmin,
		PasswordHash: hash,
	})
	if err != nil {
		if errors.Is(err, store.ErrConflict) {
			return fmt.Errorf("účet %q nebo e-mail %q už existuje", username, email)
		}
		return err
	}

	st.Audit(ctx, store.AuditEntry{
		Action:        "user_created",
		Description:   user.Username + " (admin, přes bkctl)",
		ActorUsername: "bkctl",
		TargetType:    "user",
		TargetID:      &user.ID,
	})

	fmt.Printf("Administrátor %s (#%d) byl vytvořen.\n", user.Username, user.ID)
	return nil
}

func resetPassword(ctx context.Context, st *store.Store, username string) error {
	creds, err := st.GetCredentialsByUsername(ctx, username)
	if err != nil {
		if errors.Is(err, store.ErrNotFound) {
			return fmt.Errorf("účet %q neexistuje", username)
		}
		return err
	}

	password, err := readPassword()
	if err != nil {
		return err
	}

	hash, err := security.HashPassword(password, security.DefaultParams())
	if err != nil {
		return err
	}

	// revokeSessions=true — po resetu hesla nesmí zůstat nic přihlášené.
	if err := st.SetPassword(ctx, creds.ID, hash, true); err != nil {
		return err
	}

	st.Audit(ctx, store.AuditEntry{
		Action:        "password_reset_by_admin",
		Description:   creds.Username + " (přes bkctl)",
		ActorUsername: "bkctl",
		TargetType:    "user",
		TargetID:      &creds.ID,
	})

	fmt.Printf("Heslo účtu %s bylo změněno, všechny relace zrušeny.\n", creds.Username)
	return nil
}

// readPassword načte heslo bez vypisování na terminál a s potvrzením.
func readPassword() (string, error) {
	fd := int(syscall.Stdin)

	// Bez terminálu (skript, CI) se čte ze standardního vstupu.
	if !term.IsTerminal(fd) {
		scanner := bufio.NewScanner(os.Stdin)
		if !scanner.Scan() {
			return "", errors.New("nepodařilo se přečíst heslo ze vstupu")
		}
		return validated(strings.TrimSpace(scanner.Text()))
	}

	fmt.Print("Heslo (min. 12 znaků): ")
	first, err := term.ReadPassword(fd)
	fmt.Println()
	if err != nil {
		return "", err
	}

	fmt.Print("Heslo znovu: ")
	second, err := term.ReadPassword(fd)
	fmt.Println()
	if err != nil {
		return "", err
	}

	if string(first) != string(second) {
		return "", errors.New("hesla se neshodují")
	}

	return validated(string(first))
}

func validated(pw string) (string, error) {
	if len([]rune(pw)) < 12 {
		return "", errors.New("heslo musí mít alespoň 12 znaků")
	}
	return pw, nil
}

func loadDotEnv() {
	for _, filename := range []string{".env", "config.env"} {
		data, err := os.ReadFile(filename)
		if err != nil {
			continue
		}
		lines := strings.Split(string(data), "\n")
		for _, line := range lines {
			line = strings.TrimSpace(line)
			if line == "" || strings.HasPrefix(line, "#") {
				continue
			}
			parts := strings.SplitN(line, "=", 2)
			if len(parts) == 2 {
				key := strings.TrimSpace(parts[0])
				val := strings.TrimSpace(parts[1])
				val = strings.Trim(val, `"'`)
				if os.Getenv(key) == "" {
					_ = os.Setenv(key, val)
				}
			}
		}
	}
}
