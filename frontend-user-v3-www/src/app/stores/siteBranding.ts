import { computed, ref } from "vue";
import { defineStore } from "pinia";
import {
  applyDocumentTitle,
  deriveInitials,
  persistSplashBranding,
  readSplashBranding,
  syncDocumentTitle,
  updateFavicon,
} from "@turaidc/shared/runtime";
import siteApi from "@/api/site";
import { t } from "@/locales";
import { DEFAULT_SUPPORT_CONTACTS } from "@/data/supportContacts";
import { resolveApiAssetUrl } from "@/utils/apiAssetUrl";
import { applyBrandTokens } from "@/utils/brandTheme";
import { normalizeExternalLink } from "@/utils/externalLink";

// 站点配置接口是品牌唯一真源：不再读构建期 VITE_APP_TITLE 当兜底，否则服务器
// 本地 env 写死什么品牌，首访就会先闪什么品牌再被接口回包改掉。
// 未解析出品牌时用中性描述、空 logo / 空 favicon（中性占位），避免任何品牌闪烁。
const DEFAULT_SITE_NAME = t("common.site.slogan");
const DEFAULT_FAVICON = "";
const API_BASE_URL = String(import.meta.env.VITE_API_BASE_URL || "");

function normalizeDisplayMode(value) {
  return String(value || "")
    .trim()
    .toLowerCase() === "card"
    ? "card"
    : "list";
}

/** 站点后台的布尔开关统一是 '1' / '0'，缺省或脏值走调用方给的默认值 */
function normalizeSiteFlag(value, fallback: boolean): boolean {
  const normalized = String(value ?? "")
    .trim()
    .toLowerCase();

  if (["1", "true", "on", "yes"].includes(normalized)) {
    return true;
  }

  if (["0", "false", "off", "no"].includes(normalized)) {
    return false;
  }

  return fallback;
}

/** 选购页版心：contained=居中限宽，full=铺满；未知值回落居中 */
function normalizePageLayout(value) {
  return String(value || "")
    .trim()
    .toLowerCase() === "full"
    ? "full"
    : "contained";
}

/** 版心最大宽度夹在可读区间，避免后台写脏值后把机型表挤坏或等于没限制 */
function normalizePageMaxWidth(value, fallback = 1280) {
  const width = Number(String(value ?? "").trim());

  if (!Number.isFinite(width) || width <= 0) {
    return fallback;
  }

  return Math.min(Math.max(Math.round(width), 960), 1920);
}

function pick(raw, keys, fallback) {
  for (const key of keys) {
    const value = raw?.[key];
    if (value !== undefined && value !== null && String(value).trim() !== "") {
      return String(value).trim();
    }
  }
  return fallback;
}

function readBootstrappedSiteConfig() {
  if (typeof window === "undefined") {
    return {};
  }

  return window.__CW_SITE_CONFIG__ &&
    typeof window.__CW_SITE_CONFIG__ === "object"
    ? window.__CW_SITE_CONFIG__
    : {};
}

/**
 * 上一次访问留下的站点配置缓存。index.html 的注入脚本在首帧前用它覆盖
 * 标题与 favicon；这里把它作为 store 的初值，logo / 站点名也一并即时生效，
 * 避免首屏渲染完再被接口回包改写造成的品牌闪烁。
 */
function readCachedSiteConfig() {
  if (typeof window === "undefined") {
    return {};
  }

  return readSplashBranding();
}

export const useSiteBrandingStore = defineStore("site-branding", () => {
  const bootstrappedSiteConfig = readBootstrappedSiteConfig();
  const cachedSiteConfig = readCachedSiteConfig();
  // 引导注入（SEO 动态渲染）优先，其次是上次访问的缓存，最后才是构建期默认值。
  const initialSiteConfig = { ...cachedSiteConfig, ...bootstrappedSiteConfig };
  const sidebarCollapsed = ref(false);
  // 品牌是否已解析（缓存/引导注入/接口回包任一来源）。未解析前不渲染默认 logo 与
  // 兜底站点名，否则首访会先闪一下构建期默认品牌再变成站点配置。
  const brandingResolved = ref(
    Boolean(
      String(
        initialSiteConfig.site_name ||
          initialSiteConfig.browser_title ||
          initialSiteConfig.site_logo ||
          "",
      ).trim(),
    ),
  );
  const siteName = ref(String(initialSiteConfig.site_name || "").trim());
  const browserTitle = ref(
    String(initialSiteConfig.browser_title || "").trim(),
  );
  const siteLogo = ref(
    String(
      resolveApiAssetUrl(
        String(initialSiteConfig.site_logo || "").trim(),
        API_BASE_URL,
      ) || "",
    ),
  );
  const siteFavicon = ref(
    String(
      resolveApiAssetUrl(
        String(initialSiteConfig.site_favicon || "").trim(),
        API_BASE_URL,
      ) || "",
    ),
  );
  const serviceQqGroup = ref(
    pick(
      initialSiteConfig,
      ["service_qq_group", "serviceQqGroup"],
      DEFAULT_SUPPORT_CONTACTS.qqGroup,
    ),
  );
  const serviceEmail = ref(
    pick(
      initialSiteConfig,
      ["service_email", "serviceEmail"],
      DEFAULT_SUPPORT_CONTACTS.email,
    ),
  );
  const serviceHours = ref(
    pick(
      initialSiteConfig,
      ["service_hours", "serviceHours"],
      DEFAULT_SUPPORT_CONTACTS.hours,
    ),
  );
  const supportGroupTitle = ref(
    pick(initialSiteConfig, ["support_group_title", "supportGroupTitle"], ""),
  );
  const supportGroupText = ref(
    pick(initialSiteConfig, ["support_group_text", "supportGroupText"], ""),
  );
  const supportGroupQr = ref(
    resolveApiAssetUrl(
      pick(
        initialSiteConfig,
        ["support_group_qr", "supportGroupQr"],
        DEFAULT_SUPPORT_CONTACTS.groupQr,
      ),
      API_BASE_URL,
    ),
  );
  const supportGroupLink = ref(
    normalizeExternalLink(
      pick(initialSiteConfig, ["support_group_link", "supportGroupLink"], ""),
    ),
  );
  const termsUrl = ref(pick(initialSiteConfig, ["terms_url"], ""));
  const privacyUrl = ref(pick(initialSiteConfig, ["privacy_url"], ""));
  const icpRecord = ref(
    pick(
      initialSiteConfig,
      ["icp_record", "icpRecord"],
      String(import.meta.env.VITE_ICP_RECORD || ""),
    ),
  );
  const valueAddedLicense = ref(
    pick(
      initialSiteConfig,
      [
        "value_added_license",
        "valueAddedLicense",
        "value_added_telecom_license",
      ],
      String(import.meta.env.VITE_VALUE_ADDED_LICENSE || ""),
    ),
  );
  // 全站商品展示方式：list=商品表格（列表式）/ card=商品卡片（卡片式）。
  // 只存后台设定的站点默认值；访客自己的切换由 useProductDisplayMode 存
  // localStorage 覆盖，不写进 store，避免把「运营设定的默认」和「本机偏好」混成一个状态。
  const productDisplayMode = ref(
    normalizeDisplayMode(pick(initialSiteConfig, ["product_display_mode"], "")),
  );
  // 选购页展示层开关：卡片是否展示库存、独立配置页是否展示机型对比、版心与最大宽度。
  // 这些是站点经营方的决策，不提供用户级覆盖（展示方式例外，见上）。
  const productCardShowStock = ref(
    normalizeSiteFlag(
      pick(initialSiteConfig, ["product_card_show_stock"], ""),
      true,
    ),
  );
  const productDetailSpecCompare = ref(
    normalizeSiteFlag(
      pick(initialSiteConfig, ["product_detail_spec_compare"], ""),
      true,
    ),
  );
  const productPageLayout = ref(
    normalizePageLayout(pick(initialSiteConfig, ["product_page_layout"], "")),
  );
  const productPageMaxWidth = ref(
    normalizePageMaxWidth(
      pick(initialSiteConfig, ["product_page_max_width"], ""),
    ),
  );
  const brandInitials = computed(() => deriveInitials(siteName.value));

  let fetchPromise = null;

  function toggleSidebar() {
    sidebarCollapsed.value = !sidebarCollapsed.value;
  }

  function applyPageTitle(pageTitle = "") {
    const baseTitle = browserTitle.value || siteName.value || DEFAULT_SITE_NAME;
    applyDocumentTitle(
      pageTitle,
      baseTitle,
      siteFavicon.value || DEFAULT_FAVICON,
      DEFAULT_FAVICON,
    );
  }

  function hydrateSiteConfig(data = {}) {
    const previousBaseTitle =
      browserTitle.value || siteName.value || DEFAULT_SITE_NAME;
    siteName.value = pick(
      data,
      ["site_name"],
      siteName.value || DEFAULT_SITE_NAME,
    );
    browserTitle.value = pick(
      data,
      ["browser_title"],
      siteName.value || DEFAULT_SITE_NAME,
    );
    siteLogo.value = resolveApiAssetUrl(
      pick(data, ["site_logo"], siteLogo.value || ""),
      API_BASE_URL,
    );
    siteFavicon.value = resolveApiAssetUrl(
      pick(data, ["site_favicon"], siteFavicon.value || ""),
      API_BASE_URL,
    );
    serviceQqGroup.value = pick(
      data,
      ["service_qq_group", "serviceQqGroup", "service_phone", "servicePhone"],
      serviceQqGroup.value || DEFAULT_SUPPORT_CONTACTS.qqGroup,
    );
    serviceEmail.value = pick(
      data,
      ["service_email", "serviceEmail"],
      serviceEmail.value || DEFAULT_SUPPORT_CONTACTS.email,
    );
    serviceHours.value = pick(
      data,
      ["service_hours", "serviceHours"],
      serviceHours.value || DEFAULT_SUPPORT_CONTACTS.hours,
    );
    supportGroupTitle.value = pick(
      data,
      ["support_group_title", "supportGroupTitle"],
      supportGroupTitle.value || "",
    );
    supportGroupText.value = pick(
      data,
      ["support_group_text", "supportGroupText"],
      supportGroupText.value || "",
    );
    supportGroupQr.value = resolveApiAssetUrl(
      pick(
        data,
        ["support_group_qr", "supportGroupQr"],
        supportGroupQr.value || DEFAULT_SUPPORT_CONTACTS.groupQr,
      ),
      API_BASE_URL,
    );
    supportGroupLink.value = normalizeExternalLink(
      pick(
        data,
        ["support_group_link", "supportGroupLink"],
        supportGroupLink.value || "",
      ),
    );
    termsUrl.value = pick(data, ["terms_url"], termsUrl.value || "");
    privacyUrl.value = pick(data, ["privacy_url"], privacyUrl.value || "");
    icpRecord.value = pick(
      data,
      ["icp_record", "icpRecord"],
      icpRecord.value || "",
    );
    valueAddedLicense.value = pick(
      data,
      [
        "value_added_license",
        "valueAddedLicense",
        "value_added_telecom_license",
      ],
      valueAddedLicense.value || "",
    );
    productDisplayMode.value = normalizeDisplayMode(
      pick(data, ["product_display_mode"], productDisplayMode.value),
    );
    productCardShowStock.value = normalizeSiteFlag(
      pick(data, ["product_card_show_stock"], ""),
      productCardShowStock.value,
    );
    productDetailSpecCompare.value = normalizeSiteFlag(
      pick(data, ["product_detail_spec_compare"], ""),
      productDetailSpecCompare.value,
    );
    productPageLayout.value = normalizePageLayout(
      pick(data, ["product_page_layout"], productPageLayout.value),
    );
    productPageMaxWidth.value = normalizePageMaxWidth(
      pick(data, ["product_page_max_width"], ""),
      productPageMaxWidth.value,
    );
    syncDocumentTitle(
      browserTitle.value || siteName.value || DEFAULT_SITE_NAME,
      previousBaseTitle,
      DEFAULT_SITE_NAME,
    );
    if (siteFavicon.value) {
      updateFavicon(siteFavicon.value, "");
    }

    // 品牌主色与圆角：SSR 页面由后端内联 style 注入（首屏无闪烁），
    // SPA 直达路由没有那段注入，这里补上，否则会停在 sass 编译期的默认色。
    applyBrandTokens(
      pick(data, ["brand_primary_color", "brandPrimaryColor"], ""),
      pick(data, ["brand_radius", "brandRadius"], ""),
    );
    // 缓存给下一次首帧用：index.html 的注入脚本在 Vue 挂载前就渲染完了，
    // 拿不到这里的配置，只能靠上一次访问留下的站点名 / logo / favicon。
    brandingResolved.value = true;
    persistSplashBranding({
      site_name: siteName.value,
      browser_title: browserTitle.value,
      site_logo: siteLogo.value,
      site_favicon: siteFavicon.value,
    });
  }

  async function fetchSiteConfig() {
    if (fetchPromise) {
      return fetchPromise;
    }

    fetchPromise = siteApi
      .config()
      .then((res) => {
        hydrateSiteConfig(res.data || {});
      })
      .catch(() => {
        // 接口失败时保持中性：不塞默认 logo / favicon；站点名用中性描述，
        // 至少标题与页头不是空白，但绝不闪出与站点无关的品牌。
        siteName.value = siteName.value || DEFAULT_SITE_NAME;
        browserTitle.value = browserTitle.value || DEFAULT_SITE_NAME;
        brandingResolved.value = true;
      })
      .finally(() => {
        if (siteFavicon.value) {
          updateFavicon(siteFavicon.value, "");
        }
        fetchPromise = null;
      });

    return fetchPromise;
  }

  return {
    sidebarCollapsed,
    brandingResolved,
    siteName,
    browserTitle,
    siteLogo,
    siteFavicon,
    serviceQqGroup,
    serviceEmail,
    serviceHours,
    supportGroupTitle,
    supportGroupText,
    supportGroupQr,
    supportGroupLink,
    termsUrl,
    privacyUrl,
    icpRecord,
    valueAddedLicense,
    productDisplayMode,
    productCardShowStock,
    productDetailSpecCompare,
    productPageLayout,
    productPageMaxWidth,
    brandInitials,
    toggleSidebar,
    applyPageTitle,
    hydrateSiteConfig,
    fetchSiteConfig,
  };
});
