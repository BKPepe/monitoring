package store

import (
	"context"
	"net/netip"
	"time"
)

// Session je relace uložená na serveru. Token sám v databázi není —
// ukládá se jen jeho SHA-256 otisk.
type Session struct {
	UserID     int64
	CSRFToken  string
	MFAPending bool
	ExpiresAt  time.Time
	LastSeenAt time.Time
}

type NewSession struct {
	TokenHash  []byte
	UserID     int64
	CSRFToken  string
	MFAPending bool
	UserAgent  string
	IPAddress  *netip.Addr
	ExpiresAt  time.Time
}

func (s *Store) CreateSession(ctx context.Context, in NewSession) error {
	_, err := s.pool.Exec(ctx, `
		INSERT INTO sessions (token_hash, user_id, csrf_token, mfa_pending, user_agent, ip_address, expires_at)
		VALUES ($1, $2, $3, $4, $5, $6, $7)`,
		in.TokenHash, in.UserID, in.CSRFToken, in.MFAPending,
		nullString(in.UserAgent), in.IPAddress, in.ExpiresAt)
	return err
}

// GetSession vrátí platnou relaci a posune last_seen_at.
//
// Idle timeout se vyhodnocuje přímo v dotazu: relace nečinná déle než
// idleTTL se chová jako neexistující, i když ještě nevypršela absolutně.
func (s *Store) GetSession(ctx context.Context, tokenHash []byte, idleTTL time.Duration) (*Session, error) {
	var sess Session
	err := s.pool.QueryRow(ctx, `
		UPDATE sessions
		SET last_seen_at = now()
		WHERE token_hash = $1
		  AND expires_at > now()
		  AND last_seen_at > now() - $2::interval
		RETURNING user_id, csrf_token, mfa_pending, expires_at, last_seen_at`,
		tokenHash, idleTTL.String()).
		Scan(&sess.UserID, &sess.CSRFToken, &sess.MFAPending, &sess.ExpiresAt, &sess.LastSeenAt)

	if err != nil {
		return nil, normalizeErr(err)
	}
	return &sess, nil
}

// PromoteSession označí relaci za plně ověřenou po druhém faktoru.
func (s *Store) PromoteSession(ctx context.Context, tokenHash []byte) error {
	_, err := s.pool.Exec(ctx,
		`UPDATE sessions SET mfa_pending = false WHERE token_hash = $1`, tokenHash)
	return err
}

func (s *Store) DeleteSession(ctx context.Context, tokenHash []byte) error {
	_, err := s.pool.Exec(ctx, `DELETE FROM sessions WHERE token_hash = $1`, tokenHash)
	return err
}

// DeleteUserSessions odhlásí uživatele na všech zařízeních.
func (s *Store) DeleteUserSessions(ctx context.Context, userID int64) error {
	_, err := s.pool.Exec(ctx, `DELETE FROM sessions WHERE user_id = $1`, userID)
	return err
}

// PurgeExpiredSessions maže prošlé relace. Volá se na pozadí — bez úklidu
// by tabulka rostla donekonečna.
func (s *Store) PurgeExpiredSessions(ctx context.Context) (int64, error) {
	tag, err := s.pool.Exec(ctx, `DELETE FROM sessions WHERE expires_at < now()`)
	if err != nil {
		return 0, err
	}
	return tag.RowsAffected(), nil
}

func nullString(s string) any {
	if s == "" {
		return nil
	}
	return s
}
