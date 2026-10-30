<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import AppButton from '@/components/ui/AppButton.vue'
import { ApiError } from '@/api/client'
import { formatApiError } from '@/utils/apiError'
import {
  confirmWhatsAppNumberVerification,
  getWhatsAppNumberState,
  requestWhatsAppNumberVerification,
  type WaNumberChallenge,
  type WaNumberState,
} from '@/api/whatsapp'

const { t } = useI18n()

const state = ref<WaNumberState | null>(null)
const available = ref(true)
const phone = ref('')
const code = ref('')
const challenge = ref<WaNumberChallenge | null>(null)
const busy = ref(false)
const error = ref('')
const notice = ref('')

function fail(e: unknown) {
  error.value = e instanceof ApiError ? formatApiError(e) : t('profile.waVerify.error')
}

async function load() {
  try {
    state.value = await getWhatsAppNumberState()
  } catch {
    // Hidden entirely when the endpoint is unreachable: this is an optional extra on the account page.
    available.value = false
  }
}

async function sendCode() {
  error.value = ''
  notice.value = ''
  busy.value = true
  try {
    const res = await requestWhatsAppNumberVerification(phone.value.trim() || undefined)
    if ('challenge_id' in res) {
      challenge.value = res
      code.value = ''
      notice.value = t('profile.waVerify.sent', { target: res.target ?? '' })
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
  if (!challenge.value) return
  error.value = ''
  notice.value = ''
  busy.value = true
  try {
    state.value = await confirmWhatsAppNumberVerification(challenge.value.challenge_id, code.value.trim())
    challenge.value = null
    code.value = ''
    phone.value = ''
    notice.value = t('profile.waVerify.done')
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
  <div v-if="available && state" class="mt-4 rounded-2xl border border-stone-200 bg-white p-5 dark:border-stone-800 dark:bg-stone-900" data-test="wa-verify">
    <div class="mb-1 flex flex-wrap items-center gap-2">
      <h2 class="font-display text-base font-semibold text-stone-800 dark:text-stone-100">{{ t('profile.waVerify.title') }}</h2>
      <span
        v-if="state.verified"
        class="rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300"
        data-test="wa-verified"
      >{{ t('profile.waVerify.verified') }}</span>
      <span v-else class="rounded-full bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-700 dark:bg-amber-950 dark:text-amber-300" data-test="wa-unverified">
        {{ t('profile.waVerify.unverified') }}
      </span>
    </div>
    <p class="mb-3 text-sm text-stone-500 dark:text-stone-400">{{ t('profile.waVerify.hint') }}</p>
    <p v-if="state.phone_masked" class="mb-3 text-sm text-stone-700 dark:text-stone-200">{{ state.phone_masked }}</p>

    <p v-if="!state.channel_available" class="text-sm text-stone-500 dark:text-stone-400" data-test="wa-unavailable">{{ t('profile.waVerify.unavailable') }}</p>

    <form v-else-if="challenge" class="max-w-sm space-y-3" @submit.prevent="confirm">
      <label class="block text-sm font-medium text-stone-700 dark:text-stone-300" for="wa-code">{{ t('profile.waVerify.code') }}</label>
      <input
        id="wa-code"
        v-model="code"
        inputmode="numeric"
        autocomplete="one-time-code"
        maxlength="6"
        class="block w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm tracking-widest dark:border-stone-700 dark:bg-stone-950"
      />
      <div class="flex flex-wrap gap-2">
        <AppButton type="submit" :disabled="busy || code.trim().length !== 6">{{ t('profile.waVerify.confirm') }}</AppButton>
        <AppButton type="button" variant="ghost" :disabled="busy" @click="sendCode">{{ t('profile.waVerify.resend') }}</AppButton>
      </div>
    </form>

    <form v-else class="max-w-sm space-y-3" @submit.prevent="sendCode">
      <label class="block text-sm font-medium text-stone-700 dark:text-stone-300" for="wa-phone">{{ t('profile.waVerify.number') }}</label>
      <input
        id="wa-phone"
        v-model="phone"
        inputmode="tel"
        :placeholder="state.has_phone ? t('profile.waVerify.keepNumber') : '08xxxxxxxxxx'"
        class="block w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950"
      />
      <AppButton type="submit" :disabled="busy || (!state.has_phone && !phone.trim())">{{ t('profile.waVerify.send') }}</AppButton>
    </form>

    <p v-if="notice" class="mt-3 text-sm text-emerald-600 dark:text-emerald-400" data-test="wa-notice">{{ notice }}</p>
    <p v-if="error" class="mt-3 text-sm text-red-600 dark:text-red-400" data-test="wa-error">{{ error }}</p>
  </div>
</template>
