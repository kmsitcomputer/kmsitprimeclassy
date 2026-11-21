package api

import (
	"context"
	"crypto"
	"crypto/rand"
	"crypto/rsa"
	"crypto/sha256"
	"crypto/x509"
	"encoding/base64"
	"encoding/json"
	"encoding/pem"
	"io"
	"net/http"
	"net/url"
	"os"
	"strings"
	"time"
)

type googleSheetsAPIError struct{ Code, Message string }
type googleSheetsSheet struct {
	ID             int64  `json:"sheetId"`
	Title          string `json:"title"`
	GridProperties struct {
		ColumnCount int `json:"columnCount"`
	} `json:"gridProperties"`
}
type googleSheetsMetadata struct {
	Title  string              `json:"title"`
	Sheets []googleSheetsSheet `json:"sheets"`
}

func (s *Server) googleSheetsMetadata(r *http.Request, spreadsheet string) (googleSheetsMetadata, *googleSheetsAPIError) {
	return s.googleSheetsMetadataAt(r, spreadsheet, "https://sheets.googleapis.com/v4")
}

func (s *Server) googleSheetsMetadataAt(r *http.Request, spreadsheet, base string) (googleSheetsMetadata, *googleSheetsAPIError) {
	if !s.cfg.GoogleSheets.Enabled {
		return googleSheetsMetadata{}, &googleSheetsAPIError{"INTEGRATION_DISABLED", "Integrasi Google Sheets belum diaktifkan."}
	}
	token, code, message := s.googleSheetsToken(r.Context())
	if code != "" {
		return googleSheetsMetadata{}, &googleSheetsAPIError{code, message}
	}
	return s.googleSheetsMetadataWithToken(r, spreadsheet, base, token)
}

func (s *Server) googleSheetsMetadataWithToken(r *http.Request, spreadsheet, base, token string) (googleSheetsMetadata, *googleSheetsAPIError) {
	base = strings.TrimRight(base, "/")
	endpoint := base + "/spreadsheets/" + url.PathEscape(spreadsheet) + "?fields=properties.title,sheets.properties"
	timeout := s.cfg.GoogleSheets.Timeout
	if timeout <= 0 {
		timeout = 30 * time.Second
	}
	ctx, cancel := context.WithTimeout(r.Context(), timeout)
	defer cancel()
	request, err := http.NewRequestWithContext(ctx, http.MethodGet, endpoint, nil)
	if err != nil {
		return googleSheetsMetadata{}, &googleSheetsAPIError{"INVALID_REQUEST", "Google Sheets API menolak format atau jangkauan permintaan sinkronisasi."}
	}
	request.Header.Set("Authorization", "Bearer "+token)
	response, err := http.DefaultClient.Do(request)
	if err != nil {
		return googleSheetsMetadata{}, &googleSheetsAPIError{"NETWORK_ERROR", "Tidak dapat terhubung ke Google Sheets API."}
	}
	defer response.Body.Close()
	body, err := io.ReadAll(io.LimitReader(response.Body, 4<<20))
	if err != nil {
		return googleSheetsMetadata{}, &googleSheetsAPIError{"NETWORK_ERROR", "Tidak dapat terhubung ke Google Sheets API."}
	}
	if response.StatusCode < 200 || response.StatusCode >= 300 {
		return googleSheetsMetadata{}, mapGoogleSheetsError(response.StatusCode, body)
	}
	var payload struct {
		Properties struct {
			Title string `json:"title"`
		} `json:"properties"`
		Sheets []struct {
			Properties googleSheetsSheet `json:"properties"`
		} `json:"sheets"`
	}
	if json.Unmarshal(body, &payload) != nil {
		return googleSheetsMetadata{}, &googleSheetsAPIError{"UNKNOWN_GOOGLE_API_ERROR", "Google Sheets API mengembalikan kegagalan yang tidak dikenal."}
	}
	metadata := googleSheetsMetadata{Title: payload.Properties.Title}
	for _, sheet := range payload.Sheets {
		metadata.Sheets = append(metadata.Sheets, sheet.Properties)
	}
	return metadata, nil
}

func (s *Server) googleSheetsToken(ctx context.Context) (string, string, string) {
	path := s.cfg.GoogleSheets.CredentialsPath
	if path == "" {
		if s.cfg.StorageRoot != "" {
			path = s.cfg.StorageRoot + "/private/google/service-account.json"
		} else {
			path = "/app/storage/app/private/google/service-account.json"
		}
	}
	content, err := os.ReadFile(path)
	if err != nil {
		return "", "CREDENTIAL_NOT_FOUND", "File credential Google tidak ditemukan atau tidak dapat dibaca."
	}
	var credential struct {
		Type        string `json:"type"`
		ProjectID   string `json:"project_id"`
		ClientEmail string `json:"client_email"`
		PrivateKey  string `json:"private_key"`
		TokenURI    string `json:"token_uri"`
	}
	if json.Unmarshal(content, &credential) != nil {
		return "", "INVALID_CREDENTIAL", "Credential Service Account bukan JSON yang valid."
	}
	if credential.Type != "service_account" || credential.ProjectID == "" || credential.ClientEmail == "" || credential.PrivateKey == "" {
		return "", "INVALID_CREDENTIAL", "Credential Service Account tidak valid."
	}
	block, _ := pem.Decode([]byte(credential.PrivateKey))
	if block == nil {
		return "", "AUTHENTICATION_ERROR", "Google menolak autentikasi Service Account."
	}
	var key *rsa.PrivateKey
	if parsed, parseErr := x509.ParsePKCS8PrivateKey(block.Bytes); parseErr == nil {
		key, _ = parsed.(*rsa.PrivateKey)
	} else if parsed, parseErr := x509.ParsePKCS1PrivateKey(block.Bytes); parseErr == nil {
		key = parsed
	}
	if key == nil {
		return "", "AUTHENTICATION_ERROR", "Google menolak autentikasi Service Account."
	}
	now := time.Now().Unix()
	claims := map[string]any{"iss": credential.ClientEmail, "scope": "https://www.googleapis.com/auth/spreadsheets", "aud": credential.TokenURI, "iat": now, "exp": now + 3600}
	if credential.TokenURI == "" {
		claims["aud"] = "https://oauth2.googleapis.com/token"
	}
	header := base64.RawURLEncoding.EncodeToString([]byte(`{"alg":"RS256","typ":"JWT"}`))
	claimBytes, _ := json.Marshal(claims)
	payload := base64.RawURLEncoding.EncodeToString(claimBytes)
	unsigned := header + "." + payload
	digest := sha256.Sum256([]byte(unsigned))
	signature, err := rsa.SignPKCS1v15(rand.Reader, key, crypto.SHA256, digest[:])
	if err != nil {
		return "", "AUTHENTICATION_ERROR", "Google menolak autentikasi Service Account."
	}
	assertion := unsigned + "." + base64.RawURLEncoding.EncodeToString(signature)
	tokenURI := credential.TokenURI
	if tokenURI == "" {
		tokenURI = "https://oauth2.googleapis.com/token"
	}
	form := url.Values{"grant_type": {"urn:ietf:params:oauth:grant-type:jwt-bearer"}, "assertion": {assertion}}
	timeout := s.cfg.GoogleSheets.Timeout
	if timeout <= 0 {
		timeout = 30 * time.Second
	}
	requestCtx, cancel := context.WithTimeout(ctx, timeout)
	defer cancel()
	request, err := http.NewRequestWithContext(requestCtx, http.MethodPost, tokenURI, strings.NewReader(form.Encode()))
	if err != nil {
		return "", "AUTHENTICATION_ERROR", "Google menolak autentikasi Service Account."
	}
	request.Header.Set("Content-Type", "application/x-www-form-urlencoded")
	response, err := http.DefaultClient.Do(request)
	if err != nil {
		return "", "AUTHENTICATION_ERROR", "Google menolak autentikasi Service Account."
	}
	defer response.Body.Close()
	body, err := io.ReadAll(io.LimitReader(response.Body, 1<<20))
	if err != nil || response.StatusCode < 200 || response.StatusCode >= 300 {
		return "", "AUTHENTICATION_ERROR", "Google menolak autentikasi Service Account."
	}
	var token struct {
		AccessToken string `json:"access_token"`
	}
	if json.Unmarshal(body, &token) != nil || token.AccessToken == "" {
		return "", "AUTHENTICATION_ERROR", "Google menolak autentikasi Service Account."
	}
	return token.AccessToken, "", ""
}

func mapGoogleSheetsError(status int, body []byte) *googleSheetsAPIError {
	var payload struct {
		Error struct {
			Status  string `json:"status"`
			Details []struct {
				Reason string `json:"reason"`
			} `json:"details"`
			Errors []struct {
				Reason string `json:"reason"`
			} `json:"errors"`
		} `json:"error"`
	}
	_ = json.Unmarshal(body, &payload)
	reason := ""
	if len(payload.Error.Details) > 0 {
		reason = payload.Error.Details[0].Reason
	}
	if reason == "" && len(payload.Error.Errors) > 0 {
		reason = payload.Error.Errors[0].Reason
	}
	if reason == "" {
		reason = payload.Error.Status
	}
	switch {
	case status == http.StatusUnauthorized:
		return &googleSheetsAPIError{"AUTHENTICATION_ERROR", "Google menolak autentikasi Service Account."}
	case status == http.StatusForbidden && (strings.EqualFold(reason, "SERVICE_DISABLED") || strings.EqualFold(reason, "ACCESS_NOT_CONFIGURED") || strings.EqualFold(reason, "API_DISABLED")):
		return &googleSheetsAPIError{"API_DISABLED", "Google Sheets API belum aktif pada Google Cloud Project."}
	case status == http.StatusForbidden:
		return &googleSheetsAPIError{"PERMISSION_DENIED", "Spreadsheet belum dibagikan ke Service Account atau aksesnya ditolak."}
	case status == http.StatusNotFound:
		return &googleSheetsAPIError{"SPREADSHEET_NOT_FOUND", "Spreadsheet tidak ditemukan atau tidak dapat diakses oleh Service Account."}
	case status == http.StatusTooManyRequests:
		return &googleSheetsAPIError{"RATE_LIMIT", "Batas permintaan Google Sheets API tercapai."}
	case status == http.StatusBadRequest:
		return &googleSheetsAPIError{"INVALID_REQUEST", "Google Sheets menolak format atau jangkauan permintaan sinkronisasi."}
	default:
		return &googleSheetsAPIError{"UNKNOWN_GOOGLE_API_ERROR", "Google Sheets API mengembalikan kegagalan yang tidak dikenal."}
	}
}
