package agentadmin

import (
	"fmt"
	"regexp"
	"strings"
	"unicode"
)

var previewLineBreak = regexp.MustCompile(`\r\n|\r|\n`)
var previewLongBreaks = regexp.MustCompile(`\n{3,}`)

var previewValues = map[string]string{
	"nama_pelanggan": "Budi Santoso", "nomor_pesanan": "PC-261028-000123", "status": "Dikirim", "total": "Rp 110.000", "tanggal": "29 Oktober 2026", "nama_toko": "Prime Classy",
	"link_pesanan": "https://contoh.primeclassy.test/orders/123", "daftar_produk": "Produk Contoh x1", "subtotal": "Rp 100.000", "diskon": "Rp 0", "ongkir": "Rp 10.000",
	"total_tagihan": "Rp 110.000", "metode_pembayaran": "Transfer Bank", "status_pembayaran": "Lunas", "jumlah_dibayarkan": "Rp 110.000", "sisa_tagihan": "Rp 0",
	"tanggal_pembayaran": "29 Oktober 2026", "jumlah_pembayaran_ini": "Rp 110.000", "nama_kurir": "Kurir Contoh", "layanan_pengiriman": "Reguler", "nomor_resi": "RESI-CONTOH-001",
	"nomor_telepon_kurir": "(nomor kurir tidak tersedia)", "estimasi_tiba": "(estimasi tiba tidak tersedia)", "link_pelacakan": "(tautan pelacakan tidak tersedia)", "kurir": "Kurir Contoh", "resi": "RESI-CONTOH-001", "tanggal_pengiriman": "30 Oktober 2026",
	"tanggal_diterima": "31 Oktober 2026", "tanggal_pembatalan": "29 Oktober 2026", "nomor_retur": "RTN-000045", "jumlah_refund": "Rp 55.000", "tanggal_refund": "2 November 2026",
	"jumlah_diajukan": "Rp 110.000", "alasan_penolakan": "Nominal transfer tidak sesuai", "nama_keuangan": "Petugas Keuangan", "nominal_klaim": "Rp 110.000", "waktu_upload": "29 Oktober 2026, 14.30 WIB",
	"link_verifikasi_pembayaran": "https://contoh.primeclassy.test/orders/123", "nomor_refund": "RO-CONTOH-0001",
}

// PreviewWhatsAppTemplate validates and renders with the same fake sample values as Laravel MessageTemplates.
func PreviewWhatsAppTemplate(event, template string) (string, error) {
	if err := validateTemplate(event, template); err != nil {
		return "", err
	}
	for _, required := range []string{"nomor_pesanan"} {
		if cleanPreview(previewValues[required]) == "" {
			return "", fmt.Errorf("required WhatsApp preview value missing")
		}
	}
	lines := previewLineBreak.Split(template, -1)
	out := make([]string, 0, len(lines))
	for _, line := range lines {
		drop := false
		rendered := tokenPattern.ReplaceAllStringFunc(line, func(token string) string {
			m := tokenPattern.FindStringSubmatch(token)
			if len(m) != 2 {
				return token
			}
			value := cleanPreview(previewValues[m[1]])
			if value == "" {
				if m[1] == "nama_pelanggan" {
					return "Pelanggan"
				}
				drop = true
				return ""
			}
			return value
		})
		if !drop {
			out = append(out, rendered)
		}
	}
	return strings.TrimSpace(previewLongBreaks.ReplaceAllString(strings.Join(out, "\n"), "\n\n")), nil
}

func cleanPreview(raw string) string {
	value := strings.TrimSpace(raw)
	if value == "" {
		return ""
	}
	var b strings.Builder
	for _, r := range value {
		if unicode.IsControl(r) {
			b.WriteRune(' ')
		} else if r == '{' {
			b.WriteRune('(')
		} else if r == '}' {
			b.WriteRune(')')
		} else {
			b.WriteRune(r)
		}
	}
	value = strings.TrimSpace(b.String())
	runes := []rune(value)
	if len(runes) > 200 {
		value = string(runes[:200])
	}
	return value
}
