package api

import (
	"database/sql"
	"net/http"
	"strconv"
	"time"

	"github.com/kmsitcomputer/primeclassy-go/internal/httpx"
	"github.com/kmsitcomputer/primeclassy-go/internal/i18n"
)

// handleItemReceiptsIndex is GET /order-items/{item}/receipts (admin own-agent / super_admin).
func (s *Server) handleItemReceiptsIndex(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	id, err := strconv.ParseInt(httpx.PathValue(r, "item"), 10, 64)
	if err != nil {
		writeError(w, r, httpx.NewError(http.StatusNotFound, msg(ctx, "system.endpoint_not_found")))
		return
	}
	rows, aerr := s.dispatchService().ItemReceipts(ctx, dispatchActor(r), id)
	if aerr != nil {
		writeDispatchError(w, r, aerr)
		return
	}
	httpx.OK(w, rows, "OK", nil)
}

// handleItemReceiptsStore is POST /order-items/{item}/receipts.
func (s *Server) handleItemReceiptsStore(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	id, err := strconv.ParseInt(httpx.PathValue(r, "item"), 10, 64)
	if err != nil {
		writeError(w, r, httpx.NewError(http.StatusNotFound, msg(ctx, "system.endpoint_not_found")))
		return
	}
	key := r.Header.Get("Idempotency-Key")
	if len(key) < 8 || len(key) > 100 {
		writeError(w, r, httpx.NewError(422, "Header Idempotency-Key (8\u2013100 karakter) wajib."))
		return
	}
	var body struct {
		Quantity        *int64  `json:"quantity"`
		ReceivedOn      *string `json:"received_on"`
		ReceivedAt      *string `json:"received_at"`
		EvidenceMediaID *int64  `json:"evidence_media_id"`
		EvidenceNote    *string `json:"evidence_note"`
	}
	_ = httpx.DecodeJSON(r, &body)
	fields := fieldErrors{}
	if body.Quantity == nil {
		fields.add("quantity", i18n.ValidationMessage(LocaleFrom(ctx), "required", "quantity"))
	} else if *body.Quantity < 1 {
		fields.add("quantity", i18n.ValidationMessage(LocaleFrom(ctx), "min.numeric", "quantity", "min", "1"))
	}
	if body.ReceivedOn == nil || *body.ReceivedOn == "" {
		fields.add("received_on", i18n.ValidationMessage(LocaleFrom(ctx), "required", "received_on"))
	}
	if body.EvidenceNote != nil && len([]rune(*body.EvidenceNote)) > 500 {
		fields.add("evidence_note", i18n.ValidationMessage(LocaleFrom(ctx), "max.string", "evidence_note", "max", "500"))
	}
	if len(fields) > 0 {
		writeError(w, r, httpx.Validation(map[string][]string(fields)))
		return
	}
	if _, derr := time.Parse("2006-01-02", *body.ReceivedOn); derr != nil {
		writeError(w, r, httpx.Validation(map[string][]string{"received_on": {
			"received on tidak sesuai format Y-m-d."}}))
		return
	}
	var receivedAt sql.NullString
	if body.ReceivedAt != nil {
		receivedAt = sql.NullString{String: *body.ReceivedAt, Valid: true}
	}
	var evidenceMedia sql.NullInt64
	if body.EvidenceMediaID != nil {
		var exists bool
		if err := s.database.QueryRowContext(ctx, `SELECT EXISTS(SELECT 1 FROM media WHERE id = ?)`,
			*body.EvidenceMediaID).Scan(&exists); err == nil && !exists {
			writeError(w, r, httpx.Validation(map[string][]string{"evidence_media_id": {
				i18n.ValidationMessage(LocaleFrom(ctx), "exists", "evidence_media_id")}}))
			return
		}
		evidenceMedia = sql.NullInt64{Int64: *body.EvidenceMediaID, Valid: true}
	}
	var evidenceNote sql.NullString
	if body.EvidenceNote != nil {
		evidenceNote = sql.NullString{String: *body.EvidenceNote, Valid: true}
	}
	result, aerr := s.dispatchService().RecordItemReceipt(ctx, dispatchActor(r), id, *body.Quantity,
		*body.ReceivedOn, receivedAt, evidenceMedia, evidenceNote, key)
	if aerr != nil {
		writeDispatchError(w, r, aerr)
		return
	}
	httpx.Created(w, result, "Created")
}
