<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import StoreLocatorView from '@/views/StoreLocatorView.vue'
import { useSeo } from '../composables/useSeo'

const { t } = useI18n()
useSeo(() => ({ title: `${t('nav.storeLocator')} — Prime Classy`, path: '/toko-kami' }))
</script>

<template><StoreLocatorView /></template>
