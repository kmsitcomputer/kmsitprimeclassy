package api

import (
	"errors"
	"net/http"
	"strconv"

	"github.com/kmsitcomputer/primeclassy-go/internal/courier"
	"github.com/kmsitcomputer/primeclassy-go/internal/httpx"
	"github.com/kmsitcomputer/primeclassy-go/internal/i18n"
)

func (s *Server) courierService() *courier.Service { return courier.New(s.database) }

func courierActor(r *http.Request) courier.Actor {
	u := UserFrom(r.Context())
	return courier.Actor{ID: u.ID, Role: u.Role, AgentID: derefInt(u.AgentID)}
}

func writeCourierError(w http.ResponseWriter, r *http.Request, err error) {
	var de *courier.DomainError
	if errors.As(err, &de) {
		m := de.Message
		if de.Status == http.StatusNotFound || de.Message == "" {
			m = msg(r.Context(), "system.endpoint_not_found")
		}
		writeError(w, r, httpx.NewError(de.Status, m))
		return
	}
	writeError(w, r, err)
}

func (s *Server) handleKurirOrders(w http.ResponseWriter, r *http.Request) {
	result, err := s.courierService().Orders(r.Context(), courierActor(r), r.URL.Query())
	if err != nil {
		writeCourierError(w, r, err)
		return
	}
	httpx.OK(w, result["data"], "OK", result["meta"].(map[string]int))
}

func (s *Server) handleKurirReturns(w http.ResponseWriter, r *http.Request) {
	result, err := s.courierService().Returns(r.Context(), courierActor(r), r.URL.Query())
	if err != nil {
		writeCourierError(w, r, err)
		return
	}
	httpx.OK(w, result["data"], "OK", result["meta"].(map[string]int))
}

func (s *Server) handleKurirPickupToday(w http.ResponseWriter, r *http.Request) {
	result, err := s.courierService().PickupToday(r.Context(), courierActor(r))
	if err != nil {
		writeCourierError(w, r, err)
		return
	}
	httpx.OK(w, result, "OK", nil)
}

func (s *Server) handleKurirDeliveredReport(w http.ResponseWriter, r *http.Request) {
	result, err := s.courierService().DeliveredReport(r.Context(), courierActor(r), r.URL.Query())
	if err != nil {
		writeCourierError(w, r, err)
		return
	}
	httpx.OK(w, result["data"], "OK", result["meta"].(map[string]int))
}

func (s *Server) handleKurirPickupReturn(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	id, err := strconv.ParseInt(httpx.PathValue(r, "item"), 10, 64)
	if err != nil {
		writeError(w, r, httpx.NewError(http.StatusNotFound, msg(ctx, "system.endpoint_not_found")))
		return
	}
	var body struct {
		Note *string `json:"note"`
	}
	_ = httpx.DecodeJSON(r, &body)
	note := ""
	if body.Note != nil {
		if len([]rune(*body.Note)) > 255 {
			writeError(w, r, httpx.Validation(map[string][]string{"note": {
				i18n.ValidationMessage(LocaleFrom(ctx), "max.string", "note", "max", "255")}}))
			return
		}
		note = *body.Note
	}
	result, aerr := s.courierService().PickupReturn(ctx, courierActor(r), id, note)
	if aerr != nil {
		writeCourierError(w, r, aerr)
		return
	}
	httpx.OK(w, result, "Kurir berhasil ditugaskan.", nil)
}

func (s *Server) handleKurirConfirmReturn(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	id, err := strconv.ParseInt(httpx.PathValue(r, "item"), 10, 64)
	if err != nil {
		writeError(w, r, httpx.NewError(http.StatusNotFound, msg(ctx, "system.endpoint_not_found")))
		return
	}
	var body struct {
		Received *bool `json:"received"`
	}
	_ = httpx.DecodeJSON(r, &body)
	if body.Received == nil {
		writeError(w, r, httpx.Validation(map[string][]string{"received": {
			i18n.ValidationMessage(LocaleFrom(ctx), "required", "received")}}))
		return
	}
	result, aerr := s.courierService().ConfirmReturn(ctx, courierActor(r), id, *body.Received)
	if aerr != nil {
		writeCourierError(w, r, aerr)
		return
	}
	httpx.OK(w, result, "Status pengembalian berhasil diperbarui.", nil)
}
