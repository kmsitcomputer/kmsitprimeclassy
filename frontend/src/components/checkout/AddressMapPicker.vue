<script setup lang="ts">
import { ref, onMounted, onBeforeUnmount } from 'vue'
import { useI18n } from 'vue-i18n'
import { loadGoogleMaps } from '@/utils/googleMaps'

const { t } = useI18n()

/**
 * Only rendered by CheckoutView when the checkout steps response says
 * OpenRoute is active (map_picker_enabled) — search an address via Google
 * Places, or drag the marker, and both write back read-only coordinates
 * (Blueprint: "koordinat disimpan di input text yang tidak bisa diedit").
 */
const emit = defineEmits<{ picked: [{ lat: number; lng: number; formattedAddress: string }] }>()

const mapEl = ref<HTMLDivElement | null>(null)
const searchEl = ref<HTMLInputElement | null>(null)
const error = ref<string | null>(null)
const ready = ref(false)

let map: any = null
let marker: any = null

function placeMarker(google: any, lat: number, lng: number) {
  const position = { lat, lng }
  if (marker) {
    marker.position = position
  } else {
    marker = new google.maps.marker.AdvancedMarkerElement({ map, position, gmpDraggable: true })
    marker.addListener('dragend', () => {
      const pos = marker.position
      emit('picked', { lat: pos.lat, lng: pos.lng, formattedAddress: '' })
    })
  }
  map.panTo(position)
}

onMounted(async () => {
  try {
    const google = await loadGoogleMaps()
    if (!mapEl.value) return

    const initialCenter = { lat: -6.2, lng: 106.816666 } // Jakarta, just a starting viewport
    map = new google.maps.Map(mapEl.value, { center: initialCenter, zoom: 13, mapId: 'DEMO_MAP_ID' })

    if (searchEl.value) {
      const autocomplete = new google.maps.places.Autocomplete(searchEl.value, { fields: ['geometry', 'formatted_address'] })
      autocomplete.addListener('place_changed', () => {
        const place = autocomplete.getPlace()
        const location = place.geometry?.location
        if (!location) return
        const lat = location.lat()
        const lng = location.lng()
        placeMarker(google, lat, lng)
        emit('picked', { lat, lng, formattedAddress: place.formatted_address ?? '' })
      })
    }

    map.addListener('click', (e: any) => {
      const lat = e.latLng.lat()
      const lng = e.latLng.lng()
      placeMarker(google, lat, lng)
      emit('picked', { lat, lng, formattedAddress: '' })
    })

    ready.value = true
  } catch (e) {
    error.value = e instanceof Error ? e.message : t('checkout.address.mapLoadError')
  }
})

onBeforeUnmount(() => {
  marker = null
  map = null
})
</script>

<template>
  <div class="space-y-2">
    <input
      ref="searchEl"
      type="text"
      :placeholder="t('checkout.address.mapSearchPlaceholder')"
      class="w-full rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-950"
    />
    <div ref="mapEl" class="h-56 w-full rounded-lg bg-stone-100 dark:bg-stone-800" />
    <p v-if="error" class="text-xs text-red-600">{{ error }}</p>
    <p v-if="!ready && !error" class="text-xs text-stone-400">{{ t('checkout.address.mapLoading') }}</p>
    <p class="text-xs text-stone-400">{{ t('checkout.address.mapHint') }}</p>
  </div>
</template>
