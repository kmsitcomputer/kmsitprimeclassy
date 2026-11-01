import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import HeroBlock from '../blocks/HeroBlock.vue'
import CtaBlock from '../blocks/CtaBlock.vue'
import BannerSliderBlock from '../blocks/BannerSliderBlock.vue'
import HomepageBlockRenderer from '../HomepageBlockRenderer.vue'
import { safeHref } from '@/utils/safeUrl'

describe('safeHref', () => {
  it('accepts links that cannot run script', () => {
    for (const ok of ['/produk', '#kontak', 'https://primeclassy.id/a?b=1&c=2', 'http://example.com', 'mailto:a@b.id', 'tel:+628123456789']) {
      expect(safeHref(ok)).toBe(ok)
    }
    expect(safeHref('  /produk  ')).toBe('/produk')
  })

  it('rejects script-capable, malformed and empty links', () => {
    for (const bad of ['javascript:alert(1)', ' JaVaScRiPt:alert(1)', 'data:text/html,x', 'vbscript:x', '//evil.example', 'https://a.b/x y', 'java\nscript:alert(1)', '', '   ']) {
      expect(safeHref(bad)).toBeUndefined()
    }
    expect(safeHref(undefined)).toBeUndefined()
    expect(safeHref(null)).toBeUndefined()
  })
})

/**
 * Mounts a Hero as if the block were `width` px wide and its picture had the given natural size, then fires the
 * image `load` event: this is what decides whether the text fits ON the picture (overlay) or goes BELOW it.
 */
async function heroWith(content: Record<string, unknown>, { width, natural }: { width: number; natural: [number, number] }) {
  const spy = vi.spyOn(HTMLElement.prototype, 'getBoundingClientRect').mockImplementation(() => ({ width, height: 0 }) as DOMRect)
  const w = mount(HeroBlock, { props: { content } })
  const img = w.find('img')
  Object.defineProperty(img.element, 'naturalWidth', { value: natural[0], configurable: true })
  Object.defineProperty(img.element, 'naturalHeight', { value: natural[1], configurable: true })
  await img.trigger('load')
  await w.vm.$nextTick()
  spy.mockRestore()
  return w
}

describe('HeroBlock', () => {
  const legacy = { image_url: '/storage/hero.jpg', heading: 'Kue Segar', subheading: 'Setiap hari', cta_label: 'Pesan', cta_url: '/produk' }

  it('renders a block saved before the presentation options existed: the photo, a dark overlay once the text fits on it, the button', async () => {
    const w = await heroWith(legacy, { width: 1000, natural: [2000, 1000] }) // 500px tall at this width >= 288
    expect(w.find('h1').text()).toBe('Kue Segar')
    expect(w.text()).toContain('Setiap hari')
    const img = w.find('img')
    expect(img.attributes('src')).toBe('/storage/hero.jpg')
    expect(img.classes()).toContain('object-cover')
    expect(img.attributes('alt')).toBe('')
    expect(w.find('a').attributes('href')).toBe('/produk')
    expect(w.html()).toContain('from-black/75')
  })

  it('contain keeps the whole picture (no crop, no overlay) and still shows the text', () => {
    const w = mount(HeroBlock, { props: { content: { ...legacy, image_fit: 'contain', image_position: 'top' } } })
    const img = w.find('img')
    expect(img.classes()).toContain('object-contain')
    expect(img.classes()).not.toContain('object-cover')
    expect(img.classes()).toContain('object-top')
    expect(w.html()).not.toContain('from-black/75')
    expect(w.find('h1').text()).toBe('Kue Segar')
  })

  it('light overlay switches to dark text; none drops the overlay (when the text fits on the picture)', async () => {
    const light = await heroWith({ ...legacy, overlay: 'light' }, { width: 1000, natural: [2000, 1000] })
    expect(light.find('section > div:last-child').classes()).toContain('@xl:text-stone-900')
    const none = await heroWith({ ...legacy, overlay: 'none' }, { width: 1000, natural: [2000, 1000] })
    expect(none.html()).not.toContain('from-black/75')
    expect(none.html()).not.toContain('from-white/90')
  })

  it('center alignment is applied', () => {
    const w = mount(HeroBlock, { props: { content: { ...legacy, align: 'center' } } })
    expect(w.find('section > div:last-child').classes()).toContain('text-center')
  })

  it('the photo keeps its own aspect ratio: no fixed box and no minimum height that would crop it', async () => {
    const w = await heroWith(legacy, { width: 1000, natural: [2000, 1000] })
    const cls = w.find('img').classes()
    expect(cls).toContain('h-auto') // height follows the picture's own ratio
    expect(cls).toContain('w-full')
    expect(cls).toContain('max-h-[28rem]') // only a very tall picture is clamped
    expect(cls).toContain('@xl:max-h-[34rem]')
    expect(cls.join(' ')).not.toMatch(/min-h-/) // nothing forces extra height (that was what cropped 3:1 banners)
    expect(cls).toContain('object-cover') // only decides the small crop of a clamped picture
    expect(w.html()).not.toContain('aspect-[16/10]')
    expect(w.find('section').classes().join(' ')).not.toMatch(/min-h-\[(22|26|30)rem\]/) // the old fixed hero box is gone
    expect(w.element.className).toContain('@container')
  })

  it('the text goes ON the picture only when the picture is tall enough at this width; otherwise the picture stays whole and the text sits below', async () => {
    // 3:1 banner in a 1000px block: 333px tall >= 288 -> overlay
    const roomy = await heroWith(legacy, { width: 1000, natural: [1800, 600] })
    expect(roomy.find('section > div:last-child').classes()).toContain('@xl:absolute')
    expect(roomy.find('.bg-gradient-to-t').classes()).toEqual(expect.arrayContaining(['hidden', '@xl:block']))

    // the same banner in a 700px block (a tablet): 233px tall < 288 -> NO overlay, text stays in flow under the whole picture
    const short = await heroWith(legacy, { width: 700, natural: [1800, 600] })
    expect(short.find('section > div:last-child').classes()).not.toContain('@xl:absolute')
    expect(short.find('.bg-gradient-to-t').exists()).toBe(false)
    expect(short.find('img').classes().join(' ')).not.toMatch(/min-h-/)

    // before the picture size is known nothing is overlaid either (no flash of text on the subject)
    const unknown = mount(HeroBlock, { props: { content: legacy } })
    expect(unknown.find('section > div:last-child').classes()).not.toContain('@xl:absolute')
    expect(unknown.find('.bg-gradient-to-t').exists()).toBe(false)
  })

  it('the light overlay never puts dark text on the brand panel (stacked layout); dark text exists only on the overlay', async () => {
    const overlayMode = await heroWith({ ...legacy, overlay: 'light' }, { width: 1000, natural: [2000, 1000] })
    const text = overlayMode.find('section > div:last-child')
    expect(text.classes()).not.toContain('text-stone-900')
    expect(text.classes()).toContain('@xl:text-stone-900')
    expect(overlayMode.find('a').classes()).toContain('bg-white')
    expect(overlayMode.find('a').classes()).toContain('@xl:bg-brand-600')

    const stacked = await heroWith({ ...legacy, overlay: 'light' }, { width: 600, natural: [1800, 600] })
    expect(stacked.find('section > div:last-child').classes().join(' ')).not.toContain('text-stone-900')
    expect(stacked.find('a').classes()).not.toContain('@xl:bg-brand-600')
  })

  it('works without any image', () => {
    const w = mount(HeroBlock, { props: { content: { heading: 'Halo' } } })
    expect(w.find('img').exists()).toBe(false)
    expect(w.find('a').exists()).toBe(false)
  })

  it('never renders an unsafe stored link; a label without a url keeps the old "#" button', () => {
    expect(mount(HeroBlock, { props: { content: { ...legacy, cta_url: 'javascript:alert(1)' } } }).find('a').exists()).toBe(false)
    expect(mount(HeroBlock, { props: { content: { heading: 'x', cta_label: 'Pesan' } } }).find('a').attributes('href')).toBe('#')
  })
})

describe('CtaBlock', () => {
  const legacy = { heading: 'Pesan sekarang', button_label: 'Mulai', button_url: '/produk' }

  it('renders a block saved before themes/description existed', () => {
    const w = mount(CtaBlock, { props: { content: legacy } })
    expect(w.find('h2').text()).toBe('Pesan sekarang')
    expect(w.find('a').attributes('href')).toBe('/produk')
    expect(w.find('a').text()).toBe('Mulai')
    expect(w.find('section').classes()).toContain('bg-stone-900')
    expect(w.find('p').exists()).toBe(false)
  })

  it('shows an optional description and the chosen theme / alignment', () => {
    const w = mount(CtaBlock, { props: { content: { ...legacy, description: 'Gratis ongkir', theme: 'brand', align: 'center' } } })
    expect(w.find('p').text()).toBe('Gratis ongkir')
    expect(w.find('section').classes().join(' ')).toContain('to-brand-800')
    expect(w.find('section > div:last-child').classes()).toContain('text-center')
  })

  it('contains no hard-coded product copy', () => {
    expect(mount(CtaBlock, { props: { content: legacy } }).text()).not.toMatch(/aplikasi prime/i)
  })

  it('refuses an unsafe link and opens external ones safely', () => {
    expect(mount(CtaBlock, { props: { content: { ...legacy, button_url: 'javascript:alert(1)' } } }).find('a').exists()).toBe(false)
    const ext = mount(CtaBlock, { props: { content: { ...legacy, button_url: 'https://wa.me/628123' } } }).find('a')
    expect(ext.attributes('target')).toBe('_blank')
    expect(ext.attributes('rel')).toBe('noopener noreferrer')
  })
})

describe('BannerSliderBlock', () => {
  const slides = [
    { key: 'a1', image_url: '/a.jpg', mobile_image_url: '/a-m.jpg', title: 'Satu', description: 'Deskripsi', button_label: 'Lihat', button_url: '/produk', overlay: 'dark', content_position: 'left' },
    { key: 'b2', image_url: '/b.jpg', title: 'Dua', content_position: 'center', overlay: 'light' },
    { key: 'c3', image_url: '/c.jpg' },
  ]
  const base = { layout: 'contained', autoplay: false, interval_seconds: 5, show_arrows: true, show_dots: true, slides }

  const track = (w: ReturnType<typeof mount>) => w.find('[role="region"] > div > div.flex')
  const currentIndex = (w: ReturnType<typeof mount>) => {
    const m = /translateX\(-(\d+)%\)/.exec(track(w).attributes('style') ?? '')
    return m ? Number(m[1]) / 100 : -1
  }

  beforeEach(() => {
    vi.useFakeTimers()
    vi.stubGlobal('matchMedia', undefined)
  })
  afterEach(() => {
    vi.useRealTimers()
    vi.unstubAllGlobals()
  })

  it('renders an accessible carousel with one labelled group per slide and the others inert', () => {
    const w = mount(BannerSliderBlock, { props: { content: base } })
    const region = w.find('[role="region"]')
    expect(region.attributes('aria-roledescription')).toBe('carousel')
    const groups = w.findAll('[aria-roledescription="slide"]')
    expect(groups).toHaveLength(3)
    expect(groups[0]!.attributes('aria-label')).toBe('1 / 3')
    expect(groups[0]!.attributes('aria-hidden')).toBe('false')
    expect(groups[1]!.attributes('aria-hidden')).toBe('true')
    expect(groups[1]!.attributes('inert')).toBeDefined()
    expect(groups[0]!.attributes('inert')).toBeUndefined()
  })

  it('serves the mobile image through <picture> and lazy-loads everything but the first slide', () => {
    const w = mount(BannerSliderBlock, { props: { content: base } })
    const first = w.findAll('picture')[0]!
    expect(first.find('source').attributes('srcset')).toBe('/a-m.jpg')
    expect(first.find('source').attributes('media')).toBe('(max-width: 607px)')
    expect(w.findAll('picture')[1]!.find('source').exists()).toBe(false)
    const imgs = w.findAll('img')
    expect(imgs[0]!.attributes('loading')).toBe('eager')
    expect(imgs[1]!.attributes('loading')).toBe('lazy')
  })

  it('arrows and dots navigate and wrap around', async () => {
    const w = mount(BannerSliderBlock, { props: { content: base } })
    await w.find('button[aria-label="Slide berikutnya"]').trigger('click')
    expect(currentIndex(w)).toBe(1)
    await w.find('button[aria-label="Slide sebelumnya"]').trigger('click')
    await w.find('button[aria-label="Slide sebelumnya"]').trigger('click')
    expect(currentIndex(w)).toBe(2) // wrapped to the last slide
    await w.findAll('button[aria-label^="Slide "][aria-label$="dari 3"]')[1]!.trigger('click')
    expect(currentIndex(w)).toBe(1)
    expect(w.findAll('button[aria-current="true"]')).toHaveLength(1)
  })

  it('supports arrow keys, Home and End', async () => {
    const w = mount(BannerSliderBlock, { props: { content: base } })
    const region = w.find('[role="region"]')
    await region.trigger('keydown', { key: 'ArrowRight' })
    expect(currentIndex(w)).toBe(1)
    await region.trigger('keydown', { key: 'End' })
    expect(currentIndex(w)).toBe(2)
    await region.trigger('keydown', { key: 'ArrowLeft' })
    expect(currentIndex(w)).toBe(1)
    await region.trigger('keydown', { key: 'Home' })
    expect(currentIndex(w)).toBe(0)
  })

  it('arrows and dots can be switched off; flags may arrive as "0"/"1" strings', () => {
    const off = mount(BannerSliderBlock, { props: { content: { ...base, show_arrows: false, show_dots: false } } })
    expect(off.find('button').exists()).toBe(false)
    const strings = mount(BannerSliderBlock, { props: { content: { ...base, show_arrows: '0', show_dots: '1', autoplay: '0' } } })
    expect(strings.find('button[aria-label="Slide berikutnya"]').exists()).toBe(false)
    expect(strings.findAll('button[aria-label$="dari 3"]')).toHaveLength(3)
    expect(strings.find('button[aria-label*="slide otomatis"]').exists()).toBe(false)
  })

  it('autoplays at the configured interval and loops', async () => {
    const w = mount(BannerSliderBlock, { props: { content: { ...base, autoplay: true, interval_seconds: 4 } } })
    vi.advanceTimersByTime(3900)
    await w.vm.$nextTick()
    expect(currentIndex(w)).toBe(0)
    vi.advanceTimersByTime(200)
    await w.vm.$nextTick()
    expect(currentIndex(w)).toBe(1)
    for (let step = 0; step < 2; step++) {
      vi.advanceTimersByTime(4100) // the next timer is armed after each slide change renders
      await w.vm.$nextTick()
    }
    expect(currentIndex(w)).toBe(0) // 1 -> 2 -> 0
  })

  it('clamps the interval to 3–10 seconds', async () => {
    const fast = mount(BannerSliderBlock, { props: { content: { ...base, autoplay: true, interval_seconds: 1 } } })
    vi.advanceTimersByTime(3100)
    await fast.vm.$nextTick()
    expect(currentIndex(fast)).toBe(1)
    const slow = mount(BannerSliderBlock, { props: { content: { ...base, autoplay: true, interval_seconds: 60 } } })
    vi.advanceTimersByTime(9900)
    await slow.vm.$nextTick()
    expect(currentIndex(slow)).toBe(0)
    vi.advanceTimersByTime(200)
    await slow.vm.$nextTick()
    expect(currentIndex(slow)).toBe(1)
  })

  it('pauses while hovered or focused and resumes afterwards', async () => {
    const w = mount(BannerSliderBlock, { props: { content: { ...base, autoplay: true, interval_seconds: 3 } } })
    const region = w.find('[role="region"]')
    await region.trigger('mouseenter')
    vi.advanceTimersByTime(10000)
    await w.vm.$nextTick()
    expect(currentIndex(w)).toBe(0)
    await region.trigger('mouseleave')
    vi.advanceTimersByTime(3100)
    await w.vm.$nextTick()
    expect(currentIndex(w)).toBe(1)

    await region.trigger('focusin')
    vi.advanceTimersByTime(10000)
    await w.vm.$nextTick()
    expect(currentIndex(w)).toBe(1)
  })

  it('has an explicit pause / play control while autoplay is on', async () => {
    const w = mount(BannerSliderBlock, { props: { content: { ...base, autoplay: true, interval_seconds: 3 } } })
    await w.find('button[aria-label="Jeda slide otomatis"]').trigger('click')
    vi.advanceTimersByTime(10000)
    await w.vm.$nextTick()
    expect(currentIndex(w)).toBe(0)
    const play = w.find('button[aria-label="Putar slide otomatis"]')
    expect(play.attributes('aria-pressed')).toBe('true')
    await play.trigger('click')
    vi.advanceTimersByTime(3100)
    await w.vm.$nextTick()
    expect(currentIndex(w)).toBe(1)
  })

  it('prefers-reduced-motion disables autoplay and the slide transition', async () => {
    vi.stubGlobal('matchMedia', (q: string) => ({ matches: q.includes('reduce'), addEventListener: () => {}, removeEventListener: () => {} }))
    const w = mount(BannerSliderBlock, { props: { content: { ...base, autoplay: true, interval_seconds: 3 } } })
    await w.vm.$nextTick()
    vi.advanceTimersByTime(10000)
    await w.vm.$nextTick()
    expect(currentIndex(w)).toBe(0)
    expect(track(w).classes().join(' ')).not.toContain('transition')
  })

  it('stops the timer on unmount', () => {
    const w = mount(BannerSliderBlock, { props: { content: { ...base, autoplay: true, interval_seconds: 3 } } })
    w.unmount()
    expect(vi.getTimerCount()).toBe(0)
  })

  it('only renders safe slide buttons and escapes text', () => {
    const w = mount(BannerSliderBlock, {
      props: { content: { ...base, slides: [
        { key: 'x1', image_url: '/a.jpg', title: '<img src=x onerror=alert(1)>', button_label: 'Klik', button_url: 'javascript:alert(1)' },
        { key: 'x2', image_url: '/b.jpg', button_label: 'Buka', button_url: 'https://primeclassy.id/p' },
      ] } },
    })
    expect(w.find('h2').text()).toBe('<img src=x onerror=alert(1)>')
    expect(w.find('h2 img').exists()).toBe(false)
    const links = w.findAll('a')
    expect(links).toHaveLength(1)
    expect(links[0]!.attributes('href')).toBe('https://primeclassy.id/p')
    expect(links[0]!.attributes('rel')).toBe('noopener noreferrer')
  })

  it('layout: contained is the default and narrower than wide', () => {
    const contained = mount(BannerSliderBlock, { props: { content: { ...base, layout: undefined } } })
    expect(contained.find('section').classes()).toContain('max-w-5xl')
    const wide = mount(BannerSliderBlock, { props: { content: { ...base, layout: 'wide' } } })
    expect(wide.find('section').classes()).not.toContain('max-w-5xl')
    expect(wide.find('section').classes()).toContain('w-full')
  })

  it('a single slide shows no navigation and slides without an image are skipped', () => {
    const w = mount(BannerSliderBlock, { props: { content: { ...base, autoplay: true, slides: [{ key: 'only', image_url: '/a.jpg' }, { key: 'none', title: 'no image' }] } } })
    expect(w.findAll('[aria-roledescription="slide"]')).toHaveLength(1)
    expect(w.find('button').exists()).toBe(false)
    expect(mount(BannerSliderBlock, { props: { content: { ...base, slides: [] } } }).find('section').exists()).toBe(false)
  })

  it('a desktop-only banner is an ordinary in-flow image: its own ratio, no fixed box, no filler, no crop', () => {
    const w = mount(BannerSliderBlock, { props: { content: { ...base, slides: [{ key: 'only-desktop', image_url: '/wide.jpg', title: 'T' }] } } })
    const imgs = w.findAll('img')
    expect(imgs).toHaveLength(1) // no blurred duplicate
    const cls = imgs[0]!.classes()
    expect(cls).toContain('h-auto')
    expect(cls).toContain('w-full')
    expect(cls).not.toContain('object-contain')
    expect(cls).not.toContain('object-fill')
    expect(cls).not.toContain('h-full') // fills a box only in the wide layout
    expect(cls).toContain('@xl:object-cover')
    expect(cls).toContain('@xl:max-h-[36rem]')
    const html = w.html()
    expect(html).not.toContain('blur-')
    expect(html).not.toContain('aspect-[4/3]')
    expect(html).not.toContain('aspect-[3/4]')
    expect(html).not.toMatch(/(^|[\s"'])min-h-/) // nothing forced on narrow (a wide-only clamp is prefixed @xl:)
    expect(html).not.toContain('@xl:aspect-') // and no forced ratio on wide either
  })

  it('a mobile image is used at its own ratio too (no forced box, no cover crop)', () => {
    const w = mount(BannerSliderBlock, { props: { content: { ...base, slides: [{ key: 'has-mobile', image_url: '/d.jpg', mobile_image_url: '/m.jpg' }] } } })
    expect(w.findAll('img')).toHaveLength(1)
    expect(w.find('img').classes()).toContain('h-auto')
    expect(w.find('img').classes()).not.toContain('object-cover')
    expect(w.find('source').attributes('srcset')).toBe('/m.jpg')
  })

  it('reserves a 21:9 placeholder only until the picture has loaded', async () => {
    const w = mount(BannerSliderBlock, { props: { content: { ...base, slides: [{ key: 'ph-aaaa', image_url: '/a.jpg' }] } } })
    const box = () => w.find('picture').element.parentElement!
    expect(box().className).toContain('aspect-[21/9]')
    await w.find('img').trigger('load')
    expect(box().className).not.toContain('aspect-[21/9]')
    // a broken image must not keep the placeholder forever either
    const broken = mount(BannerSliderBlock, { props: { content: { ...base, slides: [{ key: 'ph-bbbb', image_url: '/missing.jpg' }] } } })
    await broken.find('img').trigger('error')
    expect(broken.find('picture').element.parentElement!.className).not.toContain('aspect-[21/9]')
  })

  it('the carousel takes the ACTIVE slide height and the controls sit on the ACTIVE picture', async () => {
    const callbacks: ((e: unknown[]) => void)[] = []
    const watched: Element[] = []
    vi.stubGlobal('ResizeObserver', class {
      constructor(cb: (e: unknown[]) => void) { callbacks.push(cb) }
      observe(el: Element) { watched.push(el) }
      disconnect() {}
      unobserve() {}
    })
    const w = mount(BannerSliderBlock, {
      attachTo: document.body,
      props: { content: { ...base, autoplay: true, slides: [
        { key: 'h-aaaa', image_url: '/a.jpg', title: 'A' },
        { key: 'h-bbbb', image_url: '/b.jpg', title: 'B' },
      ] } },
    })
    const wrapper = () => w.find('[role="region"] > div').element as HTMLElement
    const controls = () => w.find('button[aria-label="Jeda slide otomatis"]').element.parentElement as HTMLElement
    expect(wrapper().style.height).toBe('') // nothing measured yet: natural height, no forced value

    // slide 0 is 300px tall with a 120px picture; slide 1 is 180px with a 70px picture
    const slideEls = w.findAll('[aria-roledescription="slide"]').map((n) => n.element)
    const sizes = new Map<Element, number>()
    slideEls.forEach((el, i) => { sizes.set(el, [300, 180][i]!); sizes.set(el.querySelector('picture')!.parentElement!, [120, 70][i]!) })
    sizes.forEach((h, el) => { el.getBoundingClientRect = () => ({ height: h }) as DOMRect })
    callbacks.forEach((cb) => cb([...sizes.keys()].map((target) => ({ target }))))
    await w.vm.$nextTick()

    expect(wrapper().style.height).toBe('300px')
    expect(controls().getAttribute('style')).toContain('--picture-h: 120px')

    await w.find('button[aria-label="Slide berikutnya"]').trigger('click')
    expect(wrapper().style.height).toBe('180px')
    expect(controls().getAttribute('style')).toContain('--picture-h: 70px')
    w.unmount()
    expect(watched.length).toBeGreaterThan(0)
  })

  it('measures before the first paint and re-measures after a picture loads, without relying on the observer', async () => {
    // jsdom has no ResizeObserver: everything below must work from the component's own measuring.
    vi.stubGlobal('ResizeObserver', undefined)
    let h = 147 // 21:9 placeholder height
    const spy = vi.spyOn(HTMLElement.prototype, 'getBoundingClientRect').mockImplementation(() => ({ height: h }) as DOMRect)
    try {
      const w = mount(BannerSliderBlock, { props: { content: { ...base, slides: [{ key: 'ms-aaaa', image_url: '/a.jpg' }] } } })
      const wrapper = () => w.find('[role="region"] > div').element as HTMLElement
      await w.vm.$nextTick() // a microtask: still before the browser's first paint
      expect(wrapper().style.height).toBe('147px') // set from mount, no observer involved

      h = 400 // the picture arrives and is much taller than the placeholder
      await w.find('img').trigger('load')
      await w.vm.$nextTick()
      await w.vm.$nextTick()
      expect(wrapper().style.height).toBe('400px')
    } finally {
      spy.mockRestore()
    }
  })

  it('no height transition under reduced motion', async () => {
    vi.stubGlobal('matchMedia', (q: string) => ({ matches: q.includes('reduce'), addEventListener: () => {}, removeEventListener: () => {} }))
    const callbacks: ((e: unknown[]) => void)[] = []
    vi.stubGlobal('ResizeObserver', class {
      constructor(cb: (e: unknown[]) => void) { callbacks.push(cb) }
      observe() {}
      disconnect() {}
    })
    const w = mount(BannerSliderBlock, { props: { content: { ...base, slides: [{ key: 'rm-aaaa', image_url: '/a.jpg' }] } } })
    await w.vm.$nextTick()
    const slide = w.find('[aria-roledescription="slide"]').element
    slide.getBoundingClientRect = () => ({ height: 200 }) as DOMRect
    callbacks.forEach((cb) => cb([{ target: slide }]))
    await w.vm.$nextTick()
    expect(w.find('[role="region"] > div').classes().join(' ')).not.toContain('transition')
  })

  it('text is a panel under the picture on narrow widths and an overlay only on wide ones; controls never cover the panel', () => {
    const w = mount(BannerSliderBlock, { props: { content: { ...base, autoplay: true, slides: [{ key: 'txt-aaaa', image_url: '/d.jpg', title: 'Judul', description: 'Isi', overlay: 'dark' }, { key: 'txt-bbbb', image_url: '/e.jpg' }] } } })
    const panel = w.find('h2').element.closest('div.px-5')!
    expect(panel.className).toContain('@xl:absolute')
    expect(panel.className).toContain('@xl:px-16') // clear of the arrow buttons
    const gradient = w.find('.bg-gradient-to-r')
    expect(gradient.classes()).toContain('hidden')
    expect(gradient.classes()).toContain('@xl:block')
    const controls = w.find('button[aria-label="Jeda slide otomatis"]').element.parentElement!
    expect(controls.className).toContain('h-[var(--picture-h,100%)]')
    expect(controls.className).toContain('pointer-events-none')
  })

  it('wide: a text slide gets a minimum height so the overlay text fits; an image-only slide does not', () => {
    const w = mount(BannerSliderBlock, { props: { content: { ...base, slides: [{ key: 'wt-aaaa', image_url: '/a.jpg', title: 'Judul' }, { key: 'wt-bbbb', image_url: '/b.jpg' }] } } })
    const [withText, imageOnly] = w.findAll('picture img')
    expect(withText!.classes()).toContain('@xl:min-h-[15rem]')
    expect(imageOnly!.classes()).not.toContain('@xl:min-h-[15rem]')
    for (const img of [withText!, imageOnly!]) {
      expect(img.classes()).toContain('h-auto')
      expect(img.classes()).toContain('@xl:max-h-[36rem]')
    }
  })

  it('arrows are hidden on narrow widths (swipe + dots remain) and shown on wide ones', () => {
    const w = mount(BannerSliderBlock, { props: { content: base } })
    const arrow = w.find('button[aria-label="Slide berikutnya"]')
    expect(arrow.classes()).toContain('hidden')
    expect(arrow.classes()).toContain('@xl:flex')
  })

  it('swipe navigates, but a mostly-vertical gesture does not hijack page scroll', async () => {
    const w = mount(BannerSliderBlock, { props: { content: base } })
    const region = w.find('[role="region"]')
    const touch = (x: number, y: number) => ({ touches: [{ clientX: x, clientY: y }], changedTouches: [{ clientX: x, clientY: y }] })
    await region.trigger('touchstart', touch(300, 100))
    await region.trigger('touchend', touch(200, 110))
    expect(currentIndex(w)).toBe(1)
    await region.trigger('touchstart', touch(200, 100))
    await region.trigger('touchend', touch(150, 300)) // 50px sideways but 200px down
    expect(currentIndex(w)).toBe(1)
    await region.trigger('touchstart', touch(100, 100))
    await region.trigger('touchend', touch(220, 100))
    expect(currentIndex(w)).toBe(0)
  })

  it('previewDevice mobile uses the mobile image directly (no <picture> source); desktop never does', () => {
    const slide = { key: 'pv-aaaa', image_url: '/d.jpg', mobile_image_url: '/m.jpg' }
    const mobile = mount(BannerSliderBlock, { props: { content: { ...base, slides: [slide] }, previewDevice: 'mobile' } })
    expect(mobile.find('source').exists()).toBe(false)
    expect(mobile.find('img').attributes('src')).toBe('/m.jpg')
    const desktop = mount(BannerSliderBlock, { props: { content: { ...base, slides: [slide] }, previewDevice: 'desktop' } })
    expect(desktop.find('img').attributes('src')).toBe('/d.jpg')
    // mobile preview of a slide without a mobile image falls back to the desktop image
    const fallback = mount(BannerSliderBlock, { props: { content: { ...base, slides: [{ key: 'pv-bbbb', image_url: '/d.jpg' }] }, previewDevice: 'mobile' } })
    expect(fallback.find('img:not([aria-hidden])').attributes('src')).toBe('/d.jpg')
    // and without the prop the real <picture> source is in charge
    expect(mount(BannerSliderBlock, { props: { content: { ...base, slides: [slide] } } }).find('source').exists()).toBe(true)
  })

  it('the renderer forwards previewDevice to the slider only', () => {
    const slide = { key: 'rd-aaaa', image_url: '/d.jpg', mobile_image_url: '/m.jpg' }
    const s = mount(HomepageBlockRenderer, { props: { block: { id: 1, type: 'banner_slider', content: { ...base, slides: [slide] } as never }, previewDevice: 'mobile' } })
    expect(s.find('img').attributes('src')).toBe('/m.jpg')
    const h = mount(HomepageBlockRenderer, { props: { block: { id: 2, type: 'hero', content: { heading: 'x' } }, previewDevice: 'mobile' } })
    expect(h.html()).not.toContain('previewdevice')
  })

  it('is registered in the homepage renderer', () => {
    const w = mount(HomepageBlockRenderer, { props: { block: { id: 1, type: 'banner_slider', content: base as never } } })
    expect(w.find('[role="region"][aria-roledescription="carousel"]').exists()).toBe(true)
  })
})
