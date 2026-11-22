package api

import (
	"errors"
	"net/http"

	"github.com/kmsitcomputer/primeclassy-go/internal/address"
	"github.com/kmsitcomputer/primeclassy-go/internal/httpx"
)

// writeAddressError maps an address-domain error onto the envelope.
func writeAddressError(w http.ResponseWriter, r *http.Request, err error) {
	ctx := r.Context()
	var de *address.DomainError
	if errors.As(err, &de) {
		message := de.Message
		if message == "" || message == "validation" {
			message = msg(ctx, "system.validation_failed")
		}
		if len(de.Fields) > 0 {
			payload := make(map[string][]string, len(de.Fields))
			for k, v := range de.Fields {
				switch v {
				case "required":
					payload[k] = requiredField(ctx, k)
				case "unique":
					payload[k] = uniqueField(ctx, k)
				case "exists":
					payload[k] = unknownChoice(ctx, k)
				default:
					payload[k] = []string{v}
				}
			}
			httpx.Failure(w, de.Status, message, payload)
			return
		}
		httpx.Failure(w, de.Status, message, nil)
		return
	}
	if errors.Is(err, address.ErrNotFound) {
		writeError(w, r, notFoundError(ctx))
		return
	}
	writeError(w, r, err)
}

type addressPayload struct {
	Label         *string  `json:"label"`
	RecipientName *string  `json:"recipient_name"`
	Phone         *string  `json:"phone"`
	AddressLine   *string  `json:"address_line"`
	VillageID     string   `json:"village_id"`
	Latitude      *float64 `json:"latitude"`
	Longitude     *float64 `json:"longitude"`
	IsDefault     bool     `json:"is_default"`
}

// validateAddressRequest reproduces StoreKonsumenAddressRequest::rules (the same field names and sentences).
func validateAddressRequest(r *http.Request, body addressPayload) map[string][]string {
	ctx := r.Context()
	fields := map[string][]string{}
	if body.RecipientName == nil || *body.RecipientName == "" {
		fields["recipient_name"] = requiredField(ctx, "recipient_name")
	} else if len(*body.RecipientName) > 150 {
		fields["recipient_name"] = maxString(ctx, "recipient_name", 150)
	}
	if body.Phone == nil || *body.Phone == "" {
		fields["phone"] = requiredField(ctx, "phone")
	} else if len(*body.Phone) > 20 {
		fields["phone"] = maxString(ctx, "phone", 20)
	}
	if body.AddressLine == nil || *body.AddressLine == "" {
		fields["address_line"] = requiredField(ctx, "address_line")
	} else if len(*body.AddressLine) > 1000 {
		fields["address_line"] = maxString(ctx, "address_line", 1000)
	}
	if body.VillageID == "" {
		fields["village_id"] = requiredField(ctx, "village_id")
	}
	if body.Label != nil && len(*body.Label) > 50 {
		fields["label"] = maxString(ctx, "label", 50)
	}
	if body.Latitude == nil {
		fields["latitude"] = requiredField(ctx, "latitude")
	} else if *body.Latitude < -90 || *body.Latitude > 90 {
		fields["latitude"] = []string{betweenMessage(ctx, "latitude", -90, 90)}
	}
	if body.Longitude == nil {
		fields["longitude"] = requiredField(ctx, "longitude")
	} else if *body.Longitude < -180 || *body.Longitude > 180 {
		fields["longitude"] = []string{betweenMessage(ctx, "longitude", -180, 180)}
	}
	return fields
}

// handleAddressIndex is GET /addresses.
func (s *Server) handleAddressIndex(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	u := UserFrom(ctx)
	if u == nil {
		writeError(w, r, unauthorizedError(ctx))
		return
	}
	rows, err := address.List(ctx, s.database, u.ID)
	if err != nil {
		writeAddressError(w, r, err)
		return
	}
	httpx.OK(w, rows, "OK", nil)
}

// handleAddressStore is POST /addresses.
func (s *Server) handleAddressStore(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	u := UserFrom(ctx)
	if u == nil {
		writeError(w, r, unauthorizedError(ctx))
		return
	}
	var body addressPayload
	if err := httpx.DecodeJSON(r, &body); err != nil {
		writeError(w, r, err)
		return
	}
	if fields := validateAddressRequest(r, body); len(fields) > 0 {
		writeError(w, r, httpx.Validation(fields))
		return
	}
	row, err := address.Create(ctx, s.database, u.ID, address.Input{
		Label: body.Label, RecipientName: body.RecipientName, Phone: body.Phone,
		AddressLine: body.AddressLine, VillageID: body.VillageID,
		Latitude: body.Latitude, Longitude: body.Longitude, IsDefault: body.IsDefault,
	})
	if err != nil {
		writeAddressError(w, r, err)
		return
	}
	httpx.Created(w, row, msg(ctx, "address.created"))
}

// handleAddressUpdate is PATCH /addresses/{address}.
func (s *Server) handleAddressUpdate(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	u := UserFrom(ctx)
	if u == nil {
		writeError(w, r, unauthorizedError(ctx))
		return
	}
	id, ok := pathID(w, r, "address")
	if !ok {
		return
	}
	var body addressPayload
	if err := httpx.DecodeJSON(r, &body); err != nil {
		writeError(w, r, err)
		return
	}
	if fields := validateAddressRequest(r, body); len(fields) > 0 {
		writeError(w, r, httpx.Validation(fields))
		return
	}
	row, err := address.Update(ctx, s.database, u.ID, id, address.Input{
		Label: body.Label, RecipientName: body.RecipientName, Phone: body.Phone,
		AddressLine: body.AddressLine, VillageID: body.VillageID,
		Latitude: body.Latitude, Longitude: body.Longitude, IsDefault: body.IsDefault,
	})
	if err != nil {
		writeAddressError(w, r, err)
		return
	}
	httpx.OK(w, row, msg(ctx, "address.updated"), nil)
}

// handleAddressDestroy is DELETE /addresses/{address}.
func (s *Server) handleAddressDestroy(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	u := UserFrom(ctx)
	if u == nil {
		writeError(w, r, unauthorizedError(ctx))
		return
	}
	id, ok := pathID(w, r, "address")
	if !ok {
		return
	}
	if err := address.Delete(ctx, s.database, u.ID, id); err != nil {
		writeAddressError(w, r, err)
		return
	}
	httpx.OK(w, nil, msg(ctx, "address.deleted"), nil)
}
