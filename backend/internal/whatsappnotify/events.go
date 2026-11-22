// Package whatsappnotify ports the Laravel transactional notification engine:
// WhatsAppEvents (event catalog + default templates, dumped verbatim from the
// reference runtime), MessageTemplates (safe {token} rendering), WhatsAppSettings
// (per-agent event switches), OrderRecipientResolver, RestrictedDeliveryPolicy
// (enqueue-time allow-list), the WhatsAppDelivery enqueue/skip ledger writers and
// the TransactionalEmailNotifier ledger — all inside the caller's transaction so
// a rollback notifies nobody (Laravel after-commit semantics preserved).
package whatsappnotify

import (
	_ "embed"
	"encoding/json"
)

//go:embed wa_events.json
var eventsJSON []byte

// EventDef is one WhatsAppEvents::all() entry (label/variables/required/default),
// extracted from the running Laravel reference — never hand-transcribed.
type EventDef struct {
	Label     string   `json:"label"`
	Variables []string `json:"variables"`
	Required  []string `json:"required"`
	Default   string   `json:"default"`
}

var events = func() map[string]EventDef {
	var out map[string]EventDef
	if err := json.Unmarshal(eventsJSON, &out); err != nil {
		panic("whatsappnotify: invalid embedded event catalog: " + err.Error())
	}
	return out
}()

// FOOTER is WhatsAppEvents::FOOTER, appended to every default template.
const FOOTER = "\n\nPesan otomatis dari PrimeClassy. Jangan membagikan OTP atau kata sandi kepada siapa pun."

// Event name constants (WhatsAppEvents).
const (
	EventOrderCreated         = "order_created"
	EventPaymentPaid          = "payment_paid"
	EventPaymentPartial       = "payment_partial"
	EventOrderProcessed       = "order_processed"
	EventOrderShipped         = "order_shipped"
	EventOrderDelivered       = "order_delivered"
	EventOrderCancelled       = "order_cancelled"
	EventReturnRequested      = "return_requested"
	EventReturnApproved       = "return_approved"
	EventReturnRejected       = "return_rejected"
	EventRefundCompleted      = "refund_completed"
	EventPaymentFailed        = "payment_failed"
	EventPaymentProofUploaded = "payment_proof_uploaded"
	EventRefundRequested      = "refund_requested"
	EventRefundApproved       = "refund_approved"
	EventRefundRejected       = "refund_rejected"
)

// Exists reports whether the event is in the transactional catalog.
func Exists(event string) bool {
	_, ok := events[event]
	return ok
}

// Def returns the event definition (ok=false for unknown events).
func Def(event string) (EventDef, bool) {
	d, ok := events[event]
	return d, ok
}

// DefaultTemplate returns the built-in template for the event.
func DefaultTemplate(event string) string {
	if d, ok := events[event]; ok {
		return d.Default
	}
	return ""
}
