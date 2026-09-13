<script setup lang="ts">
type IconName =
  | 'home'
  | 'search'
  | 'cart'
  | 'user'
  | 'heart'
  | 'heart-filled'
  | 'filter'
  | 'sort'
  | 'chevron-down'
  | 'chevron-right'
  | 'chevron-left'
  | 'sun'
  | 'moon'
  | 'close'
  | 'check'
  | 'plus'
  | 'minus'
  | 'trash'
  | 'box'
  | 'map-pin'
  | 'menu'
  | 'logout'
  | 'grid'
  | 'clock'
  | 'lock'
  | 'users'
  | 'truck'
  | 'document'
  | 'cash'
  | 'settings'
  | 'image'
  | 'credit-card'
  | 'dashboard'
  | 'refresh'
  | 'download'
  | 'upload'
  | 'edit'
  | 'globe'
  | 'chart-bar'
  | 'shield'
  | 'chevron-up'

defineProps<{ name: IconName; size?: number }>()
</script>

<template>
  <svg
    :width="size ?? 22"
    :height="size ?? 22"
    viewBox="0 0 24 24"
    fill="none"
    stroke="currentColor"
    stroke-width="1.8"
    stroke-linecap="round"
    stroke-linejoin="round"
    aria-hidden="true"
  >
    <template v-if="name === 'home'">
      <path d="M3 11.5 12 4l9 7.5" />
      <path d="M5.5 10v9a1 1 0 0 0 1 1H10v-6h4v6h3.5a1 1 0 0 0 1-1v-9" />
    </template>
    <template v-else-if="name === 'search'">
      <circle cx="11" cy="11" r="7" />
      <path d="m20 20-3.5-3.5" />
    </template>
    <template v-else-if="name === 'cart'">
      <path d="M3 4h2l1.6 10.6a2 2 0 0 0 2 1.7h7.7a2 2 0 0 0 2-1.6L20 8H6" />
      <circle cx="9" cy="20" r="1.4" />
      <circle cx="17" cy="20" r="1.4" />
    </template>
    <template v-else-if="name === 'user'">
      <circle cx="12" cy="8" r="3.5" />
      <path d="M4.5 20a7.5 7.5 0 0 1 15 0" />
    </template>
    <template v-else-if="name === 'heart'">
      <path
        d="M12 20.2s-7.5-4.5-9.7-9.1C1 8 2.2 4.7 5.4 3.8c2-.6 3.9.2 5 1.8l1.6 2 1.6-2c1.1-1.6 3-2.4 5-1.8 3.2.9 4.4 4.2 3.1 7.3-2.2 4.6-9.7 9.1-9.7 9.1Z"
      />
    </template>
    <template v-else-if="name === 'heart-filled'">
      <path
        d="M12 20.2s-7.5-4.5-9.7-9.1C1 8 2.2 4.7 5.4 3.8c2-.6 3.9.2 5 1.8l1.6 2 1.6-2c1.1-1.6 3-2.4 5-1.8 3.2.9 4.4 4.2 3.1 7.3-2.2 4.6-9.7 9.1-9.7 9.1Z"
        fill="currentColor"
      />
    </template>
    <template v-else-if="name === 'filter'">
      <path d="M4 5h16M7 12h10M10 19h4" />
    </template>
    <template v-else-if="name === 'sort'">
      <path d="M7 4v16M7 20l-3-3M7 20l3-3M17 20V4M17 4l-3 3M17 4l3 3" />
    </template>
    <template v-else-if="name === 'chevron-down'">
      <path d="m6 9 6 6 6-6" />
    </template>
    <template v-else-if="name === 'chevron-right'">
      <path d="m9 6 6 6-6 6" />
    </template>
    <template v-else-if="name === 'chevron-left'">
      <path d="m15 6-6 6 6 6" />
    </template>
    <template v-else-if="name === 'sun'">
      <circle cx="12" cy="12" r="4" />
      <path d="M12 2v2.5M12 19.5V22M4.2 4.2l1.8 1.8M18 18l1.8 1.8M2 12h2.5M19.5 12H22M4.2 19.8 6 18M18 6l1.8-1.8" />
    </template>
    <template v-else-if="name === 'moon'">
      <path d="M20 14.5A8.5 8.5 0 1 1 9.5 4a7 7 0 0 0 10.5 10.5Z" />
    </template>
    <template v-else-if="name === 'close'">
      <path d="m6 6 12 12M18 6 6 18" />
    </template>
    <template v-else-if="name === 'check'">
      <path d="m5 12.5 4.5 4.5L19 7" />
    </template>
    <template v-else-if="name === 'plus'">
      <path d="M12 5v14M5 12h14" />
    </template>
    <template v-else-if="name === 'minus'">
      <path d="M5 12h14" />
    </template>
    <template v-else-if="name === 'trash'">
      <path d="M4 7h16M9 7V5a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2m2 0-.8 12a1 1 0 0 1-1 .9H8.8a1 1 0 0 1-1-.9L7 7h10Z" />
    </template>
    <template v-else-if="name === 'box'">
      <path d="M3.5 8 12 3.5 20.5 8 12 12.5 3.5 8Z" />
      <path d="M3.5 8v9L12 21.5m8.5-13.5v9L12 21.5m0-9v9" />
    </template>
    <template v-else-if="name === 'map-pin'">
      <path d="M12 21s7-6.6 7-11.5A7 7 0 0 0 5 9.5C5 14.4 12 21 12 21Z" />
      <circle cx="12" cy="9.5" r="2.3" />
    </template>
    <template v-else-if="name === 'menu'">
      <path d="M4 6h16M4 12h16M4 18h16" />
    </template>
    <template v-else-if="name === 'logout'">
      <path d="M15 17.5 20.5 12 15 6.5M20.5 12H9M12 4H6a1 1 0 0 0-1 1v14a1 1 0 0 0 1 1h6" />
    </template>
    <template v-else-if="name === 'grid'">
      <path d="M4 4h7v7H4zM13 4h7v7h-7zM4 13h7v7H4zM13 13h7v7h-7z" />
    </template>
    <template v-else-if="name === 'clock'">
      <circle cx="12" cy="12" r="8.5" />
      <path d="M12 7.5V12l3 2" />
    </template>
    <template v-else-if="name === 'lock'">
      <rect x="5" y="10.5" width="14" height="9.5" rx="1.5" />
      <path d="M8 10.5V8a4 4 0 0 1 8 0v2.5" />
    </template>
    <template v-else-if="name === 'users'">
      <circle cx="9" cy="8" r="3" />
      <path d="M2.5 20a6.5 6.5 0 0 1 13 0" />
      <path d="M16 4.5a3 3 0 1 1 1.5 5.6" />
      <path d="M15 13.2c2.8.4 5 2.2 5.5 6.8" />
    </template>
    <template v-else-if="name === 'truck'">
      <path d="M2.5 6h11v10h-11Z" />
      <path d="M13.5 10h3.6l3.4 3v3h-7Z" />
      <circle cx="6.5" cy="18.5" r="1.6" />
      <circle cx="16" cy="18.5" r="1.6" />
    </template>
    <template v-else-if="name === 'document'">
      <path d="M6 3h8l4 4v14a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1Z" />
      <path d="M14 3v4h4M8 12h8M8 15.5h8M8 8.5h3" />
    </template>
    <template v-else-if="name === 'cash'">
      <rect x="2.5" y="6" width="19" height="12" rx="1.5" />
      <circle cx="12" cy="12" r="2.8" />
      <path d="M5.5 6v0M18.5 18v0" />
    </template>
    <template v-else-if="name === 'settings'">
      <circle cx="12" cy="12" r="3" />
      <path
        d="M19.4 13a1.7 1.7 0 0 0 .3 1.9l.1.1a2 2 0 1 1-2.9 2.9l-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1 1.5v.2a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.6 1.7 1.7 0 0 0-1.9.3l-.1.1a2 2 0 1 1-2.9-2.9l.1-.1a1.7 1.7 0 0 0 .3-1.9 1.7 1.7 0 0 0-1.5-1h-.2a2 2 0 1 1 0-4h.1A1.7 1.7 0 0 0 4.6 6.7a1.7 1.7 0 0 0-.3-1.9l-.1-.1a2 2 0 1 1 2.9-2.9l.1.1a1.7 1.7 0 0 0 1.9.3H9a1.7 1.7 0 0 0 1-1.5v-.2a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.9-.3l.1-.1a2 2 0 1 1 2.9 2.9l-.1.1a1.7 1.7 0 0 0-.3 1.9V9a1.7 1.7 0 0 0 1.5 1h.2a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1Z"
      />
    </template>
    <template v-else-if="name === 'image'">
      <rect x="3" y="4" width="18" height="16" rx="1.5" />
      <circle cx="8.5" cy="9" r="1.6" />
      <path d="m4 17 5-5 3.5 3.5L16 12l4 4.5" />
    </template>
    <template v-else-if="name === 'credit-card'">
      <rect x="2.5" y="5.5" width="19" height="13" rx="1.5" />
      <path d="M2.5 9.5h19" />
      <path d="M6 14.5h4" />
    </template>
    <template v-else-if="name === 'dashboard'">
      <path d="M4 13a8 8 0 0 1 16 0" />
      <path d="M4 20h16M12 13l3.5-4" />
      <circle cx="12" cy="13" r="1.2" fill="currentColor" />
    </template>
    <template v-else-if="name === 'refresh'">
      <path d="M20 11A8 8 0 0 0 6.3 6.3L4 8.5M4 13a8 8 0 0 0 13.7 4.7L20 15.5" />
      <path d="M4 4v4.5h4.5M20 20v-4.5h-4.5" />
    </template>
    <template v-else-if="name === 'download'">
      <path d="M12 3.5v11m0 0 4-4m-4 4-4-4" />
      <path d="M4.5 17v2.5a1 1 0 0 0 1 1h13a1 1 0 0 0 1-1V17" />
    </template>
    <template v-else-if="name === 'upload'">
      <path d="M12 14.5v-11m0 0 4 4m-4-4-4 4" />
      <path d="M4.5 17v2.5a1 1 0 0 0 1 1h13a1 1 0 0 0 1-1V17" />
    </template>
    <template v-else-if="name === 'edit'">
      <path d="M4 20h4l10.5-10.5a2 2 0 0 0 0-2.8l-1.2-1.2a2 2 0 0 0-2.8 0L4 16v4Z" />
      <path d="M13.5 6.5l3 3" />
    </template>
    <template v-else-if="name === 'globe'">
      <circle cx="12" cy="12" r="9" />
      <path d="M3 12h18M12 3c2.5 2.5 3.8 5.7 3.8 9s-1.3 6.5-3.8 9c-2.5-2.5-3.8-5.7-3.8-9S9.5 5.5 12 3Z" />
    </template>
    <template v-else-if="name === 'chart-bar'">
      <path d="M4 20V10M11 20V4M18 20v-7" />
      <path d="M3 20h18" />
    </template>
    <template v-else-if="name === 'shield'">
      <path d="M12 3.5 19 6.5v5c0 5-3 8.3-7 9.5-4-1.2-7-4.5-7-9.5v-5Z" />
      <path d="m9 12 2 2 4-4.5" />
    </template>
    <template v-else-if="name === 'chevron-up'">
      <path d="m6 15 6-6 6 6" />
    </template>
  </svg>
</template>
