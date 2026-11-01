import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'

const createBlock = vi.fn()
const updateBlock = vi.fn()
const getBlock = vi.fn()

vi.mock('@/api/cmsAdmin', () => ({
  getBlock: (...a: unknown[]) => getBlock(...a),
  createBlock: (...a: unknown[]) => createBlock(...a),
  updateBlock: (...a: unknown[]) => updateBlock(...a),
}))
vi.mock('@/layouts/DashboardLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }))

import CmsHomepageBlockFormView from '../CmsHomepageBlockFormView.vue'

URL.createObjectURL = vi.fn(() => 'blob:preview')
URL.revokeObjectURL = vi.fn()

async function mountForm(path: string) {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/new', name: 'admin-homepage-blocks-create', component: { template: '<div />' } },
      { path: '/edit/:id', name: 'admin-homepage-blocks-edit', component: { template: '<div />' } },
      { path: '/list', name: 'admin-homepage-blocks', component: { template: '<div />' } },
    ],
  })
  router.push(path)
  await router.isReady()
  const w = mount(CmsHomepageBlockFormView, { global: { plugins: [router], stubs: { AppIcon: true } } })
  await flushPromises()
  return { w, router }
}

const file = (name: string) => new File(['x'], name, { type: 'image/jpeg' })
const entries = (fd: FormData) => Array.from(fd.entries()).map(([k, v]) => [k, v instanceof File ? `file:${v.name}` : v] as const)

describe('CmsHomepageBlockFormView — banner slider', () => {
  beforeEach(() => {
    createBlock.mockReset()
    updateBlock.mockReset()
    getBlock.mockReset()
    createBlock.mockResolvedValue({})
    updateBlock.mockResolvedValue({})
  })

  it('banner_slider is selectable, starts with one slide, and can add / reorder / delete slides', async () => {
    const { w } = await mountForm('/new')
    expect(w.find('select option[value="banner_slider"]').exists()).toBe(true)

    await w.find('select').setValue('banner_slider')
    expect(w.findAll('[data-testid^="slide-"]').filter((n) => /^slide-\d+$/.test(n.attributes('data-testid')!))).toHaveLength(1)

    await w.find('[data-testid="add-slide"]').trigger('click')
    await w.find('[data-testid="add-slide"]').trigger('click')
    const titles = () => w.findAll('input[placeholder="Judul (opsional)"]')
    expect(titles()).toHaveLength(3)

    await titles()[0]!.setValue('A')
    await titles()[1]!.setValue('B')
    await titles()[2]!.setValue('C')

    await w.find('button[aria-label="Turunkan slide 1"]').trigger('click') // B A C
    expect(titles().map((t) => (t.element as HTMLInputElement).value)).toEqual(['B', 'A', 'C'])
    await w.find('button[aria-label="Naikkan slide 3"]').trigger('click') // B C A
    expect(titles().map((t) => (t.element as HTMLInputElement).value)).toEqual(['B', 'C', 'A'])

    await w.find('button[aria-label="Hapus slide 2"]').trigger('click') // B A
    expect(titles().map((t) => (t.element as HTMLInputElement).value)).toEqual(['B', 'A'])
  })

  it('refuses to save a slide without a desktop image and shows the reason', async () => {
    const { w } = await mountForm('/new')
    await w.find('select').setValue('banner_slider')
    await w.find('form').trigger('submit')
    await flushPromises()
    expect(createBlock).not.toHaveBeenCalled()
    expect(w.text()).toContain('Slide 1 belum memiliki gambar desktop.')
  })

  it('submits settings, slide order and images keyed by slide key', async () => {
    const { w, router } = await mountForm('/new')
    await w.find('select').setValue('banner_slider')
    await w.find('[data-testid="add-slide"]').trigger('click')

    const vm = w.vm as unknown as { $: { setupState: Record<string, any> } }
    const state = vm.$.setupState
    const [s1, s2] = state.slides as any[]
    s1.title = 'Satu'
    s1.button_label = 'Lihat'
    s1.button_url = '/produk'
    s2.title = 'Dua'
    state.setSlideImage(s1, 'desktop', [file('d1.jpg')])
    state.setSlideImage(s1, 'mobile', [file('m1.jpg')])
    state.setSlideImage(s2, 'desktop', [file('d2.jpg')])
    state.form.autoplay = false
    state.form.interval_seconds = 7
    state.form.slider_layout = 'wide'
    state.form.show_dots = false
    await w.vm.$nextTick()
    state.moveSlide(1, -1) // Dua, Satu

    await w.find('form').trigger('submit')
    await flushPromises()

    expect(createBlock).toHaveBeenCalledOnce()
    const fd = createBlock.mock.calls[0]![0] as FormData
    const e = Object.fromEntries(entries(fd))
    expect(e['type']).toBe('banner_slider')
    expect(e['content[layout]']).toBe('wide')
    expect(e['content[autoplay]']).toBe('0')
    expect(e['content[interval_seconds]']).toBe('7')
    expect(e['content[show_arrows]']).toBe('1')
    expect(e['content[show_dots]']).toBe('0')
    expect(e['content[slides][0][title]']).toBe('Dua')
    expect(e['content[slides][1][title]']).toBe('Satu')
    expect(e['content[slides][1][button_url]']).toBe('/produk')
    expect(e['content[slides][0][key]']).toBe(s2.key)
    expect(e[`slide_images[${s1.key}][desktop]`]).toBe('file:d1.jpg')
    expect(e[`slide_images[${s1.key}][mobile]`]).toBe('file:m1.jpg')
    expect(e[`slide_images[${s2.key}][desktop]`]).toBe('file:d2.jpg')
    expect(e[`slide_images[${s2.key}][mobile]`]).toBeUndefined()
    expect(s1.key).toMatch(/^[A-Za-z0-9_-]{6,40}$/)
    expect(s1.key).not.toBe(s2.key)
    expect(router.currentRoute.value.name).toBe('admin-homepage-blocks')
  })

  it('re-opens a saved slider for editing with its settings, slides and existing images', async () => {
    getBlock.mockResolvedValue({
      id: 9, type: 'banner_slider', image_url: null,
      content: {
        layout: 'wide', autoplay: '0', interval_seconds: 8, show_arrows: '1', show_dots: '0',
        slides: [
          { key: 'keep-aaaa', title: 'Lama', image_url: '/d.jpg', mobile_image_url: '/m.jpg', overlay: 'light', content_position: 'right', button_label: 'Go', button_url: '/x' },
        ],
      },
    })
    const { w } = await mountForm('/edit/9')
    const state = (w.vm as any).$.setupState
    expect(state.form.type).toBe('banner_slider')
    expect(state.form.slider_layout).toBe('wide')
    expect(state.form.autoplay).toBe(false)
    expect(state.form.interval_seconds).toBe(8)
    expect(state.form.show_dots).toBe(false)
    expect(state.slides).toHaveLength(1)
    expect(state.slides[0].key).toBe('keep-aaaa')
    expect(state.slides[0].existingDesktop).toBe('/d.jpg')
    expect(w.text()).not.toContain('Gambar desktop wajib diisi.')

    // Saving without touching images sends no files, only the content that keeps the slide's identity.
    await w.find('input[placeholder="Judul (opsional)"]').setValue('Baru')
    await w.find('button.text-red-700.hover\\:underline').trigger('click') // remove the mobile image
    await w.find('form').trigger('submit')
    await flushPromises()

    expect(updateBlock).toHaveBeenCalledOnce()
    expect(updateBlock.mock.calls[0]![0]).toBe(9)
    const e = Object.fromEntries(entries(updateBlock.mock.calls[0]![1] as FormData))
    expect(e['content[slides][0][key]']).toBe('keep-aaaa')
    expect(e['content[slides][0][title]']).toBe('Baru')
    expect(e['content[slides][0][remove_mobile]']).toBe('1')
    expect(Object.keys(e).some((k) => k.startsWith('slide_images'))).toBe(false)
  })

  it('the preview has a real mobile mode: a 390px frame that uses the mobile image', async () => {
    const { w } = await mountForm('/new')
    await w.find('select').setValue('banner_slider')
    const state = (w.vm as any).$.setupState
    state.setSlideImage(state.slides[0], 'desktop', [file('d.jpg')])
    state.slides[0].existingMobile = '/storage/m.jpg'
    await w.vm.$nextTick()

    const frame = () => w.find('[data-testid="preview-frame"]')
    expect(frame().classes()).not.toContain('w-[390px]')
    expect(w.find('[data-testid="preview-desktop"]').attributes('aria-pressed')).toBe('true')

    await w.find('[data-testid="preview-mobile"]').trigger('click')
    expect(frame().classes()).toContain('w-[390px]')
    expect(w.find('[data-testid="preview-mobile"]').attributes('aria-pressed')).toBe('true')
    expect(frame().find('img').attributes('src')).toBe('/storage/m.jpg')

    await w.find('[data-testid="preview-desktop"]').trigger('click')
    expect(frame().find('img').attributes('src')).toBe('blob:preview')
  })

  it('shows a live preview rendered by the public slider component', async () => {
    const { w } = await mountForm('/new')
    await w.find('select').setValue('banner_slider')
    const state = (w.vm as any).$.setupState
    state.setSlideImage(state.slides[0], 'desktop', [file('d.jpg')])
    await w.vm.$nextTick()
    const preview = w.find('[data-testid="block-preview"]')
    expect(preview.exists()).toBe(true)
    expect(preview.find('[role="region"][aria-roledescription="carousel"] img').attributes('src')).toBe('blob:preview')
  })
})
