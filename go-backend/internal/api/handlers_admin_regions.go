package api

import (
	"bytes"
	"context"
	"database/sql"
	"encoding/csv"
	"fmt"
	"io"
	"net/http"
	"path/filepath"
	"strings"

	"github.com/kmsitcomputer/primeclassy-go/internal/httpx"
	"github.com/kmsitcomputer/primeclassy-go/internal/i18n"
)

var regionCSVHeader = []string{"province_code", "province_name", "regency_code", "regency_name", "district_code", "district_name", "village_code", "village_name", "rajaongkir_city_id"}

func (s *Server) handleAdminRegionExport(w http.ResponseWriter, r *http.Request) {
	rows, err := s.database.QueryContext(r.Context(), `SELECT p.id,p.name,rg.id,rg.name,d.id,d.name,v.id,v.name,rg.rajaongkir_city_id
		FROM provinces p LEFT JOIN regencies rg ON rg.province_id=p.id LEFT JOIN districts d ON d.regency_id=rg.id LEFT JOIN villages v ON v.district_id=d.id
		ORDER BY p.id,rg.id,d.id,v.id`)
	if err != nil {
		writeError(w, r, err)
		return
	}
	defer rows.Close()
	w.Header().Set("Content-Type", "text/csv; charset=utf-8")
	w.Header().Set("Content-Disposition", `attachment; filename=regions.csv`)
	if _, err = io.WriteString(w, phpCSVRecord(regionCSVHeader)); err != nil {
		return
	}
	for rows.Next() {
		var provinceCode, provinceName string
		var regencyCode, regencyName, districtCode, districtName, villageCode, villageName, cityID sql.NullString
		if err = rows.Scan(&provinceCode, &provinceName, &regencyCode, &regencyName, &districtCode, &districtName, &villageCode, &villageName, &cityID); err != nil {
			return
		}
		record := []string{provinceCode, provinceName, nullableText(regencyCode), nullableText(regencyName), nullableText(districtCode), nullableText(districtName), nullableText(villageCode), nullableText(villageName), nullableText(cityID)}
		if _, err = io.WriteString(w, phpCSVRecord(record)); err != nil {
			return
		}
	}
	if err = rows.Err(); err != nil {
		return
	}
}

func phpCSVRecord(fields []string) string {
	quoted := make([]string, len(fields))
	for i, field := range fields {
		if strings.ContainsAny(field, ",\"\r\n ") {
			quoted[i] = `"` + strings.ReplaceAll(field, `"`, `""`) + `"`
		} else {
			quoted[i] = field
		}
	}
	return strings.Join(quoted, ",") + "\n"
}

type regionCSVRow struct{ provinceCode, provinceName, regencyCode, regencyName, districtCode, districtName, villageCode, villageName, cityID string }

func (s *Server) handleAdminRegionImport(w http.ResponseWriter, r *http.Request) {
	locale := LocaleFrom(r.Context())
	r.Body = http.MaxBytesReader(w, r.Body, 21<<20)
	if err := r.ParseMultipartForm(20 << 20); err != nil {
		writeError(w, r, httpx.Validation(map[string][]string{"file": {i18n.ValidationMessage(locale, "required", "file")}}))
		return
	}
	file, header, err := r.FormFile("file")
	if err != nil {
		writeError(w, r, httpx.Validation(map[string][]string{"file": {i18n.ValidationMessage(locale, "required", "file")}}))
		return
	}
	defer file.Close()
	if header.Size > 20*1024*1024 {
		writeError(w, r, httpx.Validation(map[string][]string{"file": {i18n.ValidationMessage(locale, "max.file", "file", "max", "20480")}}))
		return
	}
	ext := strings.ToLower(filepath.Ext(header.Filename))
	if ext != ".csv" && ext != ".txt" {
		writeError(w, r, &httpx.Error{Status: 422, Message: i18n.Translate(locale, "region.invalid_csv")})
		return
	}
	content, err := io.ReadAll(file)
	if err != nil {
		writeError(w, r, err)
		return
	}
	if len(content) > 0 {
		kind := http.DetectContentType(content[:min(len(content), 512)])
		if !strings.HasPrefix(kind, "text/") && kind != "application/vnd.ms-excel" {
			writeError(w, r, &httpx.Error{Status: 422, Message: i18n.Translate(locale, "region.invalid_csv")})
			return
		}
	}
	counts, err := s.importRegionCSV(r.Context(), bytes.NewReader(content), locale)
	if err != nil {
		writeError(w, r, err)
		return
	}
	actor := UserFrom(r.Context())
	if _, err = s.database.ExecContext(r.Context(), `INSERT INTO activity_logs (causer_id,subject_type,subject_id,event,description,properties,ip_address,user_agent,created_at) VALUES (?,'App\\Models\\User',?,'region.csv_imported',NULL,?,?,?,UTC_TIMESTAMP())`, actor.ID, actor.ID, fmt.Sprintf(`{"provinces":%d,"regencies":%d,"districts":%d,"villages":%d}`, counts["provinces"], counts["regencies"], counts["districts"], counts["villages"]), clientIP(r), r.UserAgent()); err != nil {
		writeError(w, r, err)
		return
	}
	message := i18n.Translate(locale, "region.imported")
	for key, value := range counts {
		message = strings.ReplaceAll(message, ":"+key, fmt.Sprint(value))
	}
	httpx.OK(w, counts, message, nil)
}

func (s *Server) importRegionCSV(ctx context.Context, source io.Reader, locale string) (map[string]int, error) {
	reader := csv.NewReader(source)
	reader.FieldsPerRecord = -1
	reader.ReuseRecord = false
	header, err := reader.Read()
	if err != nil || len(header) != len(regionCSVHeader) {
		return nil, &httpx.Error{Status: 422, Message: i18n.Translate(locale, "region.invalid_csv")}
	}
	for i := range header {
		header[i] = strings.ToLower(strings.TrimSpace(header[i]))
		if header[i] != regionCSVHeader[i] {
			return nil, &httpx.Error{Status: 422, Message: i18n.Translate(locale, "region.invalid_csv")}
		}
	}
	provinces := map[string]string{}
	regencies := map[string]regionCSVRow{}
	districts := map[string]regionCSVRow{}
	villages := map[string]regionCSVRow{}
	provinceOrder, regencyOrder, districtOrder, villageOrder := []string{}, []string{}, []string{}, []string{}
	rowNumber := 1
	for {
		row, readErr := reader.Read()
		if readErr == io.EOF {
			break
		}
		rowNumber++
		if readErr != nil {
			return nil, &httpx.Error{Status: 422, Message: strings.ReplaceAll(strings.ReplaceAll(i18n.Translate(locale, "region.invalid_row"), ":row", fmt.Sprint(rowNumber)), ":reason", "CSV parse")}
		}
		if len(row) < 9 {
			return nil, &httpx.Error{Status: 422, Message: strings.ReplaceAll(strings.ReplaceAll(i18n.Translate(locale, "region.invalid_row"), ":row", fmt.Sprint(rowNumber)), ":reason", "column count")}
		}
		for i := range row {
			row[i] = strings.TrimSpace(row[i])
		}
		v := regionCSVRow{row[0], row[1], row[2], row[3], row[4], row[5], row[6], row[7], row[8]}
		if v.provinceCode == "" {
			return nil, &httpx.Error{Status: 422, Message: strings.ReplaceAll(strings.ReplaceAll(i18n.Translate(locale, "region.invalid_row"), ":row", fmt.Sprint(rowNumber)), ":reason", "missing province_code")}
		}
		if _, exists := provinces[v.provinceCode]; !exists {
			provinceOrder = append(provinceOrder, v.provinceCode)
		}
		provinces[v.provinceCode] = v.provinceName
		if v.regencyCode != "" {
			if _, exists := regencies[v.regencyCode]; !exists {
				regencyOrder = append(regencyOrder, v.regencyCode)
			}
			regencies[v.regencyCode] = v
		}
		if v.districtCode != "" {
			if _, exists := districts[v.districtCode]; !exists {
				districtOrder = append(districtOrder, v.districtCode)
			}
			districts[v.districtCode] = v
		}
		if v.villageCode != "" {
			if _, exists := villages[v.villageCode]; !exists {
				villageOrder = append(villageOrder, v.villageCode)
			}
			villages[v.villageCode] = v
		}
	}
	tx, err := s.database.BeginTx(ctx, nil)
	if err != nil {
		return nil, err
	}
	defer tx.Rollback()
	for _, code := range provinceOrder {
		if _, err = tx.ExecContext(ctx, `INSERT INTO provinces(id,name) VALUES(?,?) ON DUPLICATE KEY UPDATE name=VALUES(name)`, code, provinces[code]); err != nil {
			_ = tx.Rollback()
			return nil, err
		}
	}
	for _, code := range regencyOrder {
		v := regencies[code]
		if _, err = tx.ExecContext(ctx, `INSERT INTO regencies(id,province_id,name,rajaongkir_city_id) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE province_id=VALUES(province_id),name=VALUES(name),rajaongkir_city_id=VALUES(rajaongkir_city_id)`, v.regencyCode, v.provinceCode, v.regencyName, nullableDB(v.cityID)); err != nil {
			return nil, err
		}
	}
	for _, code := range districtOrder {
		v := districts[code]
		if _, err = tx.ExecContext(ctx, `INSERT INTO districts(id,regency_id,name) VALUES(?,?,?) ON DUPLICATE KEY UPDATE regency_id=VALUES(regency_id),name=VALUES(name)`, v.districtCode, v.regencyCode, v.districtName); err != nil {
			return nil, err
		}
	}
	for _, code := range villageOrder {
		v := villages[code]
		if _, err = tx.ExecContext(ctx, `INSERT INTO villages(id,district_id,name) VALUES(?,?,?) ON DUPLICATE KEY UPDATE district_id=VALUES(district_id),name=VALUES(name)`, v.villageCode, v.districtCode, v.villageName); err != nil {
			return nil, err
		}
	}
	if err = tx.Commit(); err != nil {
		return nil, err
	}
	return map[string]int{"provinces": len(provinces), "regencies": len(regencies), "districts": len(districts), "villages": len(villages)}, nil
}

func nullableText(v sql.NullString) string {
	if !v.Valid {
		return ""
	}
	return v.String
}
func nullableDB(v string) any {
	if v == "" {
		return nil
	}
	return v
}
