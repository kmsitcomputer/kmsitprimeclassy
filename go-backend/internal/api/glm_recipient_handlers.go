package api

import (
	"errors"
	"io"
	"net/http"
	"strconv"
	"strings"

	"github.com/kmsitcomputer/primeclassy-go/internal/crm"
	"github.com/kmsitcomputer/primeclassy-go/internal/httpx"
	"github.com/kmsitcomputer/primeclassy-go/internal/i18n"
)

func recipientActorFrom(r *http.Request) crm.RecipientActor {
	u := UserFrom(r.Context())
	return crm.RecipientActor{ID: u.ID, Role: u.Role, AgentID: derefInt(u.AgentID), IsSuper: u.Role == "super_admin"}
}

func writeRecipientError(w http.ResponseWriter, r *http.Request, err error) {
	var de *crm.DomainError
	if errors.As(err, &de) {
		m := de.Message
		if de.Status == http.StatusNotFound || de.Message == "" {
			m = msg(r.Context(), "system.endpoint_not_found")
		}
		if de.Fields != nil {
			writeError(w, r, &httpx.Error{Status: de.Status, Message: m, ErrorsAny: de.Fields})
			return
		}
		writeError(w, r, httpx.NewError(de.Status, m))
		return
	}
	writeError(w, r, err)
}

func (s *Server) handleOrderRecipientIndex(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	id, err := strconv.ParseInt(httpx.PathValue(r, "order"), 10, 64)
	if err != nil {
		writeError(w, r, httpx.NewError(http.StatusNotFound, msg(ctx, "system.endpoint_not_found")))
		return
	}
	actor := UserFrom(ctx)
	var orderAgent int64
	if err := s.database.QueryRowContext(ctx, `SELECT agent_id FROM orders WHERE id = ?`, id).Scan(&orderAgent); err != nil {
		// Model binding 404: a missing order renders the route-not-found message.
		writeError(w, r, &httpx.Error{Status: http.StatusNotFound, Message: msg(ctx, "system.endpoint_not_found")})
		return
	}
	allowed, verr := s.orderService().CanView(ctx, roleScopesOf(actor), id)
	if verr != nil {
		writeError(w, r, orderError(ctx, verr))
		return
	}
	if !allowed {
		writeError(w, r, &httpx.Error{Status: http.StatusNotFound, Message: msg(ctx, "system.not_found")})
		return
	}
	rows, aerr := crm.New(s.database).IndexRecipient(ctx, id, recipientActorFrom(r))
	if aerr != nil {
		writeRecipientError(w, r, aerr)
		return
	}
	httpx.OK(w, rows, "OK", nil)
}

func (s *Server) handleOrderRecipientStore(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	id, err := strconv.ParseInt(httpx.PathValue(r, "order"), 10, 64)
	if err != nil {
		writeError(w, r, httpx.NewError(http.StatusNotFound, msg(ctx, "system.endpoint_not_found")))
		return
	}
	var in crm.RecipientInput
	if strings.HasPrefix(r.Header.Get("Content-Type"), "multipart/form-data") {
		if err := r.ParseMultipartForm(8 << 20); err != nil {
			writeError(w, r, httpx.NewError(http.StatusBadRequest, msg(ctx, "system.generic_error")))
			return
		}
		get := func(k string) *string {
			v := r.FormValue(k)
			if v == "" {
				return nil
			}
			return &v
		}
		in.RecipientName = get("recipient_name")
		in.RecipientPhone = get("recipient_phone")
		in.RecipientRelation = get("recipient_relation")
		in.Method = get("method")
		in.AuthorizationNote = get("authorization_note")
		if v := r.FormValue("consent_event_id"); v != "" {
			n, _ := strconv.ParseInt(v, 10, 64)
			in.ConsentEventID = &n
		}
		if v := r.FormValue("return_id"); v != "" {
			n, _ := strconv.ParseInt(v, 10, 64)
			in.ReturnID = &n
		}
		if r.FormValue("destination[bank_or_provider]") != "" || r.FormValue("destination[account_number]") != "" || r.FormValue("destination[account_holder]") != "" {
			in.Destination = &crm.Destination{BankOrProvider: get("destination[bank_or_provider]"),
				AccountNumber: get("destination[account_number]"), AccountHolder: get("destination[account_holder]")}
		}
		if files, ok := r.MultipartForm.File["evidence"]; ok && len(files) > 0 {
			handle, oerr := files[0].Open()
			if oerr == nil {
				content, rerr := io.ReadAll(io.LimitReader(handle, 12<<20))
				handle.Close()
				if rerr == nil {
					in.Evidence = content
					in.EvidenceName = files[0].Filename
				}
			}
		}
	} else {
		_ = httpx.DecodeJSON(r, &in)
	}
	fields := fieldErrors{}
	if in.RecipientName == nil || *in.RecipientName == "" {
		fields.add("recipient_name", i18n.ValidationMessage(LocaleFrom(ctx), "required", "recipient_name"))
	} else if len([]rune(*in.RecipientName)) > 255 {
		fields.add("recipient_name", i18n.ValidationMessage(LocaleFrom(ctx), "max.string", "recipient_name", "max", "255"))
	}
	if in.RecipientPhone != nil && len([]rune(*in.RecipientPhone)) > 30 {
		fields.add("recipient_phone", i18n.ValidationMessage(LocaleFrom(ctx), "max.string", "recipient_phone", "max", "30"))
	}
	if in.RecipientRelation != nil && len([]rune(*in.RecipientRelation)) > 100 {
		fields.add("recipient_relation", i18n.ValidationMessage(LocaleFrom(ctx), "max.string", "recipient_relation", "max", "100"))
	}
	if in.Method == nil || *in.Method == "" {
		fields.add("method", i18n.ValidationMessage(LocaleFrom(ctx), "required", "method"))
	} else if *in.Method != "bank_transfer" && *in.Method != "ewallet" && *in.Method != "cash" {
		fields.add("method", i18n.ValidationMessage(LocaleFrom(ctx), "in", "method"))
	}
	if in.AuthorizationNote == nil || *in.AuthorizationNote == "" {
		fields.add("authorization_note", i18n.ValidationMessage(LocaleFrom(ctx), "required", "authorization_note"))
	} else if len([]rune(*in.AuthorizationNote)) > 2000 {
		fields.add("authorization_note", i18n.ValidationMessage(LocaleFrom(ctx), "max.string", "authorization_note", "max", "2000"))
	}
	if len(fields) > 0 {
		writeError(w, r, httpx.Validation(map[string][]string(fields)))
		return
	}
	row, aerr := crm.New(s.database).RecordRecipient(ctx, recipientActorFrom(r), id, in)
	if aerr != nil {
		writeRecipientError(w, r, aerr)
		return
	}
	httpx.Created(w, row, "Penerima refund tercatat.")
}
