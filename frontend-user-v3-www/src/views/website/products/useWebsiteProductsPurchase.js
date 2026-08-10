import { ref } from 'vue'
import { useWebsiteProductConfigurator } from './useWebsiteProductConfigurator'
import { useWebsiteProductCheckout } from './useWebsiteProductCheckout'

export function useWebsiteProductsPurchase() {
  const productDetail = ref(null)

  const configurator = useWebsiteProductConfigurator(productDetail)
  const checkout = useWebsiteProductCheckout({
    productDetail,
    configForm: configurator.configForm,
    pricingEntries: configurator.pricingEntries,
    buildConfigPayload: configurator.buildConfigPayload,
    initProductDefaults: configurator.initProductDefaults,
    resetConfigForm: configurator.resetConfigForm,
  })

  return {
    ...configurator,
    ...checkout,
  }
}
