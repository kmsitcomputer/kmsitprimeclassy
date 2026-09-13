<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import ShopLayout from '@/layouts/ShopLayout.vue'
import AppButton from '@/components/ui/AppButton.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import ImageUploader from '@/components/ui/ImageUploader.vue'
import { useAuthStore } from '@/stores/auth'
import { useUiStore } from '@/stores/ui'
import { updateProfile, updatePassword, updateReferralCode, regenerateReferralCode, deleteReferralCode } from '@/api/auth'
import { listOrders } from '@/api/orders'
import type { Order } from '@/api/types'
import { ApiError } from '@/api/client'
import { formatApiError } from '@/utils/apiError'
import { formatDate, orderStatusLabel } from '@/utils/format'

const { t } = useI18n()
const auth = useAuthStore()
const ui = useUiStore()
const router = useRouter()

/** Lightweight "dashboard" widget for konsumen — total order count + the most recent one, at the top of their account hub. */
const orderSummary = ref<{ total: number; latest: Order | null } | null>(null)
if (auth.user?.role === 'konsumen') {
  listOrders(1).then((result) => {
    orderSummary.value = { total: result.meta.total, latest: result.orders[0] ?? null }
  })
}

const avatarMediaId = ref<number | null>(null)
const avatarUrl = ref<string | null>(auth.user?.avatar_url ?? null)

const referralCopied = ref(false)
async function copyReferralCode() {
  if (!auth.user?.referral_code) return
  await navigator.clipboard.writeText(auth.user.referral_code)
  referralCopied.value = true
  setTimeout(() => (referralCopied.value = false), 2000)
}

/* Self-service referral code CRUD — agen/korsal/sales only (see ProfileController). */
const canManageReferralCode = computed(() => ['agen', 'korsal', 'sales'].includes(auth.user?.role ?? ''))
const editingReferralCode = ref(false)
const referralCodeDraft = ref('')
const referralCodeSaving = ref(false)
const referralCodeError = ref('')

function openReferralCodeEdit() {
  referralCodeDraft.value = auth.user?.referral_code ?? ''
  referralCodeError.value = ''
  editingReferralCode.value = true
}

async function saveReferralCode() {
  referralCodeSaving.value = true
  referralCodeError.value = ''
  try {
    await updateReferralCode(referralCodeDraft.value.trim().toUpperCase())
    await auth.refresh()
    editingReferralCode.value = false
  } catch (e) {
    referralCodeError.value = e instanceof ApiError ? formatApiError(e) : t('profile.referralCodeError')
  } finally {
    referralCodeSaving.value = false
  }
}

async function doRegenerateReferralCode() {
  referralCodeSaving.value = true
  referralCodeError.value = ''
  try {
    await regenerateReferralCode()
    await auth.refresh()
  } catch (e) {
    referralCodeError.value = e instanceof ApiError ? formatApiError(e) : t('profile.referralCodeError')
  } finally {
    referralCodeSaving.value = false
  }
}

async function doDeleteReferralCode() {
  if (!confirm(t('profile.referralCodeDeleteConfirm'))) return
  referralCodeSaving.value = true
  referralCodeError.value = ''
  try {
    await deleteReferralCode()
    await auth.refresh()
  } catch (e) {
    referralCodeError.value = e instanceof ApiError ? formatApiError(e) : t('profile.referralCodeError')
  } finally {
    referralCodeSaving.value = false
  }
}

const profileForm = reactive({
  name: auth.user?.name ?? '',
  phone: auth.user?.phone ?? '',
  email: auth.user?.email ?? '',
})
const profileSaving = ref(false)
const profileErrors = ref<Record<string, string[]>>({})
const profileSuccess = ref(false)

async function saveProfile() {
  profileSaving.value = true
  profileErrors.value = {}
  profileSuccess.value = false
  try {
    await updateProfile({ name: profileForm.name, phone: profileForm.phone, email: profileForm.email })
    await auth.refresh()
    profileSuccess.value = true
  } catch (e) {
    if (e instanceof ApiError && e.errors) profileErrors.value = e.errors
  } finally {
    profileSaving.value = false
  }
}

async function onAvatarUpdated(mediaId: number | null) {
  avatarMediaId.value = mediaId
  try {
    await updateProfile({ avatar_media_id: mediaId })
    await auth.refresh()
    avatarUrl.value = auth.user?.avatar_url ?? null
  } catch {
    // ImageUploader already surfaces the upload error itself.
  }
}

const passwordForm = reactive({
  current_password: '',
  password: '',
  password_confirmation: '',
})
const passwordSaving = ref(false)
const passwordErrors = ref<Record<string, string[]>>({})
const passwordSuccess = ref(false)

async function savePassword() {
  passwordSaving.value = true
  passwordErrors.value = {}
  passwordSuccess.value = false
  try {
    await updatePassword({ ...passwordForm })
    passwordSuccess.value = true
    passwordForm.current_password = ''
    passwordForm.password = ''
    passwordForm.password_confirmation = ''
  } catch (e) {
    if (e instanceof ApiError && e.errors) passwordErrors.value = e.errors
  } finally {
    passwordSaving.value = false
  }
}

async function handleLogout() {
  await auth.logout()
  router.push({ name: 'home' })
}
</script>

<template>
  <ShopLayout>
    <h1 class="mb-4 font-display text-xl font-semibold text-stone-800 dark:text-stone-100">{{ t('profile.title') }}</h1>

    <div v-if="orderSummary" class="mb-4 grid grid-cols-2 gap-3">
      <div class="rounded-2xl border border-stone-200 bg-white p-4 dark:border-stone-800 dark:bg-stone-900">
        <p class="text-xs text-stone-400">{{ t('profile.totalOrders') }}</p>
        <p class="mt-1 font-display text-xl font-semibold text-stone-800 dark:text-stone-100">{{ orderSummary.total }}</p>
      </div>
      <RouterLink
        v-if="orderSummary.latest"
        :to="{ name: 'order-detail', params: { id: orderSummary.latest.id } }"
        class="rounded-2xl border border-stone-200 bg-white p-4 dark:border-stone-800 dark:bg-stone-900"
      >
        <p class="text-xs text-stone-400">{{ t('profile.latestOrder') }}</p>
        <p class="mt-1 truncate text-sm font-semibold text-stone-800 dark:text-stone-100">{{ orderStatusLabel(orderSummary.latest.status) }}</p>
        <p class="text-xs text-stone-400">{{ formatDate(orderSummary.latest.created_at) }}</p>
      </RouterLink>
    </div>

    <div class="rounded-2xl border border-stone-200 bg-white p-5 dark:border-stone-800 dark:bg-stone-900">
      <div class="mb-5 grid gap-5 sm:grid-cols-[auto_1fr]">
        <ImageUploader
          v-model:media-id="avatarMediaId"
          :url="avatarUrl"
          collection="user_avatar"
          :label="t('profile.avatar')"
          @update:media-id="onAvatarUpdated"
        />

        <form class="space-y-3" @submit.prevent="saveProfile">
          <div>
            <label class="block text-sm font-medium text-stone-700 dark:text-stone-300">{{ t('profile.name') }}</label>
            <input v-model="profileForm.name" type="text" class="mt-1 block w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950" />
            <p v-if="profileErrors.name" class="mt-1 text-xs text-red-600 dark:text-red-400">{{ profileErrors.name[0] }}</p>
          </div>
          <div>
            <label class="block text-sm font-medium text-stone-700 dark:text-stone-300">{{ t('profile.phone') }}</label>
            <input v-model="profileForm.phone" type="text" class="mt-1 block w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950" />
            <p v-if="profileErrors.phone" class="mt-1 text-xs text-red-600 dark:text-red-400">{{ profileErrors.phone[0] }}</p>
          </div>
          <div>
            <label class="block text-sm font-medium text-stone-700 dark:text-stone-300">{{ t('profile.email') }}</label>
            <input v-model="profileForm.email" type="email" class="mt-1 block w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950" />
            <p v-if="profileErrors.email" class="mt-1 text-xs text-red-600 dark:text-red-400">{{ profileErrors.email[0] }}</p>
          </div>
          <div class="flex items-center gap-3">
            <AppButton type="submit" :disabled="profileSaving">{{ profileSaving ? t('profile.saving') : t('profile.save') }}</AppButton>
            <span v-if="profileSuccess" class="text-sm text-emerald-600 dark:text-emerald-400">{{ t('profile.saved') }}</span>
          </div>
        </form>
      </div>
    </div>

    <div v-if="canManageReferralCode" class="mt-4 rounded-2xl border border-stone-200 bg-white p-5 dark:border-stone-800 dark:bg-stone-900">
      <h2 class="mb-2 font-display text-base font-semibold text-stone-800 dark:text-stone-100">{{ t('profile.referralCode') }}</h2>
      <p v-if="referralCodeError" class="mb-2 rounded-lg bg-red-50 px-3 py-2 text-xs text-red-700 dark:bg-red-950 dark:text-red-300">{{ referralCodeError }}</p>

      <template v-if="editingReferralCode">
        <div class="flex flex-wrap items-center gap-2">
          <input
            v-model="referralCodeDraft"
            type="text"
            maxlength="30"
            class="w-48 rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm uppercase tracking-wide dark:border-stone-700 dark:bg-stone-950"
          />
          <AppButton size="sm" :disabled="!referralCodeDraft.trim() || referralCodeSaving" @click="saveReferralCode">
            {{ t('profile.save') }}
          </AppButton>
          <AppButton size="sm" variant="ghost" @click="editingReferralCode = false">{{ t('common.cancel') }}</AppButton>
        </div>
        <p class="mt-1 text-xs text-stone-400">{{ t('profile.referralCodeHint') }}</p>
      </template>

      <template v-else-if="auth.user?.referral_code">
        <div class="flex flex-wrap items-center gap-2">
          <code class="rounded-lg bg-stone-100 px-3 py-2 text-sm font-semibold tracking-wide text-stone-800 dark:bg-stone-800 dark:text-stone-100">{{ auth.user.referral_code }}</code>
          <button type="button" class="text-xs font-medium text-brand-600 dark:text-brand-400" @click="copyReferralCode">
            {{ referralCopied ? t('profile.copied') : t('profile.copy') }}
          </button>
          <button type="button" class="text-xs font-medium text-stone-500 dark:text-stone-400" :disabled="referralCodeSaving" @click="openReferralCodeEdit">
            {{ t('profile.referralCodeEdit') }}
          </button>
          <button type="button" class="text-xs font-medium text-stone-500 dark:text-stone-400" :disabled="referralCodeSaving" @click="doRegenerateReferralCode">
            {{ t('profile.referralCodeRegenerate') }}
          </button>
          <button type="button" class="text-xs font-medium text-red-600 dark:text-red-400" :disabled="referralCodeSaving" @click="doDeleteReferralCode">
            {{ t('profile.referralCodeDelete') }}
          </button>
        </div>
      </template>

      <template v-else>
        <p class="mb-2 text-sm text-stone-500 dark:text-stone-400">{{ t('profile.referralCodeEmpty') }}</p>
        <div class="flex gap-2">
          <AppButton size="sm" :disabled="referralCodeSaving" @click="doRegenerateReferralCode">{{ t('profile.referralCodeGenerate') }}</AppButton>
          <AppButton size="sm" variant="ghost" :disabled="referralCodeSaving" @click="openReferralCodeEdit">{{ t('profile.referralCodeEdit') }}</AppButton>
        </div>
      </template>
    </div>

    <div class="mt-4 rounded-2xl border border-stone-200 bg-white p-5 dark:border-stone-800 dark:bg-stone-900">
      <h2 class="mb-3 font-display text-base font-semibold text-stone-800 dark:text-stone-100">{{ t('profile.changePassword') }}</h2>
      <form class="max-w-md space-y-3" @submit.prevent="savePassword">
        <div>
          <label class="block text-sm font-medium text-stone-700 dark:text-stone-300">{{ t('profile.currentPassword') }}</label>
          <input v-model="passwordForm.current_password" type="password" class="mt-1 block w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950" />
          <p v-if="passwordErrors.current_password" class="mt-1 text-xs text-red-600 dark:text-red-400">{{ passwordErrors.current_password[0] }}</p>
        </div>
        <div>
          <label class="block text-sm font-medium text-stone-700 dark:text-stone-300">{{ t('profile.newPassword') }}</label>
          <input v-model="passwordForm.password" type="password" class="mt-1 block w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950" />
          <p v-if="passwordErrors.password" class="mt-1 text-xs text-red-600 dark:text-red-400">{{ passwordErrors.password[0] }}</p>
        </div>
        <div>
          <label class="block text-sm font-medium text-stone-700 dark:text-stone-300">{{ t('profile.confirmPassword') }}</label>
          <input v-model="passwordForm.password_confirmation" type="password" class="mt-1 block w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950" />
        </div>
        <div class="flex items-center gap-3">
          <AppButton type="submit" :disabled="passwordSaving">{{ passwordSaving ? t('profile.saving') : t('profile.changePassword') }}</AppButton>
          <span v-if="passwordSuccess" class="text-sm text-emerald-600 dark:text-emerald-400">{{ t('profile.saved') }}</span>
        </div>
      </form>
    </div>

    <div class="mt-4 divide-y divide-stone-100 rounded-2xl border border-stone-200 bg-white dark:divide-stone-800 dark:border-stone-800 dark:bg-stone-900">
      <RouterLink :to="{ name: 'orders' }" class="flex items-center justify-between px-5 py-4 text-sm font-medium text-stone-700 dark:text-stone-200">
        <span class="flex items-center gap-2"><AppIcon name="clock" :size="18" /> {{ t('profile.orderHistory') }}</span>
        <AppIcon name="chevron-right" :size="17" class="text-stone-400" />
      </RouterLink>
      <RouterLink :to="{ name: 'wishlist' }" class="flex items-center justify-between px-5 py-4 text-sm font-medium text-stone-700 dark:text-stone-200">
        <span class="flex items-center gap-2"><AppIcon name="heart" :size="18" /> {{ t('profile.wishlist') }}</span>
        <AppIcon name="chevron-right" :size="17" class="text-stone-400" />
      </RouterLink>
      <RouterLink
        v-if="auth.user?.role === 'konsumen'"
        :to="{ name: 'address-book' }"
        class="flex items-center justify-between px-5 py-4 text-sm font-medium text-stone-700 dark:text-stone-200"
      >
        <span class="flex items-center gap-2"><AppIcon name="map-pin" :size="18" /> {{ t('profile.addressBook') }}</span>
        <AppIcon name="chevron-right" :size="17" class="text-stone-400" />
      </RouterLink>
      <RouterLink
        v-if="auth.can('system.cms.manage')"
        :to="{ name: 'admin-homepage-blocks' }"
        class="flex items-center justify-between px-5 py-4 text-sm font-medium text-stone-700 dark:text-stone-200"
      >
        <span class="flex items-center gap-2"><AppIcon name="grid" :size="18" /> {{ t('profile.manageHomepage') }}</span>
        <AppIcon name="chevron-right" :size="17" class="text-stone-400" />
      </RouterLink>
      <RouterLink
        v-if="auth.can('system.payment.manage')"
        :to="{ name: 'admin-payment-gateways' }"
        class="flex items-center justify-between px-5 py-4 text-sm font-medium text-stone-700 dark:text-stone-200"
      >
        <span class="flex items-center gap-2"><AppIcon name="box" :size="18" /> {{ t('profile.paymentGateway') }}</span>
        <AppIcon name="chevron-right" :size="17" class="text-stone-400" />
      </RouterLink>
      <RouterLink
        v-if="auth.user?.role === 'agen'"
        :to="{ name: 'agent-payment-methods' }"
        class="flex items-center justify-between px-5 py-4 text-sm font-medium text-stone-700 dark:text-stone-200"
      >
        <span class="flex items-center gap-2"><AppIcon name="box" :size="18" /> {{ t('profile.agentPaymentMethods') }}</span>
        <AppIcon name="chevron-right" :size="17" class="text-stone-400" />
      </RouterLink>
      <RouterLink
        v-if="auth.can('system.shipping.manage')"
        :to="{ name: 'admin-shipping-settings' }"
        class="flex items-center justify-between px-5 py-4 text-sm font-medium text-stone-700 dark:text-stone-200"
      >
        <span class="flex items-center gap-2"><AppIcon name="map-pin" :size="18" /> {{ t('profile.shippingSettings') }}</span>
        <AppIcon name="chevron-right" :size="17" class="text-stone-400" />
      </RouterLink>
      <RouterLink
        v-if="auth.user?.role === 'agen'"
        :to="{ name: 'agent-shipping-providers' }"
        class="flex items-center justify-between px-5 py-4 text-sm font-medium text-stone-700 dark:text-stone-200"
      >
        <span class="flex items-center gap-2"><AppIcon name="map-pin" :size="18" /> {{ t('profile.agentShippingProviders') }}</span>
        <AppIcon name="chevron-right" :size="17" class="text-stone-400" />
      </RouterLink>
      <RouterLink
        v-if="auth.can('orders.manage.fulfillment')"
        :to="{ name: 'admin-refunds' }"
        class="flex items-center justify-between px-5 py-4 text-sm font-medium text-stone-700 dark:text-stone-200"
      >
        <span class="flex items-center gap-2"><AppIcon name="box" :size="18" /> {{ t('profile.refund') }}</span>
        <AppIcon name="chevron-right" :size="17" class="text-stone-400" />
      </RouterLink>
      <RouterLink
        v-if="auth.can('orders.manage.fulfillment')"
        :to="{ name: 'admin-additional-payments' }"
        class="flex items-center justify-between px-5 py-4 text-sm font-medium text-stone-700 dark:text-stone-200"
      >
        <span class="flex items-center gap-2"><AppIcon name="box" :size="18" /> {{ t('profile.additionalPayment') }}</span>
        <AppIcon name="chevron-right" :size="17" class="text-stone-400" />
      </RouterLink>
      <RouterLink
        v-if="auth.can('orders.manage.fulfillment')"
        :to="{ name: 'admin-returns' }"
        class="flex items-center justify-between px-5 py-4 text-sm font-medium text-stone-700 dark:text-stone-200"
      >
        <span class="flex items-center gap-2"><AppIcon name="box" :size="18" /> {{ t('profile.returns') }}</span>
        <AppIcon name="chevron-right" :size="17" class="text-stone-400" />
      </RouterLink>
      <button type="button" class="flex w-full items-center justify-between px-5 py-4 text-left text-sm font-medium text-stone-700 dark:text-stone-200" @click="ui.toggleTheme()">
        <span class="flex items-center gap-2"><AppIcon :name="ui.theme === 'dark' ? 'moon' : 'sun'" :size="18" /> {{ t('profile.themeDisplay') }}</span>
        <span class="text-xs capitalize text-stone-400">{{ ui.theme }}</span>
      </button>
    </div>

    <AppButton variant="secondary" block class="mt-4" @click="handleLogout">
      <AppIcon name="logout" :size="16" /> {{ t('profile.logout') }}
    </AppButton>
  </ShopLayout>
</template>
