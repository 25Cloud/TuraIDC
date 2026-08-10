import { watch } from 'vue'
import { useWebsiteProductsCatalog } from './useWebsiteProductsCatalog'
import { useWebsiteProductsPurchase } from './useWebsiteProductsPurchase'

export function useWebsiteProductsPage() {
  const purchase = useWebsiteProductsPurchase()
  const catalog = useWebsiteProductsCatalog({
    onProductSelect: purchase.loadSelectedProduct,
    onResetSelection: purchase.resetSelection,
  })

  watch(
    catalog.visibleProducts,
    (products) => {
      purchase.prefetchProductDetails(products)
    },
    { immediate: true },
  )

  return {
    ...catalog,
    ...purchase,
  }
}
