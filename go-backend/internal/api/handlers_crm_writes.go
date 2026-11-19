package api

import (
	"context"
	"encoding/json"
	"errors"
	"net/http"
	"strconv"
	"strings"
	"time"

	"github.com/kmsitcomputer/primeclassy-go/internal/crm"
	"github.com/kmsitcomputer/primeclassy-go/internal/httpx"
	"github.com/kmsitcomputer/primeclassy-go/internal/i18n"
)

// CRM write handlers port CrmController (IMP-006 slice 1). Validation mirrors CrmCustomerRequest /
// CrmVisitRequest exactly (including the D→7 status alias and the FU after-validation error);
// authorizeRead/canWrite failures are the localized 403; phone duplicates are the 422 with the
// errors map the Laravel ApiException renders.

// decodeBody parses the JSON body; Laravel's ConvertEmptyStringsToNull makes ” values null.
func crmBody(r *http.Request) (map[string]json.RawMessage, error) {
	var body map[string]json.RawMessage
	if err := httpx.DecodeJSON(r, &body); err != nil {
		return nil, err
	}
	for k, raw := range body {
		var s string
		if err := json.Unmarshal(raw, &s); err == nil && s == "" {
			body[k] = json.RawMessage("null")
		}
	}
	return body, nil
}

// crmString reads a nullable string field (null → nil).
func crmString(body map[string]json.RawMessage, key string) (*string, bool, bool) {
	raw, ok := body[key]
	if !ok {
		return nil, false, false
	}
	if string(raw) == "null" {
		return nil, true, false
	}
	var v string
	if err := json.Unmarshal(raw, &v); err != nil {
		return nil, true, true // present but not a string → string-rule failure
	}
	return &v, true, false
}

// crmInt reads a nullable integer field (null → nil, non-numeric → type failure).
func crmInt(body map[string]json.RawMessage, key string) (*int, bool, bool) {
	raw, ok := body[key]
	if !ok {
		return nil, false, false
	}
	if string(raw) == "null" {
		return nil, true, false
	}
	var v int
	if err := json.Unmarshal(raw, &v); err != nil {
		// Laravel's integer rule also accepts numeric strings.
		var sv string
		if err2 := json.Unmarshal(raw, &sv); err2 == nil {
			if n, err3 := strconv.Atoi(strings.TrimSpace(sv)); err3 == nil {
				return &n, true, false
			}
		}
		return nil, true, true
	}
	return &v, true, false
}

// crmDate validates a Laravel `date` value (accepts Y-m-d, ISO timestamps and other strtotime forms).
func crmDateValue(body map[string]json.RawMessage, key string) (string, bool, bool) {
	raw, ok := body[key]
	if !ok {
		return "", false, false
	}
	if string(raw) == "null" {
		return "", true, false
	}
	var v string
	if err := json.Unmarshal(raw, &v); err != nil {
		return "", true, true
	}
	norm := parseFlexDate(v)
	if norm == "" {
		return v, true, true
	}
	return norm, true, false
}

// parseFlexDate mirrors the common Laravel date formats the SPA sends (Y-m-d and ISO 8601);
// anything unparsable returns "" (→ validation.date failure).
func parseFlexDate(v string) string {
	v = strings.TrimSpace(v)
	for _, layout := range []string{"2006-01-02", "2006-01-02T15:04:05", "2006-01-02T15:04:05Z07:00", "2006-01-02 15:04:05", "2006-01-02 15:04", "2006-01-02T15:04:05.000000Z"} {
		if t, err := time.Parse(layout, v); err == nil {
			return t.Format("2006-01-02")
		}
	}
	return ""
}

// crmCustomerValidate ports CrmCustomerRequest::rules (presence = required on POST, sometimes on PATCH).
func crmCustomerValidate(ctx context.Context, body map[string]json.RawMessage, isPost bool) (crm.CustomerInput, map[string]bool, map[string][]string) {
	in := crm.CustomerInput{}
	present := map[string]bool{}
	fields := map[string][]string{}
	loc := "id"

	add := func(key, rule string, params ...string) {
		fields[key] = append(fields[key], i18n.ValidationMessage(loc, rule, key, params...))
	}

	// name
	if v, ok, bad := crmString(body, "name"); !ok {
		if isPost {
			add("name", "required")
		}
	} else if bad {
		add("name", "string")
	} else {
		in.Name = *v
		present["name"] = true
		if len(*v) > 255 {
			add("name", "max.string", "max", "255")
		}
	}
	// phone (nullable string max:30, then service-level normalization)
	if v, ok, bad := crmString(body, "phone"); !ok {
		// nullable: absent passes
	} else if bad {
		add("phone", "string")
	} else {
		present["phone"] = true
		if v != nil && len(*v) > 30 {
			add("phone", "max.string", "max", "30")
		}
		in.Phone = v
	}
	// email (nullable email max:255)
	if v, ok, bad := crmString(body, "email"); !ok {
	} else if bad {
		add("email", "string")
	} else {
		present["email"] = true
		if v != nil {
			if len(*v) > 255 {
				add("email", "max.string", "max", "255")
			} else if !isEmail(*v) {
				add("email", "email")
			}
		}
		in.Email = v
	}
	// customer_type (nullable string max:50), relation (max:100), address (max:1000), note (max:2000)
	for _, spec := range []struct {
		key  string
		max  string
		dst  **string
		pres *bool
	}{
		{"customer_type", "50", &in.CustomerType, nil},
		{"relation", "100", &in.Relation, nil},
		{"address", "1000", &in.Address, nil},
		{"note", "2000", &in.Note, nil},
	} {
		if v, ok, bad := crmString(body, spec.key); !ok {
		} else if bad {
			add(spec.key, "string")
		} else {
			present[spec.key] = true
			if v != nil && len(*v) > maxInt(spec.max) {
				add(spec.key, "max.string", "max", spec.max)
			}
			*spec.dst = v
		}
	}
	// prospect_percent (nullable integer in set)
	if v, ok, bad := crmInt(body, "prospect_percent"); !ok {
	} else if bad {
		add("prospect_percent", "integer")
	} else {
		present["prospect_percent"] = true
		if v != nil && !crm.ProspectPercentAllowed(*v) {
			add("prospect_percent", "in")
		}
		in.ProspectPercent = v
	}
	return in, present, fields
}

// crmVisitValidate ports CrmVisitRequest::rules (+ prepareForValidation D→7, + after() FU error).
func crmVisitValidate(ctx context.Context, body map[string]json.RawMessage, isPost bool) (crm.VisitInput, map[string]bool, map[string][]string) {
	loc := LocaleFrom(ctx)
	in := crm.VisitInput{}
	present := map[string]bool{}
	fields := map[string][]string{}
	add := func(key, rule string, params ...string) {
		fields[key] = append(fields[key], i18n.ValidationMessage(loc, rule, key, params...))
	}
	// prepareForValidation: D (case-insensitive) is normalized to '7' before the rules run.
	if raw, ok := body["status"]; ok && string(raw) != "null" {
		var sv string
		if err := json.Unmarshal(raw, &sv); err == nil && strings.EqualFold(sv, "D") {
			body["status"] = json.RawMessage(`"7"`)
		}
	}
	// visit_date
	if v, ok, bad := crmDateValue(body, "visit_date"); !ok {
		if isPost {
			add("visit_date", "required")
		}
	} else if bad {
		add("visit_date", "date")
	} else {
		present["visit_date"] = true
		in.VisitDate = v
	}
	// status: required|in(N,1..7)
	if raw, ok := body["status"]; !ok {
		if isPost {
			add("status", "required")
		}
	} else if string(raw) == "null" {
		add("status", "required")
	} else {
		var sv string
		if err := json.Unmarshal(raw, &sv); err != nil {
			add("status", "string")
		} else {
			present["status"] = true
			in.Status = sv
			if !crm.VisitStatusAllowed(sv) {
				add("status", "in")
			}
		}
	}
	// prospect_percent
	if v, ok, bad := crmInt(body, "prospect_percent"); !ok {
	} else if bad {
		add("prospect_percent", "integer")
	} else {
		present["prospect_percent"] = true
		if v != nil && !crm.ProspectPercentAllowed(*v) {
			add("prospect_percent", "in")
		}
		in.ProspectPercent = v
	}
	// note (nullable max:2000)
	if v, ok, bad := crmString(body, "note"); !ok {
	} else if bad {
		add("note", "string")
	} else {
		present["note"] = true
		if v != nil && len(*v) > 2000 {
			add("note", "max.string", "max", "2000")
		}
		in.Note = v
	}
	// plan_next_date: nullable date after_or_equal:visit_date
	if v, ok, bad := crmDateValue(body, "plan_next_date"); !ok {
	} else if bad {
		add("plan_next_date", "date")
	} else {
		present["plan_next_date"] = true
		if v != "" {
			if in.VisitDate != "" && v < in.VisitDate {
				add("plan_next_date", "after_or_equal", "date", i18n.AttributeName(loc, "visit_date"))
			}
			in.PlanNextDate = &v
		} else {
			in.PlanNextDate = nil
		}
	}
	// after(): FU (case-insensitive) is refused with a pointer to plan_next_date.
	if raw, ok := body["status"]; ok && string(raw) != "null" {
		var sv string
		if err := json.Unmarshal(raw, &sv); err == nil && strings.EqualFold(sv, "FU") {
			fields["status"] = append(fields["status"], i18n.Translate(loc, "crm.fu_use_plan_next_date"))
		}
	}
	return in, present, fields
}

func isEmail(v string) bool {
	at := strings.IndexByte(v, '@')
	if at <= 0 || at == len(v)-1 {
		return false
	}
	if strings.ContainsAny(v[:at], " ") || strings.ContainsAny(v[at+1:], " ") || !strings.Contains(v[at+1:], ".") {
		return false
	}
	return true
}

func maxInt(v string) int {
	n, _ := strconv.Atoi(v)
	return n
}

// writeCRMError maps the crm domain errors to the Laravel responses (403 localized, 422 phone
// duplicate with its errors map, 404 not_found for out-of-scope/missing records).
func (s *Server) writeCRMError(w http.ResponseWriter, r *http.Request, err error) {
	ctx := r.Context()
	switch {
	case errors.Is(err, crm.ErrNotFound), errors.Is(err, crm.ErrVisitNotFound):
		// Route-model binding miss (missing id or foreign-agent row) renders the endpoint-not-found answer.
		writeError(w, r, httpx.NewError(http.StatusNotFound, msg(ctx, "system.endpoint_not_found")))
	case errors.Is(err, crm.ErrPhoneDuplicate):
		m := msg(ctx, "crm.phone_duplicate")
		httpx.Failure(w, http.StatusUnprocessableEntity, m, map[string][]string{"phone": {m}})
	case errors.Is(err, crm.ErrCannotCreate), errors.Is(err, crm.ErrCannotWrite), errors.Is(err, crm.ErrCannotRead):
		writeError(w, r, httpx.NewError(http.StatusForbidden, msg(ctx, "system.unauthorized_action")))
	default:
		writeError(w, r, err)
	}
}

func (s *Server) handleCRMCustomerStore(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	body, err := crmBody(r)
	if err != nil {
		writeError(w, r, err)
		return
	}
	// FormRequest validation runs BEFORE CrmAccess::canCreate (constructor injection order).
	in, _, fields := crmCustomerValidate(ctx, body, true)
	if len(fields) > 0 {
		writeError(w, r, httpx.Validation(fields))
		return
	}
	row, err := s.crmService().Create(ctx, crmActorFrom(r), in)
	if err != nil {
		s.writeCRMError(w, r, err)
		return
	}
	httpx.Created(w, row, msg(ctx, "crm.customer_created"))
}

func (s *Server) handleCRMCustomerUpdate(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	id, ok := pathID(w, r, "customer")
	if !ok {
		return
	}
	if !s.crmBindingPrecheck(w, r, id, false) {
		return
	}
	body, err := crmBody(r)
	if err != nil {
		writeError(w, r, err)
		return
	}
	in, present, fields := crmCustomerValidate(ctx, body, false)
	if len(fields) > 0 {
		writeError(w, r, httpx.Validation(fields))
		return
	}
	row, oldChanged, err := s.crmService().Update(ctx, crmActorFrom(r), id, in, present)
	if err != nil {
		s.writeCRMError(w, r, err)
		return
	}
	// Laravel audits old→new for the changed columns (ActivityLogger 'crm.customer.updated').
	if oldChanged != nil {
		newChanged := map[string]any{}
		for k := range oldChanged {
			if v, ok := row[k]; ok {
				newChanged[k] = v
			}
		}
		u := UserFrom(ctx)
		_ = s.writeActivityLog(ctx, u.ID, `App\Models\CrmCustomer`, id, "crm.customer.updated", map[string]any{"old": oldChanged, "new": newChanged})
	}
	httpx.OK(w, row, msg(ctx, "crm.customer_updated"), nil)
}

func (s *Server) handleCRMCustomerArchive(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	id, ok := pathID(w, r, "customer")
	if !ok {
		return
	}
	if !s.crmBindingPrecheck(w, r, id, false) {
		return
	}
	body, err := crmBody(r)
	if err != nil {
		writeError(w, r, err)
		return
	}
	restore := laravelBodyBoolean(body, "restore")
	row, archived, err := s.crmService().Archive(ctx, crmActorFrom(r), id, restore)
	if err != nil {
		s.writeCRMError(w, r, err)
		return
	}
	event := "crm.customer.restored"
	if archived {
		event = "crm.customer.archived"
	}
	u := UserFrom(ctx)
	_ = s.writeActivityLog(ctx, u.ID, `App\Models\CrmCustomer`, id, event, nil)
	httpx.OK(w, row, "OK", nil)
}

func (s *Server) handleCRMVisitStore(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	id, ok := pathID(w, r, "customer")
	if !ok {
		return
	}
	if !s.crmBindingPrecheck(w, r, id, false) {
		return
	}
	body, err := crmBody(r)
	if err != nil {
		writeError(w, r, err)
		return
	}
	in, _, fields := crmVisitValidate(ctx, body, true)
	if len(fields) > 0 {
		writeError(w, r, httpx.Validation(fields))
		return
	}
	row, err := s.crmService().CreateVisit(ctx, crmActorFrom(r), id, in)
	if err != nil {
		s.writeCRMError(w, r, err)
		return
	}
	httpx.Created(w, row, msg(ctx, "crm.visit_created"))
}

func (s *Server) handleCRMVisitUpdate(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	id, ok := pathID(w, r, "visit")
	if !ok {
		return
	}
	if !s.crmBindingPrecheck(w, r, id, true) {
		return
	}
	body, err := crmBody(r)
	if err != nil {
		writeError(w, r, err)
		return
	}
	in, present, fields := crmVisitValidate(ctx, body, false)
	if len(fields) > 0 {
		writeError(w, r, httpx.Validation(fields))
		return
	}
	row, changed, err := s.crmService().UpdateVisit(ctx, crmActorFrom(r), id, in, present)
	if err != nil {
		s.writeCRMError(w, r, err)
		return
	}
	if changed {
		old, _ := row["_old"].(map[string]any)
		delete(row, "_old")
		new := map[string]any{}
		for k := range old {
			if v, ok := row[k]; ok {
				new[k] = v
			}
		}
		u := UserFrom(ctx)
		_ = s.writeActivityLog(ctx, u.ID, `App\Models\CrmVisit`, id, "crm.visit.updated", map[string]any{"old": old, "new": new})
	}
	httpx.OK(w, row, msg(ctx, "crm.visit_updated"), nil)
}

func laravelBodyBoolean(body map[string]json.RawMessage, key string) bool {
	raw, ok := body[key]
	if !ok {
		return false
	}
	var v any
	if json.Unmarshal(raw, &v) != nil {
		return false
	}
	switch t := v.(type) {
	case bool:
		return t
	case float64:
		return t == 1
	case string:
		switch strings.ToLower(t) {
		case "1", "true", "on", "yes":
			return true
		}
	}
	return false
}

// crmBindingPrecheck mirrors the Laravel request lifecycle for routes with a bound CRM model:
// binding (404 endpoint_not_found) runs BEFORE the role middleware (403 unauthorized_action),
// and both run before FormRequest validation (422).
func (s *Server) crmBindingPrecheck(w http.ResponseWriter, r *http.Request, id int64, visit bool) bool {
	ctx := r.Context()
	actor := crmActorFrom(r)
	var err error
	var ownerID int64
	if visit {
		_, _, err = s.crmService().BindingVisit(ctx, actor, id)
	} else {
		ownerID, _, err = s.crmService().BindingCustomer(ctx, actor, id)
	}
	if err != nil {
		s.writeCRMError(w, r, err)
		return false
	}
	// role middleware equivalent (route gate role:sales,sales-kurir-sub,korsal)
	if actor.Role != "sales" && actor.Role != "sales-kurir-sub" && actor.Role != "korsal" {
		writeError(w, r, httpx.NewError(http.StatusForbidden, msg(ctx, "system.unauthorized_action")))
		return false
	}
	// CrmAccess::authorizeRead runs AFTER validation (controller body), so it is enforced inside the
	// service call, not here.
	_ = ownerID
	return true
}

// ---------------- CRM linking (CrmAssistedController) ----------------

func (s *Server) handleCRMLinkCandidates(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	// Laravel route gate role:sales,sales-kurir-sub,korsal
	actor := crmActorFrom(r)
	if actor.Role != "sales" && actor.Role != "sales-kurir-sub" && actor.Role != "korsal" {
		writeError(w, r, httpx.NewError(http.StatusForbidden, msg(ctx, "system.unauthorized_action")))
		return
	}
	rows, err := s.crmService().LinkCandidates(ctx, actor)
	if err != nil {
		s.writeCRMError(w, r, err)
		return
	}
	httpx.OK(w, rows, "OK", nil)
}

func (s *Server) handleCRMLinkOtp(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	id, ok := pathID(w, r, "customer")
	if !ok {
		return
	}
	// binding before role gate (Laravel middleware priority)
	if _, _, berr := s.crmService().BindingCustomer(ctx, crmActorFrom(r), id); berr != nil {
		s.writeCRMError(w, r, berr)
		return
	}
	actor := crmActorFrom(r)
	if actor.Role != "sales" && actor.Role != "sales-kurir-sub" && actor.Role != "korsal" {
		writeError(w, r, httpx.NewError(http.StatusForbidden, msg(ctx, "system.unauthorized_action")))
		return
	}
	body, err := crmBody(r)
	if err != nil {
		writeError(w, r, err)
		return
	}
	konsumenID, present, bad := crmInt(body, "konsumen_id")
	fields := map[string][]string{}
	loc := LocaleFrom(ctx)
	if !present {
		fields["konsumen_id"] = []string{i18n.ValidationMessage(loc, "required", "konsumen_id")}
	} else if bad {
		fields["konsumen_id"] = []string{i18n.ValidationMessage(loc, "integer", "konsumen_id")}
	}
	channel := "email"
	if raw, ok := body["channel"]; ok && string(raw) != "null" {
		var cv string
		if json.Unmarshal(raw, &cv) != nil || (cv != "email" && cv != "whatsapp") {
			fields["channel"] = []string{i18n.ValidationMessage(loc, "in", "channel")}
		} else {
			channel = cv
		}
	}
	if len(fields) > 0 {
		writeError(w, r, httpx.Validation(fields))
		return
	}
	ch, err := s.crmService().IssueLinkOtp(ctx, actor, id, int64(*konsumenID), channel)
	if err != nil {
		s.writeCRMError(w, r, err)
		return
	}
	httpx.Created(w, ch, msg(ctx, "consent.otp_sent"))
}

func (s *Server) handleCRMLink(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	id, ok := pathID(w, r, "customer")
	if !ok {
		return
	}
	if _, _, berr := s.crmService().BindingCustomer(ctx, crmActorFrom(r), id); berr != nil {
		s.writeCRMError(w, r, berr)
		return
	}
	actor := crmActorFrom(r)
	if actor.Role != "sales" && actor.Role != "sales-kurir-sub" && actor.Role != "korsal" {
		writeError(w, r, httpx.NewError(http.StatusForbidden, msg(ctx, "system.unauthorized_action")))
		return
	}
	body, err := crmBody(r)
	if err != nil {
		writeError(w, r, err)
		return
	}
	loc := LocaleFrom(ctx)
	fields := map[string][]string{}
	konsumenID, present, bad := crmInt(body, "konsumen_id")
	if !present {
		fields["konsumen_id"] = []string{i18n.ValidationMessage(loc, "required", "konsumen_id")}
	} else if bad {
		fields["konsumen_id"] = []string{i18n.ValidationMessage(loc, "integer", "konsumen_id")}
	}
	challengeID, presentC, badC := crmInt(body, "otp_challenge_id")
	if !presentC {
		fields["otp_challenge_id"] = []string{i18n.ValidationMessage(loc, "required", "otp challenge id")}
	} else if badC {
		fields["otp_challenge_id"] = []string{i18n.ValidationMessage(loc, "integer", "otp challenge id")}
	} else {
		var n int
		_ = s.database.QueryRowContext(ctx, `SELECT COUNT(*) FROM identity_otp_challenges WHERE id=?`, *challengeID).Scan(&n)
		if n == 0 {
			fields["otp_challenge_id"] = []string{i18n.ValidationMessage(loc, "exists", "otp challenge id")}
		}
	}
	if len(fields) > 0 {
		writeError(w, r, httpx.Validation(fields))
		return
	}
	row, err := s.crmService().Link(ctx, actor, id, int64(*konsumenID), int64(*challengeID))
	if err != nil {
		s.writeCRMError(w, r, err)
		return
	}
	u := UserFrom(ctx)
	var eventID int64
	_ = s.database.QueryRowContext(ctx, `SELECT id FROM crm_customer_link_events WHERE crm_customer_id=? ORDER BY id DESC LIMIT 1`, id).Scan(&eventID)
	_ = s.writeActivityLog(ctx, u.ID, `App\Models\CrmCustomer`, id, "crm.customer.linked", map[string]any{"konsumen_user_id": int64(*konsumenID), "event_id": eventID})
	httpx.OK(w, row, msg(ctx, "crm.linked"), nil)
}

func (s *Server) handleCRMUnlink(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	id, ok := pathID(w, r, "customer")
	if !ok {
		return
	}
	if _, _, berr := s.crmService().BindingCustomer(ctx, crmActorFrom(r), id); berr != nil {
		s.writeCRMError(w, r, berr)
		return
	}
	actor := crmActorFrom(r)
	if actor.Role != "sales" && actor.Role != "sales-kurir-sub" && actor.Role != "korsal" &&
		actor.Role != "agen" && actor.Role != "admin" {
		writeError(w, r, httpx.NewError(http.StatusForbidden, msg(ctx, "system.unauthorized_action")))
		return
	}
	body, err := crmBody(r)
	if err != nil {
		writeError(w, r, err)
		return
	}
	loc := LocaleFrom(ctx)
	reason, present, bad := crmString(body, "reason")
	fields := map[string][]string{}
	if !present || (present && bad) {
		fields["reason"] = []string{i18n.ValidationMessage(loc, "required", "reason")}
	} else if len(*reason) > 1000 {
		fields["reason"] = []string{i18n.ValidationMessage(loc, "max.string", "reason", "max", "1000")}
	}
	if len(fields) > 0 {
		writeError(w, r, httpx.Validation(fields))
		return
	}
	row, konsumenID, eventID, err := s.crmService().Unlink(ctx, actor, id, *reason)
	if err != nil {
		s.writeCRMError(w, r, err)
		return
	}
	u := UserFrom(ctx)
	_ = s.writeActivityLogWithDescription(ctx, u.ID, `App\Models\CrmCustomer`, id, "crm.customer.unlinked", *reason,
		map[string]any{"konsumen_user_id": konsumenID, "event_id": eventID})
	httpx.OK(w, row, msg(ctx, "crm.unlinked"), nil)
}
