// Package httpx holds the HTTP contract shared by every handler: the response envelope, the API error
// type, and decoding helpers.
//
// The envelope is byte-for-byte the shape produced by App\Support\ApiResponse in the Laravel app:
//
//	{ "success": bool, "message": string, "data": any, "errors": any, "meta": any }
//
// Every field is always present (data/errors/meta may be null) because the Vue SPA and the Nuxt
// storefront both read `data` unconditionally.
package httpx

import (
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"strconv"
	"strings"
)

// Envelope is the single response shape of the API.
type Envelope struct {
	Success bool   `json:"success"`
	Message string `json:"message"`
	Data    any    `json:"data"`
	Errors  any    `json:"errors"`
	Meta    any    `json:"meta"`
}

// Meta is the pagination block.
//
// It contains ONLY the three keys the Laravel controllers actually pass (`current_page`, `last_page`,
// `total`). LengthAwarePaginator would also expose per_page/first_page/last_page urls, but the API never
// serialises the paginator — each controller builds the meta array by hand. Adding keys here would be a
// contract difference, so the shape is pinned to the observed response.
type Meta struct {
	CurrentPage int `json:"current_page"`
	LastPage    int `json:"last_page"`
	Total       int `json:"total"`
}

// NewMeta builds the pagination block.
func NewMeta(current, last, total int) Meta {
	if current < 1 {
		current = 1
	}
	if last < 1 {
		last = 1
	}
	return Meta{CurrentPage: current, LastPage: last, Total: total}
}

// WriteJSON writes status + body with the headers the API always sends.
func WriteJSON(w http.ResponseWriter, status int, body any) {
	buf, err := json.Marshal(body)
	if err != nil {
		// Marshal failure is a programming error, not a client error: never leak internals.
		w.Header().Set("Content-Type", "application/json; charset=utf-8")
		w.WriteHeader(http.StatusInternalServerError)
		_, _ = w.Write([]byte(`{"success":false,"message":"Terjadi kesalahan pada server.","data":null,"errors":null,"meta":null}`))
		return
	}
	w.Header().Set("Content-Type", "application/json; charset=utf-8")
	// The API is session/CSRF protected and per-user; a shared cache must never hold a response.
	w.Header().Set("Cache-Control", "no-store, private")
	w.WriteHeader(status)
	_, _ = w.Write(buf)
}

// OK writes a success envelope.
func OK(w http.ResponseWriter, data any, message string, meta any) {
	WriteJSON(w, http.StatusOK, Envelope{Success: true, Message: message, Data: data, Meta: meta})
}

// Created writes a 201 success envelope.
func Created(w http.ResponseWriter, data any, message string) {
	WriteJSON(w, http.StatusCreated, Envelope{Success: true, Message: message, Data: data})
}

// Failure writes an error envelope. errors is a map for 422 validation failures, otherwise nil.
func Failure(w http.ResponseWriter, status int, message string, errors any) {
	WriteJSON(w, status, Envelope{Success: false, Message: message, Errors: errors})
}

// Error is an error that already knows its HTTP status and (optionally) field errors. Handlers return it
// and the middleware turns it into an envelope, so status codes never drift from the Laravel behaviour.
type Error struct {
	Status  int
	Message string
	// Fields is the 422 `errors` payload: field -> []message.
	Fields map[string][]string
	// Code is an optional machine-readable code inside errors["code"], used by a few endpoints
	// (e.g. registration_unavailable) exactly as the Laravel implementation does.
	Code string
	// Err is the wrapped cause, for logs only — never serialised.
	Err error
}

func (e *Error) Error() string {
	if e.Err != nil {
		return fmt.Sprintf("%s: %v", e.Message, e.Err)
	}
	return e.Message
}

func (e *Error) Unwrap() error { return e.Err }

// ErrorsPayload renders the `errors` member exactly like Laravel: field errors, or {"code": [...]} when a
// machine-readable code was attached, or null.
func (e *Error) ErrorsPayload() any {
	if len(e.Fields) > 0 {
		out := make(map[string]any, len(e.Fields)+1)
		for k, v := range e.Fields {
			out[k] = v
		}
		if e.Code != "" {
			out["code"] = []string{e.Code}
		}
		return out
	}
	if e.Code != "" {
		return map[string]any{"code": []string{e.Code}}
	}
	return nil
}

// Validation builds a 422 error with per-field messages.
//
// Message is intentionally left empty: the transport layer fills it with the localised
// `messages.system.validation_failed` text (Laravel's ValidationException renderer does the same), so callers do
// not have to know the request locale.
func Validation(fields map[string][]string) *Error {
	return &Error{Status: http.StatusUnprocessableEntity, Fields: fields}
}

// NewError builds an error with a status and message.
func NewError(status int, message string) *Error {
	return &Error{Status: status, Message: message}
}

// DecodeJSON decodes a JSON request body into dst.
//
// It rejects unknown fields only when the caller asks (strict=false everywhere today, because the Laravel
// side ignores extra keys and the SPA sends a few presentation-only ones). An empty body is not an error:
// several endpoints are valid with no payload.
func DecodeJSON(r *http.Request, dst any) error {
	if r.Body == nil {
		return nil
	}
	dec := json.NewDecoder(io.LimitReader(r.Body, maxDecodeBytes))
	if err := dec.Decode(dst); err != nil {
		if err == io.EOF {
			return nil
		}
		return Validation(map[string][]string{"body": {"The body must be valid JSON."}})
	}
	return nil
}

const maxDecodeBytes = 8 << 20

// PathValue returns a decoded path parameter.
func PathValue(r *http.Request, name string) string {
	return strings.TrimSpace(r.PathValue(name))
}

// QueryInt reads an integer query parameter with a default and a floor.
func QueryInt(r *http.Request, name string, def, min int) int {
	raw := strings.TrimSpace(r.URL.Query().Get(name))
	if raw == "" {
		return def
	}
	n, err := strconv.Atoi(raw)
	if err != nil || n < min {
		return def
	}
	return n
}

// QueryBool mirrors Laravel's $request->boolean(): "1", "true", "on", "yes" are true.
func QueryBool(r *http.Request, name string) bool {
	switch strings.ToLower(strings.TrimSpace(r.URL.Query().Get(name))) {
	case "1", "true", "on", "yes":
		return true
	}
	return false
}

// QueryString returns a trimmed query parameter.
func QueryString(r *http.Request, name string) string {
	return strings.TrimSpace(r.URL.Query().Get(name))
}
