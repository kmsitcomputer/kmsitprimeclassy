package api

import (
	"encoding/json"
	"errors"
	"fmt"
	"net/http"
	"strings"

	"github.com/kmsitcomputer/primeclassy-go/internal/httpx"
	"github.com/kmsitcomputer/primeclassy-go/internal/i18n"
	"github.com/kmsitcomputer/primeclassy-go/internal/orders"
)

type receiptAllocationInput struct {
	ReceiptID json.RawMessage `json:"receipt_id"`
	Quantity  json.RawMessage `json:"quantity"`
}

func (s *Server) handleReturnReceiptAllocationsStore(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	returnItemID, ok := pathID(w, r, "returnItem")
	if !ok {
		return
	}
	var exists bool
	if err := s.database.QueryRowContext(ctx, `SELECT EXISTS(SELECT 1 FROM return_items WHERE id=?)`, returnItemID).Scan(&exists); err != nil {
		writeError(w, r, err)
		return
	}
	if !exists {
		writeError(w, r, notFoundError(ctx))
		return
	}

	var body map[string]json.RawMessage
	if err := httpx.DecodeJSON(r, &body); err != nil {
		writeError(w, r, err)
		return
	}
	fields := map[string][]string{}
	locale := LocaleFrom(ctx)
	allocationsRaw, hasAllocations := body["allocations"]
	var inputs []receiptAllocationInput
	if !hasAllocations || strings.TrimSpace(string(allocationsRaw)) == "null" || strings.TrimSpace(string(allocationsRaw)) == `""` {
		fields["allocations"] = []string{i18n.ValidationMessage(locale, "required", "allocations")}
	} else if err := json.Unmarshal(allocationsRaw, &inputs); err != nil || inputs == nil {
		fields["allocations"] = []string{i18n.ValidationMessage(locale, "array", "allocations")}
	} else {
		if len(inputs) == 0 {
			fields["allocations"] = []string{i18n.ValidationMessage(locale, "required", "allocations")}
		} else if len(inputs) > 50 {
			fields["allocations"] = []string{i18n.ValidationMessage(locale, "max.array", "allocations", "max", "50")}
		}
	}
	note, notePresent := body["evidence_note"]
	var noteValue *string
	if notePresent && strings.TrimSpace(string(note)) != "null" {
		var value string
		if err := json.Unmarshal(note, &value); err != nil {
			fields["evidence_note"] = []string{i18n.ValidationMessage(locale, "string", "evidence_note")}
		} else {
			if len([]rune(value)) > 500 {
				fields["evidence_note"] = []string{i18n.ValidationMessage(locale, "max.string", "evidence_note", "max", "500")}
			}
			noteValue = &value
		}
	}

	validAllocations := make([]orders.ReceiptAllocation, 0, len(inputs))
	for index, input := range inputs {
		receiptID, receiptOK := parseReceiptInteger(input.ReceiptID)
		quantity, quantityOK := parseReceiptInteger(input.Quantity)
		prefix := fmt.Sprintf("allocations.%d", index)
		if !receiptOK {
			key := prefix + ".receipt_id"
			if len(input.ReceiptID) == 0 || strings.TrimSpace(string(input.ReceiptID)) == "null" || strings.TrimSpace(string(input.ReceiptID)) == `""` {
				fields[key] = []string{nestedItemValidationMessage(locale, "required", key)}
			} else {
				fields[key] = []string{nestedItemValidationMessage(locale, "integer", key)}
			}
		}
		if !quantityOK {
			key := prefix + ".quantity"
			if len(input.Quantity) == 0 || strings.TrimSpace(string(input.Quantity)) == "null" || strings.TrimSpace(string(input.Quantity)) == `""` {
				fields[key] = []string{nestedItemValidationMessage(locale, "required", key)}
			} else {
				fields[key] = []string{nestedItemValidationMessage(locale, "integer", key)}
			}
		} else if quantity < 1 {
			key := prefix + ".quantity"
			fields[key] = []string{i18n.ValidationMessage(locale, "min.numeric", key, "min", "1")}
		}
		if receiptOK && quantityOK && quantity >= 1 {
			validAllocations = append(validAllocations, orders.ReceiptAllocation{ReceiptID: receiptID, Quantity: quantity})
		}
	}
	if len(fields) > 0 {
		writeError(w, r, httpx.Validation(fields))
		return
	}

	actor := UserFrom(ctx)
	userAgent := r.UserAgent()
	if len(userAgent) > 500 {
		userAgent = userAgent[:500]
	}
	result, err := (&orders.ReceiptAllocationService{Database: s.database}).Record(ctx, returnItemID,
		orders.ReceiptAllocationActor{ID: actor.ID, Role: actor.Role, AgentID: derefInt(actor.AgentID)},
		validAllocations, noteValue, clientIP(r), userAgent)
	if err != nil {
		var problem *orders.ReceiptAllocationProblem
		if errors.As(err, &problem) {
			writeError(w, r, httpx.NewError(problem.Status, problem.Message))
			return
		}
		writeError(w, r, err)
		return
	}
	httpx.Created(w, result, "Created")
}

func parseReceiptInteger(raw json.RawMessage) (int64, bool) {
	if len(raw) == 0 {
		return 0, false
	}
	var value int64
	if err := json.Unmarshal(raw, &value); err == nil {
		return value, true
	}
	var text string
	if err := json.Unmarshal(raw, &text); err != nil || strings.TrimSpace(text) == "" {
		return 0, false
	}
	if _, err := fmt.Sscan(strings.TrimSpace(text), &value); err != nil {
		return 0, false
	}
	return value, true
}
