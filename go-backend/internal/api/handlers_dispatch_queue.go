package api

import (
	"net/http"

	"github.com/kmsitcomputer/primeclassy-go/internal/httpx"
)

func (s *Server) handleDispatchQueue(w http.ResponseWriter, r *http.Request) {
	result, err := s.dispatchService().Queue(r.Context(), dispatchActor(r), r.URL.Query())
	if err != nil {
		writeDispatchError(w, r, err)
		return
	}
	httpx.OK(w, result["data"], "OK", result["meta"])
}
