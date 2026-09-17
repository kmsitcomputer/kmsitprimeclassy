<script setup lang="ts">
import { ref, watch, onMounted, onBeforeUnmount } from 'vue'
import { useI18n } from 'vue-i18n'
import { loadGoogleMaps, onGoogleMapsAuthFailure } from '@/utils/googleMaps'

const { t } = useI18n()

/**
 * Read-only Google Maps display — the same Maps JS SDK loader/rendering
 * approach as the checkout address picker (AddressMapPicker.vue), just
 * without drag/search/click interactivity, so every screen that shows a
 * Google Map in this app goes through one consistent code path instead of
 * checkout using the JS SDK and other screens using the separate Embed
 * iframe API. Callers that have no API key configured at all should not
 * mount this component in the first place (see OrderDetailView's
 * OpenStreetMap fallback) — this component only handles the "key
 * configured but this specific load/render failed" case gracefully.
 */
const props = defineProps<{ latitude: number; longitude: number; zoom?: number }>()

const mapEl = ref<HTMLDivElement | null>(null)
const error = ref<string | null>(null)
const ready = ref(false)

let google: any = null
let map: any = null
let marker: any = null
let isMounted = true

const stopAuthFailureListener = onGoogleMapsAuthFailure(() => {
  if (!isMounted) return
  error.value = t('checkout.address.mapLoadError')
  ready.value = false
})

function placeMarker(lat: number, lng: number) {
  const position = { lat, lng }
  if (marker) {
    marker.position = position
  } else if (google && map) {
    marker = new google.maps.marker.AdvancedMarkerElement({ map, position })
  }
  if (map) map.setCenter(position)
}

// Keeps the view in sync if the parent's coordinates change after mount
// (e.g. the order finishes loading after this component already mounted).
watch(
  () => [props.latitude, props.longitude],
  ([lat, lng]) => {
    if (!ready.value || lat == null || lng == null) return
    placeMarker(lat, lng)
  },
)

onMounted(async () => {
  try {
    google = await loadGoogleMaps()
    if (!isMounted || !mapEl.value) return

    map = new google.maps.Map(mapEl.value, {
      center: { lat: props.latitude, lng: props.longitude },
      zoom: props.zoom ?? 16,
      mapId: 'DEMO_MAP_ID',
      disableDefaultUI: true,
      gestureHandling: 'none',
      keyboardShortcuts: false,
    })
    placeMarker(props.latitude, props.longitude)

    ready.value = true
  } catch (e) {
    if (!isMounted) return
    error.value = e instanceof Error ? e.message : t('checkout.address.mapLoadError')
  }
})

onBeforeUnmount(() => {
  isMounted = false
  stopAuthFailureListener()
  marker = null
  map = null
  google = null
})
</script>

<template>
  <div class="relative h-full w-full">
    <div ref="mapEl" class="h-full w-full" />
    <p v-if="error" class="absolute inset-0 flex items-center justify-center bg-stone-50 px-3 text-center text-xs text-stone-500 dark:bg-stone-800 dark:text-stone-400">
      {{ error }}
    </p>
  </div>
</template>
