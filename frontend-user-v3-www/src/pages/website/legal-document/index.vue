<template>
  <div class="legal-page">
    <div class="legal-card">
      <h1>{{ pageTitle }}</h1>
      <p>{{ pageDescription }}</p>

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

      <el-empty
        v-if="appStore.brandingResolved && !targetUrl"
        :description="$t('content.legal.documentUrlMissing')"
        :image-size="72"
      />
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed } from "vue";
import { useRoute, useRouter } from "vue-router";
import { useAppStore } from "@/stores/app";
import SkeletonBlock from "@/components/common/SkeletonBlock.vue";
import { t } from "@/locales";

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
  display: grid;
  place-items: center;
  padding: 56px 20px;
  background: #f5f8fd;
}

.legal-card {
  width: min(760px, 100%);
  padding: 36px 32px;
  border: 1px solid #e5eaf3;
  border-radius: 6px;
  background: #fff;
  box-shadow: 0 2px 10px rgba(15, 23, 42, 0.06);
}

.legal-card h1 {
  margin: 0;
  color: #0f172a;
}

.legal-card p {
  margin: 16px 0 0;
  color: #64748b;
  line-height: 1.8;
}

.legal-actions {
  display: flex;
  gap: 12px;
  margin: 24px 0;
}
</style>
