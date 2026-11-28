<template>
  <div class="home-page">
    <!--
      区块由站点配置的 home_sections 编排（顺序 + 开关 + 变体），
      没配置时按内置默认顺序渲染，站长不动配置也能正常显示。
      每个区块的渲染条件（数据就绪 / 骨架 / 延迟挂载）保持原样，
      配置层只决定「渲染哪一个、按什么顺序、开不开」。
    -->
    <template v-for="section in renderedSections" :key="section.key">
      <template v-if="section.key === 'hero'">
        <HomeHeroCarousel v-if="homeHeroReady" :hero="homeHero || {}" />
        <HomeSectionSkeleton v-else type="hero" />
      </template>

      <template v-else-if="section.key === 'products'">
        <HomeProductTabs
          v-if="productContentReady"
          :loading="loading"
          :product-types="productTypes"
          :root-groups="rootGroups"
          :group-catalog-map="groupCatalogMap"
        />
        <HomeSectionSkeleton v-else type="products" />
      </template>

      <template v-else-if="section.key === 'ai_skill'">
        <DeferredHomeSection
          v-if="deferredReady"
          :loader="loadAiSkillSection"
          skeleton-type="ai"
        />
        <HomeSectionSkeleton v-else type="ai" />
      </template>

      <template v-else-if="section.key === 'solutions'">
        <DeferredHomeSection
          v-if="deferredReady"
          :loader="loadSolutionSection"
          skeleton-type="solutions"
        />
        <HomeSectionSkeleton v-else type="solutions" />
      </template>

      <template v-else-if="section.key === 'partners'">
        <DeferredHomeSection
          v-if="deferredReady"
          :loader="loadPartnerSection"
          skeleton-type="partner"
        />
        <HomeSectionSkeleton v-else type="partner" />
      </template>

      <template v-else-if="section.key === 'news'">
        <!-- 没有公告时整段不挂：否则只剩「资讯动态」节头 + 查看更多，下面零卡片 -->
        <DeferredHomeSection
          v-if="deferredReady && notices.length > 0"
          :loader="loadNewsSection"
          skeleton-type="news"
          :section-props="{ notices }"
        />
        <HomeSectionSkeleton v-else-if="notices.length > 0" type="news" />
      </template>

      <template v-else-if="section.key === 'register'">
        <DeferredHomeSection
          v-if="deferredReady"
          :loader="loadRegisterBar"
          skeleton-type="register"
        />
        <HomeSectionSkeleton v-else type="register" />
      </template>
    </template>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import siteApi from "@/api/site";
import { useAppStore } from "@/stores/app";
import { t } from "@/locales";
import HomeHeroCarousel from "@/views/website/Home/components/HomeHeroCarousel.vue";
import HomeProductTabs from "@/views/website/Home/components/HomeProductTabs.vue";
import HomeSectionSkeleton from "@/views/website/Home/components/HomeSectionSkeleton.vue";
import DeferredHomeSection from "@/views/website/Home/components/DeferredHomeSection.vue";

// 延迟区块的 chunk loader 必须是模块级稳定引用：
// 模板里写内联箭头函数会让 DeferredHomeSection 每次渲染都重新解析一次。
const loadAiSkillSection = () =>
  import("@/views/website/Home/components/HomeAiSkillSection.vue");
const loadSolutionSection = () =>
  import("@/views/website/Home/components/HomeSolutionSection.vue");
const loadPartnerSection = () =>
  import("@/views/website/Home/components/HomePartnerSection.vue");
const loadNewsSection = () =>
  import("@/views/website/Home/components/HomeNewsSection.vue");
const loadRegisterBar = () =>
  import("@/views/website/Home/components/HomeRegisterBar.vue");

interface ProductTypeItem {
  id?: number;
  value?: string;
  label?: string;
  product_count?: number;
}

interface HomeSectionConfig {
  key: string;
  enabled: boolean;
  variant: string;
  order: number;
}

/**
 * 默认区块顺序。站点配置留空时按这个渲染。
 * 这里同时也是「合法 key 白名单」：配置里出现未知 key 直接丢弃，
 * 避免拼错一个单词就整块消失。
 */
const DEFAULT_SECTIONS: HomeSectionConfig[] = [
  { key: "hero", enabled: true, variant: "", order: 1 },
  { key: "products", enabled: true, variant: "tabs", order: 2 },
  { key: "ai_skill", enabled: true, variant: "", order: 3 },
  { key: "solutions", enabled: true, variant: "tabs", order: 4 },
  { key: "partners", enabled: true, variant: "", order: 5 },
  { key: "news", enabled: true, variant: "", order: 6 },
  { key: "register", enabled: true, variant: "", order: 7 },
];

const appStore = useAppStore();
const loading = ref(true);
const homeLoaded = ref(false);
const homeLoadSucceeded = ref(false);
const mountDeferredSections = ref(false);
const homeHero = ref<Record<string, unknown> | null>(null);
const notices = ref<any[]>([]);
const rootGroups = ref<any[]>([]);
const groupCatalogMap = ref<Record<string, unknown>>({});
const productTypes = ref<ProductTypeItem[]>([]);
const homeContentReady = computed(
  () => homeLoaded.value && homeLoadSucceeded.value,
);
const homeHeroReady = computed(() => {
  if (!homeContentReady.value) {
    return false;
  }
  const hero = homeHero.value;
  if (!hero || typeof hero !== "object") {
    return false;
  }
  const slides = hero.slides;
  // 后端未返回 slides 字段时 HomeHeroCarousel 会用内置默认轮播，
  // 同样算「已就绪」；否则页面会永远停在 Hero 骨架上。
  return slides === undefined || (Array.isArray(slides) && slides.length > 0);
});
const productContentReady = computed(() => homeContentReady.value);

/**
 * 解析站点配置的区块编排。
 * - 未知 key 丢弃
 * - 配置里没写到的已知区块追加到末尾（站长只写想挪的那几个即可，不用列全）
 * - JSON 非法时退回默认，首页不能因为一处配置拼错就空掉
 */
function resolveHomeSections(raw: unknown): HomeSectionConfig[] {
  const text = String(raw ?? "").trim();
  if (text === "") {
    return DEFAULT_SECTIONS;
  }

  let parsed: unknown;
  try {
    parsed = JSON.parse(text);
  } catch {
    return DEFAULT_SECTIONS;
  }

  if (!Array.isArray(parsed)) {
    return DEFAULT_SECTIONS;
  }

  const knownKeys = new Set(DEFAULT_SECTIONS.map((item) => item.key));
  const configured: HomeSectionConfig[] = [];
  const seen = new Set<string>();

  parsed.forEach((entry, index) => {
    if (!entry || typeof entry !== "object") {
      return;
    }

    const item = entry as Record<string, unknown>;
    const key = String(item.key ?? "").trim();

    if (!knownKeys.has(key) || seen.has(key)) {
      return;
    }

    seen.add(key);

    const order = Number(item.order);

    configured.push({
      key,
      enabled: item.enabled !== false,
      variant: String(item.variant ?? "").trim(),
      order: Number.isFinite(order) ? order : index + 1,
    });
  });

  configured.sort((a, b) => a.order - b.order);

  const missing = DEFAULT_SECTIONS.filter((item) => !seen.has(item.key)).map(
    (item, index) => ({ ...item, order: configured.length + index + 1 }),
  );

  return [...configured, ...missing];
}

const sectionConfig = computed(() =>
  resolveHomeSections(appStore.homeSections),
);

// 只渲染启用的区块
const renderedSections = computed(() =>
  sectionConfig.value.filter((section) => section.enabled),
);

// 延迟区块是否已经可以挂载：数据就绪 + 观察器已放行
const deferredReady = computed(
  () => homeContentReady.value && mountDeferredSections.value,
);


function deriveProductTypesFromGroups(groups: any[]) {
  const map = new Map<string, ProductTypeItem>();

  groups.forEach((group, index) => {
    const value = String(group?.first_product_group_code || "");
    if (!value) {
      return;
    }

    const current = map.get(value) || {
      id: Number(group?.product_type_id || index + 1),
      value,
      label: String(
        group?.first_product_group_name ||
          t("home.products.categoryFallback", { index: index + 1 }),
      ),
      product_count: 0,
    };

    current.product_count =
      Number(current.product_count || 0) + Number(group?.product_count || 0);
    map.set(value, current);
  });

  return Array.from(map.values());
}

function resolveHome() {
  // 优先消费 index.html 预热期 fetch 的 /v2/site/home 响应（window.__HOME_FETCH__），
  // 复用正常化管线；预热失败/无预热则回退常规 axios 请求。
  const prefetchPromise = (window as any).__HOME_FETCH__;
  if (!prefetchPromise) {
    return siteApi.home();
  }

  (window as any).__HOME_FETCH__ = undefined;
  return prefetchPromise.then((raw: any) => {
    if (raw && raw.code === 0 && raw.data && typeof raw.data === "object") {
      return siteApi.homeFromRaw(raw);
    }
    return siteApi.home();
  });
}

async function loadHomePage() {
  loading.value = true;
  homeLoaded.value = false;
  homeLoadSucceeded.value = false;

  const homeRes = await resolveHome().catch(() => undefined);
  if (homeRes?.data) {
    homeLoadSucceeded.value = true;
    const data = homeRes.data || {};
    notices.value = Array.isArray(data.notices) ? data.notices : [];
    rootGroups.value = Array.isArray(data.root_groups) ? data.root_groups : [];
    groupCatalogMap.value =
      data.group_catalog_map && typeof data.group_catalog_map === "object"
        ? data.group_catalog_map
        : {};

    // 从 home 响应中提取 hero 数据
    homeHero.value = data.hero || {};

    if (data.site_config) {
      appStore.hydrateSiteConfig(data.site_config);
    }
  }

  // 产品分类由 home 响应的 root_groups 推导，避免额外一次 /v2/site/product-types 请求
  productTypes.value = deriveProductTypesFromGroups(rootGroups.value);

  homeLoaded.value = true;
  loading.value = false;
}

function mountDeferredSectionsSoon() {
  const mount = () => {
    mountDeferredSections.value = true;
  };

  // 让出首屏渲染后再挂载延迟区块：这些组件的 chunk 本身就是按需 import，
  // 不会拖慢首屏；但若等「滚动到某处」才挂，骨架会长时间停在那里。
  // 先取到局部引用再判断，避免 typeof window 收窄把后续 window 变成 never。
  const host = typeof window === "undefined" ? null : window;

  if (!host) {
    mount();
    return;
  }

  const idleCallback = (host as unknown as {
    requestIdleCallback?: (cb: () => void, opts?: { timeout: number }) => void;
  }).requestIdleCallback;

  if (typeof idleCallback === "function") {
    idleCallback(mount, { timeout: 300 });
    return;
  }

  host.setTimeout(mount, 0);
}

onMounted(async () => {
  await loadHomePage();

  if (!homeLoadSucceeded.value) {
    return;
  }

  mountDeferredSectionsSoon();
});
</script>