<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import AppButton from '@/components/ui/AppButton.vue'
import { ApiError } from '@/api/client'
import { formatApiError } from '@/utils/apiError'
import {
  confirmEmailVerification,
  getEmailVerificationState,
  requestEmailVerification,
  type EmailVerificationChallenge,
  type EmailVerificationState,
} from '@/api/auth'

/**
 * Optional proof of the account's CURRENT email (never a login requirement). Hidden when the account has no email or the
 * endpoint is unreachable. A changed address voids the proof server-side; a stale code is refused by the backend and surfaces
 * here as an ordinary error, after which the holder simply requests a new code.
 */
const { t } = useI18n()
const COOLDOWN_SECONDS = 60

const state = ref<EmailVerificationState | null>(null)
const available = ref(true)
const challenge = ref<EmailVerificationChallenge | null>(null)
const code = ref('')
const busy = ref(false)
const error = ref('')
const notice = ref('')
const cooldown = ref(0)
let timer: ReturnType<typeof setInterval> | null = null

function startCooldown() {
  cooldown.value = COOLDOWN_SECONDS
  if (timer) clearInterval(timer)
  timer = setInterval(() => {
    cooldown.value -= 1
    if (cooldown.value <= 0 && timer) {
      clearInterval(timer)
      timer = null
    }
  }, 1000)
}
onBeforeUnmount(() => {
  if (timer) clearInterval(timer)
})

function fail(e: unknown) {
  error.value = e instanceof ApiError ? formatApiError(e) : t('profile.emailVerify.error')
}

async function load() {
  try {
    state.value = await getEmailVerificationState()
  } catch {
    available.value = false
  }
}

async function sendCode() {
  if (busy.value || cooldown.value > 0) return
  error.value = ''
  notice.value = ''
  busy.value = true
  try {
    const res = await requestEmailVerification()
    if ('challenge_id' in res) {
      challenge.value = res
      code.value = ''
      notice.value = t('profile.emailVerify.sent', { target: state.value?.email_masked ?? '' })
      startCooldown()
    } else {
      state.value = res
      challenge.value = null
    }
  } catch (e) {
    fail(e)
  } finally {
    busy.value = false
  }
}

async function confirm() {
  if (!challenge.value || busy.value) return
  error.value = ''
  notice.value = ''
  busy.value = true
  try {
    state.value = await confirmEmailVerification(challenge.value.challenge_id, code.value.trim())
    challenge.value = null
    code.value = ''
    notice.value = t('profile.emailVerify.done')
  } catch (e) {
    fail(e)
    code.value = ''
  } finally {
    busy.value = false
  }
}

onMounted(load)
</script>

<template>
  <div v-if="available && state && state.email_masked" class="mt-4 rounded-2xl border border-stone-200 bg-white p-5 dark:border-stone-800 dark:bg-stone-900" data-test="email-verify">
    <div class="mb-1 flex flex-wrap items-center gap-2">
      <h2 class="font-display text-base font-semibold text-stone-800 dark:text-stone-100">{{ t('profile.emailVerify.title') }}</h2>
      <span v-if="state.verified" class="rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300" data-test="email-verified">{{ t('profile.emailVerify.verified') }}</span>
      <span v-else class="rounded-full bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-700 dark:bg-amber-950 dark:text-amber-300" data-test="email-unverified">{{ t('profile.emailVerify.unverified') }}</span>
    </div>
    <p class="mb-3 text-sm text-stone-500 dark:text-stone-400">{{ t('profile.emailVerify.hint') }}</p>
    <p class="mb-3 text-sm text-stone-700 dark:text-stone-200">{{ state.email_masked }}</p>

    <template v-if="!state.verified">
      <form v-if="challenge" class="max-w-sm space-y-3" @submit.prevent="confirm">
        <label class="block text-sm font-medium text-stone-700 dark:text-stone-300" for="email-code">{{ t('profile.emailVerify.code') }}</label>
        <input id="email-code" v-model="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" class="block w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm tracking-widest dark:border-stone-700 dark:bg-stone-950" data-test="email-code" />
        <div class="flex flex-wrap gap-2">
          <AppButton type="submit" :disabled="busy || code.trim().length !== 6" data-test="email-confirm">{{ t('profile.emailVerify.confirm') }}</AppButton>
          <AppButton type="button" variant="ghost" :disabled="busy || cooldown > 0" data-test="email-resend" @click="sendCode">
            {{ cooldown > 0 ? t('profile.emailVerify.resendIn', { seconds: cooldown }) : t('profile.emailVerify.resend') }}
          </AppButton>
        </div>
      </form>
      <AppButton v-else type="button" :disabled="busy" data-test="email-send" @click="sendCode">{{ t('profile.emailVerify.send') }}</AppButton>
    </template>

    <p v-if="notice" class="mt-3 text-sm text-emerald-600 dark:text-emerald-400" role="status" data-test="email-notice">{{ notice }}</p>
    <p v-if="error" class="mt-3 text-sm text-red-600 dark:text-red-400" role="alert" data-test="email-error">{{ error }}</p>
  </div>
</template>
