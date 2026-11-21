package whatsappnotify

import (
	"context"
	"database/sql"
)

// proofBody is TransactionalEmailNotifier::PROOF_BODY (Master Template §5).
const proofBody = "Halo {nama_keuangan},\n\n" +
	"Sistem PrimeClassy menerima unggahan bukti pembayaran dari konsumen yang memerlukan pemeriksaan dan verifikasi oleh Tim Keuangan.\n\n" +
	"Informasi Pesanan\n- Nomor Pesanan: {nomor_pesanan}\n- Nama Konsumen: {nama_pelanggan}\n- Total Tagihan: {total}\n" +
	"- Nominal yang Diklaim: {nominal_klaim}\n- Waktu Unggah: {waktu_upload}\n- Status: Menunggu Verifikasi Keuangan\n\n" +
	"Tindakan yang Diperlukan\nSilakan masuk ke dashboard PrimeClassy untuk melihat bukti pembayaran, memeriksa nominal dan identitas transaksi, " +
	"lalu menyetujui atau menolak melalui fitur verifikasi.\n\n" +
	"Buka Halaman Verifikasi: {link_verifikasi_pembayaran}\n\n" +
	"Catatan keamanan: bukti pembayaran yang diunggah belum membuktikan dana sudah diterima. Jangan menyetujui pembayaran sebelum pemeriksaan selesai.\n\n" +
	"Email otomatis khusus petugas Keuangan. Mohon tidak membalas email ini."

// NotifyEmailPaymentProofUploaded ports TransactionalEmailNotifier::dispatchFinance:
// the branch's Keuangan only; an account without a verified address gets a skipped
// ledger row (email_not_verified) and no send. Per-recipient {nama_keuangan}.
func NotifyEmailPaymentProofUploaded(ctx context.Context, tx executor, verificationID, orderID int64,
	proofHash string, submittedAmount sql.NullFloat64, submittedAt sql.NullTime) error {
	if !smtpTransactionalEnabled(ctx, tx) {
		return nil
	}
	o, err := LoadOrder(ctx, tx, orderID)
	if err != nil {
		if err == sql.ErrNoRows {
			return nil
		}
		return err
	}
	baseVars := OrderVars(ctx, tx, o)
	users := financeRecipients(ctx, tx, o)
	for _, user := range users {
		var name sql.NullString
		_ = tx.QueryRowContext(ctx, `SELECT name FROM users WHERE id = ?`, user).Scan(&name)
		vars := map[string]*string{}
		for k, v := range baseVars {
			vars[k] = v
		}
		vars["nama_keuangan"] = nilIfEmpty(name)
		vars["nominal_klaim"] = Str("")
		if submittedAmount.Valid {
			vars["nominal_klaim"] = Str(moneyID(submittedAmount.Float64))
		}
		vars["waktu_upload"] = nil
		if submittedAt.Valid {
			vars["waktu_upload"] = dateTimeID(submittedAt.Time)
		}
		vars["link_verifikasi_pembayaran"] = orderURL(o.ID)
		body, ok := Render(EventPaymentProofUploaded, proofBody, vars)
		if !ok {
			continue
		}
		email := verifiedEmail(ctx, tx, user)
		subject := subjectFor(EventPaymentProofUploaded, vars)
		if len([]rune(subject)) > 190 {
			subject = string([]rune(subject)[:190])
		}
		if err := insertEmailRow(ctx, tx, emailRow{
			IdempotencyKey: mailKey(EventPaymentProofUploaded, "bank_transfer_verification",
				verificationID, user, proofHash),
			Event: EventPaymentProofUploaded, AgentID: nilInt64(o.AgentID), OrderID: &o.ID,
			SubjectType: "bank_transfer_verification", SubjectID: verificationID,
			Role: "finance", UserID: user, Email: email, Subject: subject, Body: body,
			Skipped: email == "", SkipReason: map[bool]string{true: "email_not_verified", false: ""}[email == ""],
		}); err != nil {
			return err
		}
	}
	return nil
}
