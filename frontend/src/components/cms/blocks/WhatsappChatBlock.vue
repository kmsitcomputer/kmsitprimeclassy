<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue'
import BrandIcon from '../BrandIcon.vue'
import csPrime from '@/brands/cs-prime.png'

const props = defineProps<{ content: { heading?: string; description?: string; phone: string; message?: string; button_label: string; mode?: string } }>()

const PHONE_PATTERN = /^[1-9][0-9]{7,14}$/
const phone = computed(() => String(props.content.phone ?? '').replace(/\D/g, ''))
const url = computed(() =>
  PHONE_PATTERN.test(phone.value)
    ? `https://wa.me/${phone.value}?text=${encodeURIComponent(props.content.message ?? '')}`
    : null,
)
const floating = computed(() => props.content.mode === 'floating')
// Floating widget starts collapsed on every viewport so it never covers content.
const open = ref(false)

// Keyboard: opening swaps the mascot button for the panel, which would drop focus to the page body. Move focus to the
// panel's close button, and give it back to the mascot button when the panel closes (no effect on mouse/touch use).
const closeBtn = ref<HTMLButtonElement | null>(null)
const fabBtn = ref<HTMLButtonElement | null>(null)
watch(open, async (isOpen) => {
  await nextTick()
  ;(isOpen ? closeBtn.value : fabBtn.value)?.focus()
})

// The collapsed floating button shows the CS Prime mascot. The PNG already carries its own circle (burgundy fill, cream
// ring) on a transparent canvas: the circle is 69.5% of the canvas width / 74.5% of its height and sits 1.4% left and
// 2.8% above the canvas centre. Sized and nudged so that circle fills the existing 56px button exactly, undistorted;
// the button keeps its size, position and click target but no longer paints a second (green) circle behind it.
const MASCOT_STYLE = { width: '144%', transform: 'translate(calc(-50% + 1.4%), calc(-50% + 2.8%))' }
// The expanded panel's header avatar reuses the very same image and the very same sizing technique: a box as big as the
// VISIBLE circle (46px), the PNG at 144% of it, so the circle (not the transparent canvas) measures 46px. Nothing is drawn
// behind or around it.
const AVATAR_PX = 46
</script>

<template>
  <section
    v-if="url"
    :class="floating
      ? 'fixed right-4 z-40 flex flex-col items-end gap-3 bottom-[calc(5rem+env(safe-area-inset-bottom))] sm:bottom-5 sm:right-5'
      : ''"
  >
    <!-- card mode (inline on the page): unchanged -->
    <div v-if="!floating" id="wa-panel" class="rounded-2xl bg-[#25D366] p-5 text-white shadow-lg">
      <div class="flex items-start justify-between gap-2">
        <h2 v-if="content.heading" class="font-semibold">{{ content.heading }}</h2>
      </div>
      <p v-if="content.description" class="mt-1 text-sm">{{ content.description }}</p>
      <a
        :href="url"
        target="_blank"
        rel="noopener noreferrer"
        class="mt-3 inline-flex min-h-11 items-center gap-2 rounded-lg bg-white px-4 py-2 font-semibold text-[#128C7E] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white"
      >
        <BrandIcon name="whatsapp" :size="20" />{{ content.button_label }}
      </a>
    </div>

    <!-- floating mode, expanded: the PrimeClassy CS mini panel (burgundy header, warm cream / dark plum body) -->
    <div
      v-else-if="open"
      id="wa-panel"
      role="group"
      aria-label="CS PrimeClassy"
      class="w-[min(18rem,calc(100vw-2rem))] overflow-hidden rounded-[18px] border border-[#C5A48B] bg-[#FFFCF8] shadow-[0_2px_4px_rgb(92_61_46/0.12),0_14px_32px_-10px_rgb(92_61_46/0.45)] dark:border-[#5C424B] dark:bg-[#241D20] dark:shadow-[0_2px_4px_rgb(0_0_0/0.5),0_14px_32px_-10px_rgb(0_0_0/0.8)]"
    >
      <div class="flex items-center gap-3 bg-[#672035] px-4 py-3" data-testid="cs-panel-header">
        <!-- the same cs-prime.png as the floating button; its own circle is the avatar, nothing is added around it -->
        <div class="relative shrink-0" :style="{ width: AVATAR_PX + 'px', height: AVATAR_PX + 'px' }" data-testid="cs-panel-avatar">
          <img
            :src="csPrime"
            alt=""
            aria-hidden="true"
            width="1292"
            height="1218"
            decoding="async"
            draggable="false"
            class="pointer-events-none absolute left-1/2 top-1/2 h-auto max-w-none select-none"
            :style="MASCOT_STYLE"
          />
        </div>
        <div class="min-w-0 flex-1">
          <p class="truncate text-[15px] font-semibold leading-tight text-white">CS PrimeClassy</p>
          <p class="mt-0.5 truncate text-[11.5px] leading-tight text-[#F1D9DE]">Siap membantu Anda</p>
        </div>
        <!-- 44x44 touch target, pulled back with negative margins so it does not make the header taller or squeeze the title -->
        <button
          ref="closeBtn"
          type="button"
          class="-mr-2.5 flex h-11 w-11 shrink-0 items-center justify-center rounded-full text-[#F1D9DE] transition hover:bg-white/10 hover:text-white focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-white"
          aria-label="Tutup panel WhatsApp"
          @click="open = false"
        >
          <svg viewBox="0 0 20 20" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="m5 5 10 10M15 5 5 15" /></svg>
        </button>
      </div>

      <div class="p-4">
        <p class="text-[13px] leading-relaxed text-[#392729] dark:text-[#E8DDE0]">Ada pertanyaan tentang produk atau pesanan? Kami siap membantu.</p>
        <a
          :href="url"
          target="_blank"
          rel="noopener noreferrer"
          class="mt-3.5 flex min-h-11 items-center justify-center gap-2 rounded-[11px] bg-[#F8F1EA] px-4 text-[13.5px] font-semibold leading-none text-[#672035] ring-1 ring-inset ring-[#C5A48B]/50 transition hover:bg-white focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#C5A48B] dark:ring-0 dark:focus-visible:outline-[#F8F1EA]"
        >
          <BrandIcon name="whatsapp" :size="20" class="shrink-0" />
          <span>{{ content.button_label || 'Chat via WhatsApp' }}</span>
        </a>
      </div>
    </div>

    <button
      v-if="floating && !open"
      ref="fabBtn"
      type="button"
      class="relative flex h-14 w-14 items-center justify-center rounded-full shadow-lg focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#128C7E]"
      aria-label="Buka chat WhatsApp"
      aria-controls="wa-panel"
      :aria-expanded="open"
      @click="open = true"
    >
      <img
        :src="csPrime"
        alt=""
        aria-hidden="true"
        width="1292"
        height="1218"
        decoding="async"
        draggable="false"
        class="pointer-events-none absolute left-1/2 top-1/2 h-auto max-w-none select-none"
        :style="MASCOT_STYLE"
      />
    </button>
  </section>
</template>
