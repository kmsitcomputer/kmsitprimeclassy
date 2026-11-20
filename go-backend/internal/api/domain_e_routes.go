package api

import (
	"net/http"
	"time"

	"github.com/kmsitcomputer/primeclassy-go/internal/auth"
)

// registerDomainERoutes installs the Domain E route slice. It is intentionally kept out of server.go so
// the shared router file remains untouched while parallel domain work is in flight.
func (s *Server) registerDomainERoutes() {
	s.mux.Handle("GET /api/v1/admin/integrations/whatsapp", chain(http.HandlerFunc(s.handleAdminWhatsAppSettingsShow), RequireAuth, s.RequireAgentLinked, RequireRole("super_admin")))
	s.mux.Handle("PUT /api/v1/admin/integrations/whatsapp", chain(http.HandlerFunc(s.handleAdminWhatsAppSettingsUpdate), RequireAuth, s.RequireAgentLinked, RequireRole("super_admin")))
	s.mux.Handle("PUT /api/v1/admin/integrations/whatsapp/templates/{event}", chain(http.HandlerFunc(s.handleAdminWhatsAppTemplateUpdate), RequireAuth, s.RequireAgentLinked, RequireRole("super_admin")))
	s.mux.Handle("DELETE /api/v1/admin/integrations/whatsapp/templates/{event}", chain(http.HandlerFunc(s.handleAdminWhatsAppTemplateReset), RequireAuth, s.RequireAgentLinked, RequireRole("super_admin")))
	s.mux.Handle("POST /api/v1/admin/integrations/whatsapp/templates/{event}/preview", chain(http.HandlerFunc(s.handleAdminWhatsAppTemplatePreview), RequireAuth, s.RequireAgentLinked, RequireRole("super_admin")))
	s.mux.Handle("GET /api/v1/admin/integrations/whatsapp/messages", chain(http.HandlerFunc(s.handleAdminWhatsAppMessages), RequireAuth, s.RequireAgentLinked, RequireRole("super_admin")))
	s.mux.Handle("POST /api/v1/admin/integrations/whatsapp/messages/{message}/retry", chain(http.HandlerFunc(s.handleAdminWhatsAppMessageRetry), RequireAuth, s.RequireAgentLinked, RequireRole("super_admin")))
	s.mux.Handle("POST /api/v1/admin/integrations/whatsapp/test-connection", chain(http.HandlerFunc(s.handleAdminWhatsAppTestConnection), RequireAuth, s.RequireAgentLinked, RequireRole("super_admin")))
	s.mux.Handle("POST /api/v1/admin/integrations/whatsapp/test-message", chain(http.HandlerFunc(s.handleAdminWhatsAppTestMessage), RequireAuth, s.RequireAgentLinked, RequireRole("super_admin")))
	s.mux.Handle("PATCH /api/v1/admin/order-refunds/{adjustment}/status", chain(http.HandlerFunc(s.handleAdminOrderRefundStatus), RequireAuth, s.RequireAgentLinked, RequireRole("keuangan,super_admin")))
	s.mux.Handle("POST /api/v1/admin/order-sales-reductions/{reduction}/reinstate", chain(http.HandlerFunc(s.handleAdminOrderSalesReductionReinstate), RequireAuth, s.RequireAgentLinked, RequireRole("agen,keuangan,super_admin")))
	s.mux.Handle("POST /api/v1/admin/orders/{order}/cancellation/finalize", chain(http.HandlerFunc(s.handleAdminCancellationFinalize), RequireAuth, s.RequireAgentLinked, RequireRole("agen,keuangan,super_admin")))
	s.mux.Handle("POST /api/v1/admin/refund-obligations/{obligation}/approve", chain(http.HandlerFunc(s.handleAdminRefundObligationApprove), RequireAuth, s.RequireAgentLinked, RequireRole("agen,keuangan,super_admin")))
	s.mux.Handle("POST /api/v1/admin/refund-obligations/{obligation}/attempts", chain(http.HandlerFunc(s.handleAdminRefundObligationCreateAttempt), RequireAuth, s.RequireAgentLinked, RequireRole("agen,keuangan,super_admin")))
	s.mux.Handle("POST /api/v1/admin/refund-attempts/{attempt}/initiate", chain(http.HandlerFunc(s.handleAdminRefundAttemptInitiate), RequireAuth, s.RequireAgentLinked, RequireRole("agen,keuangan,super_admin")))
	s.mux.Handle("POST /api/v1/admin/refund-attempts/{attempt}/cancel", chain(http.HandlerFunc(s.handleAdminRefundAttemptCancel), RequireAuth, s.RequireAgentLinked, RequireRole("agen,keuangan,super_admin")))
	s.mux.Handle("POST /api/v1/admin/refund-attempts/{attempt}/unknown", chain(http.HandlerFunc(s.handleAdminRefundAttemptUnknown), RequireAuth, s.RequireAgentLinked, RequireRole("agen,keuangan,super_admin")))
	s.mux.Handle("POST /api/v1/admin/refund-attempts/{attempt}/fail", chain(http.HandlerFunc(s.handleAdminRefundAttemptFail), RequireAuth, s.RequireAgentLinked, RequireRole("agen,keuangan,super_admin")))
	s.mux.Handle("POST /api/v1/admin/refund-attempts/{attempt}/complete", chain(http.HandlerFunc(s.handleAdminRefundAttemptComplete), RequireAuth, s.RequireAgentLinked, RequireRole("agen,keuangan,super_admin")))
	s.mux.Handle("POST /api/v1/admin/refund-obligations/{obligation}/needs-verification/resolve", chain(http.HandlerFunc(s.handleAdminRefundNeedsVerificationResolve), RequireAuth, s.RequireAgentLinked, RequireRole("agen,keuangan,super_admin")))
	s.mux.Handle("POST /api/v1/admin/refund-obligations/{obligation}/deferred/request", chain(http.HandlerFunc(s.handleAdminDeferredMaterializationRequest), RequireAuth, s.RequireAgentLinked, RequireRole("agen,keuangan,super_admin")))
	s.mux.Handle("GET /api/v1/admin/integrations/smtp", chain(http.HandlerFunc(s.handleAdminSMTPSettingsShow), RequireAuth, s.RequireAgentLinked, RequireRole("super_admin")))
	s.mux.Handle("PUT /api/v1/admin/integrations/smtp", chain(http.HandlerFunc(s.handleAdminSMTPSettingsUpdate), RequireAuth, s.RequireAgentLinked, RequireRole("super_admin")))
	s.mux.Handle("POST /api/v1/admin/integrations/smtp/test-connection", chain(http.HandlerFunc(s.handleAdminSMTPTestConnection), RequireAuth, s.RequireAgentLinked, RequireRole("super_admin"), s.Throttle(6, time.Minute)))
	s.mux.Handle("POST /api/v1/admin/integrations/smtp/test-email", chain(http.HandlerFunc(s.handleAdminSMTPTestEmail), RequireAuth, s.RequireAgentLinked, RequireRole("super_admin"), s.Throttle(6, time.Minute)))
	s.mux.Handle("GET /api/v1/admin/regions/export", chain(http.HandlerFunc(s.handleAdminRegionExport), RequireAuth, s.RequireAgentLinked, RequireRole("super_admin")))
	s.mux.Handle("POST /api/v1/admin/regions/import", chain(http.HandlerFunc(s.handleAdminRegionImport), RequireAuth, s.RequireAgentLinked, RequireRole("super_admin")))
	s.mux.Handle("GET /api/v1/admin/google-auth", chain(http.HandlerFunc(s.handleAdminGoogleAuthShow), RequireAuth, s.RequireAgentLinked, RequireRole("super_admin")))
	s.mux.Handle("PUT /api/v1/admin/google-auth", chain(http.HandlerFunc(s.handleAdminGoogleAuthUpdate), RequireAuth, s.RequireAgentLinked, RequireRole("super_admin")))
	s.mux.Handle("GET /api/v1/admin/shipping-couriers", chain(http.HandlerFunc(s.handleAdminShippingCouriers), RequireAuth, s.RequireAgentLinked, RequireRole("super_admin")))
	s.mux.Handle("POST /api/v1/admin/shipping-couriers", chain(http.HandlerFunc(s.handleAdminShippingCouriersStore), RequireAuth, s.RequireAgentLinked, RequireRole("super_admin")))
	s.mux.Handle("PATCH /api/v1/admin/shipping-couriers/{shippingCourier}/toggle", chain(http.HandlerFunc(s.handleAdminShippingCourierToggle), RequireAuth, s.RequireAgentLinked, RequireRole("super_admin")))
	s.mux.Handle("GET /api/v1/admin/shipping-providers", chain(http.HandlerFunc(s.handleAdminShippingProviders), RequireAuth, s.RequireAgentLinked, RequireRole("super_admin")))
	s.mux.Handle("PATCH /api/v1/admin/shipping-providers/{provider}/toggle", chain(http.HandlerFunc(s.handleAdminShippingProviderToggle), RequireAuth, s.RequireAgentLinked, RequireRole("super_admin")))
	s.mux.Handle("GET /api/v1/orders/{order}/invoice", chain(http.HandlerFunc(s.handleOrderInvoice), RequireAuth, s.RequireAgentLinked))
	s.mux.Handle("GET /api/v1/dispatch", chain(http.HandlerFunc(s.handleDispatchQueue), RequireAuth, s.RequireAgentLinked, RequireRole("super_admin,agen,admin,koordinator-kurir")))
	s.mux.Handle("GET /api/v1/admin/additional-payments", chain(http.HandlerFunc(s.handleAdminAdditionalPaymentsIndex), RequireAuth, s.RequireAgentLinked, RequireRole("super_admin,agen,admin,keuangan")))
	s.mux.Handle("GET /api/v1/admin/order-refunds", chain(http.HandlerFunc(s.handleAdminOrderRefundsIndex), RequireAuth, s.RequireAgentLinked, RequireRole("super_admin,agen,admin,keuangan")))
	s.mux.Handle("GET /api/v1/admin/orders/{order}/refunds", chain(http.HandlerFunc(s.handleAdminOrderRefundLedger), RequireAuth, s.RequireAgentLinked, RequireRole("super_admin,agen,admin,keuangan")))
	s.mux.Handle("PATCH /api/v1/admin/additional-payments/{payment}/status", chain(http.HandlerFunc(s.handleAdminAdditionalPaymentStatus), RequireAuth, s.RequireAgentLinked, RequireRole("super_admin,keuangan")))
	userRosterRoles := "super_admin,agen,korsal,sales,sales-kurir-sub,admin,keuangan,kurir,koordinator-kurir"
	userCreateRoles := "super_admin,agen,korsal,koordinator-kurir"
	s.mux.Handle("POST /api/v1/users", chain(http.HandlerFunc(s.handleUsersStore), RequireAuth, s.RequireAgentLinked, RequireRole(userCreateRoles)))
	s.mux.Handle("GET /api/v1/users", chain(http.HandlerFunc(s.handleUsersIndex), RequireAuth, s.RequireAgentLinked, RequireRole(userRosterRoles)))
	s.mux.Handle("GET /api/v1/users/{user}", chain(http.HandlerFunc(s.handleUsersShow), RequireAuth, s.RequireAgentLinked, RequireRole(userRosterRoles)))
	s.mux.Handle("PATCH /api/v1/users/{user}", chain(http.HandlerFunc(s.handleUsersUpdate), RequireAuth, s.RequireAgentLinked, RequireRole(userRosterRoles)))
	s.mux.Handle("DELETE /api/v1/users/{user}", chain(http.HandlerFunc(s.handleUsersDelete), RequireAuth, s.RequireAgentLinked, RequireRole(userRosterRoles)))
	s.mux.Handle("PATCH /api/v1/users/{user}/reassign-referral", chain(http.HandlerFunc(s.handleUsersReassignReferral), RequireAuth, s.RequireAgentLinked, RequireRole(userRosterRoles)))
	s.mux.Handle("PATCH /api/v1/users/{user}/convert-to-sales-kurir-sub", chain(http.HandlerFunc(s.handleUsersConvertToSalesKurirSub), RequireAuth, s.RequireAgentLinked, RequireRole(auth.RoleAgen)))
	s.mux.Handle("PATCH /api/v1/users/{user}/convert-to-sales-kurir", chain(http.HandlerFunc(s.handleUsersConvertToSalesKurirSub), RequireAuth, s.RequireAgentLinked, RequireRole(auth.RoleAgen)))

	agentAccount := "agen,admin"
	s.mux.Handle("POST /api/v1/agent/whatsapp/events/{event}/preview", chain(http.HandlerFunc(s.handleAgentWhatsAppTemplatePreview), RequireAuth, s.RequireAgentLinked, RequireRole(agentAccount)))
	s.mux.Handle("GET /api/v1/agent/google-auth", chain(http.HandlerFunc(s.handleAgentGoogleAuthShow), RequireAuth, s.RequireAgentLinked, RequireRole(agentAccount)))
	s.mux.Handle("PUT /api/v1/agent/google-auth", chain(http.HandlerFunc(s.handleAgentGoogleAuthUpdate), RequireAuth, s.RequireAgentLinked, RequireRole(agentAccount)))
	s.mux.Handle("GET /api/v1/agent/payment-methods", chain(http.HandlerFunc(s.handleAgentPaymentMethods), RequireAuth, s.RequireAgentLinked, RequireRole(agentAccount)))
	s.mux.Handle("PATCH /api/v1/agent/payment-methods/{method}/toggle", chain(http.HandlerFunc(s.handleAgentPaymentMethodToggle), RequireAuth, s.RequireAgentLinked, RequireRole(agentAccount)))
	s.mux.Handle("PUT /api/v1/agent/payment-methods/{method}/config", chain(http.HandlerFunc(s.handleAgentPaymentMethodConfig), RequireAuth, s.RequireAgentLinked, RequireRole(agentAccount)))

	s.mux.Handle("GET /api/v1/agent/store-profile", chain(http.HandlerFunc(s.handleAgentStoreProfileShow), RequireAuth, s.RequireAgentLinked, RequireRole(auth.RoleAgen)))
	s.mux.Handle("PUT /api/v1/agent/store-profile", chain(http.HandlerFunc(s.handleAgentStoreProfileUpdate), RequireAuth, s.RequireAgentLinked, RequireRole(auth.RoleAgen)))
	s.mux.Handle("GET /api/v1/agent/shipping-tariff", chain(http.HandlerFunc(s.handleAgentShippingTariffShow), RequireAuth, s.RequireAgentLinked, RequireRole(auth.RoleAgen)))
	s.mux.Handle("PUT /api/v1/agent/shipping-tariff", chain(http.HandlerFunc(s.handleAgentShippingTariffUpdate), RequireAuth, s.RequireAgentLinked, RequireRole(auth.RoleAgen)))
	s.mux.Handle("GET /api/v1/agent/shipping-providers", chain(http.HandlerFunc(s.handleAgentShippingProviders), RequireAuth, s.RequireAgentLinked, RequireRole(auth.RoleAgen)))
	s.mux.Handle("PATCH /api/v1/agent/shipping-providers/{provider}/toggle", chain(http.HandlerFunc(s.handleAgentShippingProviderToggle), RequireAuth, s.RequireAgentLinked, RequireRole(auth.RoleAgen)))
	s.mux.Handle("PUT /api/v1/agent/shipping-providers/{provider}/config", chain(http.HandlerFunc(s.handleAgentShippingProviderConfig), RequireAuth, s.RequireAgentLinked, RequireRole(auth.RoleAgen)))
	s.mux.Handle("GET /api/v1/agent/shipping-providers/{provider}/couriers", chain(http.HandlerFunc(s.handleAgentShippingProviderCouriers), RequireAuth, s.RequireAgentLinked, RequireRole(auth.RoleAgen)))
	s.mux.Handle("PUT /api/v1/agent/shipping-providers/{provider}/couriers", chain(http.HandlerFunc(s.handleAgentShippingProviderCouriersUpdate), RequireAuth, s.RequireAgentLinked, RequireRole(auth.RoleAgen)))

	s.mux.Handle("GET /api/v1/agent/whatsapp/scope", chain(http.HandlerFunc(s.handleAgentWhatsAppScope), RequireAuth, s.RequireAgentLinked, RequireRole(agentAccount)))
	s.mux.Handle("GET /api/v1/agent/whatsapp/events", chain(http.HandlerFunc(s.handleAgentWhatsAppEvents), RequireAuth, s.RequireAgentLinked, RequireRole(agentAccount)))
	s.mux.Handle("PUT /api/v1/agent/whatsapp/events/{event}", chain(http.HandlerFunc(s.handleAgentWhatsAppEventUpdate), RequireAuth, s.RequireAgentLinked, RequireRole(agentAccount)))
	s.mux.Handle("DELETE /api/v1/agent/whatsapp/events/{event}/template", chain(http.HandlerFunc(s.handleAgentWhatsAppTemplateReset), RequireAuth, s.RequireAgentLinked, RequireRole(agentAccount)))
	s.mux.Handle("GET /api/v1/agent/whatsapp/test-numbers", chain(http.HandlerFunc(s.handleAgentWhatsAppTestNumbers), RequireAuth, s.RequireAgentLinked, RequireRole(agentAccount)))
	s.mux.Handle("POST /api/v1/agent/whatsapp/test-numbers", chain(http.HandlerFunc(s.handleAgentWhatsAppTestNumberAdd), RequireAuth, s.RequireAgentLinked, RequireRole(agentAccount)))
	s.mux.Handle("DELETE /api/v1/agent/whatsapp/test-numbers/{id}", chain(http.HandlerFunc(s.handleAgentWhatsAppTestNumberDelete), RequireAuth, s.RequireAgentLinked, RequireRole(agentAccount)))

	// CRM read surface (IMP-006 slice 1) — OD-01: agen/admin read-only, korsal downline, sales owner.
	crmRead := "super_admin,agen,admin,korsal,sales,sales-kurir-sub"
	s.mux.Handle("GET /api/v1/crm/customers", chain(http.HandlerFunc(s.handleCRMCustomerIndex), RequireAuth, s.RequireAgentLinked, RequireRole(crmRead)))
	s.mux.Handle("GET /api/v1/crm/customers/{customer}", chain(http.HandlerFunc(s.handleCRMCustomerShow), RequireAuth, s.RequireAgentLinked, RequireRole(crmRead)))
	s.mux.Handle("GET /api/v1/crm/customers/{customer}/visits", chain(http.HandlerFunc(s.handleCRMCustomerVisits), RequireAuth, s.RequireAgentLinked, RequireRole(crmRead)))
	s.mux.Handle("GET /api/v1/crm/follow-ups", chain(http.HandlerFunc(s.handleCRMFollowUps), RequireAuth, s.RequireAgentLinked, RequireRole(crmRead)))

	// CRM write surface (IMP-006 slice 1 + GLM inventory) — Laravel runs route-model binding (404
	// endpoint_not_found) BEFORE the role middleware (403), so the role gate is enforced in-handler
	// after the binding precheck, not here.
	s.mux.Handle("POST /api/v1/crm/customers", chain(http.HandlerFunc(s.handleCRMCustomerStore), RequireAuth, s.RequireAgentLinked))
	s.mux.Handle("PATCH /api/v1/crm/customers/{customer}", chain(http.HandlerFunc(s.handleCRMCustomerUpdate), RequireAuth, s.RequireAgentLinked))
	s.mux.Handle("POST /api/v1/crm/customers/{customer}/archive", chain(http.HandlerFunc(s.handleCRMCustomerArchive), RequireAuth, s.RequireAgentLinked))
	s.mux.Handle("POST /api/v1/crm/customers/{customer}/visits", chain(http.HandlerFunc(s.handleCRMVisitStore), RequireAuth, s.RequireAgentLinked))
	s.mux.Handle("PATCH /api/v1/crm/visits/{visit}", chain(http.HandlerFunc(s.handleCRMVisitUpdate), RequireAuth, s.RequireAgentLinked))
	s.mux.Handle("GET /api/v1/crm/link-candidates", chain(http.HandlerFunc(s.handleCRMLinkCandidates), RequireAuth, s.RequireAgentLinked))
	s.mux.Handle("POST /api/v1/crm/customers/{customer}/link-otp", chain(http.HandlerFunc(s.handleCRMLinkOtp), RequireAuth, s.RequireAgentLinked))
	s.mux.Handle("POST /api/v1/crm/customers/{customer}/link", chain(http.HandlerFunc(s.handleCRMLink), RequireAuth, s.RequireAgentLinked))
	s.mux.Handle("POST /api/v1/crm/customers/{customer}/unlink", chain(http.HandlerFunc(s.handleCRMUnlink), RequireAuth, s.RequireAgentLinked))
	s.mux.Handle("POST /api/v1/crm/customers/{customer}/consent-otp", chain(http.HandlerFunc(s.handleCRMConsentOtp), RequireAuth, s.RequireAgentLinked))
	s.mux.Handle("POST /api/v1/crm/customers/{customer}/consent-events", chain(http.HandlerFunc(s.handleCRMCaptureConsent), RequireAuth, s.RequireAgentLinked))
	s.mux.Handle("POST /api/v1/orders/{order}/assisted-returns", chain(http.HandlerFunc(s.handleCRMAssistedReturn), RequireAuth, s.RequireAgentLinked, s.Throttle(30, time.Minute)))
	s.mux.Handle("POST /api/v1/orders/{order}/returns", chain(http.HandlerFunc(s.handleReturnRequestStore), RequireAuth, s.RequireAgentLinked, RequireRole("konsumen")))
	s.mux.Handle("GET /api/v1/admin/returns", chain(http.HandlerFunc(s.handleAdminReturnsIndex), RequireAuth, s.RequireAgentLinked, RequireRole("super_admin,agen,admin")))
	s.mux.Handle("GET /api/v1/admin/returns/{return}", chain(http.HandlerFunc(s.handleAdminReturnsShow), RequireAuth, s.RequireAgentLinked, RequireRole("super_admin,agen,admin")))
	s.mux.Handle("PATCH /api/v1/admin/returns/{return}/review", chain(http.HandlerFunc(s.handleAdminReturnReview), RequireAuth, s.RequireAgentLinked, RequireRole("super_admin,agen,admin")))
	s.mux.Handle("PATCH /api/v1/admin/returns/{return}/pickup-schedule", chain(http.HandlerFunc(s.handleAdminReturnPickupSchedule), RequireAuth, s.RequireAgentLinked, RequireRole("super_admin,agen,admin")))
	s.mux.Handle("GET /api/v1/dispatch/returns", chain(http.HandlerFunc(s.handleDispatchReturns), RequireAuth, s.RequireAgentLinked, RequireRole("super_admin,agen,admin,koordinator-kurir")))
	s.mux.Handle("GET /api/v1/dispatch/history", chain(http.HandlerFunc(s.handleDispatchHistory), RequireAuth, s.RequireAgentLinked, RequireRole("super_admin,agen,admin,koordinator-kurir")))
	s.mux.Handle("PATCH /api/v1/admin/return-items/{item}/refund", chain(http.HandlerFunc(s.handleAdminReturnItemRefund), RequireAuth, s.RequireAgentLinked, RequireRole("super_admin,keuangan")))

	// Commission/fee REPORTS — CommissionController::allowedBeneficiaryRoles scopes the rows per role.
	commissionRoles := "super_admin,agen,sales,sales-kurir-sub,kurir,koordinator-kurir,admin,korsal,keuangan"
	s.mux.Handle("GET /api/v1/commissions", chain(http.HandlerFunc(s.handleCommissionIndex), RequireAuth, s.RequireAgentLinked, RequireRole(commissionRoles)))
	s.mux.Handle("GET /api/v1/commissions/summary", chain(http.HandlerFunc(s.handleCommissionSummary), RequireAuth, s.RequireAgentLinked, RequireRole(commissionRoles)))

	// Management reports — transactions is korsal-open (ReportService::scopeToActor narrows it further).
	s.mux.Handle("GET /api/v1/reports/transactions", chain(http.HandlerFunc(s.handleReportTransactions), RequireAuth, s.RequireAgentLinked, RequireRole("super_admin,agen,admin,korsal")))
}
