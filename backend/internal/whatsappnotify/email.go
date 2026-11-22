package whatsappnotify

import (
	"context"
	"database/sql"
	"regexp"
	"strings"
)

// email.go ports TransactionalEmailNotifier: email_messages ledger rows + their
// SendTransactionalEmailJob queue payload. Gated by smtp.transactional_enabled
// (dashboard SMTP ON + complete + the separate switch — default OFF).

func smtpTransactionalEnabled(ctx context.Context, tx executor) bool {
	get := func(key string) string {
		var v sql.NullString
		_ = tx.QueryRowContext(ctx, `SELECT value FROM settings WHERE `+"`group`"+`='integration.smtp'
			AND `+"`key`"+` = ?`, key).Scan(&v)
		return strings.TrimSpace(v.String)
	}
	truthy := func(v string) bool {
		switch strings.ToLower(v) {
		case "1", "true", "on", "yes":
			return true
		}
		return false
	}
	return truthy(get("smtp.enabled")) && get("smtp.host") != "" && get("smtp.port") != "" &&
		get("smtp.from_address") != "" && truthy(get("smtp.transactional_enabled"))
}

var emailSubjects = map[string]string{
	EventPaymentProofUploaded: "[PrimeClassy] Bukti Pembayaran Baru — Pesanan {nomor_pesanan}",
	EventOrderCreated:         "Pesanan {nomor_pesanan} Berhasil Dibuat",
	EventPaymentPaid:          "Pesanan {nomor_pesanan} Telah Lunas",
	EventPaymentPartial:       "Pembayaran Sebagian Pesanan {nomor_pesanan} Diterima",
	EventPaymentFailed:        "Pembayaran Pesanan {nomor_pesanan} Belum Berhasil",
	EventOrderProcessed:       "Pesanan {nomor_pesanan} Sedang Diproses",
	EventOrderShipped:         "Pesanan {nomor_pesanan} Telah Dikirim",
	EventOrderDelivered:       "Pesanan {nomor_pesanan} Selesai",
	EventOrderCancelled:       "Pesanan {nomor_pesanan} Dibatalkan",
	EventReturnRequested:      "Pengajuan Retur {nomor_retur} Diterima",
	EventReturnApproved:       "Pengajuan Retur {nomor_retur} Disetujui",
	EventReturnRejected:       "Hasil Peninjauan Retur {nomor_retur}",
	EventRefundRequested:      "Permohonan Refund {nomor_refund} Diterima",
	EventRefundApproved:       "Refund {nomor_refund} Disetujui",
	EventRefundRejected:       "Hasil Peninjauan Refund {nomor_refund}",
	EventRefundCompleted:      "Pengembalian Dana Pesanan {nomor_pesanan} Selesai",
}

// plainBody ports TransactionalEmailNotifier::plain: WhatsApp footer removed, bold
// markers stripped, blank runs collapsed.
var boldRe = regexp.MustCompile(`\*([^*\n]+)\*`)

func plainBody(body string) string {
	body = strings.ReplaceAll(body, strings.TrimSpace(FOOTER), "")
	body = boldRe.ReplaceAllString(body, "$1")
	return collapseNewlines(strings.TrimSpace(body))
}

func collapseNewlines(s string) string {
	out := ""
	prevNL := 0
	for _, r := range s {
		if r == '\n' {
			prevNL++
			if prevNL > 2 {
				continue
			}
		} else {
			prevNL = 0
		}
		out += string(r)
	}
	return out
}

// subjectFor ports TransactionalEmailNotifier::subject: unknown {tokens} fall back to
// nomor_pesanan, then empty; capped at 190 chars.
func subjectFor(event string, vars map[string]*string) string {
	re := regexp.MustCompile(`\{([a-z_]+)\}`)
	subject := re.ReplaceAllStringFunc(emailSubjects[event], func(tok string) string {
		name := tok[1 : len(tok)-1]
		if v := Clean(vars[name]); v != nil {
			return *v
		}
		if v := Clean(vars["nomor_pesanan"]); v != nil {
			return *v
		}
		return ""
	})
	subject = regexp.MustCompile(`\s{2,}`).ReplaceAllString(strings.TrimSpace(subject), " ")
	if len([]rune(subject)) > 190 {
		subject = string([]rune(subject)[:190])
	}
	return subject
}

// verifiedEmail ports verifiedEmail: only a proven address (email_verified_at) counts.
func verifiedEmail(ctx context.Context, tx executor, userID int64) string {
	var email sql.NullString
	var verifiedAt sql.NullTime
	err := tx.QueryRowContext(ctx, `SELECT email, email_verified_at FROM users WHERE id = ?`, userID).
		Scan(&email, &verifiedAt)
	if err != nil || !email.Valid || !verifiedAt.Valid {
		return ""
	}
	addr := strings.ToLower(strings.TrimSpace(email.String))
	if !strings.Contains(addr, "@") || strings.HasPrefix(addr, "@") || strings.HasSuffix(addr, "@") {
		return ""
	}
	return addr
}
