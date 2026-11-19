package document

import (
	"bytes"
	"testing"
)

func TestInvoicePDF(t *testing.T) {
	data, err := InvoicePDF(Invoice{
		Number: "INV-001", IssuedAt: "2026-11-19",
		RecipientName: "Budi", RecipientAddress: "Jl. Tes",
		Subtotal: "100.00", Shipping: "10.00", AdminFee: "0.00",
		Voucher: "0.00", Total: "110.00", Paid: "110.00", Remaining: "0.00",
		Items: []InvoiceItem{{Name: "Kue", Quantity: 1, Price: "100.00", Total: "100.00"}},
	})
	if err != nil {
		t.Fatal(err)
	}
	if !bytes.HasPrefix(data, []byte("%PDF")) {
		t.Fatal("invoice output is not PDF")
	}
}
