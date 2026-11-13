import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import WhatsappChatBlock from '../blocks/WhatsappChatBlock.vue'
import SocialMediaBlock from '../blocks/SocialMediaBlock.vue'
import MarketplaceLinksBlock from '../blocks/MarketplaceLinksBlock.vue'

const base = { phone: '628123456789', message: 'Halo dunia', button_label: 'Chat Via WhatsApp', heading: 'Hubungi Kami' }

describe('WhatsappChatBlock', () => {
  it('floating mode starts collapsed as a labelled button, opens and closes', async () => {
    const w = mount(WhatsappChatBlock, { props: { content: { ...base, mode: 'floating' } } })
    expect(w.text()).not.toContain('CS PrimeClassy')
    const fab = w.find('button[aria-label="Buka chat WhatsApp"]')
    expect(fab.exists()).toBe(true)

    await fab.trigger('click')
    expect(w.text()).toContain('CS PrimeClassy')
    expect(w.find('a').attributes('href')).toBe('https://wa.me/628123456789?text=Halo%20dunia')

    await w.find('button[aria-label="Tutup panel WhatsApp"]').trigger('click')
    expect(w.text()).not.toContain('CS PrimeClassy')
    expect(w.find('button[aria-label="Buka chat WhatsApp"]').exists()).toBe(true)
  })

  it('floating position clears the mobile bottom navigation', () => {
    const w = mount(WhatsappChatBlock, { props: { content: { ...base, mode: 'floating' } } })
    expect(w.find('section').classes().join(' ')).toContain('env(safe-area-inset-bottom)')
  })

  it('card mode is always expanded without a close button', () => {
    const w = mount(WhatsappChatBlock, { props: { content: { ...base, mode: 'card' } } })
    expect(w.text()).toContain('Hubungi Kami')
    expect(w.find('button').exists()).toBe(false)
  })

  it('renders nothing for an invalid phone number', () => {
    const w = mount(WhatsappChatBlock, { props: { content: { ...base, phone: '0812-abc', mode: 'card' } } })
    expect(w.find('a').exists()).toBe(false)
  })
})

describe('brand icons in link blocks', () => {
  it('social links use the configured url and carry an icon plus an accessible name (icon-only circles)', () => {
    const w = mount(SocialMediaBlock, {
      props: { content: { layout: 'cards', platforms: { instagram: { enabled: true, url: 'https://instagram.com/x' }, tiktok: { enabled: true, url: 'https://tiktok.com/@x' }, facebook: { enabled: true, url: 'https://facebook.com/x' } } } },
    })
    const links = w.findAll('a')
    expect(links.map((l) => l.attributes('href'))).toEqual(['https://instagram.com/x', 'https://tiktok.com/@x', 'https://facebook.com/x'])
    links.forEach((l) => expect(l.find('svg path').attributes('d')).toBeTruthy())
    expect(links[0]!.attributes('aria-label')).toBe('Instagram')
  })

  it('marketplace shows the bundled Tokopedia PNG and the Shopee SVG, with CMS urls and accessible names', () => {
    const w = mount(MarketplaceLinksBlock, {
      props: { content: { layout: 'cards', platforms: { tokopedia: { enabled: true, url: 'https://tokopedia.com/x' }, shopee: { enabled: true, url: 'https://shopee.co.id/x' } } } },
    })
    const [toko, shopee] = w.findAll('a')
    expect(toko!.attributes('href')).toBe('https://tokopedia.com/x')
    expect(toko!.find('img').attributes('src')).toMatch(/tokopedia-com-logo.*\.png$/)
    expect(toko!.find('svg').exists()).toBe(false)
    expect(toko!.attributes('aria-label')).toBe('Tokopedia')
    expect(shopee!.find('img').exists()).toBe(false)
    expect(shopee!.find('svg path').attributes('d')).toBeTruthy()
  })
})
