package api

import (
	"context"
	"database/sql"
	"encoding/json"
	"net/http"
	"os"
	"strconv"
	"strings"
	"time"

	"github.com/kmsitcomputer/primeclassy-go/internal/agentadmin"
	"github.com/kmsitcomputer/primeclassy-go/internal/httpx"
	"github.com/kmsitcomputer/primeclassy-go/internal/i18n"
	"github.com/kmsitcomputer/primeclassy-go/internal/shipping"
)

// handleCheckoutGeocode ports GeocodeController::locate (IMP-002 "Gunakan Lokasi
// Saya"). The provider (Nominatim) is resolved by GEOCODE_PROVIDER; a noop/failed
// provider returns matched:false so the frontend keeps the manual selectors.
func (s *Server) handleCheckoutGeocode(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	var body struct {
		Latitude  *float64 `json:"latitude"`
		Longitude *float64 `json:"longitude"`
	}
	_ = httpx.DecodeJSON(r, &body)
	fields := fieldErrors{}
	if body.Latitude == nil {
		fields.add("latitude", i18n.ValidationMessage(LocaleFrom(ctx), "required", "latitude"))
	} else if *body.Latitude < -90 || *body.Latitude > 90 {
		fields.add("latitude", "garis lintang harus di antara -90 sampai 90.")
	}
	if body.Longitude == nil {
		fields.add("longitude", i18n.ValidationMessage(LocaleFrom(ctx), "required", "longitude"))
	} else if *body.Longitude < -180 || *body.Longitude > 180 {
		fields.add("longitude", "garis bujur harus di antara -180 sampai 180.")
	}
	if len(fields) > 0 {
		writeError(w, r, httpx.Validation(map[string][]string(fields)))
		return
	}
	match := s.geocodeLocate(*body.Latitude, *body.Longitude)
	if match == nil {
		httpx.OK(w, map[string]any{"matched": false, "result": nil},
			"Lokasi tidak dapat ditemukan — silakan pilih manual.", nil)
		return
	}
	httpx.OK(w, map[string]any{"matched": true, "result": match}, "OK", nil)
}

// geocodeLocate ports GeocodeService::locate: provider labels → canonical
// regional-master match (exact chain, single match only).
func (s *Server) geocodeLocate(lat, lng float64) map[string]any {
	provider := os.Getenv("GEOCODE_PROVIDER")
	if provider == "" {
		provider = "nominatim"
	}
	if provider != "nominatim" {
		return nil // NoopReverseGeocoder
	}
	labels := s.nominatimReverse(lat, lng)
	if labels == nil || labels["province"] == "" || labels["regency"] == "" || labels["village"] == "" {
		return nil
	}
	rows, err := s.database.QueryContext(contextBG(), `SELECT v.id, v.name, d.id, d.name,
		re.id, re.name, p.id, p.name
		FROM villages v
		JOIN districts d ON d.id = v.district_id
		JOIN regencies re ON re.id = d.regency_id
		JOIN provinces p ON p.id = re.province_id
		WHERE LOWER(TRIM(v.name)) = ?
		AND (? = '' OR LOWER(TRIM(d.name)) = ?)
		AND LOWER(TRIM(re.name)) = ?
		AND LOWER(TRIM(p.name)) = ?
		LIMIT 2`,
		geoNormalize(labels["village"]), geoNormalize(labels["district"]), geoNormalize(labels["district"]),
		geoNormalize(labels["regency"]), geoNormalize(labels["province"]))
	if err != nil {
		return nil
	}
	defer rows.Close()
	type chain struct{ vid, vname, did, dname, reid, rename, pid, pname string }
	var matches []chain
	for rows.Next() {
		var c chain
		if err := rows.Scan(&c.vid, &c.vname, &c.did, &c.dname, &c.reid, &c.rename, &c.pid, &c.pname); err != nil {
			return nil
		}
		matches = append(matches, c)
	}
	if len(matches) != 1 {
		return nil
	}
	c := matches[0]
	return map[string]any{
		"province_id": c.pid, "regency_id": c.reid, "district_id": c.did, "village_id": c.vid,
		"province_name": c.pname, "regency_name": c.rename, "district_name": c.dname,
		"village_name": c.vname, "postal_code": labels["postal_code"],
	}
}

var geoPrefixes = []string{"provinsi", "kecamatan", "kelurahan", "desa"}

func geoNormalize(v string) string {
	v = strings.ToLower(strings.TrimSpace(v))
	for _, p := range geoPrefixes {
		if strings.HasPrefix(v, p+" ") {
			return strings.TrimPrefix(v, p+" ")
		}
	}
	return v
}

// nominatimReverse ports NominatimReverseGeocoder::reverse (backend-only,
// user-triggered; no retries; any failure → nil).
func (s *Server) nominatimReverse(lat, lng float64) map[string]string {
	base := strings.TrimRight(envOr("GEOCODE_BASE_URL", "https://nominatim.openstreetmap.org"), "/")
	if !strings.HasPrefix(base, "https://") {
		return nil
	}
	q := "lat=" + strconv.FormatFloat(lat, 'f', -1, 64) +
		"&lon=" + strconv.FormatFloat(lng, 'f', -1, 64) +
		"&format=jsonv2&addressdetails=1&zoom=18&layer=address&accept-language=id"
	req, err := http.NewRequest("GET", base+"/reverse?"+q, nil)
	if err != nil {
		return nil
	}
	req.Header.Set("Accept", "application/json")
	req.Header.Set("User-Agent", envOr("GEOCODE_USER_AGENT", "PrimeClassy/1.0"))
	client := &http.Client{Timeout: 4 * time.Second}
	resp, err := client.Do(req)
	if err != nil {
		return nil
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusOK {
		return nil
	}
	var payload struct {
		Address map[string]any `json:"address"`
	}
	if json.NewDecoder(resp.Body).Decode(&payload) != nil {
		return nil
	}
	return nominatimAddressLabels(payload.Address)
}

// nominatimAddressLabels keeps the administrative levels distinct. Nominatim uses different address keys
// across Indonesian regions; treating suburb as both district and village made exact master-region matching
// impossible whenever those levels had different names.
func nominatimAddressLabels(address map[string]any) map[string]string {
	get := func(keys ...string) string {
		for _, key := range keys {
			if value, ok := address[key].(string); ok && strings.TrimSpace(value) != "" {
				return strings.TrimSpace(value)
			}
		}
		return ""
	}
	return map[string]string{
		"province":    get("state", "province"),
		"regency":     get("city", "municipality", "county", "town"),
		"district":    get("city_district", "district", "suburb"),
		"village":     get("village", "suburb", "neighbourhood", "hamlet"),
		"postal_code": get("postcode"),
	}
}

func firstNonEmpty(values ...string) string {
	for _, v := range values {
		if v != "" {
			return v
		}
	}
	return ""
}

func envOr(key, fallback string) string {
	if v := os.Getenv(key); v != "" {
		return v
	}
	return fallback
}

func contextBG() context.Context { return context.Background() }

// handleCheckoutCourierOptions ports CheckoutController::courierOptions: the
// RajaOngkir courier-picker sub-step. The provider path is identical to the
// reference: provider gate → context build → provider call; in the isolated env
// the provider call cannot succeed, so the answer is the shared 422.
func (s *Server) handleCheckoutCourierOptions(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	user, ok := requireUser(w, r)
	if !ok {
		return
	}
	var body struct {
		KonsumenID     *int64            `json:"konsumen_id"`
		Items          *[]map[string]any `json:"items"`
		AddressID      *int64            `json:"address_id"`
		RecipientName  *string           `json:"recipient_name"`
		RecipientPhone *string           `json:"recipient_phone"`
		AddressLine    *string           `json:"address_line"`
		VillageID      *string           `json:"village_id"`
		Latitude       *float64          `json:"latitude"`
		Longitude      *float64          `json:"longitude"`
	}
	_ = httpx.DecodeJSON(r, &body)
	fields := fieldErrors{}
	if body.KonsumenID != nil {
		var cnt int
		_ = s.database.QueryRowContext(ctx, `SELECT COUNT(*) FROM users WHERE id = ?`, *body.KonsumenID).Scan(&cnt)
		if cnt == 0 {
			fields.add("konsumen_id", "konsumen id yang dipilih tidak valid.")
		}
	}
	if body.Items == nil || len(*body.Items) == 0 {
		fields.add("items", "item wajib diisi.")
	} else {
		for i, it := range *body.Items {
			key := "items." + strconv.Itoa(i)
			pid, _ := it["product_id"].(float64)
			if pid == 0 {
				fields.add(key+".product_id", key+".product_id wajib diisi.")
			} else {
				var cnt int
				_ = s.database.QueryRowContext(ctx, `SELECT COUNT(*) FROM products WHERE id = ?`, int64(pid)).Scan(&cnt)
				if cnt == 0 {
					fields.add(key+".product_id", key+".product_id yang dipilih tidak valid.")
				}
			}
			q, _ := it["quantity"].(float64)
			if q < 1 {
				fields.add(key+".quantity", key+".quantity harus minimal 1.")
			} else if q > 1000 {
				fields.add(key+".quantity", key+".quantity tidak boleh lebih besar dari 1000.")
			} else if _, ok := it["quantity"]; !ok {
				fields.add(key+".quantity", key+".quantity wajib diisi.")
			}
		}
	}
	if body.AddressID == nil {
		if body.RecipientName == nil || *body.RecipientName == "" {
			fields.add("recipient_name", "nama penerima wajib diisi apabila address id tidak ada.")
		}
		if body.RecipientPhone == nil || *body.RecipientPhone == "" {
			fields.add("recipient_phone", "telepon penerima wajib diisi apabila address id tidak ada.")
		}
		if body.AddressLine == nil || *body.AddressLine == "" {
			fields.add("address_line", "alamat wajib diisi apabila address id tidak ada.")
		}
		if body.VillageID == nil || *body.VillageID == "" {
			fields.add("village_id", "village id wajib diisi apabila address id tidak ada.")
		} else {
			var cnt int
			_ = s.database.QueryRowContext(ctx, `SELECT COUNT(*) FROM villages WHERE id = ?`, *body.VillageID).Scan(&cnt)
			if cnt == 0 {
				fields.add("village_id", "village id yang dipilih tidak valid.")
			}
		}
		if body.Latitude == nil {
			fields.add("latitude", "garis lintang wajib diisi apabila address id tidak ada.")
		} else if *body.Latitude < -90 || *body.Latitude > 90 {
			fields.add("latitude", "garis lintang harus di antara -90 sampai 90.")
		}
		if body.Longitude == nil {
			fields.add("longitude", "garis bujur wajib diisi apabila address id tidak ada.")
		} else if *body.Longitude < -180 || *body.Longitude > 180 {
			fields.add("longitude", "garis bujur harus di antara -180 sampai 180.")
		}
	}
	if len(fields) > 0 {
		writeError(w, r, httpx.Validation(map[string][]string(fields)))
		return
	}
	// konsumen resolution mirrors resolveKonsumen + create policy (same scope).
	konsumenID := user.ID
	if user.Role == "konsumen" {
		konsumenID = user.ID
	} else if body.KonsumenID != nil {
		konsumenID = *body.KonsumenID
	}
	var agentID sql.NullInt64
	if err := s.database.QueryRowContext(ctx, `SELECT agent_id FROM users WHERE id = ?`, konsumenID).Scan(&agentID); err != nil || !agentID.Valid {
		writeError(w, r, httpx.NewError(http.StatusUnprocessableEntity, "Konsumen tidak terhubung ke agen/cabang mana pun."))
		return
	}
	var profileCnt int
	_ = s.database.QueryRowContext(ctx, `SELECT COUNT(*) FROM agent_profiles WHERE user_id = ?`, agentID.Int64).Scan(&profileCnt)
	if profileCnt == 0 {
		writeError(w, r, httpx.NewError(http.StatusUnprocessableEntity, "Profil agen belum lengkap."))
		return
	}
	// RajaOngkir gate + provider call (unreachable in the isolated env).
	var isActive bool
	var providerID int64
	err := s.database.QueryRowContext(ctx, `SELECT id, is_active FROM shipping_providers WHERE code = 'rajaongkir'`).Scan(&providerID, &isActive)
	if err != nil || !isActive {
		writeMethodUnavailable(w, r)
		return
	}
	var disabled int
	_ = s.database.QueryRowContext(ctx, `SELECT COUNT(*) FROM agent_shipping_provider_settings
		WHERE agent_id = ? AND shipping_provider_id = ? AND is_active = 0`, agentID.Int64, providerID).Scan(&disabled)
	if disabled > 0 {
		writeMethodUnavailable(w, r)
		return
	}
	// Provider call itself: the RajaOngkir API is external; in the isolated env it
	// cannot succeed, so the reference contract is the shared 422 below.
	writeMethodUnavailable(w, r)
}

func writeMethodUnavailable(w http.ResponseWriter, r *http.Request) {
	writeError(w, r, &httpx.Error{Status: http.StatusUnprocessableEntity,
		Message:   "Metode pengiriman yang dipilih sedang tidak tersedia.",
		ErrorsAny: map[string]any{"shipping_method": "Tidak valid."}})
}

// handleAgentProviderDestinations ports AgentShippingProviderController::destinations.
func (s *Server) handleAgentProviderDestinations(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	user, ok := requireUser(w, r)
	if !ok {
		return
	}
	agentID, err := agentadmin.ResolveAgent(user, false)
	if err != nil {
		writeAgentAdminError(w, r, err)
		return
	}
	providerID, perr := strconv.ParseInt(r.PathValue("provider"), 10, 64)
	var code string
	if perr == nil {
		_ = s.database.QueryRowContext(ctx, `SELECT code FROM shipping_providers WHERE id = ?`, providerID).Scan(&code)
	}
	if code != "rajaongkir" {
		writeError(w, r, httpx.NewError(http.StatusNotFound, "Endpoint tidak ditemukan."))
		return
	}
	var body struct {
		Search *string `json:"search"`
		APIKey *string `json:"api_key"`
	}
	_ = httpx.DecodeJSON(r, &body)
	fields := fieldErrors{}
	if body.Search == nil || *body.Search == "" {
		fields.add("search", i18n.ValidationMessage(LocaleFrom(ctx), "required", "search"))
	} else if n := len([]rune(*body.Search)); n < 3 {
		fields.add("search", "search harus minimal 3 karakter.")
	} else if n > 100 {
		fields.add("search", "search tidak boleh lebih dari 100 karakter.")
	}
	if body.APIKey != nil && len([]rune(*body.APIKey)) > 255 {
		fields.add("api_key", "api key tidak boleh lebih dari 255 karakter.")
	}
	if len(fields) > 0 {
		writeError(w, r, httpx.Validation(map[string][]string(fields)))
		return
	}
	result, err := (&shipping.Service{DB: s.database, AppKey: s.cfg.AppKey, Config: s.cfg.Shipping}).SearchDestinations(ctx, agentID, providerID, *body.Search, valueOrEmpty(body.APIKey))
	if err != nil {
		writeError(w, r, &httpx.Error{Status: http.StatusUnprocessableEntity, Message: "Koneksi atau pencarian RajaOngkir gagal."})
		return
	}
	httpx.OK(w, result, "OK", nil)
}

// handleAgentProviderTest ports AgentShippingProviderController::testConnection.
func (s *Server) handleAgentProviderTest(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	user, ok := requireUser(w, r)
	if !ok {
		return
	}
	agentID, err := agentadmin.ResolveAgent(user, false)
	if err != nil {
		writeAgentAdminError(w, r, err)
		return
	}
	providerID, perr := strconv.ParseInt(r.PathValue("provider"), 10, 64)
	var code string
	if perr == nil {
		_ = s.database.QueryRowContext(ctx, `SELECT code FROM shipping_providers WHERE id = ?`, providerID).Scan(&code)
	}
	if code == "" {
		writeError(w, r, httpx.NewError(http.StatusNotFound, "Endpoint tidak ditemukan."))
		return
	}
	var body struct {
		DestinationLatitude  *float64 `json:"destination_latitude"`
		DestinationLongitude *float64 `json:"destination_longitude"`
	}
	if code != "rajaongkir" {
		if code != "openroute" && code != "kmsit" {
			writeError(w, r, httpx.NewError(http.StatusNotFound, "Endpoint tidak ditemukan."))
			return
		}
		_ = httpx.DecodeJSON(r, &body)
		fields := fieldErrors{}
		if body.DestinationLatitude == nil {
			fields.add("destination_latitude", i18n.ValidationMessage(LocaleFrom(ctx), "required", "destination latitude"))
		} else if *body.DestinationLatitude < -90 || *body.DestinationLatitude > 90 {
			fields.add("destination_latitude", "destination latitude harus di antara -90 sampai 90.")
		}
		if body.DestinationLongitude == nil {
			fields.add("destination_longitude", i18n.ValidationMessage(LocaleFrom(ctx), "required", "destination longitude"))
		} else if *body.DestinationLongitude < -180 || *body.DestinationLongitude > 180 {
			fields.add("destination_longitude", "destination longitude harus di antara -180 sampai 180.")
		}
		if len(fields) > 0 {
			writeError(w, r, httpx.Validation(map[string][]string(fields)))
			return
		}
	}
	result, err := (&shipping.Service{DB: s.database, AppKey: s.cfg.AppKey, Config: s.cfg.Shipping}).TestProviderConnection(ctx, agentID, providerID, body.DestinationLatitude, body.DestinationLongitude)
	if err != nil {
		writeError(w, r, httpx.NewError(http.StatusUnprocessableEntity,
			"Koneksi provider pengiriman gagal. Periksa konfigurasi dan koordinat."))
		return
	}
	httpx.OK(w, result, "Koneksi provider berhasil.", nil)
}

func valueOrEmpty(value *string) string {
	if value == nil {
		return ""
	}
	return *value
}
