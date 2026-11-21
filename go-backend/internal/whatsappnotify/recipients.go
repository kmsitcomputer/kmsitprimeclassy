package whatsappnotify

import (
	"context"
	"database/sql"
)

// recipients.go ports OrderRecipientResolver + RestrictedDeliveryPolicy (enqueue side)
// and the WhatsAppNumberProof msisdn rule.

// Recipient is one resolved notification target (role/msisdn/user/templateClass/primary).
type Recipient struct {
	Role          string
	Msisdn        string
	UserID        *int64
	TemplateClass string
	Primary       bool
}

var rolePrecedence = map[string]int{"admin": 3, "creator": 2, "recipient": 1}

// MsisdnForUser ports WhatsAppNumberProof::msisdnFor: a CONSUMED whatsapp_number
// proof challenge for exactly the CURRENT phone number.
func MsisdnForUser(ctx context.Context, q queryer, userID int64) string {
	var phone sql.NullString
	if err := q.QueryRowContext(ctx, `SELECT phone FROM users WHERE id = ?`, userID).Scan(&phone); err != nil || !phone.Valid {
		return ""
	}
	msisdn := NormalizeMSISDN(phone.String)
	if msisdn == "" {
		return ""
	}
	var cnt int
	if err := q.QueryRowContext(ctx, `SELECT COUNT(*) FROM identity_otp_challenges
		WHERE subject_user_id = ? AND purpose = 'whatsapp_number' AND sent_to_email = ?
		AND consumed_at IS NOT NULL AND invalidated_at IS NULL`, userID, "wa:"+msisdn).Scan(&cnt); err != nil || cnt == 0 {
		return ""
	}
	return msisdn
}

// NormalizeMSISDN ports Support\WhatsAppNumber::msisdn.
func NormalizeMSISDN(raw string) string {
	digits := ""
	for _, r := range raw {
		if r >= '0' && r <= '9' {
			digits += string(r)
		}
	}
	if digits == "" {
		return ""
	}
	switch {
	case digits[0] == '0':
		digits = "62" + digits[1:]
	case digits[0] == '8':
		digits = "62" + digits
	}
	if len(digits) < 9 || len(digits) > 15 {
		return ""
	}
	return digits
}

// ResolveOrderRecipients ports OrderRecipientResolver::resolve.
func ResolveOrderRecipients(ctx context.Context, q queryer, o *orderRow) []Recipient {
	var candidates []Recipient
	if o.AgentID.Valid {
		rows, err := q.QueryContext(ctx, `SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id
			WHERE r.slug = 'admin' AND u.agent_id = ? AND u.deleted_at IS NULL ORDER BY u.id`, o.AgentID.Int64)
		if err == nil {
			for rows.Next() {
				var id int64
				if rows.Scan(&id) == nil {
					if msisdn := MsisdnForUser(ctx, q, id); msisdn != "" {
						candidates = append(candidates, Recipient{Role: "admin", Msisdn: msisdn,
							UserID: &id, TemplateClass: "full"})
					}
				}
			}
			rows.Close()
		}
	}
	// The account buyer is the PRIMARY recipient.
	if o.KonsumenID.Valid {
		if msisdn := MsisdnForUser(ctx, q, o.KonsumenID.Int64); msisdn != "" {
			id := o.KonsumenID.Int64
			candidates = append(candidates, Recipient{Role: "creator", Msisdn: msisdn,
				UserID: &id, TemplateClass: "full", Primary: true})
		}
	}
	// Agent-assisted checkout submitter is a second creator.
	if o.PlacedByUserID.Valid && (!o.KonsumenID.Valid || o.PlacedByUserID.Int64 != o.KonsumenID.Int64) {
		if msisdn := MsisdnForUser(ctx, q, o.PlacedByUserID.Int64); msisdn != "" {
			id := o.PlacedByUserID.Int64
			candidates = append(candidates, Recipient{Role: "creator", Msisdn: msisdn,
				UserID: &id, TemplateClass: "full"})
		}
	}
	// Checkout recipient phone (restricted template, never an account).
	if o.RecipientPhone.Valid {
		msisdn := NormalizeMSISDN(o.RecipientPhone.String)
		if msisdn != "" {
			covered := false
			for _, c := range candidates {
				if c.Msisdn == msisdn {
					covered = true
					break
				}
			}
			if !covered {
				candidates = append(candidates, Recipient{Role: "recipient", Msisdn: msisdn,
					TemplateClass: "restricted"})
			}
		}
	}
	// Same number in several roles: keep only the highest-privilege row.
	byNumber := map[string]Recipient{}
	var order []string
	for _, c := range candidates {
		if _, ok := byNumber[c.Msisdn]; !ok {
			order = append(order, c.Msisdn)
		}
		if prev, ok := byNumber[c.Msisdn]; !ok || rolePrecedence[c.Role] > rolePrecedence[prev.Role] {
			byNumber[c.Msisdn] = c
		}
	}
	out := make([]Recipient, 0, len(order))
	for _, number := range order {
		out = append(out, byNumber[number])
	}
	return out
}

// restrictedEventEligible ports RestrictedDeliveryPolicy::eventEligible (enqueue side):
// status-change notices only for the checkout recipient — financial events are absent.
func restrictedEventEligible(role, event string) bool {
	if role != "recipient" {
		return false
	}
	switch event {
	case EventOrderCreated, EventOrderProcessed, EventOrderShipped, EventOrderDelivered, EventOrderCancelled:
		return true
	}
	return false
}

// RestrictedTemplate ports OrderNotifier::restrictedTemplate: fixed server text —
// names the event, names nothing financial, carries no OTP/token/link, always says
// the same thing (no per-recipient disclosure through wording).
func RestrictedTemplate(event string, orderNo string) string {
	def, ok := events[event]
	label := "pesanan"
	if ok {
		label = def.Label
	}
	return "Halo Pelanggan,\n\nAda pembaruan " + label + " untuk pesanan " + orderNo +
		".\n\nVerifikasi nomor WhatsApp Anda di aplikasi PrimeClassy untuk melihat detail lengkap."
}
