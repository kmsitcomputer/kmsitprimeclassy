package export

import (
	"bytes"
	"fmt"

	"github.com/xuri/excelize/v2"
)

type Sheet struct {
	Name   string
	Header []string
	Rows   [][]any
}

func XLSX(sheets []Sheet) ([]byte, error) {
	if len(sheets) == 0 {
		return nil, fmt.Errorf("xlsx: at least one sheet required")
	}
	f := excelize.NewFile()
	defer f.Close()
	defaultSheet := f.GetSheetName(0)
	for i, sheet := range sheets {
		name := sheet.Name
		if name == "" {
			name = fmt.Sprintf("Sheet%d", i+1)
		}
		if i == 0 {
			if err := f.SetSheetName(defaultSheet, name); err != nil {
				return nil, err
			}
		} else {
			if _, err := f.NewSheet(name); err != nil {
				return nil, err
			}
		}
		for col, value := range sheet.Header {
			cell, err := excelize.CoordinatesToCellName(col+1, 1)
			if err != nil {
				return nil, err
			}
			if err := f.SetCellValue(name, cell, value); err != nil {
				return nil, err
			}
		}
		for rowIndex, row := range sheet.Rows {
			for colIndex, value := range row {
				cell, err := excelize.CoordinatesToCellName(colIndex+1, rowIndex+2)
				if err != nil {
					return nil, err
				}
				if err := f.SetCellValue(name, cell, value); err != nil {
					return nil, err
				}
			}
		}
	}
	var buf bytes.Buffer
	if err := f.Write(&buf); err != nil {
		return nil, err
	}
	return buf.Bytes(), nil
}
