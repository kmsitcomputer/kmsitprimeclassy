<script setup lang="ts">
import ProductDetailView from '@/views/ProductDetailView.vue'
import { useSsrData } from '@/utils/ssrData'
import { fetchProductSeed, productKey, type ProductSeed } from '@/utils/ssrLoaders'
import { useSeo, stripHtml } from '../composables/useSeo'

const props = defineProps<{ slug: string }>()
const ssr = useSsrData()
// Same key as the view → one API call per request; failure (404/error) just means "no product metadata".
const seed = await ssr.load<ProductSeed>(productKey(props.slug), () => fetchProductSeed(props.slug)).catch(() => null)
const product = seed?.product ?? null

const prices = product
  ? (product.has_variations ? product.variations.filter((v) => v.is_active).map((v) => Number(v.price)) : [Number(product.base_price)]).filter(
      (n) => Number.isFinite(n) && n > 0,
    )
  : []
const image = product?.images?.find((i) => i.is_primary)?.url ?? product?.images?.[0]?.url ?? null

useSeo({
  title: product ? `${product.name} — Prime Classy` : 'Produk tidak ditemukan — Prime Classy',
  description: product ? product.short_description || stripHtml(product.description) : null,
  path: `/products/${props.slug}`,
  image,
  ogType: 'product',
  notFound: !product,
  jsonLd: product
    ? {
        '@context': 'https://schema.org',
        '@type': 'Product',
        name: product.name,
        sku: product.sku ?? undefined,
        description: product.short_description || stripHtml(product.description) || undefined,
        image: image ?? undefined,
        category: product.category?.name,
        ...(prices.length
          ? {
              offers: {
                '@type': prices.length > 1 && Math.min(...prices) !== Math.max(...prices) ? 'AggregateOffer' : 'Offer',
                priceCurrency: 'IDR',
                ...(Math.min(...prices) !== Math.max(...prices)
                  ? { lowPrice: Math.min(...prices), highPrice: Math.max(...prices) }
                  : { price: prices[0] }),
              },
            }
          : {}),
      }
    : undefined,
})
</script>

<template><ProductDetailView :slug="slug" /></template>
