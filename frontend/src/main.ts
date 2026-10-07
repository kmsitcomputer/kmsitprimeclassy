import './assets/main.css'

import { createApp } from 'vue'
import { createPinia } from 'pinia'

import App from './App.vue'
import router from './router'
import { i18n } from './i18n'
import { registerServiceWorker } from './pwa/registerServiceWorker'
import { setupPwaInstallListeners } from './pwa/installPrompt'

const app = createApp(App)

app.use(createPinia())
app.use(router)
app.use(i18n)

// Captures beforeinstallprompt / appinstalled centrally for the install
// button (no-op guard inside; safe in every environment, incl. dev).
setupPwaInstallListeners()

// Production only (the function itself no-ops outside PROD builds and
// without service-worker support) — normal Vite development is untouched.
registerServiceWorker()

app.mount('#app')
