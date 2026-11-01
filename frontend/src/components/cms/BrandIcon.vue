<script setup lang="ts">
import { computed } from 'vue'
import instagram from '@/assets/brands/instagram.svg?raw'
import tiktok from '@/assets/brands/tiktok.svg?raw'
import facebook from '@/assets/brands/facebook.svg?raw'
import shopee from '@/assets/brands/shopee.svg?raw'
import whatsapp from '@/assets/brands/whatsapp.svg?raw'
import tokopediaPng from '@/brands/tokopedia-com-logo.png'

/**
 * Local brand marks (see docs/BRAND-ASSET-PROVENANCE note in the remediation
 * report). Only the path data of our own bundled SVG files is rendered, never
 * markup. A platform without a bundled mark renders nothing and the caller's
 * text label remains the fallback.
 */
const SOURCES: Record<string, { svg: string; color: string; label: string }> = {
  instagram: { svg: instagram, color: '#E4405F', label: 'Instagram' },
  tiktok: { svg: tiktok, color: 'currentColor', label: 'TikTok' },
  facebook: { svg: facebook, color: '#0866FF', label: 'Facebook' },
  shopee: { svg: shopee, color: '#EE4D2D', label: 'Shopee' },
  whatsapp: { svg: whatsapp, color: '#25D366', label: 'WhatsApp' },
}

const props = withDefaults(defineProps<{ name: string; size?: number; color?: string }>(), { size: 24 })

// Raster marks supplied by the Owner (bundled through Vite, aspect ratio preserved).
const RASTER: Record<string, { src: string; label: string }> = {
  tokopedia: { src: tokopediaPng, label: 'Tokopedia' },
}

const raster = computed(() => RASTER[props.name])
const entry = computed(() => SOURCES[props.name])
const path = computed(() => entry.value?.svg.match(/<path[^>]* d="([^"]+)"/)?.[1] ?? null)
const fill = computed(() => props.color ?? entry.value?.color ?? 'currentColor')
</script>

<template>
  <img
    v-if="raster"
    :src="raster.src"
    alt=""
    :width="size"
    :height="size"
    decoding="async"
    class="shrink-0 object-contain"
    :style="{ width: `${size}px`, height: `${size}px` }"
  />
  <svg
    v-else-if="path"
    xmlns="http://www.w3.org/2000/svg"
    viewBox="0 0 24 24"
    :width="size"
    :height="size"
    :fill="fill"
    role="img"
    :aria-label="entry?.label"
    class="shrink-0"
  >
    <path :d="path" />
  </svg>
</template>
