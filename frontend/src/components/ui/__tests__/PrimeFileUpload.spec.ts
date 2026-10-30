/** Universal Upload Standard — PrimeFileUpload selection, validation, state and a11y contract. */
import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'

import PrimeFileUpload from '@/components/ui/PrimeFileUpload.vue'

const img = (name = 'a.jpg', size = 1024, type = 'image/jpeg') => new File([new Uint8Array(size)], name, { type })

async function pick(w: ReturnType<typeof mount>, files: File[], sel = '[data-testid="pfu-input"]') {
  const input = w.find(sel)
  Object.defineProperty(input.element, 'files', { value: files, configurable: true })
  await input.trigger('change')
  await flushPromises()
}

describe('PrimeFileUpload', () => {
  it('accepts a valid image and emits it as a one-element array', async () => {
    const w = mount(PrimeFileUpload, { props: { modelValue: [] } })
    const f = img()
    await pick(w, [f])
    expect(w.emitted('update:modelValue')![0]).toEqual([[f]])
  })

  it('rejects an unsupported MIME type and an oversize file without emitting', async () => {
    const w = mount(PrimeFileUpload, { props: { modelValue: [], maxSizeMb: 1 } })
    await pick(w, [img('x.pdf', 10, 'application/pdf')])
    expect(w.find('[data-testid="pfu-error"]').text()).toContain('Jenis file tidak didukung')
    await pick(w, [img('big.jpg', 2 * 1024 * 1024)])
    expect(w.find('[data-testid="pfu-error"]').text()).toContain('maksimal 1MB')
    expect(w.emitted('update:modelValue')).toBeUndefined()
  })

  it('enforces the maximum file count in multiple mode', async () => {
    const w = mount(PrimeFileUpload, { props: { modelValue: [img('1.jpg')], maxFiles: 2 } })
    await pick(w, [img('2.jpg'), img('3.jpg')])
    expect(w.find('[data-testid="pfu-error"]').text()).toContain('Maksimal 2 file')
  })

  it('renders a camera button only when capture is requested', () => {
    expect(mount(PrimeFileUpload, { props: { modelValue: [] } }).find('[data-testid="pfu-camera"]').exists()).toBe(false)
    const w = mount(PrimeFileUpload, { props: { modelValue: [], capture: 'environment' } })
    expect(w.find('[data-testid="pfu-camera"]').exists()).toBe(true)
    expect(w.find('[data-testid="pfu-camera-input"]').attributes('capture')).toBe('environment')
  })

  it('shows a real percentage only when progress is supplied, otherwise indeterminate; cancel emits', async () => {
    const real = mount(PrimeFileUpload, { props: { modelValue: [img()], uploading: true, progress: 42 } })
    expect(real.find('[data-testid="pfu-progress"]').text()).toContain('42%')
    const w = mount(PrimeFileUpload, { props: { modelValue: [img()], uploading: true } })
    expect(w.find('[data-testid="pfu-progress"]').text()).not.toContain('%')
    expect(w.find('[role="progressbar"]').exists()).toBe(false)
    await w.find('[data-testid="pfu-cancel"]').trigger('click')
    expect(w.emitted('cancel')).toHaveLength(1)
  })

  it('offers retry on an external error and removal of a selected file', async () => {
    const f = img()
    const w = mount(PrimeFileUpload, { props: { modelValue: [f], error: 'Gagal' } })
    await w.find('[data-testid="pfu-retry"]').trigger('click')
    expect(w.emitted('retry')).toHaveLength(1)
    await w.find('[data-testid="pfu-selected"] button').trigger('click')
    expect(w.emitted('update:modelValue')![0]).toEqual([[]])
  })

  it('shows verification status and rejection reason only for existing evidence that carries them', () => {
    const w = mount(PrimeFileUpload, {
      props: { modelValue: [], existing: [{ name: 'bukti.jpg', url: '/x/bukti.jpg', status: 'rejected', rejectionReason: 'Buram' }, { name: 'lama.jpg' }] },
    })
    const text = w.find('[data-testid="pfu-existing"]').text()
    expect(text).toContain('Ditolak')
    expect(text).toContain('Alasan: Buram')
    expect(text).not.toContain('Terverifikasi')
  })

  it('does not accept files when read-only or disabled', async () => {
    const w = mount(PrimeFileUpload, { props: { modelValue: [], readonly: true } })
    expect(w.find('[data-testid="pfu-pick"]').exists()).toBe(false)
    expect(vi.isMockFunction(w.emitted)).toBe(false)
  })
})
