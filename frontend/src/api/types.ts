export interface Category {
  id: number
  parent_id: number | null
  name: string
  slug: string
  is_active: boolean
  sort_order: number
}

export interface ProductVariation {
  id: number
  sku: string
  label: string
  price: string
  weight_grams: number
  is_active: boolean
  agent_available_quantity?: number
  stock?: { available: number; in_stock: boolean; login_to_view: boolean }
}

export interface ProductImage {
  id: number
  product_id: number
  product_variation_id: number | null
  url: string
  is_primary: boolean
  sort_order: number
}

export interface Product {
  sku?: string | null
  id: number
  name: string
  slug: string
  description: string | null
  short_description: string | null
  category: { id: number; name: string; slug: string } | null
  has_variations: boolean
  base_price: string | null
  weight_grams: number | null
  images: ProductImage[]
  variations: ProductVariation[]
  status: string
  agent_available_quantity?: number
  stock?: { available: number; in_stock: boolean; login_to_view: boolean }
}

export interface PaginationMeta {
  current_page: number
  last_page: number
  total: number
}

export interface OrderItem {
  id: number
  product_id: number
  product_variation_id: number | null
  product_name: string
  variation_label: string | null
  sku: string | null
  unit_price: string
  original_quantity: number
  fulfilled_quantity: number
  cancelled_quantity: number
  returned_quantity: number
  refund_quantity: number
  additional_quantity: number
  subtotal: string
  status: string
  requested_delivery_date: string | null
  shipment_id: number | null
  /** R-03 shipment routing state (backend authoritative; UX gating only). */
  delivery_mode?: 'standard' | 'self_sub' | null
  self_delivered_by_user_id?: number | null
  courier: { name: string; phone: string | null; user_id: number } | null
  delivery_proof_url: string | null
  agent_fee_amount?: string
  sales_fee_amount?: string
  courier_fee_amount?: string
}

export interface PaymentMethod {
  id: number
  code: string
  name: string
  type: 'manual' | 'gateway' | 'cod'
}

export interface PaymentTransaction {
  id: number
  type: string
  amount: string
  status: string
  gateway_reference: string | null
  instructions: Record<string, unknown> | null
  paid_at: string | null
  bank_transfer_verification: {
    status: string
    proof_url: string | null
    rejection_reason: string | null
    submitted_on_behalf?: boolean
    submitted_by?: PaymentPayer | null
    paid_by?: 'konsumen' | 'sales' | 'korsal' | null
    submitted_at?: string | null
    verified_by?: PaymentPayer | null
    verified_at?: string | null
  } | null
  cod_payment_proof: {
    id: number
    status: 'pending' | 'confirmed' | 'rejected'
    proof_url: string | null
    rejection_reason: string | null
    submitted_on_behalf?: boolean
    submitted_by?: PaymentPayer | null
    paid_by?: 'konsumen' | 'sales' | 'korsal' | null
    submitted_at?: string | null
    verified_by?: PaymentPayer | null
    verified_at?: string | null
  } | null
}

/** IMP-001: who submitted a payment proof (the payer actor) — distinct from the order owner (the konsumen). */
export interface PaymentPayer {
  id: number
  name: string
  role: string | null
}

export interface OrderReturnRequest {
  id: number
  reason: string
  status: string
  reviewed_at: string | null
  total_refund_amount: string | null
  items: {
    order_item_id: number
    quantity_returned: number
    status: string
    refund_status: string
  }[]
}

export interface Order {
  id: number
  order_no: string
  status: string
  payment_status: string
  subtotal_amount: string
  shipping_fee_amount: string
  admin_fee_amount: string
  effective_discount_amount?: string
  discount_amount?: string
  total_amount: string
  recipient_name: string
  recipient_phone: string
  address: string
  latitude: string | null
  longitude: string | null
  /** A1-21: persisted structured-address ids + postal code (canonical region identifiers, nullable historically). */
  province_id?: string | null
  regency_id?: string | null
  district_id?: string | null
  village_id?: string | null
  postal_code?: string | null
  voucher_id?: number | null
  /** A1-15: server-derived capability — the order's own konsumen or a same-branch financial role. */
  viewer_can_download_invoice?: boolean
  konsumen_id?: number
  shipping_provider: string | null
  /**
   * UAT-005 LOCKED rule (Human 2026-10-07): may an item's requested delivery date be changed?
   * Server-derived (true for Kurir Online / Self Delivery-Sub; false for RajaOngkir / Pickup).
   * Convenience gate only — the backend enforces the same rule authoritatively.
   */
  reschedule_allowed?: boolean
  /**
   * UAT-005 LOCKED: Shipment is the delivery-date unit. The canonical delivery date per shipment id,
   * derived server-side from that shipment's OWN items (null when unset or when its items disagree).
   * The UI renders this instead of inventing or inferring a date.
   */
  shipment_delivery_dates?: Record<string, { delivery_date: string | null; status: string; delivery_mode: string | null }>
  delivery_date_estimate: string | null
  cancellation_reason: string | null
  konsumen: { name: string; phone: string | null } | null
  sales: { id: number; name: string } | null
  korsal: { id: number; name: string } | null
  couriers: { name: string; phone: string | null }[]
  returns: OrderReturnRequest[]
  items: OrderItem[]
  /** R-03 derived delivery groups (Order + requested_delivery_date). No invoice table exists — this is a projection, never a payment document. */
  delivery_groups?: DeliveryGroup[]
  /** R-03 append-only Admin delivery-verification history across this order's shipments. */
  delivery_verifications?: DeliveryVerification[]
  payment_method: PaymentMethod | null
  payment_transaction: PaymentTransaction | null
  /** Canonical payment figures (PaymentSummaryService) — the single source for Grand Total / DP / Total Dibayar / Sisa Pembayaran everywhere they're displayed. */
  payment_summary: PaymentSummary
  created_at: string
}

/** R-03: items sharing the same order + requested_delivery_date form one delivery group. */
export interface DeliveryGroup {
  delivery_date: string | null
  item_ids: number[]
  item_count: number
  total_quantity: number
  shipment_ids: number[]
}

export type DeliveryVerificationOutcome = 'received' | 'not_received' | 'return'

/** R-03: one append-only Admin final delivery-verification record (operational, never a payment state). */
export interface DeliveryVerification {
  id: number
  shipment_id: number
  outcome: DeliveryVerificationOutcome
  note: string | null
  verified_at: string
  verified_by: { id: number; name: string } | null
  created_at: string
}

export interface PaymentSummary {
  grand_total: number
  /** What the customer chose as DP at checkout — NOT yet money received. */
  requested_dp: number
  /** How much of the DP has actually cleared verification, capped at requested_dp. */
  verified_dp: number
  /** UAT-004: submitted-but-unverified nominal ("DP Diajukan") — informational only, never counted as paid. */
  submitted_dp: number
  /** All verified money received so far, DP + settlement combined. */
  total_paid: number
  remaining_balance: number
  /** max(0, total_paid - grand_total) — the canonical refund-eligibility signal; > 0 only when real money received exceeds the current bill. */
  overpaid_amount: number
  payment_status: string
  is_fully_paid: boolean
  /** UAT-004: 'pending' while a submitted proof awaits verification, otherwise null. */
  pending_verification_status: string | null
  /** UAT-004: whether a pending proof photo is available for review. */
  has_pending_proof: boolean
  /** Outstanding (pending) additional-payment obligation only — once paid it folds into total_paid and this returns to 0. */
  additional_payment_amount: number
  additional_payment_status: string | null
  /** Outstanding (pending) refund obligation only — once processed it folds out of total_paid and this returns to 0. */
  refund_amount: number
  refund_status: string | null
}

export interface CheckoutStep {
  key: string
  label_key: string
  active: boolean
}

export interface ShippingMethod {
  code: 'rajaongkir' | 'openroute' | 'pickup'
  label: string
}

export interface PickupLocation {
  store_name: string | null
  address: string | null
  latitude: number | null
  longitude: number | null
  phone: string | null
}

export interface CheckoutStepsResponse {
  steps: CheckoutStep[]
  on_behalf_of_konsumen: boolean
  shipping_enabled: boolean
  /** True only when OpenRoute is the active provider — gates the Google Maps picker/autocomplete. */
  map_picker_enabled: boolean
  /** "Ekspedisi" (RajaOngkir) / "Kurir Online" (OpenRoute) / "Pickup" — only more than one entry when more than one is available. */
  shipping_methods: ShippingMethod[]
  /** The order's own agent's store — where to collect when shipping_method = 'pickup'. Null until an agent is resolvable. */
  pickup_location: PickupLocation | null
  payment_methods: PaymentMethod[]
}

export interface CheckoutQuote {
  subtotal_amount: number
  shipping_fee_amount: number
  admin_fee_amount: number
  /** IMP-002 voucher discount (currency) — server-derived, never invented. */
  discount_amount: number
  /** IMP-002 voucher id attribution when a voucher was applied. */
  voucher_id: number | null
  total_amount: number
  distance_km: number | null
  shipping_provider: string
  shipping_enabled: boolean
  warnings: Array<{ product_id: number; product_variation_id: number | null; message: string }>
}

/** One courier/service RajaOngkir offers under "Ekspedisi" (e.g. JNE REG) — never a trusted price, only what the server currently quotes. */
export interface CourierOption {
  courier: string
  service: string
  cost: number
  etd: string | null
}

/** The konsumen's specific courier+service pick under "Ekspedisi" (e.g. { courier: 'jne', service: 'REG' }). */
export interface CourierSelection {
  courier: string
  service: string
}

export interface RegionOption {
  id: string
  name: string
}

export interface KonsumenAddress {
  id: number
  label: string
  recipient_name: string
  phone: string
  address_line: string
  village: { id: string; name: string; district: string; regency: string; province: string } | null
  latitude: string
  longitude: string
  is_default: boolean
}

export interface AuthUser {
  parent_id?: number | null
  id: number
  name: string
  email: string
  phone: string
  avatar_url: string | null
  role: string
  referral_code: string | null
  agent_id: number | null
  korsal_id: number | null
  sales_id: number | null
  status: string
  /** IMP-001: present only on the signed-in user's own record. */
  google_linked?: boolean
  created_at: string
}

export interface AuthPayload {
  user: AuthUser
  permissions: string[]
}
