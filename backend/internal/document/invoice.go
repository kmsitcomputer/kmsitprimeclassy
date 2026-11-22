package document

import (
	"bytes"
	"fmt"

	"github.com/jung-kurt/gofpdf"
)

type InvoiceItem struct {
	Name, Variation string
	Quantity        int
	Price, Total    string
}

type Invoice struct {
	Number, IssuedAt, RecipientName, RecipientPhone, RecipientAddress string
	Subtotal, Shipping, AdminFee, Voucher, Total, Paid, Remaining     string
	CompanyName, Title, Contact, CompanyAddress, Footer, Notes        string
	OrderDate, Status, PaymentMethod, PaymentStatus, VoucherLabel     string
	ShowRecipient, ShowItems, ShowPayment, ShowVoucher                bool
	ShowInternalReferral, ShowSales, ShowKorsal                       bool
	SalesName, KorsalName, LogoType                                   string
	LogoBytes                                                         []byte
	DP                                                                string
	Items                                                             []InvoiceItem
}

func InvoicePDF(v Invoice) ([]byte, error) {
	pdf := gofpdf.New("P", "mm", "A4", "")
	pdf.SetMargins(15, 18, 15)
	pdf.SetTitle("Invoice "+v.Number, true)
	pdf.AddPage()
	company, title := v.CompanyName, v.Title
	if company == "" {
		company = "PrimeClassy"
	}
	if title == "" {
		title = "INVOICE"
	}
	pdf.SetDrawColor(139, 38, 53)
	pdf.SetTextColor(139, 38, 53)
	if len(v.LogoBytes) != 0 {
		if v.LogoType == "png" || v.LogoType == "jpeg" || v.LogoType == "gif" {
			options := gofpdf.ImageOptions{ImageType: v.LogoType, ReadDpi: true}
			if info := pdf.RegisterImageOptionsReader("invoice-logo", options, bytes.NewReader(v.LogoBytes)); info != nil && pdf.Error() == nil {
				pdf.ImageOptions("invoice-logo", 15, 16, 36, 0, false, options, 0, "")
				pdf.SetXY(55, pdf.GetY())
			}
		}
	}
	pdf.SetFont("Helvetica", "B", 16)
	pdf.CellFormat(100, 10, company, "", 0, "L", false, 0, "")
	pdf.SetTextColor(31, 26, 27)
	pdf.SetFont("Helvetica", "B", 18)
	pdf.CellFormat(80, 10, title, "", 1, "R", false, 0, "")
	pdf.SetDrawColor(139, 38, 53)
	pdf.Line(15, pdf.GetY(), 195, pdf.GetY())
	pdf.SetFont("Helvetica", "", 10)
	pdf.CellFormat(0, 6, "Invoice "+v.Number, "", 1, "L", false, 0, "")
	pdf.CellFormat(0, 6, "Tanggal: "+v.OrderDate, "", 1, "L", false, 0, "")
	pdf.CellFormat(0, 6, "Status: "+v.Status, "", 1, "L", false, 0, "")
	pdf.Ln(4)
	if v.ShowRecipient {
		pdf.SetFont("Helvetica", "B", 10)
		pdf.CellFormat(0, 6, "Konsumen: "+v.RecipientName, "", 1, "L", false, 0, "")
		pdf.SetFont("Helvetica", "", 10)
		pdf.CellFormat(0, 6, "Telepon: "+v.RecipientPhone, "", 1, "L", false, 0, "")
		pdf.MultiCell(0, 5, "Alamat: "+v.RecipientAddress, "", "L", false)
	}
	if v.ShowInternalReferral && (v.ShowSales && v.SalesName != "" || v.ShowKorsal && v.KorsalName != "") {
		pdf.SetFont("Helvetica", "B", 10)
		if v.ShowSales && v.SalesName != "" {
			pdf.CellFormat(0, 6, "Sales: "+v.SalesName, "", 1, "L", false, 0, "")
		}
		if v.ShowKorsal && v.KorsalName != "" {
			pdf.CellFormat(0, 6, "Korsal: "+v.KorsalName, "", 1, "L", false, 0, "")
		}
	}
	if v.PaymentMethod != "" {
		pdf.SetFont("Helvetica", "B", 10)
		pdf.CellFormat(0, 6, "Metode Pembayaran: "+v.PaymentMethod, "", 1, "L", false, 0, "")
		pdf.CellFormat(0, 6, "Status Pembayaran: "+v.PaymentStatus, "", 1, "L", false, 0, "")
	}
	if v.ShowItems {
		pdf.Ln(3)
		pdf.SetFont("Helvetica", "B", 9)
		pdf.SetFillColor(139, 38, 53)
		pdf.SetTextColor(255, 255, 255)
		pdf.CellFormat(75, 7, "Produk", "1", 0, "L", true, 0, "")
		pdf.CellFormat(45, 7, "Variasi", "1", 0, "L", true, 0, "")
		pdf.CellFormat(12, 7, "Qty", "1", 0, "R", true, 0, "")
		pdf.CellFormat(24, 7, "Harga", "1", 0, "R", true, 0, "")
		pdf.CellFormat(24, 7, "Subtotal", "1", 1, "R", true, 0, "")
		pdf.SetTextColor(31, 26, 27)
		pdf.SetFont("Helvetica", "", 9)
		for _, item := range v.Items {
			pdf.CellFormat(75, 7, item.Name, "1", 0, "L", false, 0, "")
			pdf.CellFormat(45, 7, item.Variation, "1", 0, "L", false, 0, "")
			pdf.CellFormat(12, 7, fmt.Sprintf("%d", item.Quantity), "1", 0, "R", false, 0, "")
			pdf.CellFormat(24, 7, item.Price, "1", 0, "R", false, 0, "")
			pdf.CellFormat(24, 7, item.Total, "1", 1, "R", false, 0, "")
		}
	}
	if v.ShowVoucher && v.VoucherLabel != "" {
		pdf.Ln(3)
		pdf.MultiCell(0, 5, v.VoucherLabel, "", "L", false)
	}
	if v.Notes != "" {
		pdf.MultiCell(0, 5, "Catatan: "+v.Notes, "", "L", false)
	}
	if v.ShowPayment {
		pdf.Ln(4)
		summary := []struct{ label, value string }{{"Subtotal", v.Subtotal}, {"Diskon/Voucher", v.Voucher}, {"Ongkir", v.Shipping}, {"Biaya Admin", v.AdminFee}, {"Total", v.Total}, {"Dibayar", v.Paid}, {"Sisa Tagihan", v.Remaining}}
		pdf.SetFont("Helvetica", "", 10)
		for i, row := range summary {
			if i == 4 && v.DP != "" {
				pdf.CellFormat(145, 6, "DP", "", 0, "L", false, 0, "")
				pdf.CellFormat(30, 6, v.DP, "", 1, "R", false, 0, "")
			}
			pdf.CellFormat(145, 6, row.label, "", 0, "L", false, 0, "")
			if row.value != "" {
				pdf.CellFormat(30, 6, row.value, "", 1, "R", false, 0, "")
			} else {
				pdf.Ln(6)
			}
		}
	}
	if v.CompanyAddress != "" || v.Contact != "" || v.Footer != "" {
		pdf.Ln(8)
		pdf.SetFont("Helvetica", "", 8)
		pdf.MultiCell(0, 4, company+"  "+v.CompanyAddress+"  "+v.Contact+"\n"+v.Footer, "", "L", false)
	}
	var buf bytes.Buffer
	if err := pdf.Output(&buf); err != nil {
		return nil, err
	}
	return buf.Bytes(), nil
}
