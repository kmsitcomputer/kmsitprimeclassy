package api

import (
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"strconv"
	"strings"

	"github.com/kmsitcomputer/primeclassy-go/internal/consent"
	"github.com/kmsitcomputer/primeclassy-go/internal/httpx"
	"github.com/kmsitcomputer/primeclassy-go/internal/i18n"
)

func (s *Server) handleConsentCapture(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	var subjectID, orderID, challengeID int64
	var purpose, note, consentKey string
	var scope []consent.ScopeLine
	var evidence []byte
	var evidenceName string
	if strings.HasPrefix(r.Header.Get("Content-Type"), "multipart/form-data") {
		if err := r.ParseMultipartForm(8 << 20); err != nil {
			writeError(w, r, httpx.NewError(http.StatusBadRequest, msg(ctx, "system.generic_error")))
			return
		}
		if v := r.FormValue("subject_user_id"); v != "" {
			subjectID, _ = strconv.ParseInt(v, 10, 64)
		}
		if v := r.FormValue("order_id"); v != "" {
			orderID, _ = strconv.ParseInt(v, 10, 64)
		}
		if v := r.FormValue("otp_challenge_id"); v != "" {
			challengeID, _ = strconv.ParseInt(v, 10, 64)
		}
		purpose = r.FormValue("purpose")
		note = r.FormValue("note")
		consentKey = r.FormValue("consent_key")
		if v := r.FormValue("scope[0][order_item_id]"); v != "" {
			// Multipart array form: scope[0][order_item_id]=..&scope[0][quantity]=..
			for i := 0; ; i++ {
				itemID := r.FormValue(fmt.Sprintf("scope[%d][order_item_id]", i))
				qty := r.FormValue(fmt.Sprintf("scope[%d][quantity]", i))
				if itemID == "" || qty == "" {
					break
				}
				oid, _ := strconv.ParseInt(itemID, 10, 64)
				q, _ := strconv.ParseInt(qty, 10, 64)
				scope = append(scope, consent.ScopeLine{OrderItemID: oid, Quantity: q})
			}
		}
		if v := r.FormValue("scope"); v != "" && len(scope) == 0 {
			_ = json.Unmarshal([]byte(v), &scope)
		}
		if files, ok := r.MultipartForm.File["evidence"]; ok && len(files) > 0 {
			handle, oerr := files[0].Open()
			if oerr == nil {
				content, rerr := io.ReadAll(io.LimitReader(handle, 12<<20))
				handle.Close()
				if rerr == nil {
					evidence = content
					evidenceName = files[0].Filename
				}
			}
		}
	} else {
		var body struct {
			SubjectUserID  *int64              `json:"subject_user_id"`
			OrderID        *int64              `json:"order_id"`
			Purpose        *string             `json:"purpose"`
			OTPChallengeID *int64              `json:"otp_challenge_id"`
			Note           *string             `json:"note"`
			ConsentKey     *string             `json:"consent_key"`
			Scope          []consent.ScopeLine `json:"scope"`
		}
		_ = httpx.DecodeJSON(r, &body)
		if body.SubjectUserID != nil {
			subjectID = *body.SubjectUserID
		}
		if body.OrderID != nil {
			orderID = *body.OrderID
		}
		if body.OTPChallengeID != nil {
			challengeID = *body.OTPChallengeID
		}
		if body.Purpose != nil {
			purpose = *body.Purpose
		}
		if body.Note != nil {
			note = *body.Note
		}
		if body.ConsentKey != nil {
			consentKey = *body.ConsentKey
		}
		scope = body.Scope
	}
	fields := fieldErrors{}
	if subjectID == 0 {
		fields.add("subject_user_id", i18n.ValidationMessage(LocaleFrom(ctx), "required", "subject_user_id"))
	}
	if orderID == 0 {
		fields.add("order_id", i18n.ValidationMessage(LocaleFrom(ctx), "required", "order_id"))
	}
	if purpose == "" {
		fields.add("purpose", i18n.ValidationMessage(LocaleFrom(ctx), "required", "purpose"))
	} else if purpose != "assisted_return" {
		fields.add("purpose", i18n.ValidationMessage(LocaleFrom(ctx), "in", "purpose"))
	}
	if challengeID == 0 {
		fields.add("otp_challenge_id", i18n.ValidationMessage(LocaleFrom(ctx), "required", "otp_challenge_id"))
	}
	if evidence == nil {
		fields.add("evidence", i18n.ValidationMessage(LocaleFrom(ctx), "required", "evidence"))
	}
	if note == "" {
		fields.add("note", i18n.ValidationMessage(LocaleFrom(ctx), "required", "note"))
	} else if len([]rune(note)) > 1000 {
		fields.add("note", i18n.ValidationMessage(LocaleFrom(ctx), "max.string", "note", "max", "1000"))
	}
	if len(scope) == 0 {
		fields.add("scope", i18n.ValidationMessage(LocaleFrom(ctx), "required", "scope"))
	}
	if len(fields) > 0 {
		writeError(w, r, httpx.Validation(map[string][]string(fields)))
		return
	}
	result, err := s.consentService().Capture(ctx, consentActor(r), subjectID, orderID, purpose,
		scope, evidence, evidenceName, note, challengeID, consentKey)
	if err != nil {
		writeConsentError(w, r, err)
		return
	}
	httpx.Created(w, result, "Persetujuan tercatat.")
}

func (s *Server) handleConsentAppend(w http.ResponseWriter, r *http.Request, newType, message string) {
	ctx := r.Context()
	id, err := strconv.ParseInt(httpx.PathValue(r, "consentEvent"), 10, 64)
	if err != nil {
		writeError(w, r, httpx.NewError(http.StatusNotFound, msg(ctx, "system.endpoint_not_found")))
		return
	}
	var body struct {
		Reason *string `json:"reason"`
	}
	_ = httpx.DecodeJSON(r, &body)
	if body.Reason == nil || *body.Reason == "" {
		writeError(w, r, httpx.Validation(map[string][]string{"reason": {
			i18n.ValidationMessage(LocaleFrom(ctx), "required", "reason")}}))
		return
	}
	if len([]rune(*body.Reason)) > 1000 {
		writeError(w, r, httpx.Validation(map[string][]string{"reason": {
			i18n.ValidationMessage(LocaleFrom(ctx), "max.string", "reason", "max", "1000")}}))
		return
	}
	result, err := s.consentService().Append(ctx, consentActor(r), id, newType, *body.Reason)
	if err != nil {
		writeConsentError(w, r, err)
		return
	}
	httpx.Created(w, result, message)
}

func (s *Server) handleConsentWithdraw(w http.ResponseWriter, r *http.Request) {
	s.handleConsentAppend(w, r, "consent_withdrawn", "Persetujuan ditarik.")
}

func (s *Server) handleConsentDispute(w http.ResponseWriter, r *http.Request) {
	s.handleConsentAppend(w, r, "consent_disputed", "Persetujuan disengketakan.")
}

func (s *Server) handleConsentReinstate(w http.ResponseWriter, r *http.Request) {
	s.handleConsentAppend(w, r, "consent_reinstated", "Persetujuan dipulihkan.")
}

func (s *Server) handleOrderConsentEvents(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	id, err := strconv.ParseInt(httpx.PathValue(r, "order"), 10, 64)
	if err != nil {
		writeError(w, r, httpx.NewError(http.StatusNotFound, msg(ctx, "system.endpoint_not_found")))
		return
	}
	actor := UserFrom(ctx)
	allowed, verr := s.orderService().CanView(ctx, roleScopesOf(actor), id)
	if verr != nil {
		writeError(w, r, orderError(ctx, verr))
		return
	}
	if !allowed {
		writeError(w, r, &httpx.Error{Status: http.StatusNotFound, Message: msg(ctx, "system.not_found")})
		return
	}
	events, aerr := s.consentService().IndexForOrder(ctx, id)
	if aerr != nil {
		writeConsentError(w, r, aerr)
		return
	}
	httpx.OK(w, events, "OK", nil)
}

func (s *Server) handleOrderConsentContext(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	id, err := strconv.ParseInt(httpx.PathValue(r, "order"), 10, 64)
	if err != nil {
		writeError(w, r, httpx.NewError(http.StatusNotFound, msg(ctx, "system.endpoint_not_found")))
		return
	}
	actor := UserFrom(ctx)
	allowed, verr := s.orderService().CanView(ctx, roleScopesOf(actor), id)
	if verr != nil {
		writeError(w, r, orderError(ctx, verr))
		return
	}
	if !allowed {
		writeError(w, r, &httpx.Error{Status: http.StatusNotFound, Message: msg(ctx, "system.not_found")})
		return
	}
	payload, aerr := s.consentService().Context(ctx, consentActor(r), id)
	if aerr != nil {
		writeConsentError(w, r, aerr)
		return
	}
	httpx.OK(w, payload, "OK", nil)
}
