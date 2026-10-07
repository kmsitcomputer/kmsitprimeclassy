import './assets/main.css'

import { createApp } from 'vue'
import { createPinia } from 'pinia'

import App from './App.vue'
import router from './router'
import { i18n } from './i18n'
import { registerServiceWorker } from './pwa/registerServiceWorker'

const app = createApp(App)

app.use(createPinia())
app.use(router)
app.use(i18n)

// Production only (the function itself no-ops outside PROD builds and
// without service-worker support) — normal Vite development is untouched.
registerServiceWorker()

app.mount('#app')
