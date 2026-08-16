// Package httpx drží sdílené HTTP chování: odpovědi, chyby a middleware.
package httpx

import (
	"encoding/json"
	"log/slog"
	"net/http"
)

// Error je chyba tak, jak ji uvidí klient.
//
// Odpověď nikdy nenese vnitřní detail (SQL, cestu k souboru, stack) —
// ten jde do logu. Původní systém posílal `$e->getMessage()` rovnou do
// JSON, což vypisovalo strukturu databáze každému, kdo trefil chybu.
type Error struct {
	Code    string `json:"code"`
	Message string `json:"message"`
	// Detaily k jednotlivým polím formuláře, pokud jde o validaci.
	Fields map[string]string `json:"fields,omitempty"`
}

type errorEnvelope struct {
	Error Error `json:"error"`
}

func JSON(w http.ResponseWriter, status int, payload any) {
	w.Header().Set("Content-Type", "application/json; charset=utf-8")
	w.WriteHeader(status)

	if payload == nil {
		return
	}
	if err := json.NewEncoder(w).Encode(payload); err != nil {
		// Hlavičky už odešly, takže se nedá odpovědět chybou — jen zalogovat.
		slog.Error("serializace odpovědi selhala", "err", err)
	}
}

func Fail(w http.ResponseWriter, status int, code, message string) {
	JSON(w, status, errorEnvelope{Error{Code: code, Message: message}})
}

func FailFields(w http.ResponseWriter, code, message string, fields map[string]string) {
	JSON(w, http.StatusUnprocessableEntity,
		errorEnvelope{Error{Code: code, Message: message, Fields: fields}})
}

// Běžné chyby jako pojmenované funkce, ať jsou hlášky konzistentní.

func Unauthorized(w http.ResponseWriter) {
	Fail(w, http.StatusUnauthorized, "unauthorized", "Přihlášení je vyžadováno.")
}

func Forbidden(w http.ResponseWriter) {
	Fail(w, http.StatusForbidden, "forbidden", "K této akci nemáte oprávnění.")
}

func NotFound(w http.ResponseWriter) {
	Fail(w, http.StatusNotFound, "not_found", "Záznam nebyl nalezen.")
}

func BadRequest(w http.ResponseWriter, message string) {
	Fail(w, http.StatusBadRequest, "bad_request", message)
}

// Internal zaloguje skutečnou příčinu a klientovi vrátí obecnou hlášku.
func Internal(w http.ResponseWriter, r *http.Request, err error) {
	slog.Error("neošetřená chyba",
		"err", err,
		"method", r.Method,
		"path", sanitizeForLog(r.URL.Path),
		"request_id", RequestID(r.Context()),
	)
	Fail(w, http.StatusInternalServerError, "internal_error", "Došlo k neočekávané chybě.")
}

// DecodeJSON načte tělo požadavku s limitem velikosti.
//
// Bez limitu by šlo poslat gigabajtové tělo a vyčerpat paměť procesu.
// DisallowUnknownFields odhalí překlep v názvu pole místo tichého ignorování.
func DecodeJSON(w http.ResponseWriter, r *http.Request, dst any) error {
	r.Body = http.MaxBytesReader(w, r.Body, 1<<20) // 1 MiB

	dec := json.NewDecoder(r.Body)
	dec.DisallowUnknownFields()
	return dec.Decode(dst)
}
