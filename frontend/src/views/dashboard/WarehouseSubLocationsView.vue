<script setup lang="ts">
import { onMounted, ref } from 'vue'
import DashboardLayout from '@/layouts/DashboardLayout.vue'
import { createSubLocation, deactivateSubLocation, listSubLocations, type SubLocation } from '@/api/warehouse'

const locations = ref<SubLocation[]>([]); const code = ref(''); const name = ref(''); const error = ref('')
async function load() { locations.value = await listSubLocations() }
async function create() { try { await createSubLocation({ code: code.value, name: name.value }); code.value = ''; name.value = ''; await load() } catch { error.value = 'Gagal menyimpan lokasi Sub.' } }
async function deactivate(id: number) { try { await deactivateSubLocation(id); await load() } catch { error.value = 'Lokasi berisi stok atau tidak dapat dinonaktifkan.' } }
onMounted(load)
</script>
<template><DashboardLayout><h1 class="mb-1 font-display text-2xl font-semibold">Sub Locations</h1><p class="mb-5 text-sm text-stone-500">Stok fisik lokasi Sub, bukan stok tersedia untuk dijual.</p><form class="mb-5 flex gap-2" @submit.prevent="create"><input v-model="code" required placeholder="Code" /><input v-model="name" required placeholder="Name" /><button class="rounded bg-brand-600 px-3 py-2 text-white">Add</button></form><p v-if="error" class="mb-3 text-sm text-red-600">{{ error }}</p><div v-for="location in locations" :key="location.id" class="mb-2 flex justify-between border p-3"><span>{{ location.code }} · {{ location.name }} · {{ location.is_active ? 'Active' : 'Inactive' }}</span><button v-if="location.is_active" class="rounded bg-stone-200 px-3 py-1" @click="deactivate(location.id)">Deactivate</button></div></DashboardLayout></template>