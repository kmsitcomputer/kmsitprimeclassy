package document

import (
	"bytes"
	"fmt"

	"github.com/jung-kurt/gofpdf"
)

type InvoiceItem struct {
	Name     string
	Quantity int
	Price    string
	Total    string
}

type Invoice struct {
	Number           string
	IssuedAt         string
	RecipientName    string
	RecipientAddress string
	Subtotal         string
	Shipping         string
	AdminFee         string
	Voucher          string
	Total            string
	Paid             string
	Remaining        string
	Items            []InvoiceItem
}

func InvoicePDF(v Invoice) ([]byte, error) {
	pdf := gofpdf.New("P", "mm", "A4", "")
	pdf.SetMargins(15, 15, 15)
	pdf.SetTitle("Invoice "+v.Number, true)
	pdf.AddPage()
	pdf.SetFont("Helvetica", "B", 16)
	pdf.CellFormat(0, 10, "PRIME CLASSY", "", 1, "L", false, 0, "")
	pdf.SetFont("Helvetica", "", 10)
	pdf.CellFormat(0, 6, "Invoice "+v.Number, "", 1, "L", false, 0, "")
	pdf.CellFormat(0, 6, "Issued: "+v.IssuedAt, "", 1, "L", false, 0, "")
	pdf.Ln(4)
	pdf.SetFont("Helvetica", "B", 10)
	pdf.CellFormat(0, 6, "Bill to", "", 1, "L", false, 0, "")
	pdf.SetFont("Helvetica", "", 10)
	pdf.CellFormat(0, 6, v.RecipientName, "", 1, "L", false, 0, "")
	pdf.MultiCell(0, 5, v.RecipientAddress, "", "L", false)
	pdf.Ln(4)

	pdf.SetFont("Helvetica", "B", 9)
	pdf.CellFormat(100, 7, "Item", "1", 0, "L", false, 0, "")
	pdf.CellFormat(15, 7, "Qty", "1", 0, "R", false, 0, "")
	pdf.CellFormat(30, 7, "Price", "1", 0, "R", false, 0, "")
	pdf.CellFormat(30, 7, "Total", "1", 1, "R", false, 0, "")
	pdf.SetFont("Helvetica", "", 9)
	for _, item := range v.Items {
		pdf.CellFormat(100, 7, item.Name, "1", 0, "L", false, 0, "")
		pdf.CellFormat(15, 7, fmt.Sprintf("%d", item.Quantity), "1", 0, "R", false, 0, "")
		pdf.CellFormat(30, 7, item.Price, "1", 0, "R", false, 0, "")
		pdf.CellFormat(30, 7, item.Total, "1", 1, "R", false, 0, "")
	}
	pdf.Ln(4)
	summary := []struct{ label, value string }{
		{"Subtotal", v.Subtotal}, {"Shipping", v.Shipping},
		{"Admin fee", v.AdminFee}, {"Voucher", v.Voucher},
		{"Total", v.Total}, {"Paid", v.Paid}, {"Remaining", v.Remaining},
	}
	pdf.SetFont("Helvetica", "", 10)
	for _, row := range summary {
		pdf.CellFormat(145, 6, row.label, "", 0, "L", false, 0, "")
		pdf.CellFormat(30, 6, row.value, "", 1, "R", false, 0, "")
	}
	var buf bytes.Buffer
	if err := pdf.Output(&buf); err != nil {
		return nil, err
	}
	return buf.Bytes(), nil
}
