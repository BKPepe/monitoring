package store

import (
	"context"
	"log/slog"
	"net/netip"
	"time"
)

// --- Pokusy o přihlášení -------------------------------------------------

func (s *Store) RecordLoginAttempt(ctx context.Context, username string, ip *netip.Addr, ok bool) {
	if _, err := s.pool.Exec(ctx, `
		INSERT INTO login_attempts (username, ip_address, successful)
		VALUES ($1, $2, $3)`, username, ip, ok); err != nil {
		// Neúspěch zápisu nesmí zablokovat přihlášení, ale musí být vidět —
		// bez těchto záznamů přestane fungovat omezení pokusů.
		slog.Error("zápis pokusu o přihlášení selhal", "err", err)
	}
}

// FailedAttempts spočítá neúspěšné pokusy v okně.
//
// Počítá se zvlášť podle jména a zvlášť podle IP. Jen podle jména by šlo
// útočit na mnoho účtů z jedné adresy; jen podle IP by zase jeden uživatel
// za NATem zablokoval celou síť.
func (s *Store) FailedAttempts(ctx context.Context, username string, ip *netip.Addr, window time.Duration) (byUsername, byIP int, err error) {
	err = s.pool.QueryRow(ctx, `
		SELECT
			COUNT(*) FILTER (WHERE lower(username) = lower($1)),
			COUNT(*) FILTER (WHERE $2::inet IS NOT NULL AND ip_address = $2::inet)
		FROM login_attempts
		WHERE NOT successful AND attempted_at > now() - $3::interval`,
		username, ip, window.String()).Scan(&byUsername, &byIP)
	return
}

// PurgeOldLoginAttempts drží tabulku malou; starší záznamy nemají účel.
func (s *Store) PurgeOldLoginAttempts(ctx context.Context, olderThan time.Duration) (int64, error) {
	tag, err := s.pool.Exec(ctx,
		`DELETE FROM login_attempts WHERE attempted_at < now() - $1::interval`,
		olderThan.String())
	if err != nil {
		return 0, err
	}
	return tag.RowsAffected(), nil
}

// --- Audit ---------------------------------------------------------------

type AuditEntry struct {
	Action        string
	Description   string
	ActorUserID   *int64
	ActorUsername string
	TargetType    string
	TargetID      *int64
	IPAddress     *netip.Addr
}

// Audit zapíše záznam do auditní stopy.
//
// Selhání zápisu nesmí shodit operaci, kterou zaznamenává — ale nesmí ani
// zmizet beze stopy, proto se loguje jako chyba.
func (s *Store) Audit(ctx context.Context, e AuditEntry) {
	if _, err := s.pool.Exec(ctx, `
		INSERT INTO audit_log
			(action, description, actor_user_id, actor_username, target_type, target_id, ip_address)
		VALUES ($1, $2, $3, $4, $5, $6, $7)`,
		e.Action, nullString(e.Description), e.ActorUserID, nullString(e.ActorUsername),
		nullString(e.TargetType), e.TargetID, e.IPAddress); err != nil {
		slog.Error("zápis do auditu selhal", "action", e.Action, "err", err)
	}
}
