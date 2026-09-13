<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import DashboardLayout from '@/layouts/DashboardLayout.vue'
import AppButton from '@/components/ui/AppButton.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import { listAdminArticles, deleteArticle, type Article } from '@/api/cmsContent'

const router = useRouter()
const articles = ref<Article[]>([])
const loading = ref(true)

async function load() {
  loading.value = true
  articles.value = await listAdminArticles()
  loading.value = false
}

onMounted(load)

async function remove(article: Article) {
  if (!confirm(`Hapus "${article.slug}"?`)) return
  await deleteArticle(article.id)
  await load()
}
</script>

<template>
  <DashboardLayout>
    <div class="mx-auto max-w-5xl px-4 py-8">
      <div class="mb-6 flex items-center justify-between">
        <h1 class="text-xl font-semibold text-stone-900 dark:text-stone-50">Artikel & Berita</h1>
        <AppButton @click="router.push({ name: 'admin-articles-create' })"><AppIcon name="plus" :size="18" /> Tambah</AppButton>
      </div>

      <div v-if="loading" class="text-sm text-stone-500">Memuat...</div>

      <div v-else class="overflow-hidden rounded-xl border border-stone-200 dark:border-stone-800">
        <table class="w-full text-left text-sm">
          <thead class="bg-stone-50 text-stone-500 dark:bg-stone-900 dark:text-stone-400">
            <tr>
              <th class="px-4 py-2">Cover</th>
              <th class="px-4 py-2">Judul</th>
              <th class="px-4 py-2">Tipe</th>
              <th class="px-4 py-2">Status</th>
              <th class="px-4 py-2" />
            </tr>
          </thead>
          <tbody class="divide-y divide-stone-100 dark:divide-stone-800">
            <tr v-for="article in articles" :key="article.id">
              <td class="px-4 py-2">
                <img
                  v-if="article.cover_image_url"
                  :src="article.cover_image_url"
                  class="h-10 w-14 rounded object-cover"
                  alt=""
                />
                <div v-else class="flex h-10 w-14 items-center justify-center rounded bg-stone-100 dark:bg-stone-800">
                  <AppIcon name="box" :size="16" class="text-stone-400" />
                </div>
              </td>
              <td class="px-4 py-2">{{ article.translations[0]?.title ?? article.slug }}</td>
              <td class="px-4 py-2 capitalize">{{ article.type }}</td>
              <td class="px-4 py-2 capitalize">{{ article.status }}</td>
              <td class="px-4 py-2 text-right">
                <button class="mr-3 text-brand-600 hover:underline dark:text-brand-400" @click="router.push({ name: 'admin-articles-edit', params: { id: article.id } })">Edit</button>
                <button class="text-red-600 hover:underline dark:text-red-400" @click="remove(article)">Hapus</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </DashboardLayout>
</template>
