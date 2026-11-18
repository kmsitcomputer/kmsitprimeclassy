import { i18n } from '@/i18n'

// Same vue-i18n instance and message catalogues as the SPA. The server only ever renders the default
// locale ('id'): the instance is process-wide, so it is never mutated server-side (see stores/locale.ts).
export default defineNuxtPlugin((nuxtApp) => {
  nuxtApp.vueApp.use(i18n)
})
