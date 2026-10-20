<template>
  <div class="open-source-page">
    <!-- 首屏：与关于页同一套排版口径（浅色渐变 + 居中容器 + 大标题） -->
    <section class="os-hero">
      <div class="container">
        <p class="os-kicker">{{ $t("openSource.kicker") }}</p>
        <h1>{{ $t("openSource.title") }}</h1>
        <p class="os-subtitle">{{ $t("openSource.subtitle") }}</p>
        <div class="os-actions">
          <a
            :href="repositoryUrl"
            class="os-btn os-btn--primary"
            target="_blank"
            rel="noopener noreferrer"
          >
            {{ $t("openSource.source.repoTitle") }}
          </a>
          <a
            :href="deployDocsUrl"
            class="os-btn os-btn--ghost"
            target="_blank"
            rel="noopener noreferrer"
          >
            {{ $t("openSource.source.docsLink") }}
          </a>
        </div>
      </div>
    </section>

    <!-- 两条路径对比 -->
    <section class="os-section">
      <div class="container os-paths">
        <article class="os-card os-path os-path--free">
          <span class="os-badge">{{ $t("openSource.agpl.title") }}</span>
          <p class="os-path__desc">{{ $t("openSource.agpl.desc") }}</p>
          <h3 class="os-path__title">
            {{ $t("openSource.agpl.rightsTitle") }}
          </h3>
          <ul class="os-list">
            <li>{{ $t("openSource.agpl.right1") }}</li>
            <li>{{ $t("openSource.agpl.right2") }}</li>
            <li>{{ $t("openSource.agpl.right3") }}</li>
          </ul>
        </article>

        <article class="os-card os-path os-path--commercial">
          <span class="os-badge os-badge--dark">{{
            $t("openSource.commercial.title")
          }}</span>
          <p class="os-path__desc">{{ $t("openSource.commercial.desc") }}</p>
          <h3 class="os-path__title">
            {{ $t("openSource.commercial.grantTitle") }}
          </h3>
          <ul class="os-list">
            <li>{{ $t("openSource.commercial.priceDesc") }}</li>
            <li>{{ $t("openSource.commercial.grantDesc") }}</li>
            <li>{{ $t("openSource.commercial.excludeDesc") }}</li>
          </ul>
        </article>
      </div>
    </section>

    <!-- AGPL 免费路径的强制义务：面向站长/企业的合规重点 -->
    <section class="os-section os-section--muted">
      <div class="container">
        <header class="os-heading">
          <h2>{{ $t("openSource.obligations.title") }}</h2>
        </header>
        <div class="os-obligations">
          <article class="os-obligation">
            <h3>{{ $t("openSource.obligations.networkTitle") }}</h3>
            <p>{{ $t("openSource.obligations.networkDesc") }}</p>
          </article>
          <article class="os-obligation">
            <h3>{{ $t("openSource.obligations.noCloseTitle") }}</h3>
            <p>{{ $t("openSource.obligations.noCloseDesc") }}</p>
          </article>
          <article class="os-obligation">
            <h3>{{ $t("openSource.obligations.noticeTitle") }}</h3>
            <p>{{ $t("openSource.obligations.noticeDesc") }}</p>
          </article>
          <article class="os-obligation">
            <h3>{{ $t("openSource.obligations.depsTitle") }}</h3>
            <p>{{ $t("openSource.obligations.depsDesc") }}</p>
          </article>
        </div>
      </div>
    </section>

    <!-- AGPL 第 13 条的两个实操场景 -->
    <section class="os-section">
      <div class="container">
        <header class="os-heading">
          <h2>{{ $t("openSource.section13.title") }}</h2>
        </header>
        <div class="os-cases">
          <article class="os-case">
            <span class="os-case__index">1</span>
            <div>
              <h3>{{ $t("openSource.section13.unmodifiedTitle") }}</h3>
              <p>{{ $t("openSource.section13.unmodifiedDesc") }}</p>
            </div>
          </article>
          <article class="os-case">
            <span class="os-case__index">2</span>
            <div>
              <h3>{{ $t("openSource.section13.modifiedTitle") }}</h3>
              <p>{{ $t("openSource.section13.modifiedDesc") }}</p>
            </div>
          </article>
        </div>
      </div>
    </section>

    <!-- 源码获取方式 -->
    <section class="os-section os-section--muted">
      <div class="container">
        <header class="os-heading">
          <h2>{{ $t("openSource.source.title") }}</h2>
          <p class="os-heading__desc">{{ $t("openSource.source.desc") }}</p>
        </header>
        <div class="os-source-grid">
          <article class="os-card os-source-card">
            <h3>{{ $t("openSource.source.repoTitle") }}</h3>
            <p>{{ $t("openSource.source.repoDesc") }}</p>
            <a
              :href="repositoryUrl"
              class="os-link"
              target="_blank"
              rel="noopener noreferrer"
              >github.com/25Cloud/TuraIDC</a
            >
          </article>
          <article class="os-card os-source-card">
            <h3>{{ $t("openSource.source.cloneTitle") }}</h3>
            <p>{{ $t("openSource.source.cloneDesc") }}</p>
            <code class="os-code"
              >git clone https://github.com/25Cloud/TuraIDC.git</code
            >
          </article>
          <article class="os-card os-source-card">
            <h3>{{ $t("openSource.source.releaseTitle") }}</h3>
            <p>{{ $t("openSource.source.releaseDesc") }}</p>
            <a
              :href="releasesUrl"
              class="os-link"
              target="_blank"
              rel="noopener noreferrer"
              >{{ releasesUrl }}</a
            >
          </article>
          <article class="os-card os-source-card">
            <h3>{{ $t("openSource.source.docsTitle") }}</h3>
            <p>{{ $t("openSource.source.docsDesc") }}</p>
            <a
              :href="deployDocsUrl"
              class="os-link"
              target="_blank"
              rel="noopener noreferrer"
              >{{ $t("openSource.source.docsLink") }}</a
            >
          </article>
        </div>
      </div>
    </section>

    <!-- 商业许可细节 -->
    <section class="os-section">
      <div class="container">
        <header class="os-heading">
          <h2>{{ $t("openSource.commercial.title") }}</h2>
          <p class="os-heading__desc">{{ $t("openSource.commercial.desc") }}</p>
        </header>
        <div class="os-commercial">
          <div class="os-commercial__row">
            <h3>{{ $t("openSource.commercial.priceTitle") }}</h3>
            <p>{{ $t("openSource.commercial.priceDesc") }}</p>
          </div>
          <div class="os-commercial__row">
            <h3>{{ $t("openSource.commercial.grantTitle") }}</h3>
            <p>{{ $t("openSource.commercial.grantDesc") }}</p>
          </div>
          <div class="os-commercial__row">
            <h3>{{ $t("openSource.commercial.excludeTitle") }}</h3>
            <p>{{ $t("openSource.commercial.excludeDesc") }}</p>
          </div>
          <div class="os-commercial__row">
            <h3>{{ $t("openSource.commercial.howTitle") }}</h3>
            <p>{{ $t("openSource.commercial.howDesc") }}</p>
          </div>
        </div>
      </div>
    </section>

    <!-- 商业授权联系方式：邮箱来自站点配置，未解析完成时用骨架占位（不闪空白、不用遮罩转圈） -->
    <section class="os-section os-section--muted">
      <div class="container">
        <div class="os-card os-contact">
          <div>
            <h2>{{ $t("openSource.contact.title") }}</h2>
            <p class="os-contact__desc">{{ $t("openSource.contact.desc") }}</p>
          </div>
          <dl class="os-contact__list">
            <div class="os-contact__item">
              <dt>{{ $t("openSource.contact.emailLabel") }}</dt>
              <dd>
                <span
                  v-if="contactLoading"
                  class="os-skeleton"
                  aria-hidden="true"
                ></span>
                <a v-else-if="contactEmail" :href="`mailto:${contactEmail}`">{{
                  contactEmail
                }}</a>
                <span v-else class="os-muted">{{
                  $t("openSource.contact.emailFallback")
                }}</span>
              </dd>
            </div>
            <div class="os-contact__item">
              <dt>{{ $t("openSource.contact.issuesLabel") }}</dt>
              <dd>
                <a
                  :href="issuesUrl"
                  class="os-link"
                  target="_blank"
                  rel="noopener noreferrer"
                  >{{ $t("openSource.contact.issuesLink") }}</a
                >
              </dd>
            </div>
          </dl>
        </div>
        <p class="os-note">{{ $t("openSource.contact.note") }}</p>
        <p class="os-note os-note--legal">{{ $t("openSource.notice") }}</p>
      </div>
    </section>
  </div>
</template>

<script setup lang="ts">
import { computed } from "vue";
import { useAppStore } from "@/stores/app";

// 仓库与文档地址：与 README、页脚入口保持同一口径，改地址时三处一起改。
const repositoryUrl = "https://github.com/25Cloud/TuraIDC";
const releasesUrl = `${repositoryUrl}/releases`;
const issuesUrl = `${repositoryUrl}/issues`;
const deployDocsUrl = "https://docs.turaidc.com/guide/deploy/generic";

const appStore = useAppStore();

// 商业授权联系邮箱优先取站点配置；品牌配置尚未解析时显示骨架，避免先闪「待公布」再跳成邮箱。
const contactEmail = computed(() => String(appStore.serviceEmail || "").trim());
const contactLoading = computed(() => !appStore.brandingResolved);
</script>

<style scoped lang="scss">
.container {
  width: min(1200px, calc(100% - 48px));
  margin: 0 auto;
}

.os-hero {
  padding: 64px 0 40px;
  background: linear-gradient(180deg, #fff 0%, #f5f8fd 100%);
}

.os-kicker {
  margin: 0;
  color: $color-primary;
  font-weight: 600;
}

h1 {
  margin: 12px 0 0;
  color: #0f172a;
  font-size: 40px;
}

.os-subtitle {
  max-width: 780px;
  margin-top: 16px;
  color: #64748b;
  line-height: 1.9;
}

.os-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
  margin-top: 28px;
}

.os-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  height: 40px;
  padding: 0 20px;
  border-radius: 6px;
  font-size: 14px;
  font-weight: 500;
  text-decoration: none;
  transition:
    background 0.16s ease,
    color 0.16s ease,
    border-color 0.16s ease;
}

.os-btn--primary {
  background: $color-primary;
  color: #fff;

  &:hover {
    background: #0e4fcc;
  }
}

.os-btn--ghost {
  border: 1px solid #cbd5e1;
  color: #334155;

  &:hover {
    border-color: $color-primary;
    color: $color-primary;
  }
}

.os-section {
  padding: 32px 0 56px;

  &--muted {
    background: #f4f7fc;
  }
}

.os-heading {
  margin-bottom: 24px;

  h2 {
    margin: 0;
    color: #0f172a;
    font-size: 28px;
  }
}

.os-heading__desc {
  max-width: 780px;
  margin: 12px 0 0;
  color: #64748b;
  line-height: 1.8;
}

.os-paths {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 20px;
}

.os-card {
  padding: 28px;
  border: 1px solid #e5eaf3;
  border-radius: 6px;
  background: #fff;
  box-shadow: 0 2px 10px rgba(15, 23, 42, 0.06);
}

.os-path {
  display: flex;
  flex-direction: column;
  gap: 12px;

  &--free {
    border-top: 3px solid $color-primary;
  }

  &--commercial {
    border-top: 3px solid #0f172a;
  }
}

.os-badge {
  align-self: flex-start;
  padding: 4px 10px;
  border-radius: 999px;
  background: rgba(22, 93, 255, 0.1);
  color: $color-primary;
  font-size: 12px;
  font-weight: 600;

  &--dark {
    background: rgba(15, 23, 42, 0.08);
    color: #0f172a;
  }
}

.os-path__desc {
  margin: 0;
  color: #64748b;
  line-height: 1.8;
}

.os-path__title {
  margin: 8px 0 0;
  color: #0f172a;
  font-size: 16px;
}

.os-list {
  margin: 0;
  padding-left: 20px;
  color: #475569;
  line-height: 1.9;
}

.os-obligations {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 16px;
}

.os-obligation {
  padding: 22px 24px;
  border: 1px solid #e5eaf3;
  border-left: 3px solid #ef4444;
  border-radius: 6px;
  background: #fff;

  h3 {
    margin: 0;
    color: #0f172a;
    font-size: 16px;
  }

  p {
    margin: 10px 0 0;
    color: #64748b;
    line-height: 1.8;
  }
}

.os-cases {
  display: grid;
  gap: 16px;
}

.os-case {
  display: flex;
  gap: 16px;
  padding: 22px 24px;
  border: 1px solid #e5eaf3;
  border-radius: 6px;
  background: #fff;

  h3 {
    margin: 0;
    color: #0f172a;
    font-size: 16px;
  }

  p {
    margin: 10px 0 0;
    color: #64748b;
    line-height: 1.8;
  }
}

.os-case__index {
  flex-shrink: 0;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 28px;
  height: 28px;
  border-radius: 50%;
  background: rgba(22, 93, 255, 0.1);
  color: $color-primary;
  font-size: 13px;
  font-weight: 600;
}

.os-source-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 16px;
}

.os-source-card {
  display: flex;
  flex-direction: column;
  gap: 10px;

  h3 {
    margin: 0;
    color: #0f172a;
    font-size: 16px;
  }

  p {
    margin: 0;
    color: #64748b;
    line-height: 1.8;
  }
}

.os-link {
  color: $color-primary;
  text-decoration: none;
  word-break: break-all;

  &:hover {
    text-decoration: underline;
  }
}

.os-code {
  display: block;
  padding: 10px 12px;
  border-radius: 6px;
  background: #0f172a;
  color: #e2e8f0;
  font-size: 12px;
  line-height: 1.7;
  overflow-x: auto;
  white-space: nowrap;
}

.os-commercial {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 16px;
}

.os-commercial__row {
  padding: 22px 24px;
  border: 1px solid #e5eaf3;
  border-radius: 6px;
  background: #fff;

  h3 {
    margin: 0;
    color: #0f172a;
    font-size: 16px;
  }

  p {
    margin: 10px 0 0;
    color: #64748b;
    line-height: 1.8;
  }
}

.os-contact {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-start;
  justify-content: space-between;
  gap: 24px;

  h2 {
    margin: 0;
    color: #0f172a;
    font-size: 24px;
  }
}

.os-contact__desc {
  margin: 10px 0 0;
  color: #64748b;
  line-height: 1.8;
}

.os-contact__list {
  display: grid;
  gap: 14px;
  margin: 0;
  min-width: 260px;
}

.os-contact__item {
  display: grid;
  grid-template-columns: 96px minmax(0, 1fr);
  gap: 12px;
  align-items: center;

  dt {
    color: #94a3b8;
    font-size: 13px;
  }

  dd {
    margin: 0;
    color: #0f172a;
    font-size: 14px;
    font-weight: 500;
    word-break: break-all;
  }
}

.os-muted {
  color: #94a3b8;
  font-weight: 400;
}

.os-note {
  margin: 16px 0 0;
  color: #94a3b8;
  font-size: 12px;
  line-height: 1.8;
}

.os-note--legal {
  margin-top: 8px;
}

/* 骨架占位：与首页骨架同款扫光，尺寸贴合一行文本，解析完成后原位替换 */
.os-skeleton {
  display: inline-block;
  width: 180px;
  height: 14px;
  border-radius: 999px;
  background: linear-gradient(
    90deg,
    rgba(222, 230, 242, 0.92) 0%,
    rgba(245, 248, 252, 0.98) 50%,
    rgba(222, 230, 242, 0.92) 100%
  );
  background-size: 200% 100%;
  animation: os-skeleton-shimmer 1.4s ease-out infinite;
}

@keyframes os-skeleton-shimmer {
  0% {
    background-position: 100% 50%;
  }

  100% {
    background-position: 0 50%;
  }
}

@media (prefers-reduced-motion: reduce) {
  .os-skeleton {
    animation: none;
  }
}

@media (max-width: 960px) {
  .os-paths,
  .os-obligations,
  .os-source-grid,
  .os-commercial {
    grid-template-columns: 1fr;
  }

  .os-contact {
    flex-direction: column;
  }

  .os-contact__list {
    width: 100%;
  }
}

@media (max-width: 640px) {
  .container {
    width: calc(100% - 24px);
  }

  .os-hero {
    padding: 44px 0 28px;
  }

  h1 {
    font-size: 30px;
  }

  .os-heading h2 {
    font-size: 22px;
  }

  .os-card,
  .os-obligation,
  .os-case,
  .os-commercial__row {
    padding: 20px;
  }

  .os-contact__item {
    grid-template-columns: 76px minmax(0, 1fr);
  }
}
</style>
