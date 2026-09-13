<script setup lang="ts">
/**
 * The dashboard's one rich text editor (Blueprint: pages/articles/news/CMS
 * content/product description) — CKEditor 5 (Classic build) with the
 * required mark/node set: bold, italic, strikethrough, heading, paragraph,
 * list, link, image upload, quote, alignment. Emits HTML; the backend
 * re-sanitizes it on save regardless (see HtmlSanitizerService) — this is
 * never the only line of defense against XSS.
 */
import { computed, ref } from 'vue'
import { Ckeditor } from '@ckeditor/ckeditor5-vue'
import {
  ClassicEditor,
  Essentials,
  Paragraph,
  Heading,
  Bold,
  Italic,
  Strikethrough,
  Link,
  List,
  BlockQuote,
  Alignment,
  Image,
  ImageUpload,
  FileRepository,
  type EditorConfig,
  type FileLoader,
  type UploadAdapter,
  type UploadResponse,
} from 'ckeditor5'
import 'ckeditor5/ckeditor5.css'
import { uploadMedia } from '@/api/media'
import { ApiError } from '@/api/client'

const props = withDefaults(
  defineProps<{ modelValue: string; placeholder?: string; imageCollection?: string }>(),
  { placeholder: 'Tulis konten di sini...', imageCollection: 'cms_content' },
)
const emit = defineEmits<{ 'update:modelValue': [string] }>()

const uploadError = ref<string | null>(null)

/** Routes CKEditor's built-in "Insert image" button through the app's own media endpoint. */
class MediaUploadAdapter implements UploadAdapter {
  constructor(
    private loader: FileLoader,
    private collection: string,
  ) {}

  async upload(): Promise<UploadResponse> {
    const file = await this.loader.file
    if (!(file instanceof File)) throw new Error('Berkas tidak valid.')

    try {
      const media = await uploadMedia(file, this.collection)
      return { default: media.url }
    } catch (e) {
      uploadError.value = e instanceof ApiError ? e.message : 'Upload gambar gagal.'
      throw e
    }
  }

  abort(): void {
    // No in-flight XHR to cancel — uploadMedia() is a single axios call
    // that either resolves or rejects; CKEditor just discards the loader.
  }
}

function onReady(editor: ClassicEditor): void {
  editor.plugins.get(FileRepository).createUploadAdapter = (loader) =>
    new MediaUploadAdapter(loader, props.imageCollection)
}

const editorConfig = computed<EditorConfig>(() => ({
  licenseKey: 'GPL',
  plugins: [Essentials, Paragraph, Heading, Bold, Italic, Strikethrough, Link, List, BlockQuote, Alignment, Image, ImageUpload],
  toolbar: {
    items: [
      'heading',
      '|',
      'bold',
      'italic',
      'strikethrough',
      '|',
      'bulletedList',
      'numberedList',
      '|',
      'blockQuote',
      'link',
      'uploadImage',
      'alignment',
      '|',
      'undo',
      'redo',
    ],
  },
  link: { addTargetToExternalLinks: true, defaultProtocol: 'https://' },
  placeholder: props.placeholder,
}))
</script>

<template>
  <div class="cms-editor overflow-hidden rounded-xl border border-stone-300 dark:border-stone-700">
    <Ckeditor
      :editor="ClassicEditor"
      :model-value="modelValue"
      :config="editorConfig"
      @update:model-value="(...args: unknown[]) => emit('update:modelValue', args[0] as string)"
      @ready="onReady"
    />
    <p v-if="uploadError" class="border-t border-stone-200 px-4 py-2 text-sm text-red-600 dark:border-stone-700 dark:text-red-400">
      {{ uploadError }}
    </p>
  </div>
</template>

<style>
/* Unscoped: CKEditor renders its toolbar/editable outside this component's
   own template root, so a scoped :deep() selector can't reach it — the
   .cms-editor wrapper class keeps these rules from leaking to any other
   CKEditor instance that might appear elsewhere on the page. */
.cms-editor .ck.ck-editor__main > .ck-editor__editable {
  min-height: 10rem;
  padding: 0.75rem 1rem;
}
.cms-editor .ck.ck-editor__editable_inline {
  color: inherit;
}
.cms-editor .ck.ck-toolbar {
  border-left: 0;
  border-right: 0;
  border-top: 0;
}
.cms-editor .ck.ck-editor__main > .ck-editor__editable {
  border: 0;
}
</style>
