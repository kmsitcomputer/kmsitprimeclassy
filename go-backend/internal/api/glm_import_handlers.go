package api

// Catalog import wizard (REM-01): POST /catalog/imports/preview and POST /catalog/imports.
// Ports CatalogSpreadsheet::read (CSV + hardened XLSX), CatalogImportService planner,
// preview (pure validation) and apply (per-row transactional apply + stock staging).

import (
	"archive/zip"
	"context"
	crand "crypto/rand"
	"crypto/sha256"
	"database/sql"
	"encoding/csv"
	"encoding/hex"
	"encoding/json"
	"encoding/xml"
	"errors"
	"fmt"
	"io"
	"math"
	"net/http"
	"regexp"
	"sort"
	"strconv"
	"strings"
	"time"
	"unicode/utf8"

	"github.com/kmsitcomputer/primeclassy-go/internal/auth"
	"github.com/kmsitcomputer/primeclassy-go/internal/httpx"
)

var importHeaders = []string{"type", "sku", "product_slug", "product_name", "category_id",
	"status", "price", "weight_grams", "attributes", "stock_target"}

const (
	importMaxBytes   = 2_097_152
	importMaxRows    = 500
	importMaxColumns = 20
)

// importSheet reads the uploaded CSV/XLSX into string-keyed rows (CatalogSpreadsheet::read).
func importSheet(raw []byte) ([]map[string]string, error) {
	if len(raw) == 0 {
		return nil, errors.New("File kosong atau tidak terbaca.")
	}
	if len(raw) > importMaxBytes {
		return nil, errors.New("File melebihi batas ukuran 2 MiB.")
	}
	var grid [][]string
	var err error
	if len(raw) > 3 && raw[0] == 'P' && raw[1] == 'K' && raw[2] == 3 && raw[3] == 4 {
		grid, err = importReadXlsx(raw)
	} else {
		grid, err = importReadCsv(raw)
	}
	if err != nil {
		return nil, err
	}
	if len(grid) < 2 {
		return nil, errors.New("File tidak memiliki baris data.")
	}
	headers := grid[0]
	known := map[string]bool{}
	for _, h := range importHeaders {
		known[h] = true
	}
	seen := map[string]bool{}
	var unknown []string
	for i, h := range headers {
		headers[i] = strings.ToLower(strings.TrimSpace(h))
		if headers[i] != "" && !known[headers[i]] && !seen[headers[i]] {
			seen[headers[i]] = true
			unknown = append(unknown, headers[i])
		}
	}
	if len(headers) > importMaxColumns {
		return nil, errors.New("Jumlah kolom melebihi batas.")
	}
	if len(unknown) > 0 {
		return nil, errors.New("Kolom tidak dikenal: " + strings.Join(unknown[:min(5, len(unknown))], ", "))
	}
	for _, req := range []string{"type", "sku"} {
		found := false
		for _, h := range headers {
			if h == req {
				found = true
			}
		}
		if !found {
			return nil, errors.New("Kolom wajib '" + req + "' tidak ada.")
		}
	}
	if len(grid)-1 > importMaxRows {
		return nil, fmt.Errorf("Jumlah baris melebihi batas %d.", importMaxRows)
	}
	rows := make([]map[string]string, 0, len(grid)-1)
	for _, line := range grid[1:] {
		row := map[string]string{}
		for i, h := range headers {
			if h != "" {
				v := ""
				if i < len(line) {
					v = strings.TrimSpace(line[i])
				}
				row[h] = v
			}
		}
		joined := ""
		for _, v := range row {
			joined += v
		}
		if joined == "" {
			continue
		}
		rows = append(rows, row)
	}
	if len(rows) == 0 {
		return nil, errors.New("File tidak memiliki baris data.")
	}
	return rows, nil
}

func importReadCsv(raw []byte) ([][]string, error) {
	if !utf8.Valid(raw) || strings.ContainsRune(string(raw), 0) {
		return nil, errors.New("File bukan CSV UTF-8 yang valid.")
	}
	clean := strings.TrimPrefix(string(raw), "\xEF\xBB\xBF")
	r := csv.NewReader(strings.NewReader(clean))
	r.FieldsPerRecord = -1
	var grid [][]string
	for {
		cells, err := r.Read()
		if errors.Is(err, io.EOF) {
			break
		}
		if err != nil {
			return nil, errors.New("File bukan CSV UTF-8 yang valid.")
		}
		if len(grid) > importMaxRows+1 {
			break
		}
		grid = append(grid, cells)
	}
	return grid, nil
}

// importReadXlsx ports the hardened XLSX reader: zip-bomb/macro/formula guards,
// shared strings + first worksheet cells only.
func importReadXlsx(raw []byte) ([][]string, error) {
	zr, err := zip.NewReader(bytesReaderAt(raw), int64(len(raw)))
	if err != nil {
		return nil, errors.New("File XLSX tidak valid.")
	}
	total := uint64(0)
	shared := map[string]string{}
	var sheetXML []byte
	re := regexp.MustCompile(`(?i)(^|/)(vbaproject|externallinks|embeddings|activex|oleobject|macrosheets)`)
	for _, f := range zr.File {
		name := strings.ToLower(f.Name)
		total += uint64(f.UncompressedSize64)
		if total > 16*1024*1024 {
			return nil, errors.New("Isi XLSX terlalu besar setelah dekompresi.")
		}
		if re.MatchString(name) || strings.HasSuffix(name, ".bin") {
			return nil, errors.New("XLSX berisi makro/tautan eksternal/objek tertanam dan ditolak.")
		}
	}
	for _, f := range zr.File {
		if strings.EqualFold(f.Name, "xl/sharedStrings.xml") {
			b, rerr := f.Open()
			if rerr != nil {
				return nil, errors.New("File XLSX tidak valid.")
			}
			data, _ := io.ReadAll(b)
			b.Close()
			strings2, perr := parseSharedStrings(data)
			if perr != nil {
				return nil, errors.New("File XLSX tidak valid.")
			}
			shared = strings2
		}
	}
	for _, f := range zr.File {
		if m, _ := regexp.MatchString(`(?i)^xl/worksheets/[^/]+\\.xml$`, f.Name); m {
			b, rerr := f.Open()
			if rerr != nil {
				return nil, errors.New("File XLSX tidak valid.")
			}
			sheetXML, _ = io.ReadAll(b)
			b.Close()
			if strings.Contains(string(sheetXML), "<f") {
				return nil, errors.New("XLSX berisi rumus dan ditolak (gunakan nilai).")
			}
			break
		}
	}
	if sheetXML == nil {
		return nil, errors.New("File XLSX tidak valid.")
	}
	return parseSheetXML(sheetXML, shared)
}

type sstEntry struct {
	T []string `xml:"t"`
}

func parseSharedStrings(data []byte) (map[string]string, error) {
	var sst struct {
		SI []sstEntry `xml:"si"`
	}
	out := map[string]string{}
	if err := xml.Unmarshal(data, &sst); err != nil {
		return nil, err
	}
	for i, si := range sst.SI {
		out[strconv.Itoa(i)] = strings.Join(si.T, "")
	}
	return out, nil
}

type xlsxCell struct {
	R string `xml:"r,attr"`
	T string `xml:"t,attr"`
	V string `xml:"v"`
}

type xlsxRow struct {
	C []xlsxCell `xml:"c"`
}

type xlsxSheet struct {
	SD []xlsxRow `xml:"sheetData>row"`
}

func parseSheetXML(data []byte, shared map[string]string) ([][]string, error) {
	var sheet xlsxSheet
	if err := xml.Unmarshal(data, &sheet); err != nil {
		return nil, errors.New("File XLSX tidak valid.")
	}
	maxRows := 0
	var grid [][]string
	for _, row := range sheet.SD {
		if len(grid) > importMaxRows+1 {
			break
		}
		var cells []string
		col := 0
		for _, c := range row.C {
			idx := colRefToIndex(c.R)
			for col < idx {
				cells = append(cells, "")
				col++
			}
			val := c.V
			if c.T == "s" {
				val = shared[c.V]
			}
			cells = append(cells, val)
			col++
		}
		grid = append(grid, cells)
	}
	_ = maxRows
	return grid, nil
}

func colRefToIndex(ref string) int {
	letters := ""
	for _, r := range ref {
		if r >= 'A' && r <= 'Z' {
			letters += string(r)
		} else {
			break
		}
	}
	if letters == "" {
		return 0
	}
	v := 0
	for _, r := range letters {
		v = v*26 + int(r-'A'+1)
	}
	return v - 1
}

func bytesReaderAt(b []byte) io.ReaderAt { return bytesRA(b) }

type bytesRAT struct{ b []byte }

func (r bytesRAT) ReadAt(p []byte, off int64) (int, error) {
	if off >= int64(len(r.b)) {
		return 0, io.EOF
	}
	n := copy(p, r.b[off:])
	return n, nil
}

func bytesRA(b []byte) io.ReaderAt { return bytesRAT{b: b} }

func min(a, b int) int {
	if a < b {
		return a
	}
	return b
}

// ---- planner (CatalogImportService::plan / planRow) --------------------------------------------

type importPlan struct {
	Line        int
	Sku         string
	Type        string
	Action      string
	Errors      []string
	StockTarget *int64
	Changes     map[string]any
	Attributes  map[string]string
	Target      map[string]any
	Row         map[string]string
	Outcome     string
}

type importContext struct {
	AgentID       int64
	StockType     string
	SubLocationID *int64
}

func importClean(v string) string {
	v = strings.TrimSpace(v)
	if len(v) > 1 && v[0] == 39 && strings.ContainsAny(v[1:2], "=+-@	\r") {
		v = v[1:]
	}
	return v
}

func skuKey(v string) string { return strings.ToLower(importClean(v)) }

func isDigits(v string) bool {
	if v == "" {
		return false
	}
	for _, r := range v {
		if r < '0' || r > '9' {
			return false
		}
	}
	return true
}

func importParseAttributes(text string) map[string]string {
	out := map[string]string{}
	for _, pair := range strings.Split(text, ";") {
		if strings.TrimSpace(pair) == "" {
			continue
		}
		parts := strings.SplitN(pair, "=", 2)
		if len(parts) != 2 {
			return nil
		}
		n, v := strings.TrimSpace(parts[0]), strings.TrimSpace(parts[1])
		if n == "" || v == "" || len([]rune(n)) > 100 || len([]rune(v)) > 100 {
			return nil
		}
		if _, exists := out[n]; exists {
			return nil
		}
		out[n] = v
	}
	if len(out) == 0 {
		return nil
	}
	return out
}
func (s *Server) importResolveContext(ctx context.Context, actor *importActor, agentID *int64,
	stockType string, subLoc *int64) (*importContext, error) {
	resolved, err := s.importValidAgentFor(ctx, actor.ID, actor.Role, actor.AgentID, agentID)
	if err != nil {
		return nil, err
	}
	if stockType != "transit" && stockType != "shipping" && stockType != "sub" {
		return nil, errors.New("Konteks stok tidak valid.")
	}
	if (stockType == "sub") != (subLoc != nil) {
		return nil, errors.New("Konteks stok tidak valid.")
	}
	if subLoc != nil {
		var cnt int
		err := s.database.QueryRowContext(ctx, `SELECT COUNT(*) FROM warehouse_sub_locations
			WHERE id = ? AND agent_id = ? AND is_active = 1`, *subLoc, resolved).Scan(&cnt)
		if err != nil || cnt == 0 {
			return nil, errStockForbidden
		}
	}
	return &importContext{AgentID: resolved, StockType: stockType, SubLocationID: subLoc}, nil
}

var errStockForbidden = errors.New("Sub Location bukan milik Agen ini.")

// importPlan runs the full per-row validation; it writes nothing.
func (s *Server) importPlan(ctx context.Context, actor *importActor, rows []map[string]string,
	ictx *importContext, ctxError error) []*importPlan {
	counts := map[string]int{}
	for _, r := range rows {
		k := skuKey(r["sku"])
		if k != "" {
			counts[k]++
		}
	}
	plans := make([]*importPlan, 0, len(rows))
	for i, row0 := range rows {
		row := map[string]string{}
		for k, v := range row0 {
			row[k] = importClean(v)
		}
		dup := counts[skuKey(row0["sku"])] > 1
		plans = append(plans, s.importPlanRow(ctx, i+2, row, dup, ictx, ctxError))
	}
	return plans
}

func (p *importPlan) err(code string) { p.Errors = append(p.Errors, code) }

func variantFields(changes map[string]any) map[string]any {
	if st, ok := changes["status"]; ok {
		changes["is_active"] = st == "active"
		delete(changes, "status")
	}
	return changes
}

// importPlanRow ports CatalogImportService::planRow (full validation chain).
func (s *Server) importPlanRow(ctx context.Context, line int, row map[string]string,
	dupInFile bool, ictx *importContext, ctxError error) *importPlan {
	plan := &importPlan{Line: line, Sku: row["sku"], Type: row["type"], Action: "none",
		Changes: map[string]any{}, Row: row}
	if dupInFile {
		plan.err("duplicate_sku_in_file")
	}
	sku := row["sku"]
	if sku == "" || len([]rune(sku)) > 60 {
		plan.err("sku_invalid")
	}
	typ := row["type"]
	if typ != "simple" && typ != "variant" {
		plan.err("type_invalid")
	}
	if row["price"] != "" {
		f, perr := strconv.ParseFloat(row["price"], 64)
		if perr != nil || f < 0 || f > 9_999_999_999 {
			plan.err("price_invalid")
		} else {
			plan.Changes["price"] = math.Round(f*100) / 100
		}
	}
	if row["weight_grams"] != "" {
		if !isDigits(row["weight_grams"]) || row["weight_grams"] == "0" {
			plan.err("weight_invalid")
		} else {
			w, _ := strconv.ParseInt(row["weight_grams"], 10, 64)
			plan.Changes["weight_grams"] = w
		}
	}
	if row["category_id"] != "" {
		var cnt int
		_ = s.database.QueryRowContext(ctx, `SELECT COUNT(*) FROM product_categories WHERE id = ?`,
			row["category_id"]).Scan(&cnt)
		if !isDigits(row["category_id"]) || cnt == 0 {
			plan.err("category_invalid")
		} else {
			c, _ := strconv.ParseInt(row["category_id"], 10, 64)
			plan.Changes["category_id"] = c
		}
	}
	if row["status"] != "" {
		allowed := []string{"draft", "active", "inactive"}
		if typ == "variant" {
			allowed = []string{"active", "inactive"}
		}
		ok := false
		for _, a := range allowed {
			if row["status"] == a {
				ok = true
			}
		}
		if !ok {
			plan.err("status_invalid")
		} else {
			plan.Changes["status"] = row["status"]
		}
	}
	if row["product_name"] != "" {
		if len([]rune(row["product_name"])) > 150 {
			plan.err("name_invalid")
		} else {
			plan.Changes["name"] = row["product_name"]
		}
	}
	if row["attributes"] != "" {
		attrs := importParseAttributes(row["attributes"])
		if attrs == nil {
			plan.err("attributes_invalid")
		} else {
			plan.Attributes = attrs
		}
	}
	if row["stock_target"] != "" {
		if !isDigits(row["stock_target"]) || row["stock_target"] > "100000000" && len(row["stock_target"]) > 9 {
			plan.err("stock_target_invalid")
		} else if ctxError != nil {
			plan.err("stock_context_invalid")
		} else {
			t, _ := strconv.ParseInt(row["stock_target"], 10, 64)
			if t > 100_000_000 {
				plan.err("stock_target_invalid")
			} else {
				plan.StockTarget = &t
			}
		}
	}
	hasSkuInvalid := false
	for _, e := range plan.Errors {
		if e == "sku_invalid" {
			hasSkuInvalid = true
		}
	}
	if (len(plan.Errors) > 0 && hasSkuInvalid) || (typ != "simple" && typ != "variant") {
		return plan
	}
	// SKU owner resolution through the global registry.
	var ownerType string
	var ownerID int64
	err := s.database.QueryRowContext(ctx, `SELECT owner_type, owner_id FROM catalog_skus WHERE sku = ?`,
		sku).Scan(&ownerType, &ownerID)
	if err == nil {
		expected := "product"
		if typ == "variant" {
			expected = "variant"
		}
		if ownerType != expected {
			plan.err("sku_owner_type_mismatch")
			return plan
		}
		if expected == "variant" {
			var pid int64
			var deleted sql.NullTime
			err := s.database.QueryRowContext(ctx, `SELECT product_id, deleted_at FROM product_variations WHERE id = ?`,
				ownerID).Scan(&pid, &deleted)
			if err != nil {
				plan.err("sku_retired")
				return plan
			}
			var parentSlug string
			s.database.QueryRowContext(ctx, `SELECT slug FROM products WHERE id = ?`, pid).Scan(&parentSlug)
			if row["product_slug"] != "" && parentSlug != row["product_slug"] {
				plan.err("sku_belongs_to_other_product")
				return plan
			}
			plan.Target = map[string]any{"kind": "variant", "id": ownerID, "product_id": pid}
		} else {
			var hasVariations bool
			var deleted sql.NullTime
			err := s.database.QueryRowContext(ctx, `SELECT has_variations, deleted_at FROM products WHERE id = ?`,
				ownerID).Scan(&hasVariations, &deleted)
			if err != nil {
				plan.err("sku_retired")
				return plan
			}
			if hasVariations {
				plan.err("product_has_variations")
				return plan
			}
			plan.Target = map[string]any{"kind": "product", "id": ownerID}
		}
		plan.Action = "update"
		if typ == "variant" {
			delete(plan.Changes, "name")
			delete(plan.Changes, "category_id")
			plan.Changes = variantFields(plan.Changes)
		}
		return plan
	}
	// ---- create
	plan.Action = "create"
	if typ == "simple" {
		for f, code := range map[string]string{"name": "name_required", "price": "price_required", "weight_grams": "weight_required"} {
			if _, ok := plan.Changes[f]; !ok {
				plan.err(code)
			}
		}
		slug := row["product_slug"]
		if slug != "" {
			var cnt int
			_ = s.database.QueryRowContext(ctx, `SELECT COUNT(*) FROM products WHERE slug = ?`, slug).Scan(&cnt)
			if len([]rune(slug)) > 150 || cnt > 0 {
				plan.err("slug_taken")
			}
		}
		if slug == "" {
			plan.Changes["slug"] = nil
		} else {
			plan.Changes["slug"] = slug
		}
		if _, ok := plan.Changes["status"]; !ok {
			plan.Changes["status"] = "draft"
		}
	} else {
		var parentID int64
		var parentHasVar bool
		if row["product_slug"] != "" {
			s.database.QueryRowContext(ctx, `SELECT id, has_variations FROM products WHERE slug = ? AND deleted_at IS NULL`,
				row["product_slug"]).Scan(&parentID, &parentHasVar)
		}
		if parentID == 0 {
			plan.err("parent_not_found")
		} else if !parentHasVar {
			plan.err("parent_without_variations")
		} else {
			plan.Target = map[string]any{"kind": "new_variant", "product_id": parentID}
		}
		for f, code := range map[string]string{"price": "price_required", "weight_grams": "weight_required"} {
			if _, ok := plan.Changes[f]; !ok {
				plan.err(code)
			}
		}
		if plan.Attributes == nil {
			plan.err("attributes_required")
		}
		plan.Changes = variantFields(plan.Changes)
		if _, ok := plan.Changes["is_active"]; !ok {
			plan.Changes["is_active"] = true
		}
	}
	return plan
}

type importActor struct {
	ID      int64
	Role    string
	AgentID *int64
}

// importValidAgentFor ports ValidAgentResolver::forActor (role-scoped resolution).
func (s *Server) importValidAgentFor(ctx context.Context, actorID int64, actorRole string,
	actorAgentID *int64, explicit *int64) (int64, error) {
	assertValid := func(id int64) (int64, error) {
		var cnt int
		err := s.database.QueryRowContext(ctx, `SELECT COUNT(*) FROM users u
			JOIN roles r ON r.id = u.role_id
			WHERE u.id = ? AND r.slug = 'agen' AND u.deleted_at IS NULL AND u.status = 'active'`, id).Scan(&cnt)
		if err != nil {
			return 0, err
		}
		if cnt == 0 {
			return 0, errors.New("Agen tidak ditemukan atau tidak aktif.")
		}
		return id, nil
	}
	switch actorRole {
	case "super_admin":
		if explicit == nil {
			return 0, errors.New("Super Admin harus memilih Agen secara eksplisit.")
		}
		return assertValid(*explicit)
	case "agen":
		if explicit != nil && *explicit != actorID {
			return 0, errors.New("Agen hanya dapat bertindak untuk dirinya sendiri.")
		}
		return assertValid(actorID)
	default: // admin
		if actorAgentID == nil {
			return 0, errors.New("Admin belum terhubung ke Agen.")
		}
		if explicit != nil && *explicit != *actorAgentID {
			return 0, errors.New("Admin hanya dapat bertindak untuk Agen yang terhubung.")
		}
		return assertValid(*actorAgentID)
	}
}

// ---- handlers --------------------------------------------------------------------------------

func (s *Server) importRequestBody(w http.ResponseWriter, r *http.Request) ([]byte, *int64, string, *int64, bool) {
	ctx := r.Context()
	if err := r.ParseMultipartForm(importMaxBytes); err != nil {
		writeError(w, r, httpx.Validation(map[string][]string{"file": {"file wajib diisi."}}))
		return nil, nil, "", nil, false
	}
	file, _, ferr := r.FormFile("file")
	if ferr != nil {
		writeError(w, r, httpx.Validation(map[string][]string{"file": {"file wajib diisi."}}))
		return nil, nil, "", nil, false
	}
	defer file.Close()
	raw, _ := io.ReadAll(io.LimitReader(file, importMaxBytes+1))
	var agentID *int64
	if v := r.FormValue("agent_id"); v != "" {
		if id, err := strconv.ParseInt(v, 10, 64); err == nil {
			agentID = &id
		}
	}
	stockType := r.FormValue("stock_type")
	var subLoc *int64
	if v := r.FormValue("sub_location_id"); v != "" {
		if id, err := strconv.ParseInt(v, 10, 64); err == nil {
			subLoc = &id
		}
	}
	if stockType != "" && stockType != "transit" && stockType != "shipping" && stockType != "sub" {
		writeError(w, r, httpx.Validation(map[string][]string{"stock_type": {"stock type yang dipilih tidak valid."}}))
		return nil, nil, "", nil, false
	}
	_ = ctx
	return raw, agentID, stockType, subLoc, true
}

// handleCatalogImportPreview ports CatalogImportExportController::preview (pure validation).
func (s *Server) handleCatalogImportPreview(w http.ResponseWriter, r *http.Request) {
	s.handleCatalogImport(w, r, false)
}

// handleCatalogImportApply ports CatalogImportExportController::apply.
func (s *Server) handleCatalogImportApply(w http.ResponseWriter, r *http.Request) {
	s.handleCatalogImport(w, r, true)
}

func (s *Server) handleCatalogImport(w http.ResponseWriter, r *http.Request, apply bool) {
	ctx := r.Context()
	user, ok := requireUser(w, r)
	if !ok {
		return
	}
	// ProductPolicy::manage — super_admin / agen / admin (route group + policy).
	batchKey := ""
	if apply {
		batchKey = r.FormValue("idempotency_key")
		if batchKey == "" {
			writeError(w, r, httpx.Validation(map[string][]string{"idempotency_key": {
				"idempotency key wajib diisi."}}))
			return
		}
		if len(batchKey) < 8 || len(batchKey) > 100 {
			writeError(w, r, httpx.Validation(map[string][]string{"idempotency_key": {
				"idempotency key harus minimal 8 karakter."}}))
			return
		}
	}
	raw, agentID, stockType, subLoc, ok2 := s.importRequestBody(w, r)
	if !ok2 {
		return
	}
	rows, rerr := importSheet(raw)
	if rerr != nil {
		writeError(w, r, httpx.NewError(http.StatusUnprocessableEntity, rerr.Error()))
		return
	}
	actor := &importActor{ID: user.ID, Role: user.Role, AgentID: user.AgentID}
	var ictx *importContext
	var ctxError error
	anyStock := false
	for _, rr := range rows {
		if rr["stock_target"] != "" {
			anyStock = true
			break
		}
	}
	if anyStock {
		ictx, ctxError = s.importResolveContext(ctx, actor, agentID, stockType, subLoc)
	}
	plans := s.importPlan(ctx, actor, rows, ictx, ctxError)
	if apply {
		for _, p := range plans {
			if len(p.Errors) > 0 {
				p.Outcome = "rejected"
				continue
			}
			outcome, aerr := s.importApplyRow(ctx, user, p, ictx, batchKey)
			if aerr != nil {
				p.err("apply_failed")
				p.Outcome = "rejected"
			} else {
				p.Outcome = outcome
			}
		}
	}
	httpx.OK(w, s.importSummarise(plans, ictx), "OK", nil)
}

// importSummarise ports CatalogImportService::summarise.
func (s *Server) importSummarise(plans []*importPlan, ictx *importContext) map[string]any {
	rows := make([]map[string]any, 0, len(plans))
	summary := map[string]int{"total": 0, "valid": 0, "invalid": 0, "create": 0, "update": 0, "with_stock": 0}
	for _, p := range plans {
		summary["total"]++
		valid := len(p.Errors) == 0
		if valid {
			summary["valid"]++
			summary[p.Action]++
			if p.StockTarget != nil {
				summary["with_stock"]++
			}
		} else {
			summary["invalid"]++
		}
		seen := map[string]bool{}
		uniq := []string{}
		for _, e := range p.Errors {
			if !seen[e] {
				seen[e] = true
				uniq = append(uniq, e)
			}
		}
		action := p.Action
		if !valid {
			action = "none"
		}
		var stockTarget any
		if p.StockTarget != nil {
			stockTarget = *p.StockTarget
		}
		var outcome any
		if p.Outcome != "" {
			outcome = p.Outcome
		}
		rows = append(rows, map[string]any{"line": p.Line, "sku": p.Sku, "type": p.Type,
			"action": action, "valid": valid, "errors": uniq, "stock_target": stockTarget, "outcome": outcome})
	}
	var ctxOut any
	if ictx != nil {
		ctxOut = map[string]any{"agent_id": ictx.AgentID, "stock_type": ictx.StockType,
			"sub_location_id": ictx.SubLocationID}
	}
	return map[string]any{"summary": summary, "rows": rows, "context": ctxOut}
}

// importApplyRow ports CatalogImportService::applyRow (per-row transaction).
func (s *Server) importApplyRow(ctx context.Context, user *auth.User, p *importPlan,
	ictx *importContext, batchKey string) (string, error) {
	staged := p.StockTarget != nil
	var rowKey, fingerprint string
	if staged {
		h := sha256.New()
		sub := "0"
		if ictx.SubLocationID != nil {
			sub = strconv.FormatInt(*ictx.SubLocationID, 10)
		}
		fmt.Fprintf(h, "%s|%d|%s|%s|%s", batchKey, ictx.AgentID, ictx.StockType, sub, skuKey(p.Sku))
		rowKey = "imp:" + hex.EncodeToString(h.Sum(nil))[:60]
		fp, ferr := importFingerprint(map[string]any{"row": p.Row, "ctx": map[string]any{
			"agent_id": ictx.AgentID, "stock_type": ictx.StockType, "sub_location_id": ictx.SubLocationID}})
		if ferr != nil {
			return "", ferr
		}
		fingerprint = fp
		var existingFP sql.NullString
		err := s.database.QueryRowContext(ctx, `SELECT import_fingerprint FROM stock_opname_items
			WHERE import_row_key = ?`, rowKey).Scan(&existingFP)
		if err == nil {
			if existingFP.String != fingerprint {
				return "", errors.New("Idempotency key dipakai dengan data berbeda.")
			}
			return "stock_pending_replay", nil
		}
	}
	if p.Action == "update" && !staged {
		if err := s.importApplyChange(ctx, p.Target["kind"].(string), p.Target["id"].(int64), p.Changes, p.Attributes); err != nil {
			return "", err
		}
		return "catalog_updated", nil
	}
	if p.Action == "create" && !staged {
		if _, err := s.importCreate(ctx, user, p); err != nil {
			return "", err
		}
		return "catalog_created", nil
	}
	// staged path: create/update in draft + stage the opname row
	var targetKind string
	var targetID int64
	var pending map[string]any
	if p.Action == "update" {
		targetKind = p.Target["kind"].(string)
		targetID = p.Target["id"].(int64)
		pending = map[string]any{"kind": targetKind, "id": targetID, "changes": p.Changes,
			"attributes": p.Attributes}
	} else {
		id, err := s.importCreateStaged(ctx, user, p)
		if err != nil {
			return "", err
		}
		targetID = id
		if p.Type == "simple" {
			targetKind = "product"
			if p.Changes["status"] == "active" {
				pending = map[string]any{"kind": "product", "id": id,
					"changes": map[string]any{"status": "active"}, "attributes": nil}
			}
		} else {
			targetKind = "variant"
			if act, ok := p.Changes["is_active"].(bool); ok && act {
				pending = map[string]any{"kind": "variant", "id": id,
					"changes": map[string]any{"is_active": true}, "attributes": nil}
			}
		}
	}
	if err := s.importStageRow(ctx, user.ID, ictx, targetKind, targetID, rowKey, fingerprint,
		*p.StockTarget, pending); err != nil {
		return "", err
	}
	if pending != nil {
		return "catalog_withheld_stock_pending", nil
	}
	return "stock_pending", nil
}

// importFingerprint ports PayloadFingerprint::of (sorted-key JSON → sha256).
func importFingerprint(payload any) (string, error) {
	return fpOf(fpNorm(payload))
}

func fpOf(v any) (string, error) {
	b, err := json.Marshal(v)
	if err != nil {
		return "", err
	}
	sum := sha256.Sum256(b)
	return hex.EncodeToString(sum[:]), nil
}

func fpNorm(v any) any {
	switch t := v.(type) {
	case map[string]any:
		keys := make([]string, 0, len(t))
		for k := range t {
			keys = append(keys, k)
		}
		sort.Strings(keys)
		parts := make([]string, 0, len(keys))
		for _, k := range keys {
			b, _ := json.Marshal(fpNorm(t[k]))
			parts = append(parts, strconv.Quote(k)+":"+string(b))
		}
		return "{" + strings.Join(parts, ",") + "}"
	case []any:
		parts := make([]string, 0, len(t))
		for _, item := range t {
			b, _ := json.Marshal(fpNorm(item))
			parts = append(parts, string(b))
		}
		return "[" + strings.Join(parts, ",") + "]"
	case float64:
		return strconv.FormatFloat(math.Round(t*1e6)/1e6, 'f', -1, 64)
	case int, int64:
		return fmt.Sprintf("%d", t)
	default:
		return t
	}
}

// importApplyChange ports applyChange (product/variant field application).
func (s *Server) importApplyChange(ctx context.Context, kind string, id int64,
	changes map[string]any, attributes map[string]string) error {
	return s.database.Tx(ctx, func(ctx context.Context, tx *sql.Tx) error {
		if kind == "product" {
			sets := []string{}
			args := []any{}
			for k, v := range changes {
				col := k
				if k == "price" {
					col = "base_price"
				}
				sets = append(sets, col+" = ?")
				args = append(args, v)
			}
			if len(sets) > 0 {
				args = append(args, id)
				_, err := tx.ExecContext(ctx, "UPDATE products SET "+strings.Join(sets, ", ")+
					", updated_at = UTC_TIMESTAMP(6) WHERE id = ?", args...)
				return err
			}
			return nil
		}
		sets := []string{}
		args := []any{}
		for k, v := range changes {
			sets = append(sets, k+" = ?")
			args = append(args, v)
		}
		if len(sets) > 0 {
			args = append(args, id)
			if _, err := tx.ExecContext(ctx, "UPDATE product_variations SET "+strings.Join(sets, ", ")+
				", updated_at = UTC_TIMESTAMP(6) WHERE id = ?", args...); err != nil {
				return err
			}
		}
		if attributes != nil {
			var productID int64
			if err := tx.QueryRowContext(ctx, "SELECT product_id FROM product_variations WHERE id = ?", id).
				Scan(&productID); err != nil {
				return err
			}
			return s.replaceVariationAttrsTx(ctx, tx, productID, id, attributes)
		}
		return nil
	})
}

// replaceVariationAttrsTx ports ProductVariationService::updateAttributes (import path).
func (s *Server) replaceVariationAttrsTx(ctx context.Context, tx *sql.Tx, productID, variationID int64,
	attrs map[string]string) error {
	if _, err := tx.ExecContext(ctx, "DELETE FROM product_variation_compositions WHERE product_variation_id = ?", variationID); err != nil {
		return err
	}
	for name, value := range attrs {
		var attrID int64
		err := tx.QueryRowContext(ctx, `SELECT id FROM product_variation_attributes
			WHERE product_id = ? AND LOWER(TRIM(name)) = ?`, productID, strings.ToLower(strings.TrimSpace(name))).Scan(&attrID)
		if errors.Is(err, sql.ErrNoRows) {
			res, ierr := tx.ExecContext(ctx, `INSERT INTO product_variation_attributes (product_id, name, created_at, updated_at)
				VALUES (?, ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))`, productID, strings.TrimSpace(name))
			if ierr != nil {
				return ierr
			}
			attrID, _ = res.LastInsertId()
		} else if err != nil {
			return err
		}
		var optID int64
		err = tx.QueryRowContext(ctx, `SELECT id FROM product_variation_attribute_options
			WHERE product_variation_attribute_id = ? AND LOWER(TRIM(value)) = ?`, attrID, strings.ToLower(strings.TrimSpace(value))).Scan(&optID)
		if errors.Is(err, sql.ErrNoRows) {
			res, ierr := tx.ExecContext(ctx, `INSERT INTO product_variation_attribute_options
				(product_variation_attribute_id, value, created_at, updated_at)
				VALUES (?, ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))`, attrID, strings.TrimSpace(value))
			if ierr != nil {
				return ierr
			}
			optID, _ = res.LastInsertId()
		} else if err != nil {
			return err
		}
		if _, err := tx.ExecContext(ctx, `INSERT INTO product_variation_compositions
				(product_variation_id, product_variation_attribute_id, product_variation_attribute_option_id, created_at, updated_at)
				VALUES (?, ?, ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))`, variationID, attrID, optID); err != nil {
			return err
		}
	}
	return nil
}

func slugifyName(name string) string {
	re := regexp.MustCompile(`[^a-z0-9]+`)
	slug := strings.Trim(re.ReplaceAllString(strings.ToLower(name), "-"), "-")
	if slug == "" {
		slug = "produk"
	}
	b := make([]byte, 6)
	crand.Read(b)
	return slug + "-" + hex.EncodeToString(b)
}

// importCreate ports the unstaged create path (product or variation, final state).
func (s *Server) importCreate(ctx context.Context, user *auth.User, p *importPlan) (int64, error) {
	if p.Type == "simple" {
		return s.insertImportProduct(ctx, user.ID, p, false)
	}
	// variation create (ProductVariationService::create semantics)
	var id int64
	err := s.database.Tx(ctx, func(ctx context.Context, tx *sql.Tx) error {
		parentID := p.Target["product_id"].(int64)
		active, _ := p.Changes["is_active"].(bool)
		res, ierr := tx.ExecContext(ctx, `INSERT INTO product_variations
			(product_id, sku, price, weight_grams, is_active, created_at, updated_at)
			VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))`,
			parentID, p.Sku, p.Changes["price"], p.Changes["weight_grams"], active)
		if ierr != nil {
			return ierr
		}
		id, _ = res.LastInsertId()
		if _, err := tx.ExecContext(ctx, "INSERT INTO catalog_skus (sku, owner_type, owner_id) VALUES (?, 'variant', ?)",
			p.Sku, id); err != nil {
			return err
		}
		return s.replaceVariationAttrsTx(ctx, tx, parentID, id, p.Attributes)
	})
	if err != nil {
		return 0, err
	}
	return id, nil
}

// importCreateStaged ports the staged create path (never active before approval).
func (s *Server) importCreateStaged(ctx context.Context, user *auth.User, p *importPlan) (int64, error) {
	if p.Type == "simple" {
		return s.insertImportProduct(ctx, user.ID, p, true)
	}
	save := p.Changes["is_active"]
	p.Changes["is_active"] = false
	id, err := s.importCreate(ctx, user, p)
	p.Changes["is_active"] = save
	return id, err
}

func (s *Server) insertImportProduct(ctx context.Context, actorID int64, p *importPlan, staged bool) (int64, error) {
	name, _ := p.Changes["name"].(string)
	slug, _ := p.Changes["slug"].(string)
	if slug == "" {
		slug = slugifyName(name)
	}
	status, _ := p.Changes["status"].(string)
	if staged && status == "active" {
		status = "draft"
	}
	res, err := s.database.ExecContext(ctx, `INSERT INTO products
		(name, slug, sku, has_variations, base_price, weight_grams, category_id, status, created_by,
		created_at, updated_at)
		VALUES (?, ?, ?, 0, ?, ?, ?, ?, ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))`,
		name, slug, p.Sku, p.Changes["price"], p.Changes["weight_grams"], p.Changes["category_id"],
		status, actorID)
	if err != nil {
		return 0, err
	}
	id, _ := res.LastInsertId()
	if _, err := s.database.ExecContext(ctx, "INSERT INTO catalog_skus (sku, owner_type, owner_id) VALUES (?, 'product', ?)",
		p.Sku, id); err != nil {
		return 0, err
	}
	return id, nil
}

// importStageRow ports StockOpnameService::stageImportRow (one draft physical
// opname item per import row; unique row key enforced by the database).
func (s *Server) importStageRow(ctx context.Context, actorID int64, ictx *importContext,
	kind string, targetID int64, rowKey, fingerprint string, target int64, pending map[string]any) error {
	return s.database.Tx(ctx, func(ctx context.Context, tx *sql.Tx) error {
		number := ""
		for attempt := 0; attempt < 10; attempt++ {
			b := make([]byte, 5)
			crand.Read(b)
			cand := "OPN-" + time.Now().UTC().Format("20060102150405") + "-" + hex.EncodeToString(b)[:5]
			var exists bool
			if err := tx.QueryRowContext(ctx, `SELECT EXISTS(SELECT 1 FROM stock_opnames WHERE opname_number = ?)`,
				cand).Scan(&exists); err != nil {
				return err
			}
			if !exists {
				number = cand
				break
			}
		}
		if number == "" {
			return errors.New("could not allocate a unique opname number")
		}
		res, err := tx.ExecContext(ctx, `INSERT INTO stock_opnames
			(agent_id, opname_number, opname_type, stock_type, sub_location_id, status, notes, created_by,
			created_at, updated_at)
			VALUES (?, ?, 'physical_opname', ?, ?, 'draft', 'Import katalog (target fisik diusulkan, wajib dihitung Gudang)', ?,
			UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))`,
			ictx.AgentID, number, ictx.StockType, ictx.SubLocationID, actorID)
		if err != nil {
			return err
		}
		opnameID, _ := res.LastInsertId()
		var productID, variationID any
		if kind == "product" {
			productID = targetID
		} else {
			variationID = targetID
		}
		var snapQty, snapStockID, snapSub sql.NullInt64
		if ictx.SubLocationID != nil {
			snapSub = sql.NullInt64{Int64: *ictx.SubLocationID, Valid: true}
		}
		_ = tx.QueryRowContext(ctx, `SELECT id, quantity FROM warehouse_stock
			WHERE agent_id = ? AND stock_type = ? AND sub_location_id <=> ? AND product_id <=> ?
			AND product_variation_id <=> ? FOR UPDATE`,
			ictx.AgentID, ictx.StockType, snapSub, productID, variationID).Scan(&snapStockID, &snapQty)
		var revision int64
		_ = tx.QueryRowContext(ctx, `SELECT COALESCE(MAX(revision), 0) FROM warehouse_stock_revisions
			WHERE agent_id = ? AND stock_type = ? AND sub_location_id <=> ? AND product_id <=> ?
			AND product_variation_id <=> ?`,
			ictx.AgentID, ictx.StockType, snapSub, productID, variationID).Scan(&revision)
		pendingJSON := any(nil)
		if pending != nil {
			b, _ := json.Marshal(pending)
			pendingJSON = string(b)
		}
		_, err = tx.ExecContext(ctx, `INSERT INTO stock_opname_items
			(stock_opname_id, product_id, product_variation_id, sub_location_id,
			import_row_key, import_fingerprint, import_target_quantity, pending_catalog_change,
			system_quantity, system_stock_id, system_stock_revision, created_at, updated_at)
			VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))`,
			opnameID, productID, variationID, ictx.SubLocationID, rowKey, fingerprint, target, pendingJSON,
			snapQty, snapStockID, revision)
		return err
	})
}
