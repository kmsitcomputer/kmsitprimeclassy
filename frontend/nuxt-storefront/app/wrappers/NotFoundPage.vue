<script setup lang="ts">
import NotFoundView from '@/views/NotFoundView.vue'
import { useSeo } from '../composables/useSeo'

useSeo({ title: 'Halaman tidak ditemukan — Prime Classy', path: '/', notFound: true })
</script>

<template><NotFoundView /></template>
