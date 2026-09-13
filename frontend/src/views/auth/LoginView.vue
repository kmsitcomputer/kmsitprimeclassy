<script setup lang="ts">
import { ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import AuthLayout from '@/layouts/AuthLayout.vue'
import AppButton from '@/components/ui/AppButton.vue'
import PasswordInput from '@/components/ui/PasswordInput.vue'
import { useAuthStore } from '@/stores/auth'
import { ApiError } from '@/api/client'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const auth = useAuthStore()

const email = ref('')
const password = ref('')
const submitting = ref(false)
const errorMessage = ref<string | null>(null)

async function submit() {
  submitting.value = true
  errorMessage.value = null
  try {
    await auth.login(email.value, password.value)
    const fallback = auth.isKonsumen ? { name: 'home' } : { name: 'dashboard-home' }
    router.push((route.query.redirect as string) || fallback)
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? e.message : t('auth.login.genericError')
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <AuthLayout>
    <h1 class="mb-1 font-display text-lg font-semibold text-stone-800 dark:text-stone-100">{{ t('auth.login.title') }}</h1>
    <p class="mb-5 text-sm text-stone-500 dark:text-stone-400">{{ t('auth.login.subtitle') }}</p>

    <form class="space-y-3" @submit.prevent="submit">
      <p v-if="errorMessage" class="rounded-lg bg-red-50 p-3 text-sm text-red-700 dark:bg-red-950 dark:text-red-400">{{ errorMessage }}</p>

      <div>
        <label class="mb-1 block text-sm font-medium text-stone-700 dark:text-stone-200">{{ t('auth.login.email') }}</label>
        <input v-model="email" type="email" required class="w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950" />
      </div>
      <div>
        <label class="mb-1 block text-sm font-medium text-stone-700 dark:text-stone-200">{{ t('auth.login.password') }}</label>
        <PasswordInput v-model="password" required autocomplete="current-password" />
      </div>

      <AppButton type="submit" size="lg" block :disabled="submitting">{{ submitting ? t('auth.login.submitting') : t('auth.login.submit') }}</AppButton>
    </form>

    <p class="mt-5 text-center text-sm text-stone-500 dark:text-stone-400">
      {{ t('auth.login.noAccount') }}
      <RouterLink :to="{ name: 'register' }" class="font-medium text-brand-600 dark:text-brand-400">{{ t('auth.login.register') }}</RouterLink>
    </p>
  </AuthLayout>
</template>
