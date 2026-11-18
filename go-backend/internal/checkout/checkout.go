// Package checkout owns the checkout wizard contract.
//
// Ported from Services/Checkout/CheckoutStepResolver + Support/CheckoutSteps. The step list is NOT hard-coded
// in the frontend: the server decides each step's `active` flag from real conditions (auth state, whether the
// actor is ordering for themselves or a downline konsumen, whether shipping has anything to offer).
//
// The security-relevant part is `Viewer`: every branch below is derived from the authenticated session, never
// from a client-supplied id. A guest therefore cannot be told they are ordering on behalf of anyone, and a
// konsumen cannot see the methods that only staff may choose.
package checkout

import (
	"context"

	"github.com/kmsitcomputer/primeclassy-go/internal/db"
	"github.com/kmsitcomputer/primeclassy-go/internal/payment"
	"github.com/kmsitcomputer/primeclassy-go/internal/shipping"
)

// Canonical step keys, in wizard order.
const (
	StepAccount      = "account"
	StepAddress      = "address"
	StepReferral     = "referral"
	StepProducts     = "products"
	StepShipping     = "shipping"
	StepDeliveryDate = "delivery_date"
	StepPayment      = "payment"
	StepReview       = "review"
	StepConfirmation = "confirmation"
)

// Order is CheckoutSteps::order().
func Order() []string {
	return []string{
		StepAccount,
		StepAddress,
		StepReferral,
		StepProducts,
		StepShipping,
		StepDeliveryDate,
		StepPayment,
		StepReview,
		StepConfirmation,
	}
}

// LabelKey is CheckoutSteps::labelKey.
func LabelKey(step string) string { return "checkout.step." + step }

// Step is one entry of the payload's `steps` array.
type Step struct {
	Key      string `json:"key"`
	LabelKey string `json:"label_key"`
	Active   bool   `json:"active"`
}

// Viewer is the authenticated caller, reduced to exactly what the resolver may consult.
//
// Authenticated=false is a guest. IsKonsumen is the canonical role check (`isRole('konsumen')`), which is what
// flips `on_behalf_of_konsumen` — NOT a client flag. AgentID is the caller's own branch (`users.agent_id`),
// nil for a guest/super_admin that has no branch, in which case every agent-scoped override is skipped.
type Viewer struct {
	Authenticated bool
	IsKonsumen    bool
	AgentID       *int64
}

// Response is the exact payload of GET /checkout/steps.
type Response struct {
	Steps              []Step            `json:"steps"`
	OnBehalfOfKonsumen bool              `json:"on_behalf_of_konsumen"`
	ShippingEnabled    bool              `json:"shipping_enabled"`
	MapPickerEnabled   bool              `json:"map_picker_enabled"`
	ShippingMethods    []shipping.Method `json:"shipping_methods"`
	PickupLocation     *shipping.Location `json:"pickup_location"`
	PaymentMethods     []payment.Method  `json:"payment_methods"`
}

// Resolve is CheckoutStepResolver::resolve.
//
// shippingMethod narrows `payment_methods` once the customer has picked a method (Ekspedisi → bank transfer
// only). The controller only forwards `rajaongkir`/`openroute`; anything else becomes nil, which means
// "shipping not chosen yet" and therefore an unrestricted list.
func Resolve(ctx context.Context, d *db.DB, viewer Viewer, shippingMethod *string) (Response, error) {
	onBehalf := viewer.Authenticated && !viewer.IsKonsumen

	shippingEnabled, err := shipping.SelectionAvailable(ctx, d, viewer.AgentID)
	if err != nil {
		return Response{}, err
	}
	mapPicker, err := shipping.OpenRouteEnabled(ctx, d, viewer.AgentID)
	if err != nil {
		return Response{}, err
	}
	methods, err := shipping.AvailableMethods(ctx, d, viewer.AgentID)
	if err != nil {
		return Response{}, err
	}
	location, err := shipping.PickupLocation(ctx, d, viewer.AgentID)
	if err != nil {
		return Response{}, err
	}
	payMethods, err := payment.AvailableMethods(ctx, d, viewer.AgentID, shippingMethod, viewer.IsKonsumen)
	if err != nil {
		return Response{}, err
	}

	// The active map is the whole "dynamic wizard" rule. `account` is the ONLY step that differs for a guest,
	// `referral` is never a standalone step (attribution stays server-side; staff customer selection lives
	// inside the account/address form).
	active := map[string]bool{
		StepAccount:      !viewer.Authenticated,
		StepAddress:      true,
		StepReferral:     false,
		StepProducts:     true,
		StepShipping:     shippingEnabled,
		StepDeliveryDate: true,
		StepPayment:      true,
		StepReview:       true,
		StepConfirmation: true,
	}

	steps := make([]Step, 0, 9)
	for _, key := range Order() {
		steps = append(steps, Step{Key: key, LabelKey: LabelKey(key), Active: active[key]})
	}

	if methods == nil {
		methods = []shipping.Method{}
	}
	if payMethods == nil {
		payMethods = []payment.Method{}
	}

	return Response{
		Steps:              steps,
		OnBehalfOfKonsumen: onBehalf,
		ShippingEnabled:    shippingEnabled,
		MapPickerEnabled:   mapPicker,
		ShippingMethods:    methods,
		PickupLocation:     location,
		PaymentMethods:     payMethods,
	}, nil
}
