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
  } | null
  cod_payment_proof: {
    id: number
    status: 'pending' | 'confirmed' | 'rejected'
    proof_url: string | null
    rejection_reason: string | null
  } | null
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
  total_amount: string
  recipient_name: string
  recipient_phone: string
  address: string
  latitude: string | null
  longitude: string | null
  shipping_provider: string | null
  delivery_date_estimate: string | null
  cancellation_reason: string | null
  konsumen: { name: string; phone: string | null } | null
  sales: { id: number; name: string } | null
  korsal: { id: number; name: string } | null
  couriers: { name: string; phone: string | null }[]
  returns: OrderReturnRequest[]
  items: OrderItem[]
  payment_method: PaymentMethod | null
  payment_transaction: PaymentTransaction | null
  /** Canonical payment figures (PaymentSummaryService) — the single source for Grand Total / DP / Total Dibayar / Sisa Pembayaran everywhere they're displayed. */
  payment_summary: PaymentSummary
  created_at: string
}

export interface PaymentSummary {
  grand_total: number
  /** What the customer chose as DP at checkout — NOT yet money received. */
  requested_dp: number
  /** How much of the DP has actually cleared verification, capped at requested_dp. */
  verified_dp: number
  /** All verified money received so far, DP + settlement combined. */
  total_paid: number
  remaining_balance: number
  /** max(0, total_paid - grand_total) — the canonical refund-eligibility signal; > 0 only when real money received exceeds the current bill. */
  overpaid_amount: number
  payment_status: string
  is_fully_paid: boolean
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
  created_at: string
}

export interface AuthPayload {
  user: AuthUser
  permissions: string[]
}
