package api

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"strings"
	"time"
)

type sheetsProtectedConfig struct {
	Indexes    []int
	RowKeys    []string
	Validation map[int][]string
}

func (s *Server) googleSheetsReplace(r *http.Request, spreadsheet, tab string, rows [][]any, protected ...sheetsProtectedConfig) *googleSheetsAPIError {
	return s.googleSheetsReplaceAt(r, spreadsheet, tab, rows, "https://sheets.googleapis.com/v4", protected...)
}

func (s *Server) googleSheetsReplaceAt(r *http.Request, spreadsheet, tab string, rows [][]any, base string, protected ...sheetsProtectedConfig) *googleSheetsAPIError {
	if !s.cfg.GoogleSheets.Enabled {
		return &googleSheetsAPIError{"INTEGRATION_DISABLED", "Integrasi Google Sheets belum diaktifkan."}
	}
	if len(rows) == 0 {
		return &googleSheetsAPIError{"INVALID_REQUEST", "Google Sheets menolak format atau jangkauan permintaan sinkronisasi."}
	}
	token, code, message := s.googleSheetsToken(r.Context())
	if code != "" {
		return &googleSheetsAPIError{code, message}
	}
	metadata, apiErr := s.googleSheetsMetadataWithToken(r, spreadsheet, base, token)
	if apiErr != nil {
		return apiErr
	}
	var sheet *googleSheetsSheet
	for i := range metadata.Sheets {
		if metadata.Sheets[i].Title == tab {
			sheet = &metadata.Sheets[i]
			break
		}
	}
	cols := len(rows[0])
	if cols < 1 {
		cols = 1
	}
	if sheet == nil {
		response, err := s.googleSheetsPost(r.Context(), base+"/spreadsheets/"+url.PathEscape(spreadsheet)+":batchUpdate", token, map[string]any{"requests": []any{map[string]any{
			"addSheet": map[string]any{"properties": map[string]any{"title": tab, "gridProperties": map[string]any{"columnCount": cols}}},
		}}})
		if err != nil {
			return err
		}
		var added struct {
			Replies []struct {
				AddSheet struct {
					Properties googleSheetsSheet `json:"properties"`
				} `json:"addSheet"`
			} `json:"replies"`
		}
		if json.Unmarshal(response, &added) != nil || len(added.Replies) == 0 || added.Replies[0].AddSheet.Properties.ID == 0 {
			return &googleSheetsAPIError{"UNKNOWN_GOOGLE_API_ERROR", "Google Sheets API mengembalikan kegagalan yang tidak dikenal."}
		}
		created := added.Replies[0].AddSheet.Properties
		sheet = &created
	}
	protect := sheetsProtectedConfig{}
	if len(protected) > 0 {
		protect = protected[0]
	}
	protectedSet := map[int]bool{}
	for _, index := range protect.Indexes {
		if index < 0 || index >= cols {
			return &googleSheetsAPIError{"PROTECT_IDENTITY", "Identitas baris tidak cocok dengan payload — sinkronisasi aman dibatalkan."}
		}
		protectedSet[index] = true
	}
	var aligned, appended [][]any
	if len(protectedSet) > 0 {
		if len(protect.RowKeys) != len(rows)-1 {
			return &googleSheetsAPIError{"PROTECT_IDENTITY", "Identitas baris tidak cocok dengan payload — sinkronisasi aman dibatalkan."}
		}
		existing, readErr := s.googleSheetsValues(r.Context(), base, spreadsheet, quoteSheetsTab(tab)+"!A1:"+sheetsColumnLetter(cols), token)
		if readErr != nil {
			return readErr
		}
		aligned, appended = alignProtectedRows(existing, rows, protect.RowKeys, cols)
	}
	requests := []any{}
	if sheet.GridProperties.ColumnCount > 0 && sheet.GridProperties.ColumnCount < cols {
		requests = append(requests, map[string]any{"appendDimension": map[string]any{"sheetId": sheet.ID, "dimension": "COLUMNS", "length": cols - sheet.GridProperties.ColumnCount}})
	}
	if len(protectedSet) == 0 {
		requests = append(requests,
			map[string]any{"repeatCell": map[string]any{"range": map[string]any{"sheetId": sheet.ID}, "cell": map[string]any{"userEnteredValue": nil}, "fields": "userEnteredValue"}},
			map[string]any{"updateCells": map[string]any{"range": map[string]any{"sheetId": sheet.ID, "startRowIndex": 0, "startColumnIndex": 0}, "rows": encodeSheetRows(rows), "fields": "userEnteredValue"}},
		)
	} else {
		requests = append(requests, map[string]any{"updateCells": map[string]any{
			"range": map[string]any{"sheetId": sheet.ID, "startRowIndex": 0, "endRowIndex": 1, "startColumnIndex": 0, "endColumnIndex": cols},
			"rows":  encodeSheetRows(rows[:1]), "fields": "userEnteredValue",
		}})
		for _, run := range systemColumnRuns(cols, protectedSet) {
			if len(aligned) > 0 {
				requests = append(requests, map[string]any{"updateCells": map[string]any{
					"range": map[string]any{"sheetId": sheet.ID, "startRowIndex": 1, "endRowIndex": 1 + len(aligned), "startColumnIndex": run[0], "endColumnIndex": run[1]},
					"rows":  encodeSheetRows(sliceSheetColumns(aligned, run[0], run[1])), "fields": "userEnteredValue",
				}})
			}
			if len(appended) > 0 {
				requests = append(requests, map[string]any{"updateCells": map[string]any{
					"range": map[string]any{"sheetId": sheet.ID, "startRowIndex": 1 + len(aligned), "startColumnIndex": run[0], "endColumnIndex": run[1]},
					"rows":  encodeSheetRows(sliceSheetColumns(appended, run[0], run[1])), "fields": "userEnteredValue",
				}})
			}
		}
		for column, allowed := range protect.Validation {
			values := make([]map[string]string, 0, len(allowed))
			for _, value := range allowed {
				values = append(values, map[string]string{"userEnteredValue": value})
			}
			requests = append(requests, map[string]any{"setDataValidation": map[string]any{
				"range": map[string]any{"sheetId": sheet.ID, "startRowIndex": 1, "startColumnIndex": column, "endColumnIndex": column + 1},
				"rule":  map[string]any{"condition": map[string]any{"type": "ONE_OF_LIST", "values": values}, "strict": true, "showCustomUi": true},
			}})
		}
	}
	_, apiErr = s.googleSheetsPost(r.Context(), base+"/spreadsheets/"+url.PathEscape(spreadsheet)+":batchUpdate", token, map[string]any{"requests": requests})
	return apiErr
}

func (s *Server) googleSheetsValues(ctx context.Context, base, spreadsheet, rangeName, token string) ([][]any, *googleSheetsAPIError) {
	timeout := s.cfg.GoogleSheets.Timeout
	if timeout <= 0 {
		timeout = 30 * time.Second
	}
	requestCtx, cancel := context.WithTimeout(ctx, timeout)
	defer cancel()
	endpoint := base + "/spreadsheets/" + url.PathEscape(spreadsheet) + "/values/" + url.PathEscape(rangeName)
	request, err := http.NewRequestWithContext(requestCtx, http.MethodGet, endpoint, nil)
	if err != nil {
		return nil, &googleSheetsAPIError{"INVALID_REQUEST", "Google Sheets menolak format atau jangkauan permintaan sinkronisasi."}
	}
	request.Header.Set("Authorization", "Bearer "+token)
	response, err := http.DefaultClient.Do(request)
	if err != nil {
		return nil, &googleSheetsAPIError{"NETWORK_ERROR", "Tidak dapat terhubung ke Google Sheets API."}
	}
	defer response.Body.Close()
	body, err := io.ReadAll(io.LimitReader(response.Body, 4<<20))
	if err != nil {
		return nil, &googleSheetsAPIError{"NETWORK_ERROR", "Tidak dapat terhubung ke Google Sheets API."}
	}
	if response.StatusCode < 200 || response.StatusCode >= 300 {
		return nil, mapGoogleSheetsError(response.StatusCode, body)
	}
	var result struct {
		Values [][]any `json:"values"`
	}
	if json.Unmarshal(body, &result) != nil {
		return nil, &googleSheetsAPIError{"UNKNOWN_GOOGLE_API_ERROR", "Google Sheets API mengembalikan kegagalan yang tidak dikenal."}
	}
	return result.Values, nil
}

func alignProtectedRows(existing [][]any, rows [][]any, rowKeys []string, totalCols int) ([][]any, [][]any) {
	oldRows := [][]any{}
	if len(existing) > 1 {
		oldRows = existing[1:]
	}
	newIndex := map[string]int{}
	for i, key := range rowKeys {
		key = strings.TrimSpace(key)
		if _, exists := newIndex[key]; !exists {
			newIndex[key] = i
		}
	}
	consumed := map[int]bool{}
	aligned := make([][]any, 0, len(oldRows))
	for _, old := range oldRows {
		key := ""
		if len(old) > 0 {
			key = strings.TrimSpace(fmt.Sprint(old[0]))
		}
		if index, ok := newIndex[key]; key != "" && ok {
			aligned = append(aligned, rows[index+1])
			consumed[index] = true
		} else {
			padded := append([]any(nil), old...)
			for len(padded) > totalCols {
				padded = padded[:len(padded)-1]
			}
			for len(padded) < totalCols {
				padded = append(padded, "")
			}
			aligned = append(aligned, padded)
		}
	}
	appended := [][]any{}
	for i, row := range rows[1:] {
		if !consumed[i] {
			appended = append(appended, row)
		}
	}
	return aligned, appended
}

func systemColumnRuns(totalCols int, protected map[int]bool) [][2]int {
	runs := [][2]int{}
	start := -1
	for column := 0; column < totalCols; column++ {
		if protected[column] {
			if start >= 0 {
				runs = append(runs, [2]int{start, column})
				start = -1
			}
			continue
		}
		if start < 0 {
			start = column
		}
	}
	if start >= 0 {
		runs = append(runs, [2]int{start, totalCols})
	}
	return runs
}

func sliceSheetColumns(rows [][]any, from, to int) [][]any {
	out := make([][]any, 0, len(rows))
	for _, row := range rows {
		part := make([]any, to-from)
		for i := from; i < to && i < len(row); i++ {
			part[i-from] = row[i]
		}
		out = append(out, part)
	}
	return out
}

func quoteSheetsTab(tab string) string { return "'" + strings.ReplaceAll(tab, "'", "''") + "'" }

func sheetsColumnLetter(count int) string {
	letter := ""
	for count > 0 {
		count--
		letter = string(rune('A'+count%26)) + letter
		count /= 26
	}
	return letter
}

func encodeSheetRows(rows [][]any) []map[string]any {
	out := make([]map[string]any, 0, len(rows))
	for _, row := range rows {
		values := make([]map[string]any, 0, len(row))
		for _, value := range row {
			var cell map[string]any
			switch value.(type) {
			case int, int8, int16, int32, int64, uint, uint8, uint16, uint32, uint64, float32, float64:
				cell = map[string]any{"numberValue": value}
			case nil:
				cell = map[string]any{"stringValue": ""}
			default:
				cell = map[string]any{"stringValue": fmt.Sprint(value)}
			}
			values = append(values, map[string]any{"userEnteredValue": cell})
		}
		out = append(out, map[string]any{"values": values})
	}
	return out
}

func (s *Server) googleSheetsPost(ctx context.Context, endpoint, token string, payload any) ([]byte, *googleSheetsAPIError) {
	body, err := json.Marshal(payload)
	if err != nil {
		return nil, &googleSheetsAPIError{"INVALID_REQUEST", "Google Sheets menolak format atau jangkauan permintaan sinkronisasi."}
	}
	timeout := s.cfg.GoogleSheets.Timeout
	if timeout <= 0 {
		timeout = 30 * time.Second
	}
	requestCtx, cancel := context.WithTimeout(ctx, timeout)
	defer cancel()
	request, err := http.NewRequestWithContext(requestCtx, http.MethodPost, endpoint, bytes.NewReader(body))
	if err != nil {
		return nil, &googleSheetsAPIError{"INVALID_REQUEST", "Google Sheets menolak format atau jangkauan permintaan sinkronisasi."}
	}
	request.Header.Set("Authorization", "Bearer "+token)
	request.Header.Set("Content-Type", "application/json")
	response, err := http.DefaultClient.Do(request)
	if err != nil {
		return nil, &googleSheetsAPIError{"NETWORK_ERROR", "Tidak dapat terhubung ke Google Sheets API."}
	}
	defer response.Body.Close()
	responseBody, err := io.ReadAll(io.LimitReader(response.Body, 4<<20))
	if err != nil {
		return nil, &googleSheetsAPIError{"NETWORK_ERROR", "Tidak dapat terhubung ke Google Sheets API."}
	}
	if response.StatusCode < 200 || response.StatusCode >= 300 {
		return nil, mapGoogleSheetsError(response.StatusCode, responseBody)
	}
	return responseBody, nil
}
