import type { RouterConfig } from '@nuxt/schema'
import type { RouteRecordRaw } from 'vue-router'
import { routeDefs } from '@/router/routeDefs'
import { isNuxtOwnedRouteName, type NuxtOwnedRouteName } from '@/router/boundary'
import SpaHandoff from './wrappers/SpaHandoff.vue'

/**
 * ONE route table: the SPA's own (src/router/routeDefs.ts — metadata only, no SPA view is imported). Routes the storefront owns keep their path/name/props
 * and render an SEO wrapper around the existing view; every other record is kept ONLY so that
 * `RouterLink :to="{ name: 'cart' }"` in shared components still resolves, and is flagged `spaOwned` so
 * middleware/boundary.global.ts hands it to the SPA.
 */
const wrappers: Record<NuxtOwnedRouteName, () => Promise<unknown>> = {
  home: () => import('./wrappers/HomePage.vue'),
  products: () => import('./wrappers/ProductListingPage.vue'),
  'product-detail': () => import('./wrappers/ProductDetailPage.vue'),
  categories: () => import('./wrappers/CategoriesPage.vue'),
  articles: () => import('./wrappers/ArticlesPage.vue'),
  'article-detail': () => import('./wrappers/ArticleDetailPage.vue'),
  'page-detail': () => import('./wrappers/PagePage.vue'),
  'store-locator': () => import('./wrappers/StoreLocatorPage.vue'),
  'not-found': () => import('./wrappers/NotFoundPage.vue'),
}

export default {
  routes: () =>
    routeDefs.map((route): RouteRecordRaw => {
      if (isNuxtOwnedRouteName(route.name)) {
        return { ...route, component: wrappers[route.name] } as RouteRecordRaw
      }
      return {
        ...route,
        component: SpaHandoff,
        props: undefined,
        meta: { ...route.meta, spaOwned: true },
      } as RouteRecordRaw
    }),
  scrollBehavior(_to, _from, savedPosition) {
    return savedPosition ?? { top: 0 }
  },
} satisfies RouterConfig
