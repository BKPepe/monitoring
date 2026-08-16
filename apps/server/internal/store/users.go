package store

import (
	"context"
	"time"
)

// Role jsou hodnoty výčtu user_role ve schématu.
const (
	RoleAdmin  = "admin"
	RoleEditor = "editor"
	RoleViewer = "viewer"
)

// ValidRole hlídá, že do databáze nepůjde nic mimo výčet. Databáze by to
// odmítla také, ale chceme srozumitelnou chybu, ne SQL výjimku.
func ValidRole(role string) bool {
	switch role {
	case RoleAdmin, RoleEditor, RoleViewer:
		return true
	default:
		return false
	}
}

// User je veřejná podoba účtu — nikdy neobsahuje hash hesla ani TOTP secret.
type User struct {
	ID            int64      `json:"id"`
	Username      string     `json:"username"`
	Email         string     `json:"email"`
	Phone         *string    `json:"phone"`
	Role          string     `json:"role"`
	TOTPEnabled   bool       `json:"totpEnabled"`
	OAuthProvider *string    `json:"oauthProvider"`
	IsActive      bool       `json:"isActive"`
	LastLoginAt   *time.Time `json:"lastLoginAt"`
	CreatedAt     time.Time  `json:"createdAt"`
}

// Credentials nese hash hesla. Vzniká jen při přihlašování a nikdy
// neopouští balík store v serializované podobě.
type Credentials struct {
	User
	PasswordHash string
}

const userColumns = `
	id, username, email, phone, role::text, totp_enabled,
	oauth_provider, is_active, last_login_at, created_at`

func (s *Store) ListUsers(ctx context.Context) ([]User, error) {
	rows, err := s.pool.Query(ctx, `
		SELECT `+userColumns+`
		FROM users
		ORDER BY role, lower(username)`)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	users := []User{}
	for rows.Next() {
		var u User
		if err := rows.Scan(&u.ID, &u.Username, &u.Email, &u.Phone, &u.Role,
			&u.TOTPEnabled, &u.OAuthProvider, &u.IsActive, &u.LastLoginAt,
			&u.CreatedAt); err != nil {
			return nil, err
		}
		users = append(users, u)
	}

	return users, rows.Err()
}

func (s *Store) GetUser(ctx context.Context, id int64) (*User, error) {
	var u User
	err := s.pool.QueryRow(ctx, `
		SELECT `+userColumns+` FROM users WHERE id = $1`, id).
		Scan(&u.ID, &u.Username, &u.Email, &u.Phone, &u.Role, &u.TOTPEnabled,
			&u.OAuthProvider, &u.IsActive, &u.LastLoginAt, &u.CreatedAt)

	if err != nil {
		return nil, normalizeErr(err)
	}
	return &u, nil
}

// GetCredentialsByUsername hledá bez ohledu na velikost písmen — index
// users_username_lower_key zaručuje, že výsledek je nejvýš jeden.
func (s *Store) GetCredentialsByUsername(ctx context.Context, username string) (*Credentials, error) {
	var c Credentials
	err := s.pool.QueryRow(ctx, `
		SELECT `+userColumns+`, password_hash
		FROM users WHERE lower(username) = lower($1)`, username).
		Scan(&c.ID, &c.Username, &c.Email, &c.Phone, &c.Role, &c.TOTPEnabled,
			&c.OAuthProvider, &c.IsActive, &c.LastLoginAt, &c.CreatedAt,
			&c.PasswordHash)

	if err != nil {
		return nil, normalizeErr(err)
	}
	return &c, nil
}

type NewUser struct {
	Username     string
	Email        string
	Phone        *string
	Role         string
	PasswordHash string
}

func (s *Store) CreateUser(ctx context.Context, in NewUser) (*User, error) {
	var u User
	err := s.pool.QueryRow(ctx, `
		INSERT INTO users (username, email, phone, role, password_hash)
		VALUES ($1, $2, $3, $4::user_role, $5)
		RETURNING `+userColumns,
		in.Username, in.Email, in.Phone, in.Role, in.PasswordHash).
		Scan(&u.ID, &u.Username, &u.Email, &u.Phone, &u.Role, &u.TOTPEnabled,
			&u.OAuthProvider, &u.IsActive, &u.LastLoginAt, &u.CreatedAt)

	if err != nil {
		return nil, normalizeErr(err)
	}
	return &u, nil
}

type UserUpdate struct {
	Username string
	Email    string
	Phone    *string
	Role     string
	IsActive bool
}

func (s *Store) UpdateUser(ctx context.Context, id int64, in UserUpdate) (*User, error) {
	var u User
	err := s.pool.QueryRow(ctx, `
		UPDATE users
		SET username = $2, email = $3, phone = $4, role = $5::user_role, is_active = $6
		WHERE id = $1
		RETURNING `+userColumns,
		id, in.Username, in.Email, in.Phone, in.Role, in.IsActive).
		Scan(&u.ID, &u.Username, &u.Email, &u.Phone, &u.Role, &u.TOTPEnabled,
			&u.OAuthProvider, &u.IsActive, &u.LastLoginAt, &u.CreatedAt)

	if err != nil {
		return nil, normalizeErr(err)
	}
	return &u, nil
}

// SetPassword přepíše hash a zaznamená čas změny.
//
// Zároveň zruší všechny relace daného uživatele: po změně hesla nesmí
// zůstat přihlášené staré zařízení. Původní systém tohle nedělal.
func (s *Store) SetPassword(ctx context.Context, id int64, hash string, revokeSessions bool) error {
	tx, err := s.pool.Begin(ctx)
	if err != nil {
		return err
	}
	defer tx.Rollback(ctx)

	if _, err := tx.Exec(ctx, `
		UPDATE users SET password_hash = $2, password_changed_at = now() WHERE id = $1`,
		id, hash); err != nil {
		return normalizeErr(err)
	}

	if revokeSessions {
		if _, err := tx.Exec(ctx, `DELETE FROM sessions WHERE user_id = $1`, id); err != nil {
			return err
		}
	}

	return tx.Commit(ctx)
}

// RehashPassword přepíše hash beze změny hesla (migrace bcrypt → argon2id).
// Relace se neruší — uživatel právě prošel ověřením a nic se pro něj nemění.
func (s *Store) RehashPassword(ctx context.Context, id int64, hash string) error {
	_, err := s.pool.Exec(ctx, `UPDATE users SET password_hash = $2 WHERE id = $1`, id, hash)
	return err
}

func (s *Store) DeleteUser(ctx context.Context, id int64) error {
	tag, err := s.pool.Exec(ctx, `DELETE FROM users WHERE id = $1`, id)
	if err != nil {
		return normalizeErr(err)
	}
	if tag.RowsAffected() == 0 {
		return ErrNotFound
	}
	return nil
}

// CountActiveAdmins slouží k pojistce proti odstranění posledního správce.
func (s *Store) CountActiveAdmins(ctx context.Context, excludeID int64) (int, error) {
	var n int
	err := s.pool.QueryRow(ctx, `
		SELECT COUNT(*) FROM users
		WHERE role = 'admin' AND is_active AND id <> $1`, excludeID).Scan(&n)
	return n, err
}

func (s *Store) TouchLastLogin(ctx context.Context, id int64) error {
	_, err := s.pool.Exec(ctx, `UPDATE users SET last_login_at = now() WHERE id = $1`, id)
	return err
}
