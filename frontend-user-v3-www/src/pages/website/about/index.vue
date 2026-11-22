<template>
  <div class="about-page">
    <section class="about-hero">
      <div class="container">
        <p class="about-kicker">{{ $t("layout.nav.aboutUs") }}</p>
        <!-- H1 描述页面本身而非站点名；与后端 SEO 快照（renderStaticBody）口径一致 -->
        <h1>{{ $t("layout.nav.aboutUs") }}</h1>
        <p class="about-desc">
          {{ $t("content.about.intro") }}
        </p>
        <p class="about-platform">
          {{ $t("content.about.platformIntro") }}
        </p>
      </div>
    </section>

    <section class="about-section">
      <div class="container">
        <div class="about-grid">
          <article class="about-card">
            <h2>{{ $t("content.about.openSourceTitle") }}</h2>
            <p>{{ $t("content.about.openSourceDesc") }}</p>
          </article>
          <article class="about-card">
            <h2>{{ $t("content.about.opsCommitmentTitle") }}</h2>
            <p>{{ $t("content.about.opsCommitmentDesc") }}</p>
          </article>
          <article class="about-card">
            <h2>{{ $t("content.about.supportCommitmentTitle") }}</h2>
            <p>{{ $t("content.about.supportCommitmentDesc") }}</p>
          </article>
          <article class="about-card">
            <h2>{{ $t("content.about.serviceCapabilities") }}</h2>
            <p>{{ $t("content.about.serviceCapabilitiesDesc") }}</p>
          </article>
        </div>

        <div class="about-grid">
          <article class="about-card">
            <h2>{{ $t("content.about.contactInformation") }}</h2>
            <p>
              {{ $t("content.about.serviceEmailLabel") }}
              <SkeletonBlock
                v-if="!appStore.brandingResolved"
                class="about-pending-value"
                :width="180"
                :height="14"
              />
              <template v-else>{{
                appStore.serviceEmail || $t("content.about.notConfigured")
              }}</template>
            </p>
            <p>
              {{ $t("content.about.serviceHoursLabel") }}
              <SkeletonBlock
                v-if="!appStore.brandingResolved"
                class="about-pending-value"
                :width="150"
                :height="14"
              />
              <template v-else>{{
                appStore.serviceHours ||
                $t("content.about.serviceHoursFallback")
              }}</template>
            </p>
            <p>
              {{ $t("content.about.officialGroupLabel") }}
              <SkeletonBlock
                v-if="!appStore.brandingResolved"
                class="about-pending-value"
                :width="120"
                :height="14"
              />
              <template v-else>
                <a
                  v-if="appStore.supportGroupLink"
                  :href="appStore.supportGroupLink"
                  target="_blank"
                  rel="noopener noreferrer"
                  >{{
                    appStore.serviceQqGroup || $t("content.about.notConfigured")
                  }}</a
                >
                <span v-else>{{
                  appStore.serviceQqGroup || $t("content.about.notConfigured")
                }}</span>
              </template>
            </p>
          </article>
        </div>
      </div>
    </section>
  </div>
</template>

<script setup lang="ts">
import { useAppStore } from "@/stores/app";
import SkeletonBlock from "@/components/common/SkeletonBlock.vue";

const appStore = useAppStore();
</script>

<style scoped lang="scss">
.container {
  width: min(1200px, calc(100% - 48px));
  margin: 0 auto;
}

.about-hero {
  padding: 64px 0 32px;
  background: linear-gradient(180deg, #fff 0%, #f5f8fd 100%);
}

.about-kicker {
  margin: 0;
  color: $color-primary;
  font-weight: 600;
}

h1 {
  margin: 12px 0 0;
  color: #0f172a;
  font-size: 40px;
}

.about-desc {
  max-width: 720px;
  margin-top: 16px;
  color: #64748b;
  line-height: 1.9;
}

.about-platform {
  max-width: 720px;
  margin-top: 12px;
  color: #475569;
  line-height: 1.9;
  font-size: 15px;
}

.about-section {
  padding: 24px 0 72px;
}

.about-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 20px;

  & + .about-grid {
    margin-top: 20px;
  }
}

@media (max-width: 768px) {
  .about-grid {
    grid-template-columns: 1fr;
  }
}

.about-card {
  padding: 28px;
  border: 1px solid #e5eaf3;
  border-radius: 6px;
  background: #fff;
  box-shadow: 0 2px 10px rgba(15, 23, 42, 0.06);
}

.about-card h2 {
  margin: 0 0 12px;
  color: #0f172a;
}

.about-card p {
  margin: 8px 0 0;
  color: #64748b;
  line-height: 1.8;
}

/* 配置未返回时的行内骨架：与正文同高，避免「未配置」闪一下再变真实值 */
.about-pending-value {
  display: inline-block;
  vertical-align: -2px;
}
</style>
