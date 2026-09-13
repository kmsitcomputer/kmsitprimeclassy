<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import AppButton from '@/components/ui/AppButton.vue'
import PasswordInput from '@/components/ui/PasswordInput.vue'
import { useInstallStore } from '@/stores/install'
import { useUiStore } from '@/stores/ui'
import * as installApi from '@/api/install'
import type { RequirementsReport } from '@/api/install'
import { ApiError } from '@/api/client'

const router = useRouter()
const install = useInstallStore()
const ui = useUiStore()

type StepKey =
  | 'welcome'
  | 'requirements'
  | 'database'
  | 'connection_test'
  | 'app_config'
  | 'admin'
  | 'install'
  | 'finalize'
  | 'lock'
  | 'done'

const STEPS: { key: StepKey; label: string }[] = [
  { key: 'welcome', label: 'Selamat Datang' },
  { key: 'requirements', label: 'Persyaratan Server' },
  { key: 'database', label: 'Database' },
  { key: 'connection_test', label: 'Tes Koneksi' },
  { key: 'app_config', label: 'Konfigurasi Aplikasi' },
  { key: 'admin', label: 'Akun Super Admin' },
  { key: 'install', label: 'Instalasi Database' },
  { key: 'finalize', label: 'Finalisasi' },
  { key: 'lock', label: 'Kunci Instalasi' },
]

const currentStep = ref<StepKey>('welcome')
const booting = ref(true)
const errorMessage = ref<string | null>(null)
const busy = ref(false)

function goTo(step: StepKey) {
  errorMessage.value = null
  currentStep.value = step
}

function stepIndex(key: StepKey): number {
  return STEPS.findIndex((s) => s.key === key)
}

function isDone(key: StepKey): boolean {
  if (currentStep.value === 'done') return true
  return stepIndex(key) < stepIndex(currentStep.value)
}

function isCurrent(key: StepKey): boolean {
  return currentStep.value === key
}

// ---- Requirements -------------------------------------------------------
const requirements = ref<RequirementsReport | null>(null)
const checkingRequirements = ref(false)

async function loadRequirements() {
  checkingRequirements.value = true
  errorMessage.value = null
  try {
    requirements.value = await installApi.getRequirements()
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? e.message : 'Gagal memeriksa persyaratan server.'
  } finally {
    checkingRequirements.value = false
  }
}

// ---- Database -------------------------------------------------------------
const db = reactive({ host: '127.0.0.1', port: 3306, database: '', username: '', password: '' })
const dbTested = ref(false)

async function testConnection() {
  busy.value = true
  errorMessage.value = null
  try {
    await installApi.testDatabaseConnection({ ...db, port: Number(db.port) })
    dbTested.value = true
    goTo('connection_test')
  } catch (e) {
    dbTested.value = false
    errorMessage.value = e instanceof ApiError ? e.message : 'Gagal menghubungkan ke database.'
  } finally {
    busy.value = false
  }
}

async function saveDatabase() {
  busy.value = true
  errorMessage.value = null
  try {
    await installApi.saveDatabaseConfig({ ...db, port: Number(db.port) })
    goTo('app_config')
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? e.message : 'Gagal menyimpan konfigurasi database.'
  } finally {
    busy.value = false
  }
}

// ---- App configuration ------------------------------------------------
const appConfig = reactive({
  app_name: 'Prime Classy Cake & Cookies',
  app_url: '',
  frontend_url: window.location.origin,
})

async function saveAppConfig() {
  busy.value = true
  errorMessage.value = null
  try {
    await installApi.configureApp(appConfig)
    goTo('admin')
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? e.message : 'Gagal menyimpan konfigurasi aplikasi.'
  } finally {
    busy.value = false
  }
}

// ---- Super Admin (collected here, submitted at the "install" step) ----
const admin = reactive({ name: '', email: '', phone: '', password: '', password_confirmation: '' })

function proceedToInstall() {
  errorMessage.value = null
  if (!admin.name || !admin.email || !admin.phone) {
    errorMessage.value = 'Semua field wajib diisi.'
    return
  }
  if (admin.password.length < 8) {
    errorMessage.value = 'Password minimal 8 karakter.'
    return
  }
  if (admin.password !== admin.password_confirmation) {
    errorMessage.value = 'Konfirmasi password tidak cocok.'
    return
  }
  goTo('install')
}

// ---- Install (migrate + seed + create admin) ---------------------------
const installing = ref(false)

async function runInstall() {
  installing.value = true
  errorMessage.value = null
  try {
    await installApi.runInstall(admin)
    goTo('finalize')
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? e.message : 'Instalasi gagal. Silakan coba lagi.'
  } finally {
    installing.value = false
  }
}

// ---- Finalize ------------------------------------------------------------
const finalizing = ref(false)
const finalizeSummary = ref<{ has_tables: boolean; has_super_admin: boolean; storage_linked: boolean } | null>(null)

async function runFinalize() {
  finalizing.value = true
  errorMessage.value = null
  try {
    finalizeSummary.value = await installApi.finalizeInstall()
    goTo('lock')
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? e.message : 'Gagal memfinalisasi instalasi.'
  } finally {
    finalizing.value = false
  }
}

// ---- Lock ------------------------------------------------------------------
const locking = ref(false)

async function runLock() {
  locking.value = true
  errorMessage.value = null
  try {
    await installApi.lockInstall()
    install.installed = true
    goTo('done')
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? e.message : 'Gagal mengunci instalasi.'
  } finally {
    locking.value = false
  }
}

function goToLogin() {
  router.push({ name: 'login' })
}

// ---- Resume mid-install on page load -------------------------------------
onMounted(async () => {
  try {
    const status = await installApi.getInstallStatus()
    if (status.has_super_admin) {
      currentStep.value = 'finalize'
    } else if (status.has_tables) {
      currentStep.value = 'admin'
    }
  } catch {
    // Stay on 'welcome' — the wizard itself will surface errors per step.
  } finally {
    booting.value = false
  }
})

const progressPercent = computed(() => {
  if (currentStep.value === 'done') return 100
  return Math.round((stepIndex(currentStep.value) / (STEPS.length - 1)) * 100)
})
</script>

<template>
  <div class="relative min-h-screen overflow-hidden bg-stone-50 dark:bg-stone-950">
    <div class="pointer-events-none absolute inset-0 overflow-hidden">
      <div class="absolute -top-32 -left-32 h-96 w-96 rounded-full bg-brand-400/20 blur-3xl dark:bg-brand-500/10" />
      <div class="absolute top-1/3 -right-32 h-96 w-96 rounded-full bg-brand-600/10 blur-3xl dark:bg-brand-400/10" />
      <div
        class="absolute bottom-0 left-1/3 h-72 w-72 rounded-full bg-brand-300/10 blur-3xl dark:bg-brand-600/10"
      />
    </div>

    <div class="relative mx-auto flex min-h-screen max-w-5xl flex-col px-4 py-6 sm:px-6 lg:py-10">
      <header class="mb-6 flex items-center justify-between">
        <div class="font-display text-xl font-semibold tracking-tight text-brand-700 dark:text-brand-300">
          Prime Classy
        </div>
        <button
          type="button"
          class="rounded-full border border-stone-200 bg-white/70 px-3 py-1.5 text-xs font-medium text-stone-600 backdrop-blur-sm dark:border-stone-800 dark:bg-stone-900/70 dark:text-stone-300"
          @click="ui.toggleTheme()"
        >
          Tampilan
        </button>
      </header>

      <!-- Mobile progress bar -->
      <div class="mb-6 lg:hidden">
        <div class="mb-1.5 flex items-center justify-between text-xs font-medium text-stone-500 dark:text-stone-400">
          <span>{{ STEPS[stepIndex(currentStep === 'done' ? 'lock' : currentStep)]?.label }}</span>
          <span>{{ progressPercent }}%</span>
        </div>
        <div class="h-1.5 w-full overflow-hidden rounded-full bg-stone-200 dark:bg-stone-800">
          <div
            class="h-full rounded-full bg-gradient-to-r from-brand-500 to-brand-700 transition-all duration-500"
            :style="{ width: progressPercent + '%' }"
          />
        </div>
      </div>

      <div class="flex flex-1 flex-col gap-6 lg:flex-row">
        <!-- Desktop stepper -->
        <aside class="hidden lg:block lg:w-64 lg:shrink-0">
          <ol class="space-y-1">
            <li
              v-for="(s, i) in STEPS"
              :key="s.key"
              class="flex items-center gap-3 rounded-xl px-3 py-2.5 transition-colors"
              :class="isCurrent(s.key) ? 'bg-white shadow-sm dark:bg-stone-900' : ''"
            >
              <span
                class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-xs font-semibold transition-colors"
                :class="[
                  isDone(s.key)
                    ? 'bg-emerald-500 text-white'
                    : isCurrent(s.key)
                      ? 'bg-brand-600 text-white dark:bg-brand-500'
                      : 'bg-stone-200 text-stone-500 dark:bg-stone-800 dark:text-stone-400',
                ]"
              >
                <svg v-if="isDone(s.key)" viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4">
                  <path
                    fill-rule="evenodd"
                    d="M16.704 5.29a1 1 0 010 1.415l-7.5 7.5a1 1 0 01-1.415 0l-3.5-3.5a1 1 0 111.415-1.414L8.5 12.086l6.79-6.79a1 1 0 011.414-.006z"
                    clip-rule="evenodd"
                  />
                </svg>
                <template v-else>{{ i + 1 }}</template>
              </span>
              <span
                class="text-sm font-medium"
                :class="isCurrent(s.key) ? 'text-stone-900 dark:text-stone-100' : 'text-stone-500 dark:text-stone-400'"
              >
                {{ s.label }}
              </span>
            </li>
          </ol>
        </aside>

        <!-- Main panel -->
        <main
          class="flex-1 rounded-2xl border border-stone-200/70 bg-white/90 p-6 shadow-xl shadow-stone-900/5 backdrop-blur-sm dark:border-stone-800/70 dark:bg-stone-900/90 sm:p-8"
        >
          <div v-if="booting" class="py-16 text-center text-sm text-stone-500 dark:text-stone-400">
            Memuat installer...
          </div>

          <template v-else>
            <p
              v-if="errorMessage"
              class="mb-5 rounded-lg bg-red-50 p-3 text-sm text-red-700 dark:bg-red-950 dark:text-red-400"
            >
              {{ errorMessage }}
            </p>

            <!-- 1. Welcome -->
            <div v-if="currentStep === 'welcome'">
              <h1 class="mb-2 font-display text-2xl font-semibold text-stone-800 dark:text-stone-100">
                Selamat Datang
              </h1>
              <p class="mb-6 text-sm leading-relaxed text-stone-600 dark:text-stone-400">
                Wizard ini akan memandu instalasi <strong>Prime Classy Cake &amp; Cookies</strong> langkah demi
                langkah: memeriksa persyaratan server, menghubungkan database, mengatur konfigurasi aplikasi, dan
                membuat akun Super Admin pertama. Tidak ada akun atau data contoh yang dibuat — hanya akun Super
                Admin yang kamu tentukan sendiri.
              </p>
              <AppButton size="lg" @click="goTo('requirements')">Mulai Instalasi</AppButton>
            </div>

            <!-- 2. Requirements -->
            <div v-else-if="currentStep === 'requirements'">
              <h1 class="mb-1 font-display text-xl font-semibold text-stone-800 dark:text-stone-100">
                Persyaratan Server
              </h1>
              <p class="mb-5 text-sm text-stone-500 dark:text-stone-400">
                Memastikan server kamu memenuhi seluruh syarat sebelum melanjutkan.
              </p>

              <div v-if="!requirements && !checkingRequirements" class="py-6 text-center">
                <AppButton @click="loadRequirements">Periksa Sekarang</AppButton>
              </div>
              <div v-else-if="checkingRequirements" class="py-10 text-center text-sm text-stone-500 dark:text-stone-400">
                Memeriksa...
              </div>
              <div v-else-if="requirements" class="space-y-4">
                <div>
                  <p class="mb-2 text-xs font-semibold tracking-wide text-stone-400 uppercase">Versi PHP</p>
                  <div
                    class="flex items-center justify-between rounded-lg border border-stone-200 px-3 py-2 text-sm dark:border-stone-800"
                  >
                    <span>PHP {{ requirements.php.version }} (minimal {{ requirements.php.minimum }})</span>
                    <span :class="requirements.php.ok ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400'">
                      {{ requirements.php.ok ? 'OK' : 'Tidak memenuhi' }}
                    </span>
                  </div>
                </div>

                <div>
                  <p class="mb-2 text-xs font-semibold tracking-wide text-stone-400 uppercase">Ekstensi PHP</p>
                  <div class="grid grid-cols-2 gap-2 sm:grid-cols-3">
                    <div
                      v-for="ext in requirements.extensions"
                      :key="ext.label"
                      class="flex items-center justify-between rounded-lg border border-stone-200 px-3 py-2 text-sm dark:border-stone-800"
                    >
                      <span>{{ ext.label }}</span>
                      <span :class="ext.ok ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400'">
                        {{ ext.ok ? 'OK' : 'Hilang' }}
                      </span>
                    </div>
                  </div>
                </div>

                <div>
                  <p class="mb-2 text-xs font-semibold tracking-wide text-stone-400 uppercase">Izin Folder</p>
                  <div class="space-y-2">
                    <div
                      v-for="perm in requirements.permissions"
                      :key="perm.label"
                      class="flex items-center justify-between rounded-lg border border-stone-200 px-3 py-2 text-sm dark:border-stone-800"
                    >
                      <span>{{ perm.label }}</span>
                      <span :class="perm.ok ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400'">
                        {{ perm.ok ? 'Bisa ditulis' : 'Tidak bisa ditulis' }}
                      </span>
                    </div>
                  </div>
                </div>

                <div class="flex flex-wrap gap-3 pt-2">
                  <AppButton variant="secondary" @click="loadRequirements">Periksa Ulang</AppButton>
                  <AppButton :disabled="!requirements.all_ok" @click="goTo('database')">Lanjutkan</AppButton>
                </div>
                <p v-if="!requirements.all_ok" class="text-sm text-red-600 dark:text-red-400">
                  Perbaiki syarat yang belum terpenuhi di atas sebelum melanjutkan.
                </p>
              </div>
            </div>

            <!-- 3. Database -->
            <div v-else-if="currentStep === 'database'">
              <h1 class="mb-1 font-display text-xl font-semibold text-stone-800 dark:text-stone-100">
                Konfigurasi Database
              </h1>
              <p class="mb-5 text-sm text-stone-500 dark:text-stone-400">
                Masukkan detail koneksi MySQL. Buat database ini terlebih dahulu di server/hosting kamu.
              </p>

              <form class="space-y-3" @submit.prevent="testConnection">
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                  <div class="sm:col-span-2">
                    <label class="mb-1 block text-sm font-medium text-stone-700 dark:text-stone-200">Host</label>
                    <input
                      v-model="db.host"
                      type="text"
                      required
                      class="w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950"
                    />
                  </div>
                  <div>
                    <label class="mb-1 block text-sm font-medium text-stone-700 dark:text-stone-200">Port</label>
                    <input
                      v-model.number="db.port"
                      type="number"
                      required
                      class="w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950"
                    />
                  </div>
                </div>
                <div>
                  <label class="mb-1 block text-sm font-medium text-stone-700 dark:text-stone-200">Nama Database</label>
                  <input
                    v-model="db.database"
                    type="text"
                    required
                    class="w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950"
                  />
                </div>
                <div>
                  <label class="mb-1 block text-sm font-medium text-stone-700 dark:text-stone-200">Username</label>
                  <input
                    v-model="db.username"
                    type="text"
                    required
                    class="w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950"
                  />
                </div>
                <div>
                  <label class="mb-1 block text-sm font-medium text-stone-700 dark:text-stone-200">Password</label>
                  <PasswordInput v-model="db.password" autocomplete="off" />
                </div>

                <AppButton type="submit" size="lg" :disabled="busy">
                  {{ busy ? 'Menguji koneksi...' : 'Tes Koneksi' }}
                </AppButton>
              </form>
            </div>

            <!-- 4. Connection test confirmation -->
            <div v-else-if="currentStep === 'connection_test'">
              <div class="mb-5 flex items-center gap-3">
                <span class="flex h-10 w-10 items-center justify-center rounded-full bg-emerald-100 text-emerald-600 dark:bg-emerald-950 dark:text-emerald-400">
                  <svg viewBox="0 0 20 20" fill="currentColor" class="h-5 w-5">
                    <path
                      fill-rule="evenodd"
                      d="M16.704 5.29a1 1 0 010 1.415l-7.5 7.5a1 1 0 01-1.415 0l-3.5-3.5a1 1 0 111.415-1.414L8.5 12.086l6.79-6.79a1 1 0 011.414-.006z"
                      clip-rule="evenodd"
                    />
                  </svg>
                </span>
                <h1 class="font-display text-xl font-semibold text-stone-800 dark:text-stone-100">Koneksi Berhasil</h1>
              </div>

              <dl class="mb-6 space-y-2 rounded-lg border border-stone-200 p-4 text-sm dark:border-stone-800">
                <div class="flex justify-between">
                  <dt class="text-stone-500 dark:text-stone-400">Host</dt>
                  <dd class="font-medium text-stone-800 dark:text-stone-100">{{ db.host }}:{{ db.port }}</dd>
                </div>
                <div class="flex justify-between">
                  <dt class="text-stone-500 dark:text-stone-400">Database</dt>
                  <dd class="font-medium text-stone-800 dark:text-stone-100">{{ db.database }}</dd>
                </div>
                <div class="flex justify-between">
                  <dt class="text-stone-500 dark:text-stone-400">Username</dt>
                  <dd class="font-medium text-stone-800 dark:text-stone-100">{{ db.username }}</dd>
                </div>
                <div class="flex justify-between">
                  <dt class="text-stone-500 dark:text-stone-400">Password</dt>
                  <dd class="font-medium text-stone-800 dark:text-stone-100">
                    {{ db.password ? '•'.repeat(Math.min(db.password.length, 12)) : '(kosong)' }}
                  </dd>
                </div>
              </dl>

              <div class="flex flex-wrap gap-3">
                <AppButton variant="secondary" @click="goTo('database')">Kembali</AppButton>
                <AppButton :disabled="busy" @click="saveDatabase">
                  {{ busy ? 'Menyimpan...' : 'Simpan & Lanjutkan' }}
                </AppButton>
              </div>
            </div>

            <!-- 5. Application configuration -->
            <div v-else-if="currentStep === 'app_config'">
              <h1 class="mb-1 font-display text-xl font-semibold text-stone-800 dark:text-stone-100">
                Konfigurasi Aplikasi
              </h1>
              <p class="mb-5 text-sm text-stone-500 dark:text-stone-400">
                Domain-domain ini menentukan bagaimana frontend dan backend saling terhubung dengan aman.
              </p>

              <form class="space-y-3" @submit.prevent="saveAppConfig">
                <div>
                  <label class="mb-1 block text-sm font-medium text-stone-700 dark:text-stone-200">Nama Aplikasi</label>
                  <input
                    v-model="appConfig.app_name"
                    type="text"
                    required
                    class="w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950"
                  />
                </div>
                <div>
                  <label class="mb-1 block text-sm font-medium text-stone-700 dark:text-stone-200">
                    URL Aplikasi
                  </label>
                  <input
                    v-model="appConfig.app_url"
                    type="url"
                    placeholder="https://namadomainkamu.com"
                    required
                    class="w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950"
                  />
                </div>
                <div>
                  <label class="mb-1 block text-sm font-medium text-stone-700 dark:text-stone-200">URL Frontend</label>
                  <input
                    v-model="appConfig.frontend_url"
                    type="url"
                    placeholder="https://namadomainkamu.com"
                    required
                    class="w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950"
                  />
                </div>

                <p class="text-xs text-stone-400">
                  Satu domain untuk semuanya: toko, dashboard, dan API (<code>/api</code>) berjalan di URL yang sama,
                  jadi kedua kolom di atas biasanya berisi domain yang sama (contoh: <code>https://namadomainkamu.com</code>).
                </p>

                <AppButton type="submit" size="lg" :disabled="busy">
                  {{ busy ? 'Menyimpan...' : 'Lanjutkan' }}
                </AppButton>
              </form>
            </div>

            <!-- 6. Super Admin -->
            <div v-else-if="currentStep === 'admin'">
              <h1 class="mb-1 font-display text-xl font-semibold text-stone-800 dark:text-stone-100">
                Buat Akun Super Admin
              </h1>
              <p class="mb-5 text-sm text-stone-500 dark:text-stone-400">
                Ini adalah akun pertama dan tertinggi di aplikasi — bukan akun demo. Simpan email dan password ini
                baik-baik.
              </p>

              <form class="space-y-3" @submit.prevent="proceedToInstall">
                <div>
                  <label class="mb-1 block text-sm font-medium text-stone-700 dark:text-stone-200">Nama</label>
                  <input
                    v-model="admin.name"
                    type="text"
                    required
                    class="w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950"
                  />
                </div>
                <div>
                  <label class="mb-1 block text-sm font-medium text-stone-700 dark:text-stone-200">Email</label>
                  <input
                    v-model="admin.email"
                    type="email"
                    required
                    class="w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950"
                  />
                </div>
                <div>
                  <label class="mb-1 block text-sm font-medium text-stone-700 dark:text-stone-200">No. HP</label>
                  <input
                    v-model="admin.phone"
                    type="text"
                    required
                    class="w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950"
                  />
                </div>
                <div>
                  <label class="mb-1 block text-sm font-medium text-stone-700 dark:text-stone-200">Password</label>
                  <PasswordInput v-model="admin.password" required :minlength="8" autocomplete="new-password" />
                </div>
                <div>
                  <label class="mb-1 block text-sm font-medium text-stone-700 dark:text-stone-200">
                    Konfirmasi Password
                  </label>
                  <PasswordInput
                    v-model="admin.password_confirmation"
                    required
                    :minlength="8"
                    autocomplete="new-password"
                  />
                </div>

                <AppButton type="submit" size="lg">Lanjutkan</AppButton>
              </form>
            </div>

            <!-- 7. Install database -->
            <div v-else-if="currentStep === 'install'">
              <h1 class="mb-1 font-display text-xl font-semibold text-stone-800 dark:text-stone-100">
                Instalasi Database
              </h1>
              <p class="mb-6 text-sm text-stone-500 dark:text-stone-400">
                Semua informasi sudah siap. Klik tombol di bawah untuk membuat seluruh struktur tabel, data dasar
                (role, bahasa, pengaturan, metode pembayaran, provider pengiriman), dan akun Super Admin kamu.
              </p>

              <div v-if="installing" class="flex items-center gap-3 py-6">
                <svg class="h-5 w-5 animate-spin text-brand-600 dark:text-brand-400" viewBox="0 0 24 24" fill="none">
                  <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                  <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z" />
                </svg>
                <span class="text-sm text-stone-600 dark:text-stone-400">Sedang menginstal, mohon tunggu...</span>
              </div>
              <AppButton v-else size="lg" @click="runInstall">Instal Sekarang</AppButton>
            </div>

            <!-- 8. Finalize -->
            <div v-else-if="currentStep === 'finalize'">
              <h1 class="mb-1 font-display text-xl font-semibold text-stone-800 dark:text-stone-100">Finalisasi</h1>
              <p class="mb-6 text-sm text-stone-500 dark:text-stone-400">
                Memverifikasi bahwa database dan akun Super Admin sudah siap sebelum mengunci instalasi.
              </p>

              <div v-if="finalizeSummary" class="mb-6 space-y-2 rounded-lg border border-stone-200 p-4 text-sm dark:border-stone-800">
                <div class="flex justify-between">
                  <span class="text-stone-500 dark:text-stone-400">Struktur database</span>
                  <span class="font-medium text-emerald-600 dark:text-emerald-400">Siap</span>
                </div>
                <div class="flex justify-between">
                  <span class="text-stone-500 dark:text-stone-400">Akun Super Admin</span>
                  <span class="font-medium text-emerald-600 dark:text-emerald-400">Siap</span>
                </div>
                <div class="flex justify-between">
                  <span class="text-stone-500 dark:text-stone-400">Tautan storage (upload gambar)</span>
                  <span
                    class="font-medium"
                    :class="finalizeSummary.storage_linked ? 'text-emerald-600 dark:text-emerald-400' : 'text-amber-600 dark:text-amber-400'"
                  >
                    {{ finalizeSummary.storage_linked ? 'Siap' : 'Perlu dibuat manual (lihat README)' }}
                  </span>
                </div>
              </div>

              <AppButton size="lg" :disabled="finalizing" @click="runFinalize">
                {{ finalizing ? 'Memverifikasi...' : 'Verifikasi & Lanjutkan' }}
              </AppButton>
            </div>

            <!-- 9. Lock -->
            <div v-else-if="currentStep === 'lock'">
              <h1 class="mb-1 font-display text-xl font-semibold text-stone-800 dark:text-stone-100">
                Kunci Instalasi
              </h1>
              <p class="mb-6 text-sm text-stone-500 dark:text-stone-400">
                Langkah terakhir. Setelah dikunci, halaman instalasi ini <strong>tidak dapat diakses lagi</strong> —
                ini melindungi aplikasi kamu dari instalasi ulang yang tidak sah.
              </p>

              <AppButton size="lg" variant="danger" :disabled="locking" @click="runLock">
                {{ locking ? 'Mengunci...' : 'Kunci Instalasi Sekarang' }}
              </AppButton>
            </div>

            <!-- Done -->
            <div v-else-if="currentStep === 'done'" class="py-4 text-center">
              <span
                class="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-full bg-emerald-100 text-emerald-600 dark:bg-emerald-950 dark:text-emerald-400"
              >
                <svg viewBox="0 0 20 20" fill="currentColor" class="h-7 w-7">
                  <path
                    fill-rule="evenodd"
                    d="M16.704 5.29a1 1 0 010 1.415l-7.5 7.5a1 1 0 01-1.415 0l-3.5-3.5a1 1 0 111.415-1.414L8.5 12.086l6.79-6.79a1 1 0 011.414-.006z"
                    clip-rule="evenodd"
                  />
                </svg>
              </span>
              <h1 class="mb-2 font-display text-xl font-semibold text-stone-800 dark:text-stone-100">
                Instalasi Selesai
              </h1>
              <p class="mb-6 text-sm text-stone-500 dark:text-stone-400">
                Aplikasi sudah terkunci dan siap digunakan. Silakan masuk dengan akun Super Admin yang baru saja
                dibuat.
              </p>
              <AppButton size="lg" block @click="goToLogin">Masuk Sekarang</AppButton>
            </div>
          </template>
        </main>
      </div>
    </div>
  </div>
</template>
