<script setup lang="ts">
import { onMounted, ref } from 'vue'
import DashboardLayout from '@/layouts/DashboardLayout.vue'
import AppButton from '@/components/ui/AppButton.vue'
import { getInvoiceConfig, saveInvoiceConfig, type InvoiceConfig } from '@/api/invoice'
import { ApiError } from '@/api/client'
import { formatApiError } from '@/utils/apiError'

/**
 * IMP-002 — Invoice presentation configuration.
 *
 * Super Admin edits the GLOBAL row; Agen edits their OWN branch row (the
 * backend resolves which by the actor's role/agent scope). Display-only —
 * financial truth on the PDF always derives from the canonical Order/
 * PaymentSummary values.
 */
const loading = ref(false)
const saving = ref(false)
const errorMessage = ref('')
const successMessage = ref('')

const form = ref<InvoiceConfig>({
  company_name: 'PrimeClassy',
  title: 'INVOICE',
  contact: '',
  address: '',
  footer: 'Terima kasih atas kepercayaan Anda.',
  notes: '',
  show_items: true,
  show_konsumen: true,
  show_sales: true,
  show_korsal: true,
  show_payment_summary: true,
  show_voucher: true,
  logo_media_id: null,
})

async function load() {
  loading.value = true
  errorMessage.value = ''
  try {
    const cfg = await getInvoiceConfig()
    form.value = { ...form.value, ...cfg }
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? formatApiError(e) : 'Gagal memuat konfigurasi invoice.'
  } finally {
    loading.value = false
  }
}

async function save() {
  saving.value = true
  errorMessage.value = ''
  successMessage.value = ''
  try {
    await saveInvoiceConfig({
      company_name: form.value.company_name,
      title: form.value.title,
      contact: form.value.contact,
      address: form.value.address,
      footer: form.value.footer,
      notes: form.value.notes,
      show_items: form.value.show_items,
      show_konsumen: form.value.show_konsumen,
      show_sales: form.value.show_sales,
      show_korsal: form.value.show_korsal,
      show_payment_summary: form.value.show_payment_summary,
      show_voucher: form.value.show_voucher,
      // A1-22: logo_media_id is persisted + rendered server-side (same-branch
      // media only); the UI now exposes the accepted field.
      ...(form.value.logo_media_id ? { logo_media_id: form.value.logo_media_id } : { logo_media_id: null }),
    })
    successMessage.value = 'Konfigurasi invoice disimpan.'
    await load()
  } catch (e) {
    errorMessage.value = e instanceof ApiError ? formatApiError(e) : 'Gagal menyimpan konfigurasi.'
  } finally {
    saving.value = false
  }
}

onMounted(load)
</script>

<template>
  <DashboardLayout>
    <div class="max-w-2xl space-y-6">
      <div>
        <h1 class="text-2xl font-bold text-slate-800 dark:text-slate-100">Konfigurasi Invoice</h1>
        <p class="text-sm text-slate-500">Pengaturan tampilan invoice PDF (bukan Print Resi). Nilai finansial selalu dari server.</p>
      </div>

      <div v-if="errorMessage" class="rounded border border-red-300 bg-red-50 p-3 text-red-700">{{ errorMessage }}</div>
      <div v-if="successMessage" class="rounded border border-emerald-300 bg-emerald-50 p-3 text-emerald-700">{{ successMessage }}</div>

      <div class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-800">
        <div class="grid gap-3">
          <label class="text-sm font-medium">Nama Perusahaan / Cabang
            <input v-model="form.company_name" class="mt-1 w-full rounded border border-slate-300 p-2 dark:bg-slate-900" />
          </label>
          <label class="text-sm font-medium">Judul
            <input v-model="form.title" class="mt-1 w-full rounded border border-slate-300 p-2 dark:bg-slate-900" />
          </label>
          <label class="text-sm font-medium">Kontak
            <input v-model="form.contact" class="mt-1 w-full rounded border border-slate-300 p-2 dark:bg-slate-900" />
          </label>
          <label class="text-sm font-medium">Alamat
            <textarea v-model="form.address" class="mt-1 w-full rounded border border-slate-300 p-2 dark:bg-slate-900" rows="2"></textarea>
          </label>
          <label class="text-sm font-medium">Catatan
            <textarea v-model="form.notes" class="mt-1 w-full rounded border border-slate-300 p-2 dark:bg-slate-900" rows="2"></textarea>
          </label>
          <label class="text-sm font-medium">Footer
            <textarea v-model="form.footer" class="mt-1 w-full rounded border border-slate-300 p-2 dark:bg-slate-900" rows="2"></textarea>
          </label>
          <label class="text-sm font-medium">ID Logo (Media — diunggah oleh Agen cabang)
            <input v-model.number="form.logo_media_id" type="number" min="0" placeholder="0 = tanpa logo" class="mt-1 w-full rounded border border-slate-300 p-2 dark:bg-slate-900" />
          </label>
        </div>

        <div class="mt-4 grid gap-2 sm:grid-cols-2">
          <label class="flex items-center gap-2 text-sm"><input v-model="form.show_items" type="checkbox" /> Tampilkan item</label>
          <label class="flex items-center gap-2 text-sm"><input v-model="form.show_payment_summary" type="checkbox" /> Tampilkan ringkasan pembayaran</label>
          <label class="flex items-center gap-2 text-sm"><input v-model="form.show_sales" type="checkbox" /> Tampilkan Sales</label>
          <label class="flex items-center gap-2 text-sm"><input v-model="form.show_korsal" type="checkbox" /> Tampilkan Korsal</label>
          <label class="flex items-center gap-2 text-sm"><input v-model="form.show_voucher" type="checkbox" /> Tampilkan voucher</label>
        </div>

        <div class="mt-5"><AppButton :loading="saving" @click="save">Simpan Konfigurasi</AppButton></div>
      </div>
    </div>
  </DashboardLayout>
</template>