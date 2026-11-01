import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import WhatsappChatBlock from '../blocks/WhatsappChatBlock.vue'
import BrandIcon from '../BrandIcon.vue'

const base = { phone: '628123456789', message: 'Halo dunia', button_label: 'Chat via WhatsApp', heading: 'Judul CMS Lama', description: 'Deskripsi CMS lama' }

async function openPanel() {
  const w = mount(WhatsappChatBlock, { props: { content: { ...base, mode: 'floating' } } })
  await w.find('button[aria-label="Buka chat WhatsApp"]').trigger('click')
  return w
}

describe('CS PrimeClassy mini panel (floating, expanded)', () => {
  it('has the approved header: the cs-prime mascot, CS PrimeClassy, Siap membantu Anda, and a close button', async () => {
    const w = await openPanel()
    const header = w.find('[data-testid="cs-panel-header"]')
    expect(header.classes()).toContain('bg-[#672035]')
    expect(header.find('p:nth-of-type(1)').text()).toBe('CS PrimeClassy')
    expect(header.find('p:nth-of-type(2)').text()).toBe('Siap membantu Anda')
    const close = header.find('button[aria-label="Tutup panel WhatsApp"]')
    expect(close.classes()).toEqual(expect.arrayContaining(['h-11', 'w-11'])) // 44px touch target
  })

  it('the header avatar is the SAME asset and sizing technique as the floating button, with nothing drawn around it', async () => {
    const w = await openPanel()
    const fabSrc = (await (async () => {
      const closed = mount(WhatsappChatBlock, { props: { content: { ...base, mode: 'floating' } } })
      return closed.find('button[aria-label="Buka chat WhatsApp"] img').attributes('src')
    })())
    const avatar = w.find('[data-testid="cs-panel-avatar"]')
    const img = avatar.find('img')
    expect(img.attributes('src')).toBe(fabSrc)
    expect(img.attributes('src')).toMatch(/cs-prime.*\.png$/)
    expect(img.attributes('alt')).toBe('')
    expect(img.attributes('width')).toBe('1292') // intrinsic ratio kept
    expect(img.attributes('height')).toBe('1218')
    expect(img.attributes('style')).toContain('width: 144%') // the PNG's circle (69.5% of the canvas) becomes the box
    // the box is the visible circle: 46px (44-48), and no disc / ring / border is added behind or around it
    expect((avatar.element as HTMLElement).style.width).toBe('46px')
    expect((avatar.element as HTMLElement).style.height).toBe('46px')
    expect(avatar.classes().join(' ')).not.toMatch(/bg-|border|ring|rounded|shadow|overflow-hidden/)
    expect(w.find('[data-testid="cs-panel-header"] svg').exists()).toBe(true) // only the close glyph; no generic headset icon
  })

  it('has the approved body copy and nothing invented', async () => {
    const w = await openPanel()
    const text = w.text()
    expect(text).toContain('Ada pertanyaan tentang produk atau pesanan? Kami siap membantu.')
    expect(text).not.toMatch(/online|jam |buka|tutup pukul|agen/i) // no hours, availability or agent claims
  })

  it('the action keeps the genuine WhatsApp logo (not the mascot), green, 20px, 8px before the label', async () => {
    const w = await openPanel()
    const link = w.find('#wa-panel a')
    const icon = link.findComponent(BrandIcon)
    expect(icon.props('name')).toBe('whatsapp')
    expect(icon.props('size')).toBe(20)
    expect(icon.props('color')).toBeUndefined() // the registered brand colour (WhatsApp green), not recoloured
    expect(link.find('svg').attributes('fill')).toBe('#25D366')
    expect(link.find('svg path').attributes('d')).toBeTruthy()
    expect(link.find('img').exists()).toBe(false)
    expect(link.text()).toBe('Chat via WhatsApp')
    expect(link.classes()).toEqual(expect.arrayContaining(['min-h-11', 'gap-2', 'justify-center', 'items-center', 'bg-[#F8F1EA]', 'text-[#672035]', 'rounded-[11px]', 'font-semibold']))
  })

  it('keeps the destination, new-tab safety and the configured label', async () => {
    const w = await openPanel()
    const link = w.find('#wa-panel a')
    expect(link.attributes('href')).toBe('https://wa.me/628123456789?text=Halo%20dunia')
    expect(link.attributes('target')).toBe('_blank')
    expect(link.attributes('rel')).toBe('noopener noreferrer')
    const custom = mount(WhatsappChatBlock, { props: { content: { ...base, button_label: 'Hubungi CS', mode: 'floating' } } })
    await custom.find('button[aria-label="Buka chat WhatsApp"]').trigger('click')
    expect(custom.find('#wa-panel a').text()).toBe('Hubungi CS')
  })

  it('is theme-aware through the same class-based dark mode: warm cream / champagne in light, plum / muted burgundy in dark', async () => {
    const cls = (await openPanel()).find('#wa-panel').classes()
    expect(cls).toEqual(expect.arrayContaining(['bg-[#FFFCF8]', 'dark:bg-[#241D20]', 'border-[#C5A48B]', 'dark:border-[#5C424B]', 'rounded-[18px]', 'overflow-hidden']))
    expect(cls.join(' ')).not.toContain('25D366') // the old bright green is gone from this panel
    const body = (await openPanel()).find('#wa-panel .p-4 p').classes()
    expect(body).toEqual(expect.arrayContaining(['text-[#392729]', 'dark:text-[#E8DDE0]']))
  })

  it('width is capped for narrow screens and the close button closes it back to the unchanged mascot button', async () => {
    const w = await openPanel()
    expect(w.find('#wa-panel').classes()).toContain('w-[min(18rem,calc(100vw-2rem))]')
    await w.find('button[aria-label="Tutup panel WhatsApp"]').trigger('click')
    expect(w.find('#wa-panel').exists()).toBe(false)
    expect(w.find('button[aria-label="Buka chat WhatsApp"]').classes()).toEqual(expect.arrayContaining(['h-14', 'w-14', 'rounded-full']))
  })

  it('keyboard: opening moves focus to the close button, closing gives it back to the mascot button', async () => {
    const w = mount(WhatsappChatBlock, { attachTo: document.body, props: { content: { ...base, mode: 'floating' } } })
    await w.find('button[aria-label="Buka chat WhatsApp"]').trigger('click')
    await w.vm.$nextTick(); await w.vm.$nextTick()
    expect(document.activeElement?.getAttribute('aria-label')).toBe('Tutup panel WhatsApp')
    await w.find('button[aria-label="Tutup panel WhatsApp"]').trigger('click')
    await w.vm.$nextTick(); await w.vm.$nextTick()
    expect(document.activeElement?.getAttribute('aria-label')).toBe('Buka chat WhatsApp')
    w.unmount()
  })

  it('card mode (inline on the page) keeps its previous panel', () => {
    const w = mount(WhatsappChatBlock, { props: { content: { ...base, mode: 'card' } } })
    expect(w.find('#wa-panel').classes()).toContain('bg-[#25D366]')
    expect(w.text()).toContain('Judul CMS Lama')
    expect(w.text()).not.toContain('CS PrimeClassy')
    expect(w.find('img').exists()).toBe(false)
  })
})
