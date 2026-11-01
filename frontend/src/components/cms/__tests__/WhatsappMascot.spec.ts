import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import WhatsappChatBlock from '../blocks/WhatsappChatBlock.vue'

const base = { phone: '628123456789', message: 'Halo dunia', button_label: 'Chat Via WhatsApp', heading: 'Hubungi Kami' }
const fabOf = (w: ReturnType<typeof mount>) => w.find('button[aria-label="Buka chat WhatsApp"]')

describe('floating WhatsApp button — CS Prime mascot', () => {
  it('shows the supplied cs-prime.png instead of the green WhatsApp glyph', () => {
    const w = mount(WhatsappChatBlock, { props: { content: { ...base, mode: 'floating' } } })
    const fab = fabOf(w)
    const img = fab.find('img')
    expect(img.exists()).toBe(true)
    expect(img.attributes('src')).toMatch(/cs-prime.*\.png$/)
    expect(img.attributes('alt')).toBe('') // decorative: the button already has its accessible name
    expect(img.attributes('aria-hidden')).toBe('true')
    expect(img.attributes('width')).toBe('1292') // intrinsic ratio kept (1292x1218), never stretched
    expect(img.attributes('height')).toBe('1218')
    expect(img.classes()).toContain('h-auto')
    expect(fab.find('svg').exists()).toBe(false) // the old icon is gone from this button
  })

  it('does not paint a second circle: the PNG supplies it, the button keeps its size, shape, label and behaviour', () => {
    const w = mount(WhatsappChatBlock, { props: { content: { ...base, mode: 'floating' } } })
    const cls = fabOf(w).classes()
    expect(cls).toEqual(expect.arrayContaining(['h-14', 'w-14', 'rounded-full', 'shadow-lg', 'flex', 'items-center', 'justify-center']))
    expect(cls.join(' ')).not.toContain('bg-[#25D366]') // no green disc behind / around the mascot
    expect(fabOf(w).attributes('aria-controls')).toBe('wa-panel')
    expect(fabOf(w).attributes('aria-expanded')).toBe('false')
    // the image cannot enlarge the click target or intercept clicks
    expect(fabOf(w).find('img').classes()).toContain('pointer-events-none')
  })

  it('keeps the container position and stacking', () => {
    const w = mount(WhatsappChatBlock, { props: { content: { ...base, mode: 'floating' } } })
    const cls = w.find('section').classes().join(' ')
    expect(cls).toContain('fixed')
    expect(cls).toContain('z-40')
    expect(cls).toContain('bottom-[calc(5rem+env(safe-area-inset-bottom))]')
    expect(cls).toContain('sm:bottom-5')
  })

  it('still opens the panel, and the panel link keeps its own WhatsApp icon and the same destination', async () => {
    const w = mount(WhatsappChatBlock, { props: { content: { ...base, mode: 'floating' } } })
    await fabOf(w).trigger('click')
    const link = w.find('#wa-panel a')
    expect(link.attributes('href')).toBe('https://wa.me/628123456789?text=Halo%20dunia')
    expect(link.find('svg path').attributes('d')).toBeTruthy() // the regular WhatsApp mark, untouched
    expect(link.find('img').exists()).toBe(false)
    expect(w.find('button[aria-label="Tutup panel WhatsApp"]').exists()).toBe(true)
  })

  it('card mode has no floating button, so no mascot', () => {
    const w = mount(WhatsappChatBlock, { props: { content: { ...base, mode: 'card' } } })
    expect(w.find('img').exists()).toBe(false)
    expect(w.find('a svg path').attributes('d')).toBeTruthy()
  })
})
