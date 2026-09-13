<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

withDefaults(
  defineProps<{
    modelValue: string | undefined
    required?: boolean
    minlength?: number
    autocomplete?: string
  }>(),
  { modelValue: '', required: false, autocomplete: 'current-password' },
)
defineEmits<{ 'update:modelValue': [value: string] }>()

const visible = ref(false)
</script>

<template>
  <div class="relative">
    <input
      :type="visible ? 'text' : 'password'"
      :value="modelValue"
      :required="required"
      :minlength="minlength"
      :autocomplete="autocomplete"
      class="w-full rounded-lg border border-stone-200 bg-white px-3 py-2 pr-10 text-sm dark:border-stone-700 dark:bg-stone-950"
      @input="$emit('update:modelValue', ($event.target as HTMLInputElement).value)"
    />
    <button
      type="button"
      class="absolute inset-y-0 right-0 flex items-center px-3 text-stone-400 hover:text-stone-600 dark:text-stone-500 dark:hover:text-stone-300"
      :aria-label="visible ? t('passwordInput.hide') : t('passwordInput.show')"
      tabindex="-1"
      @click="visible = !visible"
    >
      <svg v-if="!visible" viewBox="0 0 20 20" fill="currentColor" class="h-4.5 w-4.5">
        <path
          d="M10 3.5c-4.478 0-8.268 2.943-9.542 7 1.274 4.057 5.064 7 9.542 7s8.268-2.943 9.542-7c-1.274-4.057-5.064-7-9.542-7zM10 15a5 5 0 110-10 5 5 0 010 10z"
        />
        <path d="M10 7.5a2.5 2.5 0 100 5 2.5 2.5 0 000-5z" />
      </svg>
      <svg v-else viewBox="0 0 20 20" fill="currentColor" class="h-4.5 w-4.5">
        <path
          d="M3.28 2.22a.75.75 0 00-1.06 1.06l3.04 3.04C3.51 7.6 2.1 9.14 1.32 10.5c1.274 4.057 5.064 7 9.542 7 1.84 0 3.56-.494 5.037-1.354l3.126 3.126a.75.75 0 101.06-1.06L3.28 2.22zM10 15a5 5 0 01-4.478-7.22l1.56 1.56a2.5 2.5 0 003.138 3.138l1.56 1.56A4.98 4.98 0 0110 15z"
        />
        <path
          d="M14.828 12.475l1.113 1.113c1.201-1.038 2.144-2.345 2.737-3.588-1.274-4.057-5.064-7-9.542-7-.96 0-1.887.135-2.762.386l1.259 1.259A5 5 0 0114.828 12.475z"
        />
      </svg>
    </button>
  </div>
</template>
