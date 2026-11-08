import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
const { fetchFile } = vi.hoisted(() => ({ fetchFile: vi.fn() }))
vi.mock('@/api/protectedFile', () => ({ ProtectedFileError: class extends Error {}, fetchProtectedFile: fetchFile }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))
import ProtectedFileViewer from '../ProtectedFileViewer.vue'

afterEach(() => { vi.restoreAllMocks(); document.body.innerHTML = '' })
describe('protected file lifecycle', () => {
  it('previews and exposes a download link, then releases its object URL', async () => {
    fetchFile.mockResolvedValue({ blob: new Blob(['png']), mime: 'image/png', filename: 'proof.png' })
    const revoke = vi.fn()
    vi.stubGlobal('URL', Object.assign(URL, { createObjectURL: vi.fn(() => 'blob:proof'), revokeObjectURL: revoke }))
    const wrapper = mount(ProtectedFileViewer, { props: { url: '/api/v1/payment-proofs/1', label: 'Proof' }, attachTo: document.body })
    await wrapper.get('button').trigger('click')
    await flushPromises()
    expect(document.body.querySelector('img')?.getAttribute('src')).toBe('blob:proof')
    expect(document.body.querySelector('a')?.getAttribute('download')).toBe('proof.png')
    expect(document.body.querySelector('a')?.getAttribute('href')).toBe('blob:proof')
    ;(document.body.querySelector('[role="dialog"] button') as HTMLButtonElement).click()
    await flushPromises()
    expect(revoke).toHaveBeenCalledWith('blob:proof')
    wrapper.unmount()
  })
  it('discards a response arriving after the viewer closes', async () => {
    let resolve!: (file: unknown) => void
    fetchFile.mockReturnValue(new Promise((r) => { resolve = r }))
    const create = vi.fn(() => 'blob:test')
    vi.stubGlobal('URL', Object.assign(URL, { createObjectURL: create, revokeObjectURL: vi.fn() }))
    const wrapper = mount(ProtectedFileViewer, { props: { url: '/api/v1/payment-proofs/1', label: 'Proof' }, attachTo: document.body })
    await wrapper.get('button').trigger('click')
    ;(document.body.querySelector('[role="dialog"] button') as HTMLButtonElement).click()
    resolve({ blob: new Blob(['ok']), mime: 'image/png', filename: 'proof.png' })
    await flushPromises()
    expect(create).not.toHaveBeenCalled()
    expect(document.body.querySelector('[role="dialog"]')).toBeNull()
    wrapper.unmount()
  })
})
