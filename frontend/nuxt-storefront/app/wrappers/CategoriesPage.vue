<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import CategoriesView from '@/views/CategoriesView.vue'
import { useSeo } from '../composables/useSeo'

const { t } = useI18n()
useSeo(() => ({ title: `${t('categories.title')} — Prime Classy`, path: '/categories' }))
</script>

<template><CategoriesView /></template>
