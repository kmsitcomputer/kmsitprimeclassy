<script setup lang="ts">
import { ref, reactive, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import AuthLayout from '@/layouts/AuthLayout.vue'
import AppButton from '@/components/ui/AppButton.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import PasswordInput from '@/components/ui/PasswordInput.vue'
import { useAuthStore } from '@/stores/auth'
import { previewReferral, googleAuthUrl } from '@/api/auth'
import { ApiError } from '@/api/client'
import { getPersistedReferralCode, clearPersistedReferral } from '@/utils/referral'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const auth = useAuthStore()

// Priority: explicit ?ref= on this navigation, then the persisted referral.
const form = reactive({
  name: '',
  email: '',
  phone: '',
  password: '',
  password_confirmation: '',
  referral_code:
    (route.query.ref as string) ||
    getPersistedReferralCode() ||
    '',
})

const submitting = ref(false)
const errors = ref<Record<string, string[]>>({})
const generalError = ref<string | null>(null)

const referralPreview = ref<{
  referrer_name: string
  agent_store_name: string | null
} | null>(null)

const referralChecking = ref(false)

let debounceHandle: ReturnType<typeof setTimeout> | undefined
let activeCheck = 0

/**
 * Validate a referral code against the canonical endpoint.
 * Ignore stale results when the code changes.
 */
async function checkReferral(code: string) {
  const checkId = ++activeCheck

  referralChecking.value = true

  try {
    const preview = await previewReferral(code)

    if (checkId === activeCheck) {
      referralPreview.value = preview
    }
  } catch {
    if (checkId === activeCheck) {
      referralPreview.value = null
    }
  } finally {
    if (checkId === activeCheck) {
      referralChecking.value = false
    }
  }
}

function scheduleReferralCheck(code: string) {
  referralPreview.value = null
  clearTimeout(debounceHandle)

  if (!code.trim()) {
    referralChecking.value = false
    return
  }

  referralChecking.value = true

  debounceHandle = setTimeout(() => {
    void checkReferral(code.trim())
  }, 400)
}

watch(
  () => form.referral_code,
  (code) => {
    activeCheck++
    scheduleReferralCheck(code)
  },
)

// Validate a prefilled referral code when the page first loads.
if ((route.query.ref as string | undefined) || getPersistedReferralCode()) {
  scheduleReferralCheck(form.referral_code)
}

const googleError = route.query.google_error as string | undefined

if (googleError) {
  generalError.value = t(
    `auth.google.errors.${googleError}`,
    t('auth.google.errors.provider_error'),
  )
}

/**
 * Google registration is allowed with or without a referral code.
 * The backend remains responsible for validating the referral and
 * assigning the default agent when no referral is provided.
 */
function registerWithGoogle() {
  const referralCode = form.referral_code.trim()

  window.location.assign(
    googleAuthUrl('register', referralCode),
  )
}

async function submit() {
  submitting.value = true
  errors.value = {}
  generalError.value = null

  try {
    await auth.register(form)

    clearPersistedReferral()

    router.push(
      (route.query.redirect as string) || { name: 'home' },
    )
  } catch (e) {
    if (e instanceof ApiError) {
      generalError.value = e.message
      errors.value = e.errors ?? {}
    } else {
      generalError.value = t('auth.register.genericError')
    }
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <AuthLayout>
    <h1
      class="mb-1 font-display text-lg font-semibold text-stone-800 dark:text-stone-100"
    >
      {{ t('auth.register.title') }}
    </h1>

    <form class="space-y-3" @submit.prevent="submit">
      <p
        v-if="generalError"
        class="rounded-lg bg-red-50 p-3 text-sm text-red-700 dark:bg-red-950 dark:text-red-400"
      >
        {{ generalError }}
      </p>

      <!-- Full name -->
      <div>
        <label
          class="mb-1 block text-sm font-medium text-stone-700 dark:text-stone-200"
        >
          {{ t('auth.register.fullName') }}
          <span class="text-red-500">*</span>
        </label>

        <input
          v-model="form.name"
          type="text"
          required
          class="w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950"
        />

        <p
          v-if="errors.name"
          class="mt-1 text-xs text-red-600"
        >
          {{ errors.name[0] }}
        </p>
      </div>

      <!-- Phone -->
      <div>
        <label
          class="mb-1 block text-sm font-medium text-stone-700 dark:text-stone-200"
        >
          {{ t('auth.register.phone') }}
          <span class="text-red-500">*</span>
        </label>

        <input
          v-model="form.phone"
          type="tel"
          required
          class="w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950"
        />

        <p
          v-if="errors.phone"
          class="mt-1 text-xs text-red-600"
        >
          {{ errors.phone[0] }}
        </p>
      </div>

      <!-- Email -->
      <div>
        <label
          class="mb-1 block text-sm font-medium text-stone-700 dark:text-stone-200"
        >
          {{ t('auth.register.email') }} (Optional)
        </label>

        <input
          v-model="form.email"
          type="email"
          class="w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950"
        />

        <p
          v-if="errors.email"
          class="mt-1 text-xs text-red-600"
        >
          {{ errors.email[0] }}
        </p>
      </div>

      <!-- Password -->
      <div>
        <label
          class="mb-1 block text-sm font-medium text-stone-700 dark:text-stone-200"
        >
          {{ t('auth.register.password') }}
          <span class="text-red-500">*</span>
        </label>

        <PasswordInput
          v-model="form.password"
          required
          :minlength="8"
          autocomplete="new-password"
        />

        <p
          v-if="errors.password"
          class="mt-1 text-xs text-red-600"
        >
          {{ errors.password[0] }}
        </p>
      </div>

      <!-- Confirm password -->
      <div>
        <label
          class="mb-1 block text-sm font-medium text-stone-700 dark:text-stone-200"
        >
          {{ t('auth.register.confirmPassword') }}
          <span class="text-red-500">*</span>
        </label>

        <PasswordInput
          v-model="form.password_confirmation"
          required
          autocomplete="new-password"
        />
      </div>

      <!-- Manual registration -->
      <AppButton
        type="submit"
        size="lg"
        block
        :disabled="submitting"
      >
        {{
          submitting
            ? t('auth.register.submitting')
            : t('auth.register.submit')
        }}
      </AppButton>
    </form>

    <!-- Google registration: referral is optional -->
    <div class="mt-3">
      <AppButton
        type="button"
        variant="secondary"
        size="lg"
        block
        @click="registerWithGoogle"
      >
        {{ t('auth.google.register') }}
      </AppButton>

      <p
        v-if="!form.referral_code.trim()"
        class="mt-1 text-center text-xs text-stone-400"
      >
        <!-- Daftar menggunakan Google tanpa kode referral. -->
      </p>

      <p
        v-else-if="referralChecking"
        class="mt-1 text-center text-xs text-stone-400"
      >
        <!-- Memeriksa kode referral... -->
      </p>

      <p
        v-else-if="!referralPreview"
        class="mt-1 text-center text-xs text-stone-400"
      >
        <!-- Kode referral tidak valid atau tidak ditemukan.
        Kamu tetap bisa mencoba mendaftar menggunakan Google tanpa referral. -->
      </p>

      <p
        v-else
        class="mt-1 text-center text-xs text-stone-500 dark:text-stone-400"
      >
        Referral:
        {{ referralPreview.referrer_name }}
        <template v-if="referralPreview.agent_store_name">
          — {{ referralPreview.agent_store_name }}
        </template>
      </p>
    </div>

    <!-- Login link -->
    <p
      class="mt-5 text-center text-sm text-stone-500 dark:text-stone-400"
    >
      {{ t('auth.register.haveAccount') }}

      <RouterLink
        :to="{ name: 'login' }"
        class="font-medium text-brand-600 dark:text-brand-400"
      >
        {{ t('auth.register.login') }}
      </RouterLink>
    </p>
  </AuthLayout>
</template>
