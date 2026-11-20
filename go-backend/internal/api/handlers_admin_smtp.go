package api

import (
	"net/http"

	"github.com/kmsitcomputer/primeclassy-go/internal/admininfra"
	"github.com/kmsitcomputer/primeclassy-go/internal/httpx"
)

func (s *Server) handleAdminSMTPSettingsShow(w http.ResponseWriter, r *http.Request) {
	data, err := admininfra.New(s.database, s.cfg.AppKey).Snapshot(r.Context(), "smtp")
	if err != nil {
		writeError(w, r, err)
		return
	}
	httpx.OK(w, data, "OK", nil)
}
