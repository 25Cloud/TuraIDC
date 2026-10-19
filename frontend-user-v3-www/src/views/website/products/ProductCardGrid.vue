<template>
  <div class="product-card-grid">
    <article
      v-for="card in cards"
      :key="card.id"
      class="product-card"
      :class="{ 'product-card--sold-out': card.soldOut }"
    >
      <header class="product-card-head">
        <div class="product-card-tags">
          <span v-if="card.typeLabel" class="product-card-type">{{
            card.typeLabel
          }}</span>
          <span
            v-if="card.stock"
            class="product-card-flag"
            :class="`product-card-flag--${card.stock.tone}`"
            >{{ card.stock.text }}</span
          >
        </div>
        <h3 class="product-card-name" :title="card.name">{{ card.name }}</h3>
      </header>

      <div class="product-card-body">
        <!--
          描述与详情页同源（products.description），后台填写的富文本片段落库前已过
          白名单消毒；自动生成的描述是 <ul><li>键: 值</li></ul>，不含下单时才选的
          系统维度。整段完整展示，不做截断——卡片描述必须和点进配置页看到的一致。
        -->
        <!-- eslint-disable vue/no-v-html -- 描述入库前已过 RichHtmlSanitizer 白名单消毒 -->
        <div
          v-if="card.description"
          class="product-card-desc"
          v-html="card.description"
        />
        <!-- eslint-enable vue/no-v-html -->
        <p v-else class="product-card-desc product-card-desc--empty">
          {{ $t("product.card.noDescription") }}
        </p>
      </div>

      <footer class="product-card-foot">
        <div class="product-card-price">
          <template v-if="card.price.hasAgentPrice">
            <span class="agent-price-original"
              >¥{{ card.price.agentOriginal }}</span
            >
            <em v-if="card.price.agentPercent" class="discount-tag">{{
              card.price.agentPercent
            }}</em>
            <span class="price-symbol">¥</span>
            <strong class="price-amount">{{ card.price.agentAmount }}</strong>
          </template>
          <template v-else-if="card.price.listAmount">
            <span class="price-symbol">¥</span>
            <strong class="price-amount">{{ card.price.listAmount }}</strong>
            <span class="price-cycle">/{{ card.price.listCycle }}</span>
          </template>
          <template v-else>{{ $t("product.card.priceOnRequest") }}</template>
        </div>
        <el-button
          type="primary"
          class="product-card-buy"
          :disabled="card.soldOut"
          @click="openConfigure(card.configurePath)"
        >
          {{ $t("product.card.buyNow") }}
        </el-button>
      </footer>
    </article>

    <el-empty
      v-if="!cards.length"
      class="product-card-empty"
      :description="$t('product.card.empty')"
    />
  </div>
</template>

<script setup>
import { computed } from "vue";
import { useRouter } from "vue-router";
import { t } from "@/locales";
import { resolveProductDisplayName } from "@/utils/websiteProductConfig";
import { buildWebsiteProductPath } from "@/utils/productRoute";
import { resolveCardPrice } from "./productPrice";

const props = defineProps({
  products: {
    type: Array,
    default: () => [],
  },
  // 卡片是否展示库存由后台「商品展示」页面控制；售罄标记不受这个开关影响
  showStock: {
    type: Boolean,
    default: true,
  },
});

const router = useRouter();

/**
 * 库存徽标：售罄永远展示（关系到能不能下单），
 * 库存充足 / 仅剩 N 台只在后台开启「卡片显示库存」时展示。
 */
function resolveStockBadge(product) {
  const stock = Number(product?.stock);

  if (!Number.isFinite(stock)) {
    return null;
  }

  if (stock === 0) {
    return { tone: "danger", text: t("product.card.soldOut") };
  }

  if (!props.showStock) {
    return null;
  }

  if (stock > 0 && stock <= 10) {
    return {
      tone: "warn",
      text: t("product.card.stockLeft", { count: stock }),
    };
  }

  if (stock === -1 || stock > 10) {
    return { tone: "ok", text: t("product.card.inStock") };
  }

  return null;
}

/**
 * 卡片式是「选商品 → 进配置页」两步，与魔方 public/themes/cart/default/product.tpl
 * 同构：卡片只承担辨认与比价，具体配置交给商品配置页。
 *
 * 配置页地址与列表式完全一致（/products/{typeSlug}/{groupId}/{childGroupId}/{productId}）：
 * 卡片式用户与列表式用户互相分享链接时，对方也能打开同一台商品，
 * 只是渲染成各自偏好/后台默认的形态。
 * 整张卡片不绑跳转——只有「立即选购」按钮能进配置页，避免浏览描述时误触。
 */
const cards = computed(() =>
  (props.products || []).map((product) => {
    const id = Number(product?.id || 0);
    const path = buildWebsiteProductPath({
      typeSlug: String(
        product?.first_product_group_slug ||
          product?.first_product_group_code ||
          "",
      ).trim(),
      groupId: Number(product?.second_product_group_id || 0),
      childGroupId: Number(product?.third_product_group_id || 0),
      productId: id,
    });

    return {
      id,
      name: resolveProductDisplayName(product),
      // 商品类型作为卡片左上角的识别标签，避免一屏卡片全是白底黑字没有锚点
      typeLabel: String(
        product?.product_type_label || product?.type_label || "",
      ).trim(),
      description: String(product?.description_html || ""),
      soldOut: Number(product?.stock ?? -1) === 0,
      stock: resolveStockBadge(product),
      price: resolveCardPrice(product),
      // 缺少层级字段的历史数据退回旧的单段商品地址（会规范成四段）
      configurePath: path === "/products" ? `/products/${id}` : path,
    };
  }),
);

function openConfigure(path) {
  if (!path) {
    return;
  }

  router.push(path);
}
</script>

<style scoped lang="scss" src="./productCardGrid.scss"></style>
