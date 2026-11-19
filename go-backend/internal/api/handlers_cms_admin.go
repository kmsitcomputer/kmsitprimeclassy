package api

import (
	"errors"
	"net/http"

	"github.com/kmsitcomputer/primeclassy-go/internal/auth"
	"github.com/kmsitcomputer/primeclassy-go/internal/cms"
	"github.com/kmsitcomputer/primeclassy-go/internal/db"
	"github.com/kmsitcomputer/primeclassy-go/internal/httpx"
)

// registerCMSProfileRoutes is called by the Domain E route aggregator. The
// migration owner keeps server.go and generated route inventory changes separate.
func (s *Server) registerCMSProfileRoutes() {
	s.mux.HandleFunc("GET /api/v1/referral/{code}", s.handleReferralPreview)
	s.mux.Handle("PATCH /api/v1/profile", chain(http.HandlerFunc(s.handleProfileUpdate), RequireAuth, s.RequireAgentLinked))
	s.mux.Handle("GET /api/v1/profile/email/verification", chain(http.HandlerFunc(s.handleEmailVerificationState), RequireAuth, s.RequireAgentLinked))
	s.mux.Handle("PATCH /api/v1/profile/referral-code", chain(http.HandlerFunc(s.handleProfileReferralCodeUpdate),
		RequireAuth, s.RequireAgentLinked, RequireRole("agen,korsal,sales,sales-kurir-sub")))
	s.mux.Handle("POST /api/v1/profile/referral-code/regenerate", chain(http.HandlerFunc(s.handleProfileReferralCodeRegenerate),
		RequireAuth, s.RequireAgentLinked, RequireRole("agen,korsal,sales,sales-kurir-sub")))
	s.mux.Handle("DELETE /api/v1/profile/referral-code", chain(http.HandlerFunc(s.handleProfileReferralCodeDelete),
		RequireAuth, s.RequireAgentLinked, RequireRole("agen,korsal,sales,sales-kurir-sub")))

	s.mux.Handle("GET /api/v1/cms/homepage-blocks", chain(http.HandlerFunc(s.handleCMSBlockIndex), RequireAuth, s.RequireAgentLinked, RequireRole("super_admin")))
	s.mux.Handle("POST /api/v1/cms/homepage-blocks", chain(http.HandlerFunc(s.handleCMSBlockStore), RequireAuth, s.RequireAgentLinked, RequireRole("super_admin")))
	s.mux.Handle("PATCH /api/v1/cms/homepage-blocks/reorder", chain(http.HandlerFunc(s.handleCMSBlockReorder), RequireAuth, s.RequireAgentLinked, RequireRole("super_admin")))
	s.mux.Handle("PATCH /api/v1/cms/homepage-blocks/{block}", chain(http.HandlerFunc(s.handleCMSBlockUpdate), RequireAuth, s.RequireAgentLinked, RequireRole("super_admin")))
	s.mux.Handle("PATCH /api/v1/cms/homepage-blocks/{block}/toggle", chain(http.HandlerFunc(s.handleCMSBlockToggle), RequireAuth, s.RequireAgentLinked, RequireRole("super_admin")))
	s.mux.Handle("DELETE /api/v1/cms/homepage-blocks/{block}", chain(http.HandlerFunc(s.handleCMSBlockDestroy), RequireAuth, s.RequireAgentLinked, RequireRole("super_admin")))

	s.mux.Handle("GET /api/v1/cms/articles", chain(http.HandlerFunc(s.handleCMSArticleIndex), RequireAuth, s.RequireAgentLinked, RequireRole("super_admin")))
	s.mux.Handle("POST /api/v1/cms/articles", chain(http.HandlerFunc(s.handleCMSArticleStore), RequireAuth, s.RequireAgentLinked, RequireRole("super_admin")))
	s.mux.Handle("PATCH /api/v1/cms/articles/{article}", chain(http.HandlerFunc(s.handleCMSArticleUpdate), RequireAuth, s.RequireAgentLinked, RequireRole("super_admin")))
	s.mux.Handle("DELETE /api/v1/cms/articles/{article}", chain(http.HandlerFunc(s.handleCMSArticleDestroy), RequireAuth, s.RequireAgentLinked, RequireRole("super_admin")))

	s.mux.Handle("GET /api/v1/cms/pages", chain(http.HandlerFunc(s.handleCMSPageIndex), RequireAuth, s.RequireAgentLinked, RequireRole("super_admin")))
	s.mux.Handle("POST /api/v1/cms/pages", chain(http.HandlerFunc(s.handleCMSPageStore), RequireAuth, s.RequireAgentLinked, RequireRole("super_admin")))
	s.mux.Handle("PATCH /api/v1/cms/pages/{page}", chain(http.HandlerFunc(s.handleCMSPageUpdate), RequireAuth, s.RequireAgentLinked, RequireRole("super_admin")))
	s.mux.Handle("DELETE /api/v1/cms/pages/{page}", chain(http.HandlerFunc(s.handleCMSPageDestroy), RequireAuth, s.RequireAgentLinked, RequireRole("super_admin")))
}

func (s *Server) requireCMSAdmin(w http.ResponseWriter, r *http.Request) bool {
	u := UserFrom(r.Context())
	if u == nil {
		writeError(w, r, unauthorizedError(r.Context()))
		return false
	}
	if !u.IsRole(auth.RoleSuperAdmin) {
		writeError(w, r, forbiddenError(r.Context()))
		return false
	}
	return true
}

func (s *Server) handleCMSArticleIndex(w http.ResponseWriter, r *http.Request) {
	if !s.requireCMSAdmin(w, r) {
		return
	}
	ctx := r.Context()
	typ, status := httpx.QueryString(r, "type"), httpx.QueryString(r, "status")
	if typ != "" && typ != "article" && typ != "news" {
		writeError(w, r, httpx.Validation(map[string][]string{"type": invalidChoice(ctx, "type")}))
		return
	}
	if status != "" && status != "draft" && status != "published" {
		writeError(w, r, httpx.Validation(map[string][]string{"status": invalidChoice(ctx, "status")}))
		return
	}
	page := httpx.QueryInt(r, "page", 1, 1)
	perPage := httpx.QueryInt(r, "per_page", 15, 1)
	result, err := cms.AdminArticles(ctx, s.database, typ, status, page, perPage)
	if err != nil {
		writeError(w, r, err)
		return
	}
	httpx.OK(w, result.Items, "OK", httpx.NewMeta(result.Page, result.LastPage, result.Total))
}

func (s *Server) handleCMSArticleStore(w http.ResponseWriter, r *http.Request) {
	if !s.requireCMSAdmin(w, r) {
		return
	}
	u, _ := requireUser(w, r)
	var input cms.ArticleInput
	if err := httpx.DecodeJSON(r, &input); err != nil {
		writeError(w, r, err)
		return
	}
	result, err := cms.CreateArticle(r.Context(), s.database, input, u.ID)
	if db.IsDuplicateKey(err) {
		writeError(w, r, httpx.Validation(map[string][]string{"slug": uniqueField(r.Context(), "slug")}))
		return
	}
	if err != nil {
		writeError(w, r, err)
		return
	}
	httpx.Created(w, result, "Created")
}

func (s *Server) handleCMSArticleUpdate(w http.ResponseWriter, r *http.Request) {
	if !s.requireCMSAdmin(w, r) {
		return
	}
	id, ok := pathID(w, r, "article")
	if !ok {
		return
	}
	var input cms.ArticleInput
	if err := httpx.DecodeJSON(r, &input); err != nil {
		writeError(w, r, err)
		return
	}
	result, err := cms.UpdateArticle(r.Context(), s.database, id, input)
	if db.IsDuplicateKey(err) {
		writeError(w, r, httpx.Validation(map[string][]string{"slug": uniqueField(r.Context(), "slug")}))
		return
	}
	if errors.Is(err, cms.ErrAdminContentNotFound) {
		writeError(w, r, notFoundError(r.Context()))
		return
	}
	if err != nil {
		writeError(w, r, err)
		return
	}
	httpx.OK(w, result, "OK", nil)
}

func (s *Server) handleCMSArticleDestroy(w http.ResponseWriter, r *http.Request) {
	if !s.requireCMSAdmin(w, r) {
		return
	}
	id, ok := pathID(w, r, "article")
	if !ok {
		return
	}
	err := cms.DeleteArticle(r.Context(), s.database, id)
	if errors.Is(err, cms.ErrAdminContentNotFound) {
		writeError(w, r, notFoundError(r.Context()))
		return
	}
	if err != nil {
		writeError(w, r, err)
		return
	}
	httpx.OK(w, nil, msg(r.Context(), "cms.article_deleted"), nil)
}

func (s *Server) handleCMSPageIndex(w http.ResponseWriter, r *http.Request) {
	if !s.requireCMSAdmin(w, r) {
		return
	}
	ctx := r.Context()
	status := httpx.QueryString(r, "status")
	if status != "" && status != "draft" && status != "published" {
		writeError(w, r, httpx.Validation(map[string][]string{"status": invalidChoice(ctx, "status")}))
		return
	}
	result, err := cms.AdminPages(ctx, s.database, status,
		httpx.QueryInt(r, "page", 1, 1), httpx.QueryInt(r, "per_page", 15, 1))
	if err != nil {
		writeError(w, r, err)
		return
	}
	httpx.OK(w, result.Items, "OK", httpx.NewMeta(result.Page, result.LastPage, result.Total))
}

func (s *Server) handleCMSPageStore(w http.ResponseWriter, r *http.Request) {
	if !s.requireCMSAdmin(w, r) {
		return
	}
	var input cms.PageInput
	if err := httpx.DecodeJSON(r, &input); err != nil {
		writeError(w, r, err)
		return
	}
	result, err := cms.CreatePage(r.Context(), s.database, input)
	if db.IsDuplicateKey(err) {
		writeError(w, r, httpx.Validation(map[string][]string{"slug": uniqueField(r.Context(), "slug")}))
		return
	}
	if err != nil {
		writeError(w, r, err)
		return
	}
	httpx.Created(w, result, "Created")
}

func (s *Server) handleCMSPageUpdate(w http.ResponseWriter, r *http.Request) {
	if !s.requireCMSAdmin(w, r) {
		return
	}
	id, ok := pathID(w, r, "page")
	if !ok {
		return
	}
	var input cms.PageInput
	if err := httpx.DecodeJSON(r, &input); err != nil {
		writeError(w, r, err)
		return
	}
	result, err := cms.UpdatePage(r.Context(), s.database, id, input)
	if db.IsDuplicateKey(err) {
		writeError(w, r, httpx.Validation(map[string][]string{"slug": uniqueField(r.Context(), "slug")}))
		return
	}
	if errors.Is(err, cms.ErrAdminContentNotFound) {
		writeError(w, r, notFoundError(r.Context()))
		return
	}
	if err != nil {
		writeError(w, r, err)
		return
	}
	httpx.OK(w, result, "OK", nil)
}

func (s *Server) handleCMSPageDestroy(w http.ResponseWriter, r *http.Request) {
	if !s.requireCMSAdmin(w, r) {
		return
	}
	id, ok := pathID(w, r, "page")
	if !ok {
		return
	}
	err := cms.DeletePage(r.Context(), s.database, id)
	if errors.Is(err, cms.ErrAdminContentNotFound) {
		writeError(w, r, notFoundError(r.Context()))
		return
	}
	if err != nil {
		writeError(w, r, err)
		return
	}
	httpx.OK(w, nil, msg(r.Context(), "cms.page_deleted"), nil)
}

func (s *Server) handleCMSBlockIndex(w http.ResponseWriter, r *http.Request) {
	if !s.requireCMSAdmin(w, r) {
		return
	}
	result, err := cms.AdminBlocks(r.Context(), s.database)
	if err != nil {
		writeError(w, r, err)
		return
	}
	httpx.OK(w, result, "OK", nil)
}

func (s *Server) handleCMSBlockStore(w http.ResponseWriter, r *http.Request) {
	if !s.requireCMSAdmin(w, r) {
		return
	}
	var input cms.BlockInput
	if err := httpx.DecodeJSON(r, &input); err != nil {
		writeError(w, r, err)
		return
	}
	result, err := cms.CreateBlock(r.Context(), s.database, input)
	if err != nil {
		writeError(w, r, err)
		return
	}
	httpx.Created(w, result, "Created")
}

func (s *Server) handleCMSBlockUpdate(w http.ResponseWriter, r *http.Request) {
	if !s.requireCMSAdmin(w, r) {
		return
	}
	id, ok := pathID(w, r, "block")
	if !ok {
		return
	}
	var input cms.BlockInput
	if err := httpx.DecodeJSON(r, &input); err != nil {
		writeError(w, r, err)
		return
	}
	result, err := cms.UpdateBlock(r.Context(), s.database, id, input)
	if errors.Is(err, cms.ErrAdminContentNotFound) {
		writeError(w, r, notFoundError(r.Context()))
		return
	}
	if err != nil {
		writeError(w, r, err)
		return
	}
	httpx.OK(w, result, "OK", nil)
}

func (s *Server) handleCMSBlockToggle(w http.ResponseWriter, r *http.Request) {
	if !s.requireCMSAdmin(w, r) {
		return
	}
	id, ok := pathID(w, r, "block")
	if !ok {
		return
	}
	result, err := cms.ToggleBlock(r.Context(), s.database, id)
	if errors.Is(err, cms.ErrAdminContentNotFound) {
		writeError(w, r, notFoundError(r.Context()))
		return
	}
	if err != nil {
		writeError(w, r, err)
		return
	}
	message := msg(r.Context(), "cms.block_deactivated")
	if active, _ := result["is_active"].(bool); active {
		message = msg(r.Context(), "cms.block_activated")
	}
	httpx.OK(w, result, message, nil)
}

func (s *Server) handleCMSBlockReorder(w http.ResponseWriter, r *http.Request) {
	if !s.requireCMSAdmin(w, r) {
		return
	}
	var body struct {
		BlockIDs []int64 `json:"block_ids"`
	}
	if err := httpx.DecodeJSON(r, &body); err != nil {
		writeError(w, r, err)
		return
	}
	result, err := cms.ReorderBlocks(r.Context(), s.database, body.BlockIDs)
	if errors.Is(err, cms.ErrAdminContentNotFound) {
		writeError(w, r, notFoundError(r.Context()))
		return
	}
	if err != nil {
		writeError(w, r, err)
		return
	}
	httpx.OK(w, result, msg(r.Context(), "cms.reorder_saved"), nil)
}

func (s *Server) handleCMSBlockDestroy(w http.ResponseWriter, r *http.Request) {
	if !s.requireCMSAdmin(w, r) {
		return
	}
	id, ok := pathID(w, r, "block")
	if !ok {
		return
	}
	err := cms.DeleteBlock(r.Context(), s.database, id)
	if errors.Is(err, cms.ErrAdminContentNotFound) {
		writeError(w, r, notFoundError(r.Context()))
		return
	}
	if err != nil {
		writeError(w, r, err)
		return
	}
	httpx.OK(w, nil, msg(r.Context(), "cms.block_deleted"), nil)
}
