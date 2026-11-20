package api

import (
	"fmt"
	"net/http"
	"reflect"
	"strconv"
	"time"

	"github.com/kmsitcomputer/primeclassy-go/internal/catalog"
	"github.com/kmsitcomputer/primeclassy-go/internal/httpx"
	"github.com/kmsitcomputer/primeclassy-go/internal/i18n"
	"github.com/xuri/excelize/v2"
)

func (s *Server) handleCatalogExport(w http.ResponseWriter, r *http.Request) {
	u := UserFrom(r.Context())
	if u == nil {
		writeError(w, r, unauthorizedError(r.Context()))
		return
	}
	if !u.IsRole("super_admin", "agen", "admin") {
		writeError(w, r, httpx.NewError(http.StatusForbidden, "This action is unauthorized."))
		return
	}

	q := r.URL.Query()
	format := q.Get("format")
	if format == "" {
		format = "csv"
	}
	fields := map[string][]string{}
	if format != "csv" && format != "xlsx" {
		fields["format"] = []string{i18n.ValidationMessage(LocaleFrom(r.Context()), "in", "format", "values", "csv, xlsx")}
	}
	var explicit *int64
	if raw := q.Get("agent_id"); raw != "" {
		n, err := strconv.ParseInt(raw, 10, 64)
		if err != nil {
			fields["agent_id"] = []string{i18n.ValidationMessage(LocaleFrom(r.Context()), "integer", "agent_id")}
		} else {
			explicit = &n
		}
	}
	stockType := q.Get("stock_type")
	if stockType != "" && stockType != "transit" && stockType != "shipping" && stockType != "sub" {
		fields["stock_type"] = []string{i18n.ValidationMessage(LocaleFrom(r.Context()), "in", "stock_type", "values", "transit, shipping, sub")}
	}
	var location *int64
	if raw := q.Get("sub_location_id"); raw != "" {
		n, err := strconv.ParseInt(raw, 10, 64)
		if err != nil {
			fields["sub_location_id"] = []string{i18n.ValidationMessage(LocaleFrom(r.Context()), "integer", "sub_location_id")}
		} else {
			location = &n
		}
	}
	if len(fields) > 0 {
		writeError(w, r, httpx.Validation(fields))
		return
	}

	var scope *catalog.ExportStock
	if stockType != "" {
		agentID, err := s.resolveCatalogExportAgent(r, explicit)
		if err != nil {
			writeError(w, r, err)
			return
		}
		if (stockType == "sub") != (location != nil) {
			writeError(w, r, httpx.NewError(http.StatusUnprocessableEntity, "Konteks stok tidak valid."))
			return
		}
		if location != nil {
			var ok int
			if err := s.database.QueryRowContext(r.Context(), `SELECT COUNT(*) FROM warehouse_sub_locations WHERE id=? AND agent_id=? AND is_active=1`, *location, agentID).Scan(&ok); err != nil {
				writeError(w, r, err)
				return
			}
			if ok == 0 {
				writeError(w, r, httpx.NewError(http.StatusForbidden, "Sub Location bukan milik Agen ini."))
				return
			}
		}
		scope = &catalog.ExportStock{AgentID: agentID, Type: stockType, LocationID: location}
	}
	rows, err := catalog.ExportRows(r.Context(), s.database, scope)
	if err != nil {
		writeError(w, r, err)
		return
	}
	stamp := time.Now().Format("20060102-150405")
	w.Header().Set("X-Content-Type-Options", "nosniff")
	if format == "csv" {
		w.Header().Set("Content-Type", "text/csv; charset=UTF-8")
		w.Header().Set("Content-Disposition", fmt.Sprintf(`attachment; filename="katalog-%s.csv"`, stamp))
		w.WriteHeader(http.StatusOK)
		_, _ = w.Write(catalog.ExportCSV(rows))
		return
	}
	file := excelize.NewFile()
	defer file.Close()
	sheet := file.GetSheetName(0)
	for i, h := range catalog.ExportHeaders {
		cell, _ := excelize.CoordinatesToCellName(i+1, 1)
		_ = file.SetCellStr(sheet, cell, h)
	}
	for ri, row := range rows {
		for ci, value := range row {
			cell, _ := excelize.CoordinatesToCellName(ci+1, ri+2)
			if value == nil || reflect.TypeOf(value).Kind() == reflect.String {
				_ = file.SetCellStr(sheet, cell, catalog.ExportString(value))
			} else {
				_ = file.SetCellValue(sheet, cell, value)
			}
		}
	}
	buf, err := file.WriteToBuffer()
	if err != nil {
		writeError(w, r, err)
		return
	}
	w.Header().Set("Content-Type", "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet")
	w.Header().Set("Content-Disposition", fmt.Sprintf(`attachment; filename="katalog-%s.xlsx"`, stamp))
	w.WriteHeader(http.StatusOK)
	_, _ = w.Write(buf.Bytes())
}

func (s *Server) resolveCatalogExportAgent(r *http.Request, explicit *int64) (int64, error) {
	u := UserFrom(r.Context())
	var id int64
	if u.Role == "super_admin" {
		if explicit == nil {
			return 0, httpx.Validation(map[string][]string{"agent_id": {"Agen wajib dipilih."}})
		}
		id = *explicit
	} else {
		if u.AgentID == nil {
			return 0, httpx.NewError(http.StatusForbidden, "Anda tidak memiliki akses untuk melakukan aksi ini.")
		}
		id = *u.AgentID
		if explicit != nil && *explicit != id {
			return 0, httpx.NewError(http.StatusForbidden, "Anda tidak dapat bertindak untuk Agen lain.")
		}
	}
	var ok int
	err := s.database.QueryRowContext(r.Context(), `SELECT COUNT(*) FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=? AND u.status='active' AND u.deleted_at IS NULL AND r.slug='agen'`, id).Scan(&ok)
	if err != nil {
		return 0, err
	}
	if ok == 0 {
		return 0, httpx.NewError(http.StatusForbidden, "Agen tidak valid atau tidak aktif.")
	}
	return id, nil
}
