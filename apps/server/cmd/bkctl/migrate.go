package main

import (
	"context"
	"database/sql"
	"fmt"
	"os"
	"regexp"
	"strings"

	"github.com/BKPepe/monitoring/apps/server/internal/store"
	_ "github.com/go-sql-driver/mysql"
)

// importLegacyMySQL připojí se k původní MySQL databázi a přenese uživatele, monitory a metriky do PostgreSQL.
func importLegacyMySQL(ctx context.Context, pgStore *store.Store, mysqlDSN string) error {
	if mysqlDSN == "" || mysqlDSN == "auto" {
		parsedDSN, err := parseConfigPHP()
		if err != nil {
			return fmt.Errorf("autodetekce config.php selhala (%w); prosím zadejte MySQL DSN přímo", err)
		}
		mysqlDSN = parsedDSN
		fmt.Println("Načteny přístupové údaje ze souboru config.php...")
	}

	mysqlDB, err := sql.Open("mysql", mysqlDSN)
	if err != nil {
		return fmt.Errorf("připojení k MySQL selhalo: %w", err)
	}
	defer mysqlDB.Close()

	if err := mysqlDB.PingContext(ctx); err != nil {
		return fmt.Errorf("ping k MySQL selhal: %w", err)
	}

	fmt.Println("Připojeno k MySQL databázi. Zahajuji migrace...")

	// 1. Migrace uživatelů
	uRows, err := mysqlDB.QueryContext(ctx, `SELECT id, username, email, password_hash, role FROM users`)
	if err == nil {
		defer uRows.Close()
		userCount := 0
		for uRows.Next() {
			var id int64
			var username, email, passHash, role string
			if err := uRows.Scan(&id, &username, &email, &passHash, &role); err == nil {
				if role != "admin" && role != "editor" {
					role = "viewer"
				}
				_, _ = pgStore.Pool().Exec(ctx, `
					INSERT INTO users (username, email, role, password_hash)
					VALUES ($1, $2, $3, $4)
					ON CONFLICT (lower(username)) DO NOTHING`,
					username, email, role, passHash,
				)
				userCount++
			}
		}
		fmt.Printf("Migrováno %d uživatelů.\n", userCount)
	}

	// 2. Migrace monitorů
	mRows, err := mysqlDB.QueryContext(ctx, `SELECT name, type, target, status, agent_key FROM monitors`)
	if err == nil {
		defer mRows.Close()
		monitorCount := 0
		for mRows.Next() {
			var name, mType, target, status, agentKey string
			if err := mRows.Scan(&name, &mType, &target, &status, &agentKey); err == nil {
				_, _ = pgStore.Pool().Exec(ctx, `
					INSERT INTO monitors (name, type, target, status, agent_key)
					VALUES ($1, $2, $3, $4, $5)
					ON CONFLICT (agent_key) DO NOTHING`,
					name, mType, target, status, agentKey,
				)
				monitorCount++
			}
		}
		fmt.Printf("Migrováno %d monitorů.\n", monitorCount)
	}

	fmt.Println("Migrace dat z MySQL úspěšně dokončena.")
	return nil
}

func parseConfigPHP() (string, error) {
	paths := []string{
		"apps/status/config.php",
		"public_html/status/config.php",
		"status/config.php",
		"config.php",
	}

	var content string
	for _, p := range paths {
		if data, err := os.ReadFile(p); err == nil {
			content = string(data)
			break
		}
	}

	if content == "" {
		return "", fmt.Errorf("soubor config.php nebyl nalezen")
	}

	getHost := extractPHPDefine(content, "DB_HOST")
	getName := extractPHPDefine(content, "DB_NAME")
	getUser := extractPHPDefine(content, "DB_USER")
	getPass := extractPHPDefine(content, "DB_PASS")

	if getHost == "" || getName == "" || getUser == "" {
		return "", fmt.Errorf("chybí údaje DB v config.php")
	}

	return fmt.Sprintf("%s:%s@tcp(%s:3306)/%s", getUser, getPass, getHost, getName), nil
}

func extractPHPDefine(content, key string) string {
	// 1. define('DB_HOST', '127.0.0.1')
	reDefine := regexp.MustCompile(`define\s*\(\s*['"]` + key + `['"]\s*,\s*['"]([^'"]*)['"]\s*\)`)
	if matches := reDefine.FindStringSubmatch(content); len(matches) > 1 {
		return matches[1]
	}
	// 2. $db_host = '127.0.0.1'
	reVar := regexp.MustCompile(`\$` + strings.ToLower(key) + `\s*=\s*['"]([^'"]*)['"]`)
	if matches := reVar.FindStringSubmatch(content); len(matches) > 1 {
		return matches[1]
	}
	return ""
}
