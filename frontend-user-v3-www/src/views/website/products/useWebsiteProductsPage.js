import { watch } from "vue";
import { useWebsiteProductsCatalog } from "./useWebsiteProductsCatalog";
import { useWebsiteProductsPurchase } from "./useWebsiteProductsPurchase";

export function useWebsiteProductsPage(options = {}) {
  const purchase = useWebsiteProductsPurchase();
  const catalog = useWebsiteProductsCatalog({
    onProductSelect: purchase.loadSelectedProduct,
    onResetSelection: purchase.resetSelection,
    autoSelectProduct: options.autoSelectProduct,
  });

  // 详情预取只为列表式的机型切换做准备：卡片式首屏只需要卡片字段，
  // 预取整组商品详情会额外打出 8 个请求，把目录加载拖慢。
  const shouldPrefetchProductDetails = options.shouldPrefetchProductDetails;

  watch(
    catalog.visibleProducts,
    (products) => {
      if (
        typeof shouldPrefetchProductDetails === "function" &&
        !shouldPrefetchProductDetails()
      ) {
        return;
      }

      purchase.prefetchProductDetails(products);
    },
    { immediate: true },
  );

  return {
    ...catalog,
    ...purchase,
  };
}
