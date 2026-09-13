<script setup lang="ts">
import { ref, reactive, computed, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import DashboardLayout from '@/layouts/DashboardLayout.vue'
import AppButton from '@/components/ui/AppButton.vue'
import ImageUploader from '@/components/ui/ImageUploader.vue'
import RichTextEditor from '@/components/ui/RichTextEditor.vue'
import { getPage, createPage, updatePage, type PagePayload } from '@/api/cmsContent'
import { listLanguages, type Language } from '@/api/languages'
import { ApiError } from '@/api/client'

const route = useRoute()
const router = useRouter()
const editingId = computed(() => (route.params.id ? Number(route.params.id) : null))

const languages = ref<Language[]>([])
const loading = ref(true)
const submitting = ref(false)
const generalError = ref<string | null>(null)
const errors = ref<Record<string, string[]>>({})
const activeLanguageId = ref<number | null>(null)
const notFound = ref(false)

const form = reactive({
  slug: '',
  status: 'draft' as 'draft' | 'published',
  coverMediaId: null as number | null,
  coverUrl: null as string | null,
  translations: {} as Record<number, { title: string; body: string }>,
})

const currentTranslation = computed(() => {
  if (activeLanguageId.value === null) return null
  form.translations[activeLanguageId.value] ??= { title: '', body: '' }
  return form.translations[activeLanguageId.value]
})

function languageName(id: number): string {
  return languages.value.find((l) => l.id === id)?.name ?? ''
}

async function load() {
  loading.value = true
  languages.value = await listLanguages()
  for (const lang of languages.value) {
    form.translations[lang.id] = { title: '', body: '' }
  }
  activeLanguageId.value = languages.value[0]?.id ?? null

  if (editingId.value) {
    const page = await getPage(editingId.value)
    if (!page) {
      notFound.value = true
      loading.value = false
      return
    }
    form.slug = page.slug
    form.status = page.status
    form.coverUrl = page.cover_image_url
    for (const t of page.translations) {
      form.translations[t.language_id] = { title: t.title, body: t.body ?? '' }
    }
  }
  loading.value = false
}
onMounted(load)

async function submit() {
  submitting.value = true
  errors.value = {}
  generalError.value = null

  const translations = languages.value
    .map((lang) => {
      const t = form.translations[lang.id]
      return { language_id: lang.id, title: t?.title ?? '', body: t?.body ?? '' }
    })
    .filter((t) => t.title.trim() !== '')

  try {
    const payload: PagePayload = {
      slug: form.slug,
      status: form.status,
      cover_media_id: form.coverMediaId ?? undefined,
      translations,
    }

    if (editingId.value) {
      await updatePage(editingId.value, payload)
    } else {
      await createPage(payload)
    }
    router.push({ name: 'admin-pages' })
  } catch (e) {
    if (e instanceof ApiError) {
      generalError.value = e.message
      errors.value = e.errors ?? {}
    } else {
      generalError.value = 'Terjadi kesalahan.'
    }
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <DashboardLayout>
    <div class="mx-auto max-w-3xl px-4 py-8">
      <div class="mb-6 flex items-center justify-between">
        <h1 class="text-xl font-semibold text-stone-900 dark:text-stone-50">
          {{ editingId ? 'Edit Halaman' : 'Tambah Halaman' }}
        </h1>
        <router-link :to="{ name: 'admin-pages' }" class="text-sm text-stone-500 hover:underline dark:text-stone-400">
          &larr; Kembali ke daftar
        </router-link>
      </div>

      <div v-if="loading" class="text-sm text-stone-500">Memuat...</div>
      <div v-else-if="notFound" class="rounded-xl bg-red-50 p-6 text-sm text-red-700 dark:bg-red-950/40 dark:text-red-300">
        Halaman tidak ditemukan.
      </div>

      <form v-else class="rounded-2xl border border-stone-200 bg-white p-6 dark:border-stone-800 dark:bg-stone-900" @submit.prevent="submit">
        <p v-if="generalError" class="mb-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-950/40 dark:text-red-300">
          {{ generalError }}
        </p>

        <div class="space-y-4">
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="mb-1 block text-sm font-medium text-stone-700 dark:text-stone-200">Slug</label>
              <input v-model="form.slug" type="text" class="w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950" />
              <p v-if="errors.slug" class="mt-1 text-xs text-red-600">{{ errors.slug[0] }}</p>
            </div>
            <div>
              <label class="mb-1 block text-sm font-medium text-stone-700 dark:text-stone-200">Status</label>
              <select v-model="form.status" class="w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950">
                <option value="draft">Draft</option>
                <option value="published">Publikasikan</option>
              </select>
            </div>
          </div>

          <ImageUploader
            v-model:media-id="form.coverMediaId"
            v-model:url="form.coverUrl"
            collection="cms_page_cover"
            label="Gambar Cover"
          />

          <div>
            <div class="mb-2 flex gap-1 border-b border-stone-200 dark:border-stone-700">
              <button
                v-for="lang in languages"
                :key="lang.id"
                type="button"
                class="rounded-t-lg px-3 py-1.5 text-sm font-medium"
                :class="
                  activeLanguageId === lang.id
                    ? 'border-b-2 border-brand-600 text-brand-700 dark:text-brand-400'
                    : 'text-stone-500 hover:text-stone-800 dark:hover:text-stone-200'
                "
                @click="activeLanguageId = lang.id"
              >
                {{ lang.name }}
              </button>
            </div>

            <template v-if="currentTranslation">
              <div class="space-y-3">
                <div>
                  <label class="mb-1 block text-sm font-medium text-stone-700 dark:text-stone-200">
                    Judul ({{ languageName(activeLanguageId!) }})
                  </label>
                  <input v-model="currentTranslation.title" type="text" class="w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950" />
                </div>
                <div>
                  <label class="mb-1 block text-sm font-medium text-stone-700 dark:text-stone-200">Isi</label>
                  <RichTextEditor v-model="currentTranslation.body" />
                </div>
              </div>
            </template>
          </div>
        </div>

        <AppButton type="submit" size="lg" block class="mt-6" :disabled="submitting">
          {{ submitting ? 'Menyimpan...' : 'Simpan' }}
        </AppButton>
      </form>
    </div>
  </DashboardLayout>
</template>
