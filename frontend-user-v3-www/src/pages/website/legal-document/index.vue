<template>
  <div class="legal-page">
    <div class="legal-card">
      <h1>{{ pageTitle }}</h1>
      <p class="legal-updated">{{ pageDescription }}</p>

      <div class="legal-actions">
        <!-- 站点配置未返回前先用按钮骨架占位，避免先闪「文档地址缺失」再出现按钮 -->
        <SkeletonBlock
          v-if="!appStore.brandingResolved"
          :width="132"
          :height="36"
          :radius="3"
        />
        <el-button v-else-if="targetUrl" type="primary" @click="openDocument">{{
          $t("content.legal.openOriginal")
        }}</el-button>
        <el-button @click="router.push('/')">{{
          $t("common.notFound.backHome")
        }}</el-button>
      </div>

      <!-- 站内默认正文：后台未配外链时直接可用；配置了外链则按钮作为官方原文入口 -->
      <article class="legal-body">
        <section
          v-for="section in sections"
          :key="section.title"
          class="legal-section"
        >
          <h2>{{ section.title }}</h2>
          <p v-for="(paragraph, index) in section.paragraphs" :key="index">
            {{ paragraph }}
          </p>
        </section>
      </article>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { useRoute, useRouter } from "vue-router";
import { useAppStore } from "@/stores/app";
import SkeletonBlock from "@/components/common/SkeletonBlock.vue";
import siteApi from "@/api/site";
import { t } from "@/locales";
import { buildLegalSections } from "@/content/legalDocuments";

const route = useRoute();
const router = useRouter();
const appStore = useAppStore();

const documentKey = computed(() => String(route.meta?.documentKey || "terms"));
const isPrivacy = computed(() => documentKey.value === "privacy");
const targetUrl = computed(() =>
  isPrivacy.value ? appStore.privacyUrl : appStore.termsUrl,
);
const pageTitle = computed(() =>
  isPrivacy.value ? t("seo.privacy.title") : t("seo.terms.title"),
);
const pageDescription = computed(() =>
  isPrivacy.value
    ? t("content.legal.privacyDescription")
    : t("content.legal.termsDescription"),
);

// 后台可维护的正文；接口不可用或后台没填时回落内置文案，页面不会开天窗
const remoteSections = ref<Array<{ title: string; paragraphs: string[] }> | null>(
  null,
);

const fallbackSections = computed(() =>
  buildLegalSections(documentKey.value === "privacy" ? "privacy" : "terms", {
    siteName: appStore.siteName,
    serviceEmail: appStore.serviceEmail,
    icpRecord: appStore.icpRecord,
    websiteOrigin: window.location.origin,
  }),
);

const sections = computed(() =>
  remoteSections.value && remoteSections.value.length > 0
    ? remoteSections.value
    : fallbackSections.value,
);

onMounted(async () => {
  try {
    const list = await siteApi.legal(
      documentKey.value === "privacy" ? "privacy" : "terms",
    );
    if (Array.isArray(list) && list.length > 0) {
      remoteSections.value = list;
    }
  } catch {
    // 接口不可用时保持内置文案，不做任何可见报错
  }
});

function openDocument() {
  if (!targetUrl.value) {
    return;
  }
  window.open(targetUrl.value, "_blank", "noopener,noreferrer");
}
</script>

<style scoped lang="scss">
.legal-page {
  min-height: 60vh;
  padding: 56px 20px;
  background: #f5f8fd;
}

.legal-card {
  width: min(860px, 100%);
  margin: 0 auto;
  padding: 36px 40px;
  border: 1px solid #e5eaf3;
  border-radius: 6px;
  background: #fff;
  box-shadow: 0 2px 10px rgba(15, 23, 42, 0.06);
}

.legal-card h1 {
  margin: 0;
  color: #0f172a;
}

.legal-updated {
  margin: 12px 0 0;
  color: #94a3b8;
  font-size: 13px;
}

.legal-actions {
  display: flex;
  gap: 12px;
  margin: 24px 0;
}

.legal-body {
  margin-top: 8px;
  border-top: 1px solid #eef2f8;
  padding-top: 8px;
}

.legal-section {
  margin-top: 20px;
}

.legal-section h2 {
  margin: 0 0 10px;
  color: #0f172a;
  font-size: 17px;
}

.legal-section p {
  margin: 8px 0 0;
  color: #475569;
  line-height: 1.9;
  font-size: 14px;
}

@media (max-width: 768px) {
  .legal-card {
    padding: 24px 18px;
  }
}
</style>
