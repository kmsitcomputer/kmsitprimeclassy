<script setup lang="ts">
import { ref, watch, onMounted, onBeforeUnmount } from 'vue'
import { useI18n } from 'vue-i18n'
import { loadGoogleMaps } from '@/utils/googleMaps'

const { t } = useI18n()

/**
 * Rendered by CheckoutView's Address step whenever Google Maps is configured
 * — a second way (alongside "Gunakan Lokasi Sekarang") to set the same
 * latitude/longitude: search an address via Google Places, click the map, or
 * drag the marker. All three write back read-only coordinates (Blueprint:
 * "koordinat disimpan di input text yang tidak bisa diedit").
 *
 * `latitude`/`longitude` are one-way inputs the parent may already have (a
 * prior geolocation fix, a re-entered step) — shown as the initial marker,
 * and kept in sync if the parent's value changes from elsewhere (e.g. the
 * konsumen then clicks "Gunakan Lokasi Sekarang"). This component never
 * invents a value on its own until the user actually interacts with it.
 */
const props = defineProps<{ latitude?: number | null; longitude?: number | null }>()
const emit = defineEmits<{ picked: [{ lat: number; lng: number; formattedAddress: string }] }>()

const mapEl = ref<HTMLDivElement | null>(null)
const searchEl = ref<HTMLInputElement | null>(null)
const error = ref<string | null>(null)
const ready = ref(false)

let google: any = null
let map: any = null
let marker: any = null
// Guards against the prop-sync watcher re-placing the marker in response to
// the very update this component itself just emitted (no functional loop
// risk either way since positions would match, but avoids a redundant pan).
let lastEmitted: { lat: number; lng: number } | null = null

function placeMarker(lat: number, lng: number, pan = true) {
  const position = { lat, lng }
  if (marker) {
    marker.position = position
  } else if (google && map) {
    marker = new google.maps.marker.AdvancedMarkerElement({ map, position, gmpDraggable: true })
    marker.addListener('dragend', () => {
      const pos = marker.position
      lastEmitted = { lat: pos.lat, lng: pos.lng }
      emit('picked', { lat: pos.lat, lng: pos.lng, formattedAddress: '' })
    })
  }
  if (pan && map) map.panTo(position)
}

// Reacts to the parent setting/changing the coordinate from outside this
// component (geolocation, a saved value on re-entering the step) — moves
// the marker to match without emitting anything back (the parent already
// has this value; re-emitting would be a no-op loop, not a bug, but pointless).
watch(
  () => [props.latitude, props.longitude],
  ([lat, lng]) => {
    if (lat == null || lng == null || !ready.value) return
    if (lastEmitted && lastEmitted.lat === lat && lastEmitted.lng === lng) return
    placeMarker(lat, lng)
  },
)

onMounted(async () => {
  try {
    google = await loadGoogleMaps()
    if (!mapEl.value) return

    const hasInitial = props.latitude != null && props.longitude != null
    // A neutral generic viewport (not any particular customer's location) —
    // only used until a real coordinate (saved, geolocated, or picked) exists.
    const initialCenter = hasInitial ? { lat: props.latitude!, lng: props.longitude! } : { lat: -6.2, lng: 106.816666 }
    map = new google.maps.Map(mapEl.value, { center: initialCenter, zoom: hasInitial ? 16 : 12, mapId: 'DEMO_MAP_ID' })

    if (hasInitial) placeMarker(props.latitude!, props.longitude!, false)

    if (searchEl.value) {
      const autocomplete = new google.maps.places.Autocomplete(searchEl.value, { fields: ['geometry', 'formatted_address'] })
      autocomplete.addListener('place_changed', () => {
        const place = autocomplete.getPlace()
        const location = place.geometry?.location
        if (!location) return
        const lat = location.lat()
        const lng = location.lng()
        placeMarker(lat, lng)
        lastEmitted = { lat, lng }
        emit('picked', { lat, lng, formattedAddress: place.formatted_address ?? '' })
      })
    }

    map.addListener('click', (e: any) => {
      const lat = e.latLng.lat()
      const lng = e.latLng.lng()
      placeMarker(lat, lng)
      lastEmitted = { lat, lng }
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
  google = null
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
    <div ref="mapEl" class="h-72 w-full rounded-lg bg-stone-100 sm:h-80 dark:bg-stone-800" />
    <p v-if="error" class="text-xs text-red-600">{{ error }}</p>
    <p v-if="!ready && !error" class="text-xs text-stone-400">{{ t('checkout.address.mapLoading') }}</p>
    <p class="text-xs text-stone-400">{{ t('checkout.address.mapHint') }}</p>
  </div>
</template>
