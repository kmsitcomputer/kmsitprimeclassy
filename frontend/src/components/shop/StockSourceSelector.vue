<script setup lang="ts">
import { computed } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { useCartStore } from '@/stores/cart'
const auth = useAuthStore()
const cart = useCartStore()
const source = computed({
  get: () => auth.user ? cart.stockSourceFor(auth.user.id) : 'agent',
  set: (value: 'agent' | 'sub') => { if (auth.user) cart.setStockSource(auth.user.id, value) },
})
</script>
<template>
  <fieldset v-if="auth.user?.role === 'sales-kurir-sub'" class="mb-4 rounded-lg border border-stone-200 p-3 text-sm dark:border-stone-700" data-testid="stock-source">
    <legend class="px-1">Sumber stok seluruh keranjang</legend>
    <label class="mr-4 inline-flex items-center gap-1.5"><input v-model="source" type="radio" value="agent" /> Stok Agen induk</label>
    <label class="inline-flex items-center gap-1.5"><input v-model="source" type="radio" value="sub" /> Stok Sub saya</label>
  </fieldset>
</template>
