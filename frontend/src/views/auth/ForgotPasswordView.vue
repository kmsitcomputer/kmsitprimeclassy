<script setup lang="ts">
import { computed, onBeforeUnmount, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import AuthLayout from '@/layouts/AuthLayout.vue'
import AppButton from '@/components/ui/AppButton.vue'
import PasswordInput from '@/components/ui/PasswordInput.vue'
import { ApiError } from '@/api/client'
import { forgotPassword, resetPassword, type RecoveryChannel } from '@/api/auth'
import { formatApiError } from '@/utils/apiError'

/**
 * Password recovery. Stage 1 asks for an identifier (email or phone) and a channel; the answer is ALWAYS the same generic
 * notice and always moves to stage 2, whether or not the account exists or has a usable channel — this view must never hint
 * at account existence. Stage 2 takes the 6-digit code + the new password. Only an invalid/expired code is reported there.
 */
const { t } = useI18n()

type Stage = 'request' | 'confirm' | 'done'
const COOLDOWN_SECONDS = 60

const stage = ref<Stage>('request')
const identifier = ref('')
const channel = ref<RecoveryChannel>('email')
const code = ref('')
const password = ref('')
const passwordConfirmation = ref('')
const busy = ref(false)
const error = ref('')
const notice = ref('')
const cooldown = ref(0)
let timer: ReturnType<typeof setInterval> | null = null

const codeValid = computed(() => /^\d{6}$/.test(code.value.trim()))
const passwordValid = computed(() => password.value.length >= 8 && password.value === passwordConfirmation.value)

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

async function requestCode() {
  if (busy.value || !identifier.value.trim()) return
  busy.value = true
  error.value = ''
  notice.value = ''
  try {
    await forgotPassword(identifier.value.trim(), channel.value)
    notice.value = t('auth.recovery.sentGeneric')
    stage.value = 'confirm'
    startCooldown()
  } catch (e) {
    // Only throttling and transport problems differ — none of them depends on whether the account exists.
    error.value = e instanceof ApiError && e.status === 429 ? t('auth.recovery.rateLimited') : t('auth.recovery.genericError')
  } finally {
    busy.value = false
  }
}

async function submitReset() {
  if (busy.value || !codeValid.value || !passwordValid.value) return
  busy.value = true
  error.value = ''
  try {
    await resetPassword({
      identifier: identifier.value.trim(),
      code: code.value.trim(),
      password: password.value,
      password_confirmation: passwordConfirmation.value,
    })
    stage.value = 'done'
    notice.value = ''
  } catch (e) {
    code.value = ''
    error.value = e instanceof ApiError ? (e.status === 429 ? t('auth.recovery.rateLimited') : formatApiError(e)) : t('auth.recovery.genericError')
  } finally {
    busy.value = false
  }
}

function startOver() {
  stage.value = 'request'
  code.value = ''
  password.value = ''
  passwordConfirmation.value = ''
  error.value = ''
  notice.value = ''
}

const inputClass = 'w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950'
</script>

<template>
  <AuthLayout>
    <h1 class="mb-1 font-display text-lg font-semibold text-stone-800 dark:text-stone-100">{{ t('auth.recovery.title') }}</h1>

    <template v-if="stage === 'done'">
      <p class="mb-5 rounded-lg bg-emerald-50 p-3 text-sm text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300" role="status" data-test="recovery-done">{{ t('auth.recovery.done') }}</p>
      <RouterLink :to="{ name: 'login' }" class="block text-center text-sm font-medium text-brand-600 dark:text-brand-400" data-test="recovery-to-login">{{ t('auth.recovery.backToLogin') }}</RouterLink>
    </template>

    <form v-else-if="stage === 'request'" class="space-y-3" novalidate @submit.prevent="requestCode">
      <p class="mb-2 text-sm text-stone-500 dark:text-stone-400">{{ t('auth.recovery.subtitle') }}</p>
      <p v-if="error" class="rounded-lg bg-red-50 p-3 text-sm text-red-700 dark:bg-red-950 dark:text-red-400" role="alert" data-test="recovery-error">{{ error }}</p>

      <div>
        <label for="recovery-identifier" class="mb-1 block text-sm font-medium text-stone-700 dark:text-stone-200">{{ t('auth.recovery.identifier') }}</label>
        <input id="recovery-identifier" v-model="identifier" type="text" name="identifier" required autocomplete="username" :class="inputClass" data-test="recovery-identifier" />
      </div>

      <fieldset>
        <legend class="mb-1 block text-sm font-medium text-stone-700 dark:text-stone-200">{{ t('auth.recovery.channel') }}</legend>
        <label class="mr-4 inline-flex items-center gap-2 text-sm text-stone-700 dark:text-stone-200">
          <input v-model="channel" type="radio" value="email" name="channel" data-test="recovery-channel-email" /> {{ t('auth.recovery.channelEmail') }}
        </label>
        <label class="inline-flex items-center gap-2 text-sm text-stone-700 dark:text-stone-200">
          <input v-model="channel" type="radio" value="whatsapp" name="channel" data-test="recovery-channel-wa" /> {{ t('auth.recovery.channelWhatsapp') }}
        </label>
      </fieldset>

      <AppButton type="submit" size="lg" block :disabled="busy || !identifier.trim()" data-test="recovery-send">{{ busy ? t('auth.recovery.sending') : t('auth.recovery.send') }}</AppButton>
      <RouterLink :to="{ name: 'login' }" class="block text-center text-sm text-stone-500 dark:text-stone-400">{{ t('auth.recovery.backToLogin') }}</RouterLink>
    </form>

    <form v-else class="space-y-3" novalidate @submit.prevent="submitReset">
      <p v-if="notice" class="rounded-lg bg-stone-50 p-3 text-sm text-stone-600 dark:bg-stone-800 dark:text-stone-300" role="status" data-test="recovery-notice">{{ notice }}</p>
      <p v-if="error" class="rounded-lg bg-red-50 p-3 text-sm text-red-700 dark:bg-red-950 dark:text-red-400" role="alert" data-test="recovery-error">{{ error }}</p>

      <div>
        <label for="recovery-code" class="mb-1 block text-sm font-medium text-stone-700 dark:text-stone-200">{{ t('auth.recovery.code') }}</label>
        <input id="recovery-code" v-model="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" :class="[inputClass, 'tracking-widest']" data-test="recovery-code" />
      </div>
      <div>
        <label class="mb-1 block text-sm font-medium text-stone-700 dark:text-stone-200">{{ t('auth.recovery.newPassword') }}</label>
        <PasswordInput v-model="password" required :minlength="8" autocomplete="new-password" />
      </div>
      <div>
        <label class="mb-1 block text-sm font-medium text-stone-700 dark:text-stone-200">{{ t('auth.recovery.confirmPassword') }}</label>
        <PasswordInput v-model="passwordConfirmation" required :minlength="8" autocomplete="new-password" />
        <p v-if="passwordConfirmation && password !== passwordConfirmation" class="mt-1 text-xs text-red-600 dark:text-red-400" data-test="recovery-mismatch">{{ t('auth.recovery.mismatch') }}</p>
      </div>

      <AppButton type="submit" size="lg" block :disabled="busy || !codeValid || !passwordValid" data-test="recovery-reset">{{ busy ? t('auth.recovery.resetting') : t('auth.recovery.reset') }}</AppButton>

      <div class="flex items-center justify-between text-sm">
        <button type="button" class="font-medium text-brand-600 disabled:opacity-50 dark:text-brand-400" :disabled="busy || cooldown > 0" data-test="recovery-resend" @click="requestCode">
          {{ cooldown > 0 ? t('auth.recovery.resendIn', { seconds: cooldown }) : t('auth.recovery.resend') }}
        </button>
        <button type="button" class="text-stone-500 dark:text-stone-400" data-test="recovery-restart" @click="startOver">{{ t('auth.recovery.change') }}</button>
      </div>
    </form>
  </AuthLayout>
</template>
