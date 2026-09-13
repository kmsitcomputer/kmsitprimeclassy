<script setup lang="ts">
import { ref, onMounted } from 'vue'
import DashboardLayout from '@/layouts/DashboardLayout.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import { listMedia, deleteMedia, type Media } from '@/api/media'

const items = ref<Media[]>([])
const loading = ref(true)
const currentPage = ref(1)
const lastPage = ref(1)
const collection = ref('')

async function load(page = 1) {
  loading.value = true
  const result = await listMedia({ collection: collection.value || undefined, page })
  items.value = result.items
  currentPage.value = result.currentPage
  lastPage.value = result.lastPage
  loading.value = false
}

onMounted(() => load())

async function remove(media: Media) {
  if (!confirm(`Hapus "${media.original_filename ?? media.id}"?`)) return
  await deleteMedia(media.id)
  await load(currentPage.value)
}

function formatSize(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
}
</script>

<template>
  <DashboardLayout>
    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
      <h1 class="text-xl font-semibold text-stone-900 dark:text-stone-50">Media</h1>
      <div class="flex items-center gap-2">
        <input
          v-model="collection"
          type="text"
          placeholder="Filter koleksi (mis. cms_content)"
          class="rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950"
          @keyup.enter="load()"
        />
        <button type="button" class="rounded-lg bg-stone-800 px-3 py-2 text-xs font-medium text-white hover:bg-stone-900 dark:bg-stone-100 dark:text-stone-900" @click="load()">
          Filter
        </button>
      </div>
    </div>

    <div v-if="loading" class="text-sm text-stone-500">Memuat...</div>
    <div v-else-if="items.length === 0" class="text-sm text-stone-500">Belum ada media.</div>

    <div v-else class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
      <div
        v-for="media in items"
        :key="media.id"
        class="group relative overflow-hidden rounded-xl border border-stone-200 bg-white dark:border-stone-800 dark:bg-stone-900"
      >
        <img v-if="media.mime_type.startsWith('image/')" :src="media.url" class="h-32 w-full object-cover" alt="" />
        <div v-else class="flex h-32 w-full items-center justify-center bg-stone-100 dark:bg-stone-800">
          <AppIcon name="document" :size="28" class="text-stone-400" />
        </div>
        <div class="p-2">
          <p class="truncate text-xs font-medium text-stone-700 dark:text-stone-200">{{ media.original_filename ?? '—' }}</p>
          <p class="text-[11px] text-stone-400">{{ media.collection }} · {{ formatSize(media.size) }}</p>
        </div>
        <button
          type="button"
          class="absolute right-1.5 top-1.5 hidden rounded-lg bg-black/60 p-1.5 text-white group-hover:block"
          title="Hapus"
          @click="remove(media)"
        >
          <AppIcon name="trash" :size="14" />
        </button>
      </div>
    </div>

    <div v-if="lastPage > 1" class="mt-6 flex items-center justify-center gap-2 text-sm">
      <button type="button" class="rounded-lg border border-stone-200 px-3 py-1.5 disabled:opacity-40 dark:border-stone-700" :disabled="currentPage <= 1" @click="load(currentPage - 1)">
        Sebelumnya
      </button>
      <span class="text-stone-500">{{ currentPage }} / {{ lastPage }}</span>
      <button type="button" class="rounded-lg border border-stone-200 px-3 py-1.5 disabled:opacity-40 dark:border-stone-700" :disabled="currentPage >= lastPage" @click="load(currentPage + 1)">
        Berikutnya
      </button>
    </div>
  </DashboardLayout>
</template>
