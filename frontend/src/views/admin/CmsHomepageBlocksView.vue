<script setup lang="ts">
import { ref, onMounted, computed } from 'vue'
import { useRouter } from 'vue-router'
import DashboardLayout from '@/layouts/DashboardLayout.vue'
import AppButton from '@/components/ui/AppButton.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import { listAdminBlocks, deleteBlock, toggleBlock, reorderBlocks, type AdminHomepageBlock } from '@/api/cmsAdmin'

const BLOCK_TYPES = [
  { value: 'hero', label: 'Hero' },
  { value: 'banner', label: 'Banner' },
  { value: 'category', label: 'Kategori' },
  { value: 'product', label: 'Produk' },
  { value: 'promotional', label: 'Promosi' },
  { value: 'text', label: 'Teks' },
  { value: 'image', label: 'Gambar' },
  { value: 'article', label: 'Artikel' },
  { value: 'cta', label: 'CTA' },
  { value: 'custom', label: 'Custom HTML' },
] as const

const router = useRouter()
const blocks = ref<AdminHomepageBlock[]>([])
const loading = ref(true)

async function load() {
  loading.value = true
  blocks.value = await listAdminBlocks()
  loading.value = false
}

onMounted(load)

async function remove(block: AdminHomepageBlock) {
  if (!confirm(`Hapus block "${block.type}" ini?`)) return
  await deleteBlock(block.id)
  await load()
}

async function toggle(block: AdminHomepageBlock) {
  await toggleBlock(block.id)
  await load()
}

async function move(index: number, delta: number) {
  const target = index + delta
  if (target < 0 || target >= blocks.value.length) return
  const reordered = [...blocks.value]
  const [item] = reordered.splice(index, 1)
  if (!item) return
  reordered.splice(target, 0, item)
  blocks.value = reordered
  await reorderBlocks(reordered.map((b) => b.id))
}

const typeLabel = computed(() => (type: string) => BLOCK_TYPES.find((t) => t.value === type)?.label ?? type)
</script>

<template>
  <DashboardLayout>
    <div class="mb-4 flex items-center justify-between">
      <h1 class="font-display text-xl font-semibold text-stone-800 dark:text-stone-100">Kelola Homepage</h1>
      <AppButton @click="router.push({ name: 'admin-homepage-blocks-create' })">
        <AppIcon name="plus" :size="16" /> Tambah Block
      </AppButton>
    </div>

    <div v-if="loading" class="space-y-3">
      <div v-for="i in 3" :key="i" class="h-20 animate-pulse rounded-2xl bg-stone-100 dark:bg-stone-800" />
    </div>

    <ul v-else class="space-y-3">
      <li
        v-for="(block, index) in blocks"
        :key="block.id"
        class="flex items-center gap-3 rounded-2xl border border-stone-200 bg-white p-3 dark:border-stone-800 dark:bg-stone-900"
        :class="!block.is_active && 'opacity-50'"
      >
        <img v-if="block.image_url" :src="block.image_url" class="h-14 w-14 shrink-0 rounded-lg object-cover" />
        <div v-else class="grid h-14 w-14 shrink-0 place-items-center rounded-lg bg-stone-100 text-stone-300 dark:bg-stone-800">
          <AppIcon name="box" :size="20" />
        </div>

        <div class="min-w-0 flex-1">
          <span class="rounded-full bg-brand-50 px-2 py-0.5 text-xs font-medium text-brand-700 dark:bg-brand-950 dark:text-brand-300">
            {{ typeLabel(block.type) }}
          </span>
          <p class="mt-1 truncate text-sm text-stone-600 dark:text-stone-300">
            {{ block.content?.heading || block.content?.body || block.content?.html || `Block #${block.id}` }}
          </p>
        </div>

        <div class="flex shrink-0 items-center gap-1">
          <button type="button" class="grid h-8 w-8 place-items-center rounded-full text-stone-400 hover:bg-stone-100 disabled:opacity-30 dark:hover:bg-stone-800" :disabled="index === 0" @click="move(index, -1)">↑</button>
          <button type="button" class="grid h-8 w-8 place-items-center rounded-full text-stone-400 hover:bg-stone-100 disabled:opacity-30 dark:hover:bg-stone-800" :disabled="index === blocks.length - 1" @click="move(index, 1)">↓</button>
          <button type="button" class="rounded-full px-2.5 py-1 text-xs font-medium" :class="block.is_active ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-400' : 'bg-stone-100 text-stone-500 dark:bg-stone-800'" @click="toggle(block)">
            {{ block.is_active ? 'Aktif' : 'Nonaktif' }}
          </button>
          <button type="button" class="grid h-8 w-8 place-items-center rounded-full text-stone-400 hover:bg-stone-100 dark:hover:bg-stone-800" @click="router.push({ name: 'admin-homepage-blocks-edit', params: { id: block.id } })"><AppIcon name="menu" :size="15" /></button>
          <button type="button" class="grid h-8 w-8 place-items-center rounded-full text-red-500 hover:bg-red-50 dark:hover:bg-red-950" @click="remove(block)"><AppIcon name="trash" :size="15" /></button>
        </div>
      </li>

      <li v-if="!blocks.length" class="rounded-2xl bg-stone-100 p-8 text-center text-sm text-stone-500 dark:bg-stone-900">
        Belum ada block. Klik "Tambah Block" untuk mulai menyusun homepage.
      </li>
    </ul>
  </DashboardLayout>
</template>
