package api

import (
	"net/http"

	"github.com/kmsitcomputer/primeclassy-go/internal/auth"
	"github.com/kmsitcomputer/primeclassy-go/internal/httpx"
	"github.com/kmsitcomputer/primeclassy-go/internal/i18n"
	"github.com/kmsitcomputer/primeclassy-go/internal/installer"
)

func (s *Server) installerService() *installer.Service {
	return installer.New(s.installLock, ".env")
}

// installNotInstalled mirrors EnsureNotInstalled: 403 once the lock file exists.
func (s *Server) installNotInstalled(w http.ResponseWriter, r *http.Request) bool {
	if s.installerService().IsInstalled() {
		writeError(w, r, httpx.NewError(http.StatusForbidden, "Aplikasi sudah terinstall."))
		return false
	}
	return true
}

func (s *Server) handleInstallRequirements(w http.ResponseWriter, r *http.Request) {
	if !s.installNotInstalled(w, r) {
		return
	}
	httpx.OK(w, s.installerService().Requirements(), "OK", nil)
}

func (s *Server) handleInstallFinalize(w http.ResponseWriter, r *http.Request) {
	if !s.installNotInstalled(w, r) {
		return
	}
	httpx.OK(w, s.installerService().Finalize(r.Context(), s.database.DB), "Finalisasi berhasil, cache aplikasi sudah dioptimalkan.", nil)
}

func (s *Server) handleInstallLock(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	if !s.installNotInstalled(w, r) {
		return
	}
	if !installer.HasSuperAdmin(ctx, s.database.DB) {
		writeError(w, r, httpx.NewError(http.StatusUnprocessableEntity, "Belum bisa dikunci — akun Super Admin belum dibuat."))
		return
	}
	if err := s.installerService().MarkInstalled(); err != nil {
		writeError(w, r, err)
		return
	}
	httpx.OK(w, nil, "Instalasi berhasil dikunci. Halaman installer tidak dapat diakses lagi.", nil)
}

func (s *Server) handleInstallDatabaseTest(w http.ResponseWriter, r *http.Request) {
	if !s.installNotInstalled(w, r) {
		return
	}
	host, port, database, username, password, ok := s.installDatabaseBody(w, r)
	if !ok {
		return
	}
	if err := s.installerService().TestConnection(host, port, database, username, password); err != nil {
		writeError(w, r, httpx.NewError(http.StatusUnprocessableEntity, err.Error()))
		return
	}
	httpx.OK(w, nil, "Koneksi database berhasil.", nil)
}

func (s *Server) handleInstallDatabaseSave(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	if !s.installNotInstalled(w, r) {
		return
	}
	// Defence in depth: a super_admin already existing means a live install.
	if installer.HasSuperAdmin(ctx, s.database.DB) {
		writeError(w, r, httpx.NewError(http.StatusForbidden, "Aplikasi sudah terinstall."))
		return
	}
	host, port, database, username, password, ok := s.installDatabaseBody(w, r)
	if !ok {
		return
	}
	if err := s.installerService().SaveDatabaseConfig(host, port, database, username, password); err != nil {
		writeError(w, r, httpx.NewError(http.StatusUnprocessableEntity, err.Error()))
		return
	}
	httpx.OK(w, nil, "Konfigurasi database berhasil disimpan.", nil)
}

func (s *Server) handleInstallConfigureApp(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	if !s.installNotInstalled(w, r) {
		return
	}
	if installer.HasSuperAdmin(ctx, s.database.DB) {
		writeError(w, r, httpx.NewError(http.StatusForbidden, "Aplikasi sudah terinstall."))
		return
	}
	var body struct {
		AppName     *string `json:"app_name"`
		AppURL      *string `json:"app_url"`
		FrontendURL *string `json:"frontend_url"`
	}
	_ = httpx.DecodeJSON(r, &body)
	fields := fieldErrors{}
	if body.AppName == nil || *body.AppName == "" {
		fields.add("app_name", i18n.ValidationMessage(LocaleFrom(ctx), "required", "app_name"))
	} else if len([]rune(*body.AppName)) > 100 {
		fields.add("app_name", i18n.ValidationMessage(LocaleFrom(ctx), "max.string", "app_name", "max", "100"))
	}
	if body.AppURL == nil || *body.AppURL == "" {
		fields.add("app_url", i18n.ValidationMessage(LocaleFrom(ctx), "required", "app_url"))
	}
	if body.FrontendURL == nil || *body.FrontendURL == "" {
		fields.add("frontend_url", i18n.ValidationMessage(LocaleFrom(ctx), "required", "frontend_url"))
	}
	if len(fields) > 0 {
		writeError(w, r, httpx.Validation(map[string][]string(fields)))
		return
	}
	if err := s.installerService().ConfigureApp(*body.AppName, *body.AppURL, *body.FrontendURL); err != nil {
		writeError(w, r, err)
		return
	}
	httpx.OK(w, nil, "Konfigurasi aplikasi berhasil disimpan.", nil)
}

func (s *Server) installDatabaseBody(w http.ResponseWriter, r *http.Request) (string, int64, string, string, string, bool) {
	ctx := r.Context()
	var body struct {
		Host     *string `json:"host"`
		Port     *int64  `json:"port"`
		Database *string `json:"database"`
		Username *string `json:"username"`
		Password *string `json:"password"`
	}
	_ = httpx.DecodeJSON(r, &body)
	fields := fieldErrors{}
	if body.Host == nil || *body.Host == "" {
		fields.add("host", i18n.ValidationMessage(LocaleFrom(ctx), "required", "host"))
	} else if len([]rune(*body.Host)) > 255 {
		fields.add("host", i18n.ValidationMessage(LocaleFrom(ctx), "max.string", "host", "max", "255"))
	}
	if body.Port == nil {
		fields.add("port", i18n.ValidationMessage(LocaleFrom(ctx), "required", "port"))
	} else if *body.Port < 1 || *body.Port > 65535 {
		fields.add("port", "port harus berupa angka antara 1 dan 65535.")
	}
	if body.Database == nil || *body.Database == "" {
		fields.add("database", i18n.ValidationMessage(LocaleFrom(ctx), "required", "database"))
	} else if len([]rune(*body.Database)) > 64 {
		fields.add("database", i18n.ValidationMessage(LocaleFrom(ctx), "max.string", "database", "max", "64"))
	}
	if body.Username == nil || *body.Username == "" {
		fields.add("username", i18n.ValidationMessage(LocaleFrom(ctx), "required", "username"))
	} else if len([]rune(*body.Username)) > 255 {
		fields.add("username", i18n.ValidationMessage(LocaleFrom(ctx), "max.string", "username", "max", "255"))
	}
	if body.Password != nil && len([]rune(*body.Password)) > 255 {
		fields.add("password", i18n.ValidationMessage(LocaleFrom(ctx), "max.string", "password", "max", "255"))
	}
	if len(fields) > 0 {
		writeError(w, r, httpx.Validation(map[string][]string(fields)))
		return "", 0, "", "", "", false
	}
	password := ""
	if body.Password != nil {
		password = *body.Password
	}
	return *body.Host, *body.Port, *body.Database, *body.Username, password, true
}

// handleInstallRun mirrors InstallController::run: the wizard's one-shot Super Admin
// creation step. On a database that already has a super_admin (any live install, and
// this testing env) it is refused — exactly the reference's own defence in depth.
func (s *Server) handleInstallRun(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	if !s.installNotInstalled(w, r) {
		return
	}
	var roleID int64
	if err := s.database.QueryRowContext(ctx, `SELECT id FROM roles WHERE slug = 'super_admin'`).Scan(&roleID); err != nil {
		writeError(w, r, httpx.NewError(http.StatusUnprocessableEntity, "Jalankan migrasi database terlebih dahulu."))
		return
	}
	if installer.HasSuperAdmin(ctx, s.database.DB) {
		writeError(w, r, httpx.NewError(http.StatusUnprocessableEntity, "Akun Super Admin sudah ada."))
		return
	}
	var body struct {
		Name                 *string `json:"name"`
		Email                *string `json:"email"`
		Phone                *string `json:"phone"`
		Password             *string `json:"password"`
		PasswordConfirmation *string `json:"password_confirmation"`
	}
	_ = httpx.DecodeJSON(r, &body)
	fields := fieldErrors{}
	if body.Name == nil || *body.Name == "" {
		fields.add("name", i18n.ValidationMessage(LocaleFrom(ctx), "required", "name"))
	} else if len([]rune(*body.Name)) > 150 {
		fields.add("name", i18n.ValidationMessage(LocaleFrom(ctx), "max.string", "name", "max", "150"))
	}
	if body.Email == nil || *body.Email == "" {
		fields.add("email", i18n.ValidationMessage(LocaleFrom(ctx), "required", "email"))
	}
	if body.Phone == nil || *body.Phone == "" {
		fields.add("phone", i18n.ValidationMessage(LocaleFrom(ctx), "required", "phone"))
	} else if len([]rune(*body.Phone)) > 20 {
		fields.add("phone", i18n.ValidationMessage(LocaleFrom(ctx), "max.string", "phone", "max", "20"))
	}
	if body.Password == nil || *body.Password == "" {
		fields.add("password", i18n.ValidationMessage(LocaleFrom(ctx), "required", "password"))
	} else if len([]rune(*body.Password)) < 8 {
		fields.add("password", i18n.ValidationMessage(LocaleFrom(ctx), "min.string", "password", "min", "8"))
	}
	if body.PasswordConfirmation == nil || *body.PasswordConfirmation == "" ||
		(body.Password != nil && *body.PasswordConfirmation != *body.Password) {
		fields.add("password_confirmation", i18n.ValidationMessage(LocaleFrom(ctx), "confirmed", "password"))
	}
	if len(fields) > 0 {
		writeError(w, r, httpx.Validation(map[string][]string(fields)))
		return
	}
	hash, herr := auth.HashPassword(*body.Password)
	if herr != nil {
		writeError(w, r, herr)
		return
	}
	res, ierr := s.database.ExecContext(ctx, `INSERT INTO users (role_id, name, email, phone, password, status,
		created_at, updated_at) VALUES (?, ?, ?, ?, ?, 'active', UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))`,
		roleID, *body.Name, *body.Email, *body.Phone, hash)
	if ierr != nil {
		writeError(w, r, ierr)
		return
	}
	newID, _ := res.LastInsertId()
	httpx.Created(w, map[string]any{"id": newID, "name": *body.Name, "email": *body.Email},
		"Akun Super Admin berhasil dibuat. Instalasi selesai.")
}

var _ = i18n.Translate
