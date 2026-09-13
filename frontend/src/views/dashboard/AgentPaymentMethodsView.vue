<script setup lang="ts">
import { ref, reactive, computed, onMounted } from 'vue'
import DashboardLayout from '@/layouts/DashboardLayout.vue'
import AppButton from '@/components/ui/AppButton.vue'
import PasswordInput from '@/components/ui/PasswordInput.vue'
import {
  getAgentPaymentMethods,
  toggleAgentPaymentMethod,
  setAgentPaymentMethodEnvironment,
  saveAgentPaymentMethodConfig,
  type AgentPaymentMethodRow,
} from '@/api/agentSettings'
import { ApiError } from '@/api/client'

/**
 * Agen-only: enable/disable every payment method (cod/manual/gateway) for
 * this agen's own branch, plus — for manual/gateway types — the agen's own
 * credentials (bank account, or Xendit/Tripay/Stripe API keys) and, for
 * gateway types, which environment is active. Never another agen's branch
 * — see AgentPaymentMethodController. A method super_admin has disabled
 * globally stays disabled here regardless of this toggle.
 */

const CREDENTIAL_FIELDS: Record<string, { key: string; label: string }[]> = {
  bank_transfer: [
    { key: 'bank_name', label: 'Nama Bank' },
    { key: 'account_name', label: 'Nama Pemilik Rekening' },
    { key: 'account_number', label: 'Nomor Rekening' },
  ],
  xendit: [
    { key: 'secret_key', label: 'Secret Key' },
    { key: 'callback_token', label: 'Callback Verification Token' },
  ],
  tripay: [
    { key: 'merchant_code', label: 'Merchant Code' },
    { key: 'private_key', label: 'Private Key' },
    { key: 'api_key', label: 'API Key' },
  ],
  stripe: [
    { key: 'secret_key', label: 'Secret Key' },
    { key: 'webhook_secret', label: 'Webhook Signing Secret' },
  ],
}

function isSecretField(key: string): boolean {
  return key.includes('key') || key.includes('secret') || key.includes('token')
}

/**
 * Methods that carry no credentials of their own: cod is cash, and DP reuses
 * the branch's existing Transfer Bank account (see
 * PaymentService::initiateDownPayment) — neither must ever show a credentials
 * form. For DP that form would render zero fields (no CREDENTIAL_FIELDS
 * entry), leaving the agen unable to configure anything.
 */
const NO_CREDENTIAL_METHODS = ['cod', 'down_payment']

function hasOwnCredentials(code: string): boolean {
  return !NO_CREDENTIAL_METHODS.includes(code)
}

const methods = ref<AgentPaymentMethodRow[]>([])
/** DP pays into the branch's Transfer Bank account, so its usability follows that method's own config. */
const bankTransferConfigured = computed(
  () => methods.value.find((m) => m.code === 'bank_transfer')?.configured === true,
)
const loading = ref(true)
const loadError = ref<string | null>(null)
const togglingId = ref<number | null>(null)
const editingId = ref<number | null>(null)
const formValues = reactive<Record<string, string>>({})
const submitting = ref(false)
const errors = ref<Record<string, string[]>>({})
const generalError = ref<string | null>(null)

async function load() {
  loading.value = true
  loadError.value = null
  try {
    methods.value = await getAgentPaymentMethods()
  } catch (e) {
    loadError.value = e instanceof ApiError ? e.message : 'Gagal memuat metode pembayaran.'
  } finally {
    loading.value = false
  }
}
onMounted(load)

async function toggle(method: AgentPaymentMethodRow) {
  togglingId.value = method.id
  try {
    await toggleAgentPaymentMethod(method.id)
    await load()
  } finally {
    togglingId.value = null
  }
}

async function setEnvironment(method: AgentPaymentMethodRow, environment: 'sandbox' | 'production') {
  await setAgentPaymentMethodEnvironment(method.id, environment)
  await load()
}

function openForm(method: AgentPaymentMethodRow) {
  editingId.value = method.id
  errors.value = {}
  generalError.value = null
  for (const field of CREDENTIAL_FIELDS[method.code] ?? []) {
    formValues[field.key] = ''
  }
}

function closeForm() {
  editingId.value = null
}

async function submitConfig(method: AgentPaymentMethodRow) {
  submitting.value = true
  errors.value = {}
  generalError.value = null
  try {
    const config: Record<string, string> = {}
    for (const field of CREDENTIAL_FIELDS[method.code] ?? []) {
      config[field.key] = formValues[field.key] ?? ''
    }
    await saveAgentPaymentMethodConfig(method.id, config, method.type === 'gateway' ? (method.active_environment ?? 'sandbox') : undefined)
    closeForm()
    await load()
  } catch (e) {
    if (e instanceof ApiError) {
      generalError.value = e.message
      errors.value = e.errors ?? {}
    } else {
      generalError.value = 'Gagal menyimpan konfigurasi.'
    }
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <DashboardLayout>
    <h1 class="mb-1 font-display text-xl font-semibold text-stone-800 dark:text-stone-100">Metode Pembayaran Saya</h1>
    <p class="mb-5 text-sm text-stone-500 dark:text-stone-400">
      Aktifkan/nonaktifkan metode pembayaran dan atur kredensial Anda sendiri (rekening bank atau API key gateway) —
      terpisah dari agen lain, dan tidak memengaruhi status aktif global.
    </p>

    <div v-if="loading" class="space-y-3">
      <div v-for="i in 3" :key="i" class="h-20 animate-pulse rounded-2xl bg-stone-100 dark:bg-stone-800" />
    </div>
    <p v-else-if="loadError" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-950 dark:text-red-300">{{ loadError }}</p>

    <div v-else-if="!loadError" class="space-y-3">
      <div v-for="method in methods" :key="method.id" class="rounded-2xl border border-stone-200 bg-white p-4 dark:border-stone-800 dark:bg-stone-900">
        <div class="flex flex-wrap items-center justify-between gap-3">
          <div>
            <h2 class="font-display text-base font-semibold text-stone-800 dark:text-stone-100">{{ method.name }}</h2>
            <p class="text-xs text-stone-400">{{ method.code }} &middot; {{ method.type }}</p>
            <p v-if="!method.globally_active" class="mt-1 text-xs font-medium text-amber-600 dark:text-amber-400">
              Dinonaktifkan secara global oleh Super Admin — toggle di sini tidak akan mengaktifkannya.
            </p>
          </div>
          <div class="flex items-center gap-2">
            <span
              class="rounded-full px-2.5 py-1 text-xs font-medium"
              :class="method.is_active ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-400' : 'bg-stone-100 text-stone-500 dark:bg-stone-800'"
            >
              {{ method.is_active ? 'Aktif' : 'Nonaktif' }}
            </span>
            <AppButton size="sm" variant="secondary" :disabled="togglingId === method.id" @click="toggle(method)">
              {{ method.is_active ? 'Nonaktifkan' : 'Aktifkan' }}
            </AppButton>
          </div>
        </div>

        <p v-if="method.code === 'down_payment'" class="mt-3 text-xs text-stone-500 dark:text-stone-400">
          DP memakai rekening Transfer Bank cabang Anda, jadi tidak ada kredensial terpisah untuk diatur.
          <span v-if="!bankTransferConfigured" class="font-medium text-amber-600 dark:text-amber-400">
            Atur dulu rekening Transfer Bank di atas sebelum DP bisa dipakai.
          </span>
        </p>

        <div v-if="hasOwnCredentials(method.code)" class="mt-3 flex flex-wrap items-center gap-3">
          <span
            class="flex items-center gap-1 text-xs"
            :class="method.configured ? 'text-emerald-600' : 'text-stone-400'"
          >
            {{ method.configured ? 'Sudah dikonfigurasi ✓' : 'Belum dikonfigurasi' }}
          </span>
          <button type="button" class="text-xs font-medium text-brand-600 dark:text-brand-400" @click="openForm(method)">
            Atur kredensial
          </button>

          <div v-if="method.type === 'gateway'" class="flex items-center gap-2 text-sm">
            <span class="text-stone-500 dark:text-stone-400">Environment:</span>
            <button
              v-for="env in (['sandbox', 'production'] as const)"
              :key="env"
              type="button"
              class="rounded-full px-2.5 py-1 text-xs font-medium"
              :class="method.active_environment === env ? 'bg-brand-600 text-white' : 'bg-stone-100 text-stone-600 dark:bg-stone-800 dark:text-stone-300'"
              @click="setEnvironment(method, env)"
            >
              {{ env }}
            </button>
          </div>
        </div>

        <form
          v-if="editingId === method.id"
          class="mt-3 space-y-2.5 rounded-xl bg-stone-50 p-3 dark:bg-stone-800/60"
          @submit.prevent="submitConfig(method)"
        >
          <p v-if="generalError" class="rounded-lg bg-red-50 p-2 text-xs text-red-700 dark:bg-red-950 dark:text-red-400">{{ generalError }}</p>
          <div v-for="field in CREDENTIAL_FIELDS[method.code] ?? []" :key="field.key">
            <label class="mb-1 block text-xs font-medium text-stone-600 dark:text-stone-300">{{ field.label }}</label>
            <PasswordInput v-if="isSecretField(field.key)" v-model="formValues[field.key]" autocomplete="off" />
            <input
              v-else
              v-model="formValues[field.key]"
              type="text"
              autocomplete="off"
              class="w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950"
            />
            <p v-if="errors[`config.${field.key}`]?.length" class="mt-1 text-xs text-red-600">{{ errors[`config.${field.key}`]![0] }}</p>
          </div>
          <div class="flex gap-2 pt-1">
            <AppButton size="sm" type="submit" :disabled="submitting">{{ submitting ? 'Menyimpan...' : 'Simpan' }}</AppButton>
            <AppButton size="sm" variant="ghost" type="button" @click="closeForm">Batal</AppButton>
          </div>
        </form>
      </div>
      <p v-if="methods.length === 0" class="text-sm text-stone-400">Tidak ada metode pembayaran yang tersedia.</p>
    </div>
  </DashboardLayout>
</template>
