<script setup lang="ts">
import { ref, reactive, computed, onMounted, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import ShopLayout from '@/layouts/ShopLayout.vue'
import AppButton from '@/components/ui/AppButton.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import { useCartStore } from '@/stores/cart'
import { useAuthStore } from '@/stores/auth'
import { createOrder, submitBankTransferProof, getOrder } from '@/api/orders'
import { getCheckoutSteps, quoteCheckout } from '@/api/checkout'
import { listUsers } from '@/api/users'
import { listAddresses, createAddress } from '@/api/addresses'
import { listProvinces, listRegencies, listDistricts, listVillages } from '@/api/regions'
import { ApiError } from '@/api/client'
import { formatRupiah } from '@/utils/format'
import { isGoogleMapsConfigured } from '@/utils/googleMaps'
import AddressMapPicker from '@/components/checkout/AddressMapPicker.vue'
import type { CheckoutStepsResponse, CheckoutQuote, Order, PaymentMethod, RegionOption, AuthUser, KonsumenAddress } from '@/api/types'

/**
 * Dynamic "installer style" checkout wizard. The step list itself (which
 * steps exist, and which are active for this request) comes entirely from
 * GET /checkout/steps — this component never hard-codes "skip shipping" or
 * "skip account" logic; it just renders whatever the backend says is active,
 * in order (see App\Support\CheckoutSteps / CheckoutStepResolver).
 */

const { t } = useI18n()

const STEP_LABELS = computed<Record<string, string>>(() => ({
  account: t('checkout.steps.account'),
  address: t('checkout.steps.address'),
  referral: t('checkout.steps.referral'),
  products: t('checkout.steps.products'),
  shipping: t('checkout.steps.shipping'),
  delivery_date: t('checkout.steps.delivery_date'),
  payment: t('checkout.steps.payment'),
  review: t('checkout.steps.review'),
  confirmation: t('checkout.steps.confirmation'),
}))

const cart = useCartStore()
const auth = useAuthStore()
const router = useRouter()

const stepsConfig = ref<CheckoutStepsResponse | null>(null)
const loadingSteps = ref(true)
const currentIndex = ref(0)

const activeSteps = computed(() => stepsConfig.value?.steps.filter((s) => s.active) ?? [])
const currentStep = computed(() => activeSteps.value[currentIndex.value]?.key ?? null)
const isLastBeforeConfirmation = computed(() => currentStep.value === 'review')

async function loadSteps() {
  loadingSteps.value = true
  stepsConfig.value = await getCheckoutSteps()
  loadingSteps.value = false
}

onMounted(() => {
  if (cart.isEmpty) {
    router.replace({ name: 'cart' })
    return
  }
  loadSteps()
})

// Once the guest logs in/registers (redirected back here) and the "account"
// step is no longer active, jump straight past it instead of leaving them
// stuck on a step that just disappeared from the list.
watch(
  () => auth.isLoggedIn,
  async (loggedIn) => {
    if (loggedIn) {
      await loadSteps()
      currentIndex.value = 0
    }
  },
)

function goNext() {
  if (currentIndex.value < activeSteps.value.length - 1) currentIndex.value++
}
function goBack() {
  if (currentIndex.value > 0) currentIndex.value--
}

/* ---------- Address step ---------- */
const destination = reactive({
  address_id: null as number | null,
  recipient_name: '',
  recipient_phone: '',
  address_line: '',
  village_id: null as string | null,
  latitude: null as number | null,
  longitude: null as number | null,
})
const locating = ref(false)
const locationError = ref<string | null>(null)

/* Saved address book (konsumen only — see KonsumenAddressController's role:konsumen gate) — a
 * convenience on top of the manual fields above, never a separate code path: picking one just
 * fills the same `destination` fields (plus address_id, which the backend re-resolves from,
 * ignoring the rest — see StoreOrderRequest/QuoteCheckoutRequest). */
const savedAddresses = ref<KonsumenAddress[]>([])
const selectedSavedAddressId = ref<number | 'new'>('new')
const saveThisAddress = ref(false)

async function loadSavedAddresses() {
  if (auth.user?.role !== 'konsumen') return
  savedAddresses.value = await listAddresses()
  if (savedAddresses.value.length) {
    selectSavedAddress(savedAddresses.value.find((a) => a.is_default)?.id ?? savedAddresses.value[0]!.id)
  }
}
onMounted(loadSavedAddresses)

function selectSavedAddress(id: number | 'new') {
  selectedSavedAddressId.value = id
  if (id === 'new') {
    destination.address_id = null
    return
  }
  const addr = savedAddresses.value.find((a) => a.id === id)
  if (!addr) return
  destination.address_id = addr.id
  destination.recipient_name = addr.recipient_name
  destination.recipient_phone = addr.phone
  destination.address_line = addr.address_line
  destination.village_id = addr.village?.id ?? null
  destination.latitude = Number(addr.latitude)
  destination.longitude = Number(addr.longitude)
}

function useMyLocation() {
  if (!navigator.geolocation) {
    locationError.value = t('checkout.address.geolocationUnsupported')
    return
  }
  locating.value = true
  locationError.value = null
  navigator.geolocation.getCurrentPosition(
    (pos) => {
      destination.latitude = pos.coords.latitude
      destination.longitude = pos.coords.longitude
      locating.value = false
    },
    () => {
      locationError.value = t('checkout.address.geolocationFailed')
      locating.value = false
    },
  )
}

function onMapPicked(picked: { lat: number; lng: number }) {
  destination.latitude = picked.lat
  destination.longitude = picked.lng
}

const mapPickerEnabled = computed(() => (stepsConfig.value?.map_picker_enabled ?? false) && isGoogleMapsConfigured())

/* Cascading Provinsi -> Kota/Kabupaten -> Kecamatan -> Kelurahan selects. */
const provinces = ref<RegionOption[]>([])
const regencies = ref<RegionOption[]>([])
const districts = ref<RegionOption[]>([])
const villages = ref<RegionOption[]>([])
const selectedProvince = ref<string | null>(null)
const selectedRegency = ref<string | null>(null)
const selectedDistrict = ref<string | null>(null)

listProvinces().then((list) => (provinces.value = list))

watch(selectedProvince, async (id) => {
  regencies.value = []
  districts.value = []
  villages.value = []
  selectedRegency.value = null
  selectedDistrict.value = null
  destination.village_id = null
  if (id) regencies.value = await listRegencies(id)
})

watch(selectedRegency, async (id) => {
  districts.value = []
  villages.value = []
  selectedDistrict.value = null
  destination.village_id = null
  if (id) districts.value = await listDistricts(id)
})

watch(selectedDistrict, async (id) => {
  villages.value = []
  destination.village_id = null
  if (id) villages.value = await listVillages(id)
})

const addressComplete = computed(
  () =>
    destination.address_id !== null ||
    (!!destination.recipient_name &&
      !!destination.recipient_phone &&
      !!destination.address_line &&
      !!destination.village_id &&
      destination.latitude !== null &&
      destination.longitude !== null),
)

/* ---------- Shipping method: "Ekspedisi" (RajaOngkir) vs "Kurir Online" (OpenRoute) ---------- */
const shippingMethods = computed(() => stepsConfig.value?.shipping_methods ?? [])
const selectedShippingMethod = ref<string | null>(null)

// Only one provider active -> nothing to choose, pre-select it automatically.
watch(shippingMethods, (methods) => {
  if (methods.length === 1) selectedShippingMethod.value = methods[0]!.code
  else if (methods.length === 0) selectedShippingMethod.value = null
})

/* ---------- Referral step: "order on behalf of a konsumen" picker (agen/korsal/sales only) ---------- */
const konsumenSearch = ref('')
const konsumenResults = ref<AuthUser[]>([])
const searchingKonsumen = ref(false)
const selectedKonsumen = ref<AuthUser | null>(null)

let konsumenSearchTimeout: ReturnType<typeof setTimeout> | undefined
watch(konsumenSearch, (search) => {
  clearTimeout(konsumenSearchTimeout)
  if (!search.trim()) {
    konsumenResults.value = []
    return
  }
  konsumenSearchTimeout = setTimeout(async () => {
    searchingKonsumen.value = true
    try {
      const result = await listUsers(1, { role: 'konsumen', search: search.trim() })
      konsumenResults.value = result.users
    } finally {
      searchingKonsumen.value = false
    }
  }, 300)
})

function pickKonsumen(k: AuthUser) {
  selectedKonsumen.value = k
  konsumenSearch.value = ''
  konsumenResults.value = []
}

const needsKonsumenSelection = computed(() => !!stepsConfig.value?.on_behalf_of_konsumen)
const konsumenSelected = computed(() => !needsKonsumenSelection.value || !!selectedKonsumen.value)

/* ---------- Delivery date step ---------- */
const deliveryDate = ref<string>('')
const minDeliveryDate = new Date().toISOString().slice(0, 10)

/* ---------- Payment step ---------- */
const selectedPaymentCode = ref<string | null>(null)
const paymentMethods = computed<PaymentMethod[]>(() => stepsConfig.value?.payment_methods ?? [])
const selectedPaymentMethod = computed(() => paymentMethods.value.find((m) => m.code === selectedPaymentCode.value) ?? null)
const isDpSelected = computed(() => selectedPaymentCode.value === 'down_payment')
/** DP nominal paid now — the server re-validates it against the recomputed total (0 < dp < total). */
const dpAmount = ref<string>('')

/* ---------- Quote (server-computed totals — never invented on the frontend) ---------- */
const quote = ref<CheckoutQuote | null>(null)
const quoting = ref(false)
const quoteError = ref<string | null>(null)

const orderLines = computed(() =>
  cart.lines.map((l) => ({ product_id: l.productId, product_variation_id: l.variationId, quantity: l.quantity })),
)

async function loadQuote() {
  quoting.value = true
  quoteError.value = null
  try {
    quote.value = await quoteCheckout(orderLines.value, destination, selectedShippingMethod.value, selectedKonsumen.value?.id)
  } catch (e) {
    quoteError.value = e instanceof ApiError ? e.message : t('checkout.review.quoteError')
  } finally {
    quoting.value = false
  }
}

// Refresh the quote right before Review renders it, so it always reflects
// the latest address/cart state rather than a stale snapshot.
watch(currentStep, (step) => {
  if (step === 'review') loadQuote()
})

/* ---------- Order creation ---------- */
const idempotencyKey = crypto.randomUUID()
const submitting = ref(false)
const submitError = ref<string | null>(null)
const createdOrder = ref<Order | null>(null)

async function placeOrder() {
  if (!selectedPaymentCode.value) return
  submitError.value = null

  // DP: the nominal is mandatory and must be positive — the server re-validates
  // it against the freshly computed total (0 < dp < total).
  if (isDpSelected.value && !(Number(dpAmount.value) > 0)) {
    submitError.value = t('checkout.payment.dpInvalid')
    return
  }

  submitting.value = true
  try {
    const order = await createOrder({
      items: orderLines.value,
      destination,
      paymentMethodCode: selectedPaymentCode.value,
      deliveryDate: deliveryDate.value || null,
      shippingMethod: selectedShippingMethod.value,
      konsumenId: selectedKonsumen.value?.id,
      dpAmount: isDpSelected.value ? Number(dpAmount.value) : null,
      idempotencyKey,
    })
    createdOrder.value = order
    cart.clear()

    // Best-effort — never blocks confirmation if it fails, the order itself already succeeded.
    if (saveThisAddress.value && selectedSavedAddressId.value === 'new' && destination.village_id && destination.latitude !== null && destination.longitude !== null) {
      try {
        await createAddress({
          recipient_name: destination.recipient_name,
          phone: destination.recipient_phone,
          address_line: destination.address_line,
          village_id: destination.village_id,
          latitude: destination.latitude,
          longitude: destination.longitude,
          is_default: savedAddresses.value.length === 0,
        })
      } catch {
        // Non-critical — the order already succeeded either way.
      }
    }

    goNext() // -> confirmation
  } catch (e) {
    submitError.value = e instanceof ApiError ? e.message : t('checkout.review.submitError')
  } finally {
    submitting.value = false
  }
}

/* ---------- Confirmation: bank-transfer proof upload ---------- */
const proofFile = ref<File | null>(null)
const uploadingProof = ref(false)
const proofUploaded = ref(false)
const proofError = ref<string | null>(null)

function onProofSelected(e: Event) {
  proofFile.value = (e.target as HTMLInputElement).files?.[0] ?? null
}

async function uploadProof() {
  if (!createdOrder.value || !proofFile.value) return
  uploadingProof.value = true
  proofError.value = null
  try {
    await submitBankTransferProof(createdOrder.value.id, proofFile.value)
    proofUploaded.value = true
    createdOrder.value = await getOrder(createdOrder.value.id)
  } catch (e) {
    proofError.value = e instanceof ApiError ? e.message : t('checkout.confirmation.proofUploadError')
  } finally {
    uploadingProof.value = false
  }
}

async function refreshOrderStatus() {
  if (!createdOrder.value) return
  createdOrder.value = await getOrder(createdOrder.value.id)
}
</script>

<template>
  <ShopLayout>
    <h1 class="mb-4 font-display text-xl font-semibold text-stone-800 dark:text-stone-100">{{ t('checkout.title') }}</h1>

    <div v-if="loadingSteps" class="py-16 text-center text-sm text-stone-400">{{ t('checkout.loadingSteps') }}</div>

    <div v-else class="grid grid-cols-1 gap-6 lg:grid-cols-[1fr_320px]">
      <div class="rounded-2xl border border-stone-200 bg-white p-4 dark:border-stone-800 dark:bg-stone-900">
        <!-- Stepper -->
        <ol class="mb-5 flex gap-1 overflow-x-auto pb-1">
          <li
            v-for="(step, i) in activeSteps"
            :key="step.key"
            class="flex shrink-0 items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium"
            :class="
              i === currentIndex
                ? 'bg-brand-600 text-white dark:bg-brand-500'
                : i < currentIndex
                  ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-400'
                  : 'bg-stone-100 text-stone-400 dark:bg-stone-800'
            "
          >
            <AppIcon v-if="i < currentIndex" name="check" :size="12" />
            <span>{{ i + 1 }}. {{ STEP_LABELS[step.key] ?? step.key }}</span>
          </li>
        </ol>

        <!-- Step: Account (guest only) -->
        <div v-if="currentStep === 'account'" class="space-y-4 text-center">
          <AppIcon name="lock" :size="32" class="mx-auto text-stone-300 dark:text-stone-600" />
          <p class="text-sm text-stone-600 dark:text-stone-300">{{ t('checkout.account.prompt') }}</p>
          <div class="flex justify-center gap-3">
            <RouterLink :to="{ name: 'login', query: { redirect: '/checkout' } }">
              <AppButton variant="secondary">{{ t('checkout.account.login') }}</AppButton>
            </RouterLink>
            <RouterLink :to="{ name: 'register', query: { redirect: '/checkout' } }">
              <AppButton>{{ t('checkout.account.register') }}</AppButton>
            </RouterLink>
          </div>
        </div>

        <!-- Step: Address -->
        <div v-else-if="currentStep === 'address'" class="space-y-4">
          <h2 class="font-display text-base font-semibold text-stone-800 dark:text-stone-100">{{ t('checkout.address.title') }}</h2>

          <!-- Saved address picker (konsumen only) -->
          <div v-if="savedAddresses.length" class="space-y-2">
            <label class="mb-1 block text-sm font-medium text-stone-700 dark:text-stone-200">{{ t('checkout.address.savedAddressLabel') }}</label>
            <div class="space-y-2">
              <button
                v-for="addr in savedAddresses"
                :key="addr.id"
                type="button"
                class="block w-full rounded-lg border px-3 py-2 text-left text-sm"
                :class="selectedSavedAddressId === addr.id ? 'border-brand-500 bg-brand-50 dark:bg-brand-950/30' : 'border-stone-200 dark:border-stone-700'"
                @click="selectSavedAddress(addr.id)"
              >
                <span class="font-medium text-stone-800 dark:text-stone-100">{{ addr.label }} — {{ addr.recipient_name }}</span>
                <span class="block text-xs text-stone-500 dark:text-stone-400">{{ addr.address_line }}</span>
              </button>
              <button
                type="button"
                class="block w-full rounded-lg border px-3 py-2 text-left text-sm"
                :class="selectedSavedAddressId === 'new' ? 'border-brand-500 bg-brand-50 dark:bg-brand-950/30' : 'border-stone-200 dark:border-stone-700'"
                @click="selectSavedAddress('new')"
              >
                {{ t('checkout.address.useNewAddress') }}
              </button>
            </div>
          </div>

          <template v-if="selectedSavedAddressId === 'new'">
            <div>
              <label class="mb-1 block text-sm font-medium text-stone-700 dark:text-stone-200">{{ t('checkout.address.recipientName') }}</label>
              <input v-model="destination.recipient_name" type="text" class="w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950" />
            </div>
            <div>
              <label class="mb-1 block text-sm font-medium text-stone-700 dark:text-stone-200">{{ t('checkout.address.phone') }}</label>
              <input v-model="destination.recipient_phone" type="tel" class="w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950" />
            </div>
          </template>
          <div v-if="selectedSavedAddressId === 'new'" class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <div>
              <label class="mb-1 block text-sm font-medium text-stone-700 dark:text-stone-200">{{ t('checkout.address.province') }}</label>
              <select v-model="selectedProvince" class="w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950">
                <option :value="null" disabled>{{ t('checkout.address.provincePlaceholder') }}</option>
                <option v-for="p in provinces" :key="p.id" :value="p.id">{{ p.name }}</option>
              </select>
            </div>
            <div>
              <label class="mb-1 block text-sm font-medium text-stone-700 dark:text-stone-200">{{ t('checkout.address.regency') }}</label>
              <select v-model="selectedRegency" :disabled="!selectedProvince" class="w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm disabled:opacity-50 dark:border-stone-700 dark:bg-stone-950">
                <option :value="null" disabled>{{ t('checkout.address.regencyPlaceholder') }}</option>
                <option v-for="r in regencies" :key="r.id" :value="r.id">{{ r.name }}</option>
              </select>
            </div>
            <div>
              <label class="mb-1 block text-sm font-medium text-stone-700 dark:text-stone-200">{{ t('checkout.address.district') }}</label>
              <select v-model="selectedDistrict" :disabled="!selectedRegency" class="w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm disabled:opacity-50 dark:border-stone-700 dark:bg-stone-950">
                <option :value="null" disabled>{{ t('checkout.address.districtPlaceholder') }}</option>
                <option v-for="d in districts" :key="d.id" :value="d.id">{{ d.name }}</option>
              </select>
            </div>
            <div>
              <label class="mb-1 block text-sm font-medium text-stone-700 dark:text-stone-200">{{ t('checkout.address.village') }}</label>
              <select v-model="destination.village_id" :disabled="!selectedDistrict" class="w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm disabled:opacity-50 dark:border-stone-700 dark:bg-stone-950">
                <option :value="null" disabled>{{ t('checkout.address.villagePlaceholder') }}</option>
                <option v-for="v in villages" :key="v.id" :value="v.id">{{ v.name }}</option>
              </select>
            </div>
          </div>
          <template v-if="selectedSavedAddressId === 'new'">
            <div>
              <label class="mb-1 block text-sm font-medium text-stone-700 dark:text-stone-200">{{ t('checkout.address.fullAddress') }}</label>
              <textarea v-model="destination.address_line" rows="3" class="w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950" />
            </div>

            <!-- Google Maps picker/autocomplete — only when OpenRoute is the active shipping provider. -->
            <AddressMapPicker v-if="mapPickerEnabled" @picked="onMapPicked" />

            <div>
              <button
                type="button"
                class="flex items-center gap-2 rounded-lg border border-stone-200 px-3 py-2 text-sm font-medium text-stone-700 dark:border-stone-700 dark:text-stone-200"
                :disabled="locating"
                @click="useMyLocation"
              >
                <AppIcon name="map-pin" :size="16" />
                {{ locating ? t('checkout.address.detectingLocation') : t('checkout.address.useMyLocation') }}
              </button>
              <p v-if="locationError" class="mt-1.5 text-xs text-red-600">{{ locationError }}</p>
            </div>

            <div v-if="destination.latitude !== null" class="grid grid-cols-2 gap-3">
              <div>
                <label class="mb-1 block text-xs font-medium text-stone-500 dark:text-stone-400">{{ t('checkout.address.latitude') }}</label>
                <input :value="destination.latitude" type="text" readonly class="w-full rounded-lg border border-stone-200 bg-stone-50 px-3 py-2 text-sm text-stone-500 dark:border-stone-700 dark:bg-stone-800 dark:text-stone-400" />
              </div>
              <div>
                <label class="mb-1 block text-xs font-medium text-stone-500 dark:text-stone-400">{{ t('checkout.address.longitude') }}</label>
                <input :value="destination.longitude" type="text" readonly class="w-full rounded-lg border border-stone-200 bg-stone-50 px-3 py-2 text-sm text-stone-500 dark:border-stone-700 dark:bg-stone-800 dark:text-stone-400" />
              </div>
              <p class="col-span-2 text-xs text-emerald-600">{{ t('checkout.address.coordinatesDetected') }}</p>
            </div>

            <label v-if="auth.user?.role === 'konsumen'" class="flex items-center gap-2 text-sm text-stone-600 dark:text-stone-300">
              <input v-model="saveThisAddress" type="checkbox" />
              {{ t('checkout.address.saveForNextTime') }}
            </label>
          </template>
        </div>

        <!-- Step: Referral — either read-only agent-context info (konsumen ordering for themselves), or a konsumen picker (agen/korsal/sales ordering on behalf of one of their own downline konsumen). -->
        <div v-else-if="currentStep === 'referral'" class="space-y-3">
          <h2 class="font-display text-base font-semibold text-stone-800 dark:text-stone-100">{{ t('checkout.referral.title') }}</h2>

          <template v-if="needsKonsumenSelection">
            <p class="text-sm text-stone-600 dark:text-stone-300">{{ t('checkout.referral.pickKonsumenPrompt') }}</p>

            <div v-if="selectedKonsumen" class="flex items-center justify-between rounded-xl border border-brand-300 bg-brand-50 p-3 text-sm dark:bg-brand-950/40">
              <span class="font-medium text-stone-800 dark:text-stone-100">{{ selectedKonsumen.name }} &middot; {{ selectedKonsumen.phone }}</span>
              <button type="button" class="text-xs font-medium text-brand-600 dark:text-brand-400" @click="selectedKonsumen = null">
                {{ t('checkout.referral.change') }}
              </button>
            </div>
            <template v-else>
              <input
                v-model="konsumenSearch"
                type="text"
                :placeholder="t('checkout.referral.searchPlaceholder')"
                class="w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950"
              />
              <p v-if="searchingKonsumen" class="text-xs text-stone-400">{{ t('checkout.referral.searching') }}</p>
              <ul v-else-if="konsumenResults.length" class="divide-y divide-stone-100 overflow-hidden rounded-xl border border-stone-200 dark:divide-stone-800 dark:border-stone-700">
                <li v-for="k in konsumenResults" :key="k.id">
                  <button
                    type="button"
                    class="flex w-full items-center justify-between px-3 py-2 text-left text-sm hover:bg-stone-50 dark:hover:bg-stone-800"
                    @click="pickKonsumen(k)"
                  >
                    <span class="text-stone-800 dark:text-stone-100">{{ k.name }}</span>
                    <span class="text-xs text-stone-400">{{ k.phone }}</span>
                  </button>
                </li>
              </ul>
              <p v-else-if="konsumenSearch.trim()" class="text-xs text-stone-400">{{ t('checkout.referral.noResults') }}</p>
            </template>
          </template>
          <p v-else class="text-sm text-stone-600 dark:text-stone-300">
            {{ t('checkout.referral.body') }}
          </p>
        </div>

        <!-- Step: Products (cart review) -->
        <div v-else-if="currentStep === 'products'" class="space-y-3">
          <div class="flex items-center justify-between">
            <h2 class="font-display text-base font-semibold text-stone-800 dark:text-stone-100">{{ t('checkout.products.title') }}</h2>
            <RouterLink :to="{ name: 'cart' }" class="text-xs font-medium text-brand-600 dark:text-brand-400">{{ t('checkout.products.editCart') }}</RouterLink>
          </div>
          <ul class="space-y-2">
            <li v-for="line in cart.lines" :key="line.key" class="flex justify-between gap-2 rounded-lg border border-stone-100 p-2.5 text-sm dark:border-stone-800">
              <span class="line-clamp-2 text-stone-700 dark:text-stone-200">{{ line.name }} <span v-if="line.variationLabel">({{ line.variationLabel }})</span> &times;{{ line.quantity }}</span>
              <span class="shrink-0 font-medium text-stone-800 dark:text-stone-100">{{ formatRupiah(line.unitPrice * line.quantity) }}</span>
            </li>
          </ul>
        </div>

        <!-- Step: Shipping -->
        <div v-else-if="currentStep === 'shipping'" class="space-y-3">
          <h2 class="font-display text-base font-semibold text-stone-800 dark:text-stone-100">{{ t('checkout.shipping.title') }}</h2>

          <template v-if="shippingMethods.length > 1">
            <p class="text-sm text-stone-600 dark:text-stone-300">{{ t('checkout.shipping.choosePrompt') }}</p>
            <label
              v-for="method in shippingMethods"
              :key="method.code"
              class="flex cursor-pointer items-center gap-3 rounded-xl border p-3 text-sm"
              :class="selectedShippingMethod === method.code ? 'border-brand-500 bg-brand-50 dark:bg-brand-950/40' : 'border-stone-200 dark:border-stone-700'"
            >
              <input v-model="selectedShippingMethod" type="radio" :value="method.code" class="accent-brand-600" />
              <span class="font-medium text-stone-800 dark:text-stone-100">{{ method.label }}</span>
              <span class="text-xs text-stone-400">
                {{ method.code === 'rajaongkir' ? t('checkout.shipping.rajaongkirHint') : t('checkout.shipping.openrouteHint') }}
              </span>
            </label>
          </template>
          <p v-else-if="shippingMethods.length === 1" class="text-sm text-stone-600 dark:text-stone-300">
            {{ t('checkout.shipping.methodLabel') }} <strong>{{ shippingMethods[0]?.label }}</strong>
          </p>

          <p class="text-xs text-stone-400">
            {{ t('checkout.shipping.note') }}
          </p>
        </div>

        <!-- Step: Delivery date -->
        <div v-else-if="currentStep === 'delivery_date'" class="space-y-3">
          <h2 class="font-display text-base font-semibold text-stone-800 dark:text-stone-100">{{ t('checkout.deliveryDate.title') }}</h2>
          <label class="mb-1 block text-sm font-medium text-stone-700 dark:text-stone-200">{{ t('checkout.deliveryDate.label') }}</label>
          <input v-model="deliveryDate" type="date" :min="minDeliveryDate" class="rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950" />
        </div>

        <!-- Step: Payment -->
        <div v-else-if="currentStep === 'payment'" class="space-y-3">
          <h2 class="font-display text-base font-semibold text-stone-800 dark:text-stone-100">{{ t('checkout.payment.title') }}</h2>
          <label
            v-for="method in paymentMethods"
            :key="method.code"
            class="flex cursor-pointer items-center gap-3 rounded-xl border p-3 text-sm"
            :class="selectedPaymentCode === method.code ? 'border-brand-500 bg-brand-50 dark:bg-brand-950/40' : 'border-stone-200 dark:border-stone-700'"
          >
            <input v-model="selectedPaymentCode" type="radio" :value="method.code" class="accent-brand-600" />
            <span class="font-medium text-stone-800 dark:text-stone-100">{{ method.name }}</span>
            <span class="text-xs text-stone-400">
              {{ method.code === 'down_payment' ? t('checkout.payment.dpHint') : method.type === 'cod' ? t('checkout.payment.codHint') : method.type === 'manual' ? t('checkout.payment.manualHint') : t('checkout.payment.gatewayHint') }}
            </span>
          </label>

          <div v-if="isDpSelected" class="rounded-xl border border-brand-200 bg-brand-50/60 p-3 dark:border-brand-800 dark:bg-brand-950/30">
            <label class="mb-1 block text-sm font-medium text-stone-700 dark:text-stone-200">{{ t('checkout.payment.dpLabel') }}</label>
            <input
              v-model="dpAmount"
              type="number"
              min="1"
              inputmode="numeric"
              class="w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950"
            />
            <p class="mt-1 text-xs text-stone-500 dark:text-stone-400">{{ t('checkout.payment.dpInputHint') }}</p>
          </div>

          <p v-if="!paymentMethods.length" class="text-sm text-stone-400">{{ t('checkout.payment.empty') }}</p>
        </div>

        <!-- Step: Review -->
        <div v-else-if="currentStep === 'review'" class="space-y-4">
          <h2 class="font-display text-base font-semibold text-stone-800 dark:text-stone-100">{{ t('checkout.review.title') }}</h2>

          <div v-if="quoting" class="text-sm text-stone-400">{{ t('checkout.review.calculating') }}</div>
          <p v-else-if="quoteError" class="rounded-lg bg-red-50 p-3 text-sm text-red-700 dark:bg-red-950 dark:text-red-400">{{ quoteError }}</p>
          <template v-else-if="quote">
            <ul v-if="quote.warnings.length" class="space-y-1 rounded-lg bg-amber-50 p-3 text-xs text-amber-700 dark:bg-amber-950 dark:text-amber-400">
              <li v-for="(w, i) in quote.warnings" :key="i">{{ w.message }}</li>
            </ul>
            <dl class="space-y-1.5 text-sm">
              <div class="flex justify-between text-stone-600 dark:text-stone-300"><dt>{{ t('checkout.review.subtotal') }}</dt><dd>{{ formatRupiah(quote.subtotal_amount) }}</dd></div>
              <div class="flex justify-between text-stone-600 dark:text-stone-300">
                <dt>{{ t('checkout.review.shipping') }}</dt><dd>{{ quote.shipping_enabled ? formatRupiah(quote.shipping_fee_amount) : t('checkout.review.free') }}</dd>
              </div>
              <div class="flex justify-between text-stone-600 dark:text-stone-300"><dt>{{ t('checkout.review.adminFee') }}</dt><dd>{{ formatRupiah(quote.admin_fee_amount) }}</dd></div>
              <div class="flex justify-between border-t border-stone-100 pt-1.5 font-semibold text-stone-800 dark:border-stone-800 dark:text-stone-100">
                <dt>{{ t('checkout.review.total') }}</dt><dd>{{ formatRupiah(quote.total_amount) }}</dd>
              </div>
            </dl>

            <div v-if="isDpSelected && Number(dpAmount) > 0" class="rounded-lg bg-brand-50 p-3 text-xs text-brand-700 dark:bg-brand-950/40 dark:text-brand-300">
              <p>{{ t('checkout.payment.dpNow', { amount: formatRupiah(Number(dpAmount)) }) }}</p>
              <p>{{ t('checkout.payment.dpRemaining', { amount: formatRupiah(Math.max(quote.total_amount - Number(dpAmount), 0)) }) }}</p>
            </div>
          </template>

          <div class="space-y-1 rounded-lg bg-stone-50 p-3 text-xs text-stone-500 dark:bg-stone-800/60 dark:text-stone-400">
            <p>{{ t('checkout.review.shippingTo', { name: destination.recipient_name, address: destination.address_line }) }}</p>
            <p v-if="deliveryDate">{{ t('checkout.review.preferredDate', { date: deliveryDate }) }}</p>
            <p v-if="shippingMethods.length">{{ t('checkout.review.shippingMethod', { method: shippingMethods.find((m) => m.code === selectedShippingMethod)?.label }) }}</p>
            <p>{{ t('checkout.review.paymentMethod', { method: selectedPaymentMethod?.name }) }}</p>
          </div>

          <p v-if="submitError" class="rounded-lg bg-red-50 p-3 text-sm text-red-700 dark:bg-red-950 dark:text-red-400">{{ submitError }}</p>

          <AppButton size="lg" block :disabled="submitting || quoting" @click="placeOrder">
            {{ submitting ? t('checkout.review.submitting') : t('checkout.review.submit') }}
          </AppButton>
        </div>

        <!-- Step: Confirmation -->
        <div v-else-if="currentStep === 'confirmation' && createdOrder" class="space-y-4 text-center">
          <AppIcon name="check" :size="36" class="mx-auto rounded-full bg-emerald-100 p-2 text-emerald-600 dark:bg-emerald-950" />
          <div>
            <p class="font-display text-lg font-semibold text-stone-800 dark:text-stone-100">{{ t('checkout.confirmation.success') }}</p>
            <p class="text-sm text-stone-500 dark:text-stone-400">{{ t('checkout.confirmation.orderNo', { no: createdOrder.order_no }) }}</p>
          </div>

          <!-- COD -->
          <p v-if="createdOrder.payment_method?.type === 'cod'" class="rounded-lg bg-stone-50 p-3 text-left text-sm text-stone-600 dark:bg-stone-800/60 dark:text-stone-300">
            {{ t('checkout.confirmation.codInstructions', { amount: formatRupiah(createdOrder.total_amount) }) }}
          </p>

          <!-- Manual bank transfer -->
          <div v-else-if="createdOrder.payment_method?.type === 'manual'" class="space-y-3 rounded-lg bg-stone-50 p-3 text-left text-sm dark:bg-stone-800/60">
            <p class="font-medium text-stone-700 dark:text-stone-200">{{ t('checkout.confirmation.transferTo') }}</p>
            <p class="text-stone-600 dark:text-stone-300">
              {{ createdOrder.payment_transaction?.instructions?.bank_name }} —
              {{ createdOrder.payment_transaction?.instructions?.account_number }} a/n
              {{ createdOrder.payment_transaction?.instructions?.account_name }}
            </p>
            <p class="font-semibold text-stone-800 dark:text-stone-100">{{ t('checkout.confirmation.total', { amount: formatRupiah(createdOrder.total_amount) }) }}</p>

            <div v-if="!createdOrder.payment_transaction?.bank_transfer_verification">
              <label class="mb-1 block text-xs font-medium text-stone-600 dark:text-stone-300">{{ t('checkout.confirmation.uploadProof') }}</label>
              <input type="file" accept="image/*" class="block w-full text-xs" @change="onProofSelected" />
              <p v-if="proofError" class="mt-1 text-xs text-red-600">{{ proofError }}</p>
              <AppButton class="mt-2" size="sm" :disabled="!proofFile || uploadingProof" @click="uploadProof">
                {{ uploadingProof ? t('checkout.confirmation.uploading') : t('checkout.confirmation.sendProof') }}
              </AppButton>
            </div>
            <div v-else class="flex items-center justify-between rounded-lg bg-white p-2 text-xs dark:bg-stone-900">
              <span>
                {{ t('checkout.confirmation.verificationStatus') }}
                <strong>{{ { pending: t('checkout.verificationStatus.pending'), verified: t('checkout.verificationStatus.verified'), rejected: t('checkout.verificationStatus.rejected') }[createdOrder.payment_transaction!.bank_transfer_verification!.status] }}</strong>
              </span>
              <button type="button" class="font-medium text-brand-600 dark:text-brand-400" @click="refreshOrderStatus">{{ t('checkout.confirmation.checkStatus') }}</button>
            </div>
          </div>

          <!-- Gateway -->
          <div v-else-if="createdOrder.payment_method?.type === 'gateway'" class="space-y-2 rounded-lg bg-stone-50 p-3 text-left text-sm dark:bg-stone-800/60">
            <p class="text-stone-600 dark:text-stone-300">
              {{ (createdOrder.payment_transaction?.instructions?.instructions as string) || t('checkout.confirmation.gatewayFallback') }}
            </p>
            <p class="font-semibold text-stone-800 dark:text-stone-100">{{ t('checkout.confirmation.total', { amount: formatRupiah(createdOrder.total_amount) }) }}</p>
          </div>

          <AppButton variant="secondary" block @click="router.push({ name: 'order-detail', params: { id: createdOrder.id } })">
            {{ t('checkout.confirmation.viewOrderDetail') }}
          </AppButton>
        </div>

        <!-- Navigation -->
        <div v-if="currentStep && currentStep !== 'confirmation'" class="mt-6 flex justify-between border-t border-stone-100 pt-4 dark:border-stone-800">
          <AppButton v-if="currentIndex > 0" variant="ghost" @click="goBack">
            <AppIcon name="chevron-left" :size="16" /> {{ t('checkout.nav.back') }}
          </AppButton>
          <span v-else />
          <AppButton
            v-if="currentStep !== 'review' && currentStep !== 'account'"
            :disabled="
              (currentStep === 'address' && !addressComplete) ||
              (currentStep === 'referral' && !konsumenSelected) ||
              (currentStep === 'shipping' && shippingMethods.length > 1 && !selectedShippingMethod) ||
              (currentStep === 'payment' && !selectedPaymentCode)
            "
            @click="goNext"
          >
            {{ t('checkout.nav.next') }} <AppIcon name="chevron-right" :size="16" />
          </AppButton>
        </div>
      </div>

      <aside class="h-fit rounded-2xl border border-stone-200 bg-white p-4 dark:border-stone-800 dark:bg-stone-900">
        <h2 class="mb-3 font-display text-base font-semibold text-stone-800 dark:text-stone-100">{{ t('checkout.summary.title') }}</h2>
        <ul class="mb-3 space-y-2 text-sm">
          <li v-for="line in cart.lines" :key="line.key" class="flex justify-between gap-2 text-stone-600 dark:text-stone-300">
            <span class="line-clamp-2">{{ line.name }} &times;{{ line.quantity }}</span>
            <span class="shrink-0">{{ formatRupiah(line.unitPrice * line.quantity) }}</span>
          </li>
        </ul>
        <div class="flex justify-between border-t border-stone-100 pt-3 text-sm font-semibold text-stone-800 dark:border-stone-800 dark:text-stone-100">
          <span>{{ t('checkout.summary.subtotal') }}</span>
          <span>{{ formatRupiah(cart.subtotal) }}</span>
        </div>
        <p class="mt-1 text-xs text-stone-400">{{ t('checkout.summary.note') }}</p>
      </aside>
    </div>
  </ShopLayout>
</template>
