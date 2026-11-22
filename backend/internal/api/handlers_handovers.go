package api

// Handover read + print endpoints.
//
//	GET /api/v1/warehouse/handovers/{handover}        JSON — the model with its transfer, items, and both actors
//	GET /api/v1/warehouse/handovers/{handover}/print  the A4 print page (HTML)
//
// Policy: super_admin, or agen/admin/gudang of the same branch; a foreign id is a 404.

import (
	"html/template"
	"net/http"

	"github.com/kmsitcomputer/primeclassy-go/internal/httpx"
	"github.com/kmsitcomputer/primeclassy-go/internal/inventory"
)

// handleHandoverShow is GET /warehouse/handovers/{handover}.
func (s *Server) handleHandoverShow(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	actor := UserFrom(ctx)
	if actor == nil {
		writeError(w, r, unauthorizedError(ctx))
		return
	}
	id, ok := pathID(w, r, "handover")
	if !ok {
		return
	}
	// super_admin may read any branch; everyone else is confined to their own (the policy's own split).
	var agentID int64
	if actor.AgentID != nil {
		agentID = *actor.AgentID
	}
	if !actor.IsRole("super_admin") && agentID == 0 {
		writeError(w, r, &httpx.Error{Status: http.StatusForbidden, Message: msg(ctx, "system.unauthorized_action")})
		return
	}
	handover, err := inventory.LoadHandover(ctx, s.database, agentID, id)
	if err != nil {
		writeError(w, r, stockRequestError(ctx, err))
		return
	}
	httpx.OK(w, renderHandover(handover), "OK", nil)
}

// handleHandoverPrint is GET /warehouse/handovers/{handover}/print — Blade's warehouse/handover-print as a Go
// template with the same structure, escaping and field order.
func (s *Server) handleHandoverPrint(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	actor := UserFrom(ctx)
	if actor == nil {
		writeError(w, r, unauthorizedError(ctx))
		return
	}
	if !actor.IsRole("super_admin", "agen", "admin", "gudang") {
		writeError(w, r, &httpx.Error{Status: http.StatusForbidden, Message: msg(ctx, "system.unauthorized_action")})
		return
	}
	id, ok := pathID(w, r, "handover")
	if !ok {
		return
	}
	var agentID int64
	if actor.AgentID != nil {
		agentID = *actor.AgentID
	}
	if !actor.IsRole("super_admin") && agentID == 0 {
		writeError(w, r, &httpx.Error{Status: http.StatusForbidden, Message: msg(ctx, "system.unauthorized_action")})
		return
	}
	print, err := inventory.BuildHandoverPrint(ctx, s.database, agentID, id)
	if err != nil {
		writeError(w, r, stockRequestError(ctx, err))
		return
	}
	w.Header().Set("Content-Type", "text/html; charset=utf-8")
	w.WriteHeader(http.StatusOK)
	_ = handoverPrintTemplate.Execute(w, print)
}

// handoverPrintTemplate mirrors resources/views/warehouse/handover-print.blade.php exactly: same elements, same
// styles, same escaping semantics (html/template escapes like Blade's {{ }}).
var handoverPrintTemplate = template.Must(template.New("handover").Parse(`<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ .HandoverNumber }}</title>
    <style>
        @page { size: A4; margin: 18mm; }
        body { font-family: Arial, sans-serif; color: #222; font-size: 12px; }
        h1 { margin-bottom: 4px; } table { width: 100%; border-collapse: collapse; margin-top: 18px; }
        th, td { border: 1px solid #888; padding: 7px; text-align: left; } th:last-child, td:last-child { text-align: right; }
        .meta { display: grid; grid-template-columns: 1fr 1fr; gap: 4px 24px; } .signatures { display: flex; justify-content: space-between; margin-top: 80px; }
        .signature { width: 40%; border-top: 1px solid #222; padding-top: 6px; }
    </style>
</head>
<body>
    <h1>Prime Classy Cake &amp; Cookies</h1><h2>Stock Handover</h2>
    <div class="meta">
        <div>Agent: {{ .AgentName }}</div><div>Printed: {{ .PrintedAt }}</div>
        <div>Handover: {{ .HandoverNumber }}</div><div>Transfer: {{ .TransferNumber }}</div>
        <div>Source: {{ .Source }}</div><div>Destination: {{ .Destination }}</div>
        <div>Handed over by: {{ .HandedOverName }}</div><div>Received by: {{ .ReceivedName }}</div>
    </div>
    <table><thead><tr><th>SKU</th><th>Product / Variation</th><th>Qty</th></tr></thead><tbody>{{ range .Items }}
            <tr><td>{{ .SKU }}</td><td>{{ .Label }}</td><td>{{ .Quantity }}</td></tr>{{ end }}
        </tbody></table>
    <p>Notes: {{ .Note }}</p>
    <div class="signatures"><div class="signature">Handed over by</div><div class="signature">Received by</div></div>
</body>
</html>`))

// renderHandover is the raw model serialisation the controller returns (no resource class).
func renderHandover(h *inventory.Handover) map[string]any {
	transfer := map[string]any{}
	if h.Transfer != nil {
		transfer = *h.Transfer
		inventory.StripPrintHelpers(transfer)
	}
	return map[string]any{
		"id":                h.ID,
		"agent_id":          h.AgentID,
		"stock_transfer_id": h.StockTransferID,
		"handover_number":   nullableString(h.HandoverNumber),
		"handed_over_by":    handoverUser(h.HandedOverUser),
		"received_by":       handoverUser(h.ReceivedUser),
		"status":            h.Status,
		"handed_over_at":    renderTimestamp(h.HandedOverAt),
		"received_at":       renderTimestamp(h.ReceivedAt),
		"note":              nullableString(h.Note),
		"created_at":        h.CreatedAt.UTC().Format("2006-01-02T15:04:05.000000Z"),
		"updated_at":        h.UpdatedAt.UTC().Format("2006-01-02T15:04:05.000000Z"),
		"transfer":          transfer,
	}
}

func handoverUser(user *map[string]any) any {
	if user == nil {
		return nil
	}
	return *user
}
