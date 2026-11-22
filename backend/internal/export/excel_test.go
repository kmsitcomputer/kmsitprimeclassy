package export

import (
	"bytes"
	"testing"

	"github.com/xuri/excelize/v2"
)

func TestXLSXCreatesSheetsAndRows(t *testing.T) {
	data, err := XLSX([]Sheet{{
		Name: "Orders", Header: []string{"ID", "Number"},
		Rows: [][]any{{1, "INV-001"}},
	}})
	if err != nil {
		t.Fatal(err)
	}
	if len(data) == 0 || !bytes.HasPrefix(data, []byte("PK")) {
		t.Fatalf("invalid XLSX bytes: %d", len(data))
	}
	f, err := excelize.OpenReader(bytes.NewReader(data))
	if err != nil {
		t.Fatal(err)
	}
	defer f.Close()
	value, err := f.GetCellValue("Orders", "B2")
	if err != nil {
		t.Fatal(err)
	}
	if value != "INV-001" {
		t.Fatalf("B2 = %q", value)
	}
}
