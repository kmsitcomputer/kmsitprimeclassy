package api

import (
	"database/sql"
	"encoding/json"
	"errors"
	"net/http"
	"strconv"
	"strings"

	"github.com/kmsitcomputer/primeclassy-go/internal/agentadmin"
	"github.com/kmsitcomputer/primeclassy-go/internal/auth"
	"github.com/kmsitcomputer/primeclassy-go/internal/httpx"
	"github.com/kmsitcomputer/primeclassy-go/internal/i18n"
	"github.com/kmsitcomputer/primeclassy-go/internal/users"
)

func writeUsersError(w http.ResponseWriter, r *http.Request, err error) {
	var de *users.Error
	if errors.As(err, &de) {
		httpx.Failure(w, de.Status, de.Message, de.Fields)
		return
	}
	if errors.Is(err, auth.ErrUserNotFound) || errors.Is(err, sql.ErrNoRows) {
		writeError(w, r, notFoundError(r.Context()))
		return
	}
	writeError(w, r, err)
}

func writeAgentAdminError(w http.ResponseWriter, r *http.Request, err error) {
	var de *agentadmin.Error
	if errors.As(err, &de) {
		httpx.Failure(w, de.Status, de.Message, de.Fields)
		return
	}
	if errors.Is(err, sql.ErrNoRows) {
		writeError(w, r, notFoundError(r.Context()))
		return
	}
	writeError(w, r, err)
}

func (s *Server) recordAgentAudit(r *http.Request, actor *auth.User, agentID int64, event string, properties map[string]any) error {
	raw, err := json.Marshal(properties)
	if err != nil {
		return err
	}
	_, err = s.database.ExecContext(r.Context(), `INSERT INTO activity_logs
		(causer_id,subject_type,subject_id,event,properties,created_at)
		VALUES (?,'App\\Models\\User',?,?,?,UTC_TIMESTAMP(6))`, actor.ID, agentID, event, raw)
	return err
}

func (s *Server) recordAgentProfileAudit(r *http.Request, actor *auth.User, agentID int64, old, current any) error {
	var profileID int64
	if err := s.database.QueryRowContext(r.Context(), `SELECT id FROM agent_profiles WHERE user_id=?`, agentID).Scan(&profileID); err != nil {
		return err
	}
	raw, err := json.Marshal(map[string]any{"old": old, "new": current})
	if err != nil {
		return err
	}
	_, err = s.database.ExecContext(r.Context(), `INSERT INTO activity_logs
		(causer_id,subject_type,subject_id,event,properties,created_at)
		VALUES (?,'App\\Models\\AgentProfile',?,'agent_profile.self_updated',?,UTC_TIMESTAMP(6))`, actor.ID, profileID, raw)
	return err
}

func userData(r *http.Request, s *Server, actor *auth.User, u *auth.User) (map[string]any, error) {
	return u.UserJSON(r.Context(), s.database, actor.ID)
}

func (s *Server) handleUsersIndex(w http.ResponseWriter, r *http.Request) {
	actor, ok := requireUser(w, r)
	if !ok {
		return
	}
	pageNo, _ := strconv.Atoi(r.URL.Query().Get("page"))
	perPage, _ := strconv.Atoi(r.URL.Query().Get("per_page"))
	agentID, _ := strconv.ParseInt(r.URL.Query().Get("agent_id"), 10, 64)
	result, err := users.New(s.database).List(r.Context(), users.Filters{
		Actor: actor, AgentID: agentID, Role: r.URL.Query().Get("role"),
		Search: r.URL.Query().Get("search"), Status: r.URL.Query().Get("status"),
		Page: pageNo, PerPage: perPage,
	})
	if err != nil {
		writeUsersError(w, r, err)
		return
	}
	data := make([]map[string]any, 0, len(result.Users))
	for _, u := range result.Users {
		resource, err := userData(r, s, actor, u)
		if err != nil {
			writeError(w, r, err)
			return
		}
		data = append(data, resource)
	}
	httpx.OK(w, data, "OK", map[string]int{
		"current_page": result.Current, "last_page": result.Last, "total": result.Total, "per_page": result.PerPage,
	})
}

func (s *Server) handleUsersShow(w http.ResponseWriter, r *http.Request) {
	actor, ok := requireUser(w, r)
	if !ok {
		return
	}
	id, ok := pathID(w, r, "user")
	if !ok {
		return
	}
	svc := users.New(s.database)
	target, err := svc.Find(r.Context(), id)
	if err != nil {
		writeUsersError(w, r, err)
		return
	}
	if !users.CanView(actor, target) {
		writeError(w, r, forbiddenError(r.Context()))
		return
	}
	data, err := userData(r, s, actor, target)
	if err != nil {
		writeError(w, r, err)
		return
	}
	httpx.OK(w, data, "OK", nil)
}

func (s *Server) handleUsersStore(w http.ResponseWriter, r *http.Request) {
	actor, ok := requireUser(w, r)
	if !ok {
		return
	}
	var body struct {
		Role                 string `json:"role"`
		Name                 string `json:"name"`
		Email                string `json:"email"`
		Phone                string `json:"phone"`
		Password             string `json:"password"`
		PasswordConfirmation string `json:"password_confirmation"`
		AgentID              *int64 `json:"agent_id"`
		KorsalID             *int64 `json:"korsal_id"`
	}
	if err := httpx.DecodeJSON(r, &body); err != nil {
		writeError(w, r, err)
		return
	}
	body.Phone = users.NormalizePhone(body.Phone)
	created, err := users.New(s.database).Create(r.Context(), actor, users.CreateInput{
		Role: body.Role, Name: body.Name, Email: body.Email, Phone: body.Phone, Password: body.Password,
		Confirmation: body.PasswordConfirmation, AgentID: body.AgentID, KorsalID: body.KorsalID,
	})
	if err != nil {
		writeUsersError(w, r, err)
		return
	}
	data, err := userData(r, s, actor, created)
	if err != nil {
		writeError(w, r, err)
		return
	}
	httpx.Created(w, data, msg(r.Context(), "user.created"))
}

func (s *Server) handleUsersUpdate(w http.ResponseWriter, r *http.Request) {
	actor, ok := requireUser(w, r)
	if !ok {
		return
	}
	id, ok := pathID(w, r, "user")
	if !ok {
		return
	}
	var body struct {
		Name   *string `json:"name"`
		Phone  *string `json:"phone"`
		Status *string `json:"status"`
	}
	if err := httpx.DecodeJSON(r, &body); err != nil {
		writeError(w, r, err)
		return
	}
	if body.Phone != nil {
		normalized := users.NormalizePhone(*body.Phone)
		body.Phone = &normalized
	}
	svc := users.New(s.database)
	target, err := svc.Find(r.Context(), id)
	if err != nil {
		writeUsersError(w, r, err)
		return
	}
	patch := users.UpdateInput{}
	if body.Name != nil {
		patch.Name = users.Field[string]{Present: true, Value: *body.Name}
	}
	if body.Phone != nil {
		patch.Phone = users.Field[string]{Present: true, Value: *body.Phone}
	}
	if body.Status != nil {
		patch.Status = users.Field[string]{Present: true, Value: *body.Status}
	}
	updated, err := svc.Update(r.Context(), actor, target, patch)
	if err != nil {
		writeUsersError(w, r, err)
		return
	}
	data, err := userData(r, s, actor, updated)
	if err != nil {
		writeError(w, r, err)
		return
	}
	httpx.OK(w, data, "OK", nil)
}

func (s *Server) handleUsersDelete(w http.ResponseWriter, r *http.Request) {
	actor, ok := requireUser(w, r)
	if !ok {
		return
	}
	id, ok := pathID(w, r, "user")
	if !ok {
		return
	}
	svc := users.New(s.database)
	target, err := svc.Find(r.Context(), id)
	if err == nil {
		err = svc.Delete(r.Context(), actor, target)
	}
	if err != nil {
		writeUsersError(w, r, err)
		return
	}
	httpx.OK(w, nil, msg(r.Context(), "user.deleted"), nil)
}

func (s *Server) handleUsersConvertToSalesKurirSub(w http.ResponseWriter, r *http.Request) {
	actor, ok := requireUser(w, r)
	if !ok {
		return
	}
	id, ok := pathID(w, r, "user")
	if !ok {
		return
	}
	svc := users.New(s.database)
	target, err := svc.Find(r.Context(), id)
	if err == nil {
		target, err = svc.ConvertToSalesKurirSub(r.Context(), actor, target)
	}
	if err != nil {
		writeUsersError(w, r, err)
		return
	}
	data, err := userData(r, s, actor, target)
	if err != nil {
		writeError(w, r, err)
		return
	}
	httpx.OK(w, data, "OK", nil)
}

func (s *Server) handleUsersReassignReferral(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	actor, ok := requireUser(w, r)
	if !ok {
		return
	}
	id, ok := pathID(w, r, "user")
	if !ok {
		return
	}
	var body struct {
		KorsalID *int64 `json:"korsal_id"`
		SalesID  *int64 `json:"sales_id"`
	}
	if err := httpx.DecodeJSON(r, &body); err != nil {
		writeError(w, r, err)
		return
	}
	// ReassignReferralRequest::rules — BOTH required_without failures fire together, and each id must
	// exist in users (exists:users,id). FormRequest validation runs BEFORE UserPolicy::update.
	fields := map[string][]string{}
	if body.KorsalID == nil && body.SalesID == nil {
		fields["korsal_id"] = []string{i18n.ValidationMessage(LocaleFrom(ctx), "required_without", "korsal_id", "values", "sales")}
		fields["sales_id"] = []string{i18n.ValidationMessage(LocaleFrom(ctx), "required_without", "sales_id", "values", "korsal")}
	}
	if body.KorsalID != nil && !existsIn(ctx, s.database, "users", "id", formatID(*body.KorsalID)) {
		fields["korsal_id"] = []string{i18n.ValidationMessage(LocaleFrom(ctx), "exists", "korsal_id")}
	}
	if body.SalesID != nil && !existsIn(ctx, s.database, "users", "id", formatID(*body.SalesID)) {
		fields["sales_id"] = []string{i18n.ValidationMessage(LocaleFrom(ctx), "exists", "sales_id")}
	}
	if len(fields) > 0 {
		writeError(w, r, &httpx.Error{Status: http.StatusUnprocessableEntity, Message: msg(ctx, "system.validation_failed"), Fields: fields})
		return
	}
	svc := users.New(s.database)
	target, err := svc.Find(ctx, id)
	if err == nil {
		target, err = svc.Reassign(ctx, actor, target, body.KorsalID, body.SalesID)
	}
	if err != nil {
		writeUsersError(w, r, err)
		return
	}
	data, err := userData(r, s, actor, target)
	if err != nil {
		writeError(w, r, err)
		return
	}
	httpx.OK(w, data, "OK", nil)
}

func (s *Server) agentAdmin(w http.ResponseWriter, r *http.Request, allowAdmin bool) (*auth.User, int64, bool) {
	actor, ok := requireUser(w, r)
	if !ok {
		return nil, 0, false
	}
	agentID, err := agentadmin.ResolveAgent(actor, allowAdmin)
	if err != nil {
		writeAgentAdminError(w, r, err)
		return nil, 0, false
	}
	return actor, agentID, true
}

func (s *Server) handleAgentStoreProfileShow(w http.ResponseWriter, r *http.Request) {
	_, agentID, ok := s.agentAdmin(w, r, false)
	if !ok {
		return
	}
	data, err := agentadmin.New(s.database, s.cfg.AppKey).StoreProfile(r.Context(), agentID)
	if err != nil {
		writeAgentAdminError(w, r, err)
		return
	}
	httpx.OK(w, data, "OK", nil)
}

func (s *Server) handleAgentStoreProfileUpdate(w http.ResponseWriter, r *http.Request) {
	actor, agentID, ok := s.agentAdmin(w, r, false)
	if !ok {
		return
	}
	var body agentadmin.StoreProfileInput
	if err := httpx.DecodeJSON(r, &body); err != nil {
		writeError(w, r, err)
		return
	}
	svc := agentadmin.New(s.database, s.cfg.AppKey)
	before, err := svc.StoreProfile(r.Context(), agentID)
	if err != nil {
		writeAgentAdminError(w, r, err)
		return
	}
	if err := svc.UpdateStoreProfile(r.Context(), agentID, body); err != nil {
		writeAgentAdminError(w, r, err)
		return
	}
	data, err := svc.StoreProfile(r.Context(), agentID)
	if err != nil {
		writeAgentAdminError(w, r, err)
		return
	}
	if err := s.recordAgentProfileAudit(r, actor, agentID, before, data); err != nil {
		writeError(w, r, err)
		return
	}
	httpx.OK(w, data, msg(r.Context(), "agent.profile_updated"), nil)
}

func (s *Server) handleAgentPaymentMethods(w http.ResponseWriter, r *http.Request) {
	_, agentID, ok := s.agentAdmin(w, r, true)
	if !ok {
		return
	}
	data, err := agentadmin.New(s.database, s.cfg.AppKey).PaymentMethods(r.Context(), agentID)
	if err != nil {
		writeAgentAdminError(w, r, err)
		return
	}
	httpx.OK(w, data, "OK", nil)
}

func (s *Server) handleAgentPaymentMethodToggle(w http.ResponseWriter, r *http.Request) {
	actor, agentID, ok := s.agentAdmin(w, r, true)
	if !ok {
		return
	}
	id, ok := pathID(w, r, "method")
	if !ok {
		return
	}
	data, err := agentadmin.New(s.database, s.cfg.AppKey).TogglePaymentMethod(r.Context(), agentID, id)
	if err != nil {
		writeAgentAdminError(w, r, err)
		return
	}
	if err := s.recordAgentAudit(r, actor, agentID, "agent_payment_method.toggled", map[string]any{"payment_method": data.Code, "is_active": data.Active, "actor_role": actor.Role}); err != nil {
		writeError(w, r, err)
		return
	}
	httpx.OK(w, data, msg(r.Context(), "payment.gateway_toggled"), nil)
}

func (s *Server) handleAgentPaymentMethodConfig(w http.ResponseWriter, r *http.Request) {
	actor, agentID, ok := s.agentAdmin(w, r, true)
	if !ok {
		return
	}
	id, ok := pathID(w, r, "method")
	if !ok {
		return
	}
	var body struct {
		Environment string         `json:"environment"`
		Config      map[string]any `json:"config"`
	}
	if err := httpx.DecodeJSON(r, &body); err != nil {
		writeError(w, r, err)
		return
	}
	if err := agentadmin.New(s.database, s.cfg.AppKey).SavePaymentConfig(r.Context(), agentID, id, body.Environment, body.Config); err != nil {
		writeAgentAdminError(w, r, err)
		return
	}
	fields := make([]string, 0, len(body.Config))
	for key := range body.Config {
		fields = append(fields, key)
	}
	if err := s.recordAgentAudit(r, actor, agentID, "agent_payment_method.config_updated", map[string]any{"payment_method_id": id, "environment": body.Environment, "fields": fields, "actor_role": actor.Role}); err != nil {
		writeError(w, r, err)
		return
	}
	httpx.OK(w, map[string]bool{"configured": true}, msg(r.Context(), "payment.gateway_config_saved"), nil)
}

func (s *Server) handleAgentShippingProviders(w http.ResponseWriter, r *http.Request) {
	_, agentID, ok := s.agentAdmin(w, r, false)
	if !ok {
		return
	}
	data, err := agentadmin.New(s.database, s.cfg.AppKey).ShippingProviders(r.Context(), agentID)
	if err != nil {
		writeAgentAdminError(w, r, err)
		return
	}
	httpx.OK(w, data, "OK", nil)
}

func (s *Server) handleAgentShippingProviderToggle(w http.ResponseWriter, r *http.Request) {
	actor, agentID, ok := s.agentAdmin(w, r, false)
	if !ok {
		return
	}
	id, ok := pathID(w, r, "provider")
	if !ok {
		return
	}
	data, err := agentadmin.New(s.database, s.cfg.AppKey).ToggleShippingProvider(r.Context(), agentID, id)
	if err != nil {
		writeAgentAdminError(w, r, err)
		return
	}
	if err := s.recordAgentAudit(r, actor, agentID, "agent_shipping_provider.toggled", map[string]any{"shipping_provider": data.Code, "is_active": data.Active}); err != nil {
		writeError(w, r, err)
		return
	}
	httpx.OK(w, data, msg(r.Context(), "shipping.provider_toggled"), nil)
}

func (s *Server) handleAgentShippingProviderConfig(w http.ResponseWriter, r *http.Request) {
	actor, agentID, ok := s.agentAdmin(w, r, false)
	if !ok {
		return
	}
	id, ok := pathID(w, r, "provider")
	if !ok {
		return
	}
	var body struct {
		Config map[string]any `json:"config"`
	}
	if err := httpx.DecodeJSON(r, &body); err != nil {
		writeError(w, r, err)
		return
	}
	if err := agentadmin.New(s.database, s.cfg.AppKey, s.cfg.Shipping.KmsitTrustedHosts).SaveShippingConfig(r.Context(), agentID, id, body.Config); err != nil {
		writeAgentAdminError(w, r, err)
		return
	}
	fields := make([]string, 0, len(body.Config))
	for key := range body.Config {
		fields = append(fields, key)
	}
	if err := s.recordAgentAudit(r, actor, agentID, "agent_shipping_provider.config_updated", map[string]any{"shipping_provider_id": id, "fields": fields}); err != nil {
		writeError(w, r, err)
		return
	}
	httpx.OK(w, map[string]bool{"configured": true}, msg(r.Context(), "shipping.provider_config_saved"), nil)
}

func (s *Server) handleAgentShippingProviderCouriers(w http.ResponseWriter, r *http.Request) {
	_, agentID, ok := s.agentAdmin(w, r, false)
	if !ok {
		return
	}
	id, ok := pathID(w, r, "provider")
	if !ok {
		return
	}
	data, err := agentadmin.New(s.database, s.cfg.AppKey).ShippingCouriers(r.Context(), agentID, id)
	if err != nil {
		writeAgentAdminError(w, r, err)
		return
	}
	httpx.OK(w, data, "OK", nil)
}

func (s *Server) handleAgentShippingProviderCouriersUpdate(w http.ResponseWriter, r *http.Request) {
	actor, agentID, ok := s.agentAdmin(w, r, false)
	if !ok {
		return
	}
	id, ok := pathID(w, r, "provider")
	if !ok {
		return
	}
	var body struct {
		Couriers []string `json:"couriers"`
	}
	if err := httpx.DecodeJSON(r, &body); err != nil {
		writeError(w, r, err)
		return
	}
	if body.Couriers == nil {
		writeError(w, r, httpx.Validation(map[string][]string{"couriers": requiredField(r.Context(), "couriers")}))
		return
	}
	data, err := agentadmin.New(s.database, s.cfg.AppKey).UpdateCouriers(r.Context(), agentID, id, body.Couriers)
	if err != nil {
		writeAgentAdminError(w, r, err)
		return
	}
	if err := s.recordAgentAudit(r, actor, agentID, "agent_shipping_provider.couriers_updated", map[string]any{"shipping_provider_id": id, "new": data}); err != nil {
		writeError(w, r, err)
		return
	}
	httpx.OK(w, map[string][]string{"couriers": data}, "Kurir tersimpan.", nil)
}

func (s *Server) handleAgentShippingTariffShow(w http.ResponseWriter, r *http.Request) {
	_, agentID, ok := s.agentAdmin(w, r, false)
	if !ok {
		return
	}
	data, err := agentadmin.New(s.database, s.cfg.AppKey).Tariff(r.Context(), agentID)
	if err != nil {
		writeAgentAdminError(w, r, err)
		return
	}
	httpx.OK(w, map[string]any{"tariff": data}, "OK", nil)
}

func (s *Server) handleAgentShippingTariffUpdate(w http.ResponseWriter, r *http.Request) {
	actor, agentID, ok := s.agentAdmin(w, r, false)
	if !ok {
		return
	}
	var body map[string]any
	if err := httpx.DecodeJSON(r, &body); err != nil {
		writeError(w, r, err)
		return
	}
	data, err := agentadmin.New(s.database, s.cfg.AppKey).SaveTariff(r.Context(), agentID, body)
	if err != nil {
		writeAgentAdminError(w, r, err)
		return
	}
	if err := s.recordAgentAudit(r, actor, agentID, "agent_shipping_tariff.updated", map[string]any{"new": data}); err != nil {
		writeError(w, r, err)
		return
	}
	httpx.OK(w, map[string]any{"tariff": data}, msg(r.Context(), "shipping.provider_config_saved"), nil)
}

func (s *Server) handleAgentWhatsAppScope(w http.ResponseWriter, r *http.Request) {
	actor, ok := requireUser(w, r)
	if !ok {
		return
	}
	agentID, err := agentadmin.ResolveAgent(actor, true)
	if err != nil {
		writeAgentAdminError(w, r, err)
		return
	}
	var name string
	if err := s.database.QueryRowContext(r.Context(), `SELECT name FROM users WHERE id=? AND deleted_at IS NULL`, agentID).Scan(&name); err != nil {
		writeAgentAdminError(w, r, err)
		return
	}
	httpx.OK(w, map[string]any{"agents": []map[string]any{{"id": agentID, "name": name}}}, "OK", nil)
}

func (s *Server) handleAgentWhatsAppEvents(w http.ResponseWriter, r *http.Request) {
	_, agentID, ok := s.agentAdmin(w, r, true)
	if !ok {
		return
	}
	data, err := agentadmin.New(s.database, s.cfg.AppKey).WhatsAppEvents(r.Context(), agentID)
	if err != nil {
		writeAgentAdminError(w, r, err)
		return
	}
	httpx.OK(w, data, "OK", nil)
}

func (s *Server) handleAgentWhatsAppEventUpdate(w http.ResponseWriter, r *http.Request) {
	actor, agentID, ok := s.agentAdmin(w, r, true)
	if !ok {
		return
	}
	var body struct {
		Enabled  *bool   `json:"enabled"`
		Template *string `json:"template"`
	}
	if err := httpx.DecodeJSON(r, &body); err != nil {
		writeError(w, r, err)
		return
	}
	data, err := agentadmin.New(s.database, s.cfg.AppKey).UpdateWhatsAppEvent(r.Context(), actor.ID, agentID, httpx.PathValue(r, "event"), body.Enabled, body.Template)
	if err != nil {
		writeAgentAdminError(w, r, err)
		return
	}
	if err := s.recordAgentAudit(r, actor, agentID, "whatsapp.agent_event.updated", map[string]any{"event": httpx.PathValue(r, "event"), "template_changed": body.Template != nil, "enabled": body.Enabled}); err != nil {
		writeError(w, r, err)
		return
	}
	httpx.OK(w, data, "Konfigurasi WhatsApp tersimpan.", nil)
}

func (s *Server) handleAgentWhatsAppTemplateReset(w http.ResponseWriter, r *http.Request) {
	actor, agentID, ok := s.agentAdmin(w, r, true)
	if !ok {
		return
	}
	data, err := agentadmin.New(s.database, s.cfg.AppKey).ResetWhatsAppTemplate(r.Context(), actor.ID, agentID, httpx.PathValue(r, "event"))
	if err != nil {
		writeAgentAdminError(w, r, err)
		return
	}
	if err := s.recordAgentAudit(r, actor, agentID, "whatsapp.agent_event.template_reset", map[string]any{"event": httpx.PathValue(r, "event")}); err != nil {
		writeError(w, r, err)
		return
	}
	httpx.OK(w, data, "Konfigurasi WhatsApp tersimpan.", nil)
}

func (s *Server) handleAgentWhatsAppTestNumbers(w http.ResponseWriter, r *http.Request) {
	_, agentID, ok := s.agentAdmin(w, r, true)
	if !ok {
		return
	}
	data, err := agentadmin.New(s.database, s.cfg.AppKey).WhatsAppTestNumbers(r.Context(), agentID)
	if err != nil {
		writeAgentAdminError(w, r, err)
		return
	}
	httpx.OK(w, map[string]any{"numbers": data, "max": 5}, "OK", nil)
}
func (s *Server) handleAgentWhatsAppTestNumberAdd(w http.ResponseWriter, r *http.Request) {
	actor, agentID, ok := s.agentAdmin(w, r, true)
	if !ok {
		return
	}
	var body struct {
		Number  string `json:"number"`
		Confirm bool   `json:"confirm_authorized"`
	}
	if err := httpx.DecodeJSON(r, &body); err != nil {
		writeError(w, r, err)
		return
	}
	if strings.TrimSpace(body.Number) == "" || !body.Confirm {
		writeError(w, r, httpx.Validation(map[string][]string{"number": requiredField(r.Context(), "number")}))
		return
	}
	data, err := agentadmin.New(s.database, s.cfg.AppKey).AddWhatsAppTestNumber(r.Context(), actor.ID, agentID, body.Number)
	if err != nil {
		writeAgentAdminError(w, r, err)
		return
	}
	if err := s.recordAgentAudit(r, actor, agentID, "whatsapp.agent_test_number.registered", map[string]any{"masked": agentadmin.MaskWhatsAppNumber(body.Number)}); err != nil {
		writeError(w, r, err)
		return
	}
	httpx.OK(w, map[string]any{"numbers": data, "max": 5}, "Nomor uji terdaftar.", nil)
}

func (s *Server) handleAgentWhatsAppTestNumberDelete(w http.ResponseWriter, r *http.Request) {
	actor, agentID, ok := s.agentAdmin(w, r, true)
	if !ok {
		return
	}
	id, ok := pathID(w, r, "id")
	if !ok {
		return
	}
	data, err := agentadmin.New(s.database, s.cfg.AppKey).RemoveWhatsAppTestNumber(r.Context(), agentID, id)
	if err != nil {
		writeAgentAdminError(w, r, err)
		return
	}
	if err := s.recordAgentAudit(r, actor, agentID, "whatsapp.agent_test_number.removed", map[string]any{"id": id}); err != nil {
		writeError(w, r, err)
		return
	}
	httpx.OK(w, map[string]any{"numbers": data, "max": 5}, "OK", nil)
}
