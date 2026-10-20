<template>
  <div
    class="page-skeleton"
    :class="[
      `page-skeleton--${variant}`,
      { 'page-skeleton--with-header': withHeader },
    ]"
    role="status"
    aria-live="polite"
    aria-busy="true"
    :aria-label="$t('common.loading')"
  >
    <!-- 整页首次加载（Vue 布局组件尚未挂载）时补一条顶栏骨架，尺寸对齐真实 header -->
    <div v-if="withHeader" class="page-skeleton__topbar" aria-hidden="true">
      <div class="page-skeleton__container page-skeleton__topbar-inner">
        <SkeletonBlock :width="148" :height="32" />
        <div class="page-skeleton__nav">
          <SkeletonBlock
            v-for="(width, index) in navWidths"
            :key="`nav-${index}`"
            :width="width"
            :height="14"
            radius="999"
          />
        </div>
        <div class="page-skeleton__topbar-actions">
          <SkeletonBlock :width="64" :height="34" radius="3" />
          <SkeletonBlock :width="96" :height="34" radius="3" />
        </div>
      </div>
    </div>

    <!-- 首页：Hero（左侧目录轨 + 标题区 + 五项能力条）+ 产品区块 -->
    <template v-if="variant === 'home'">
      <section class="page-skeleton__hero" aria-hidden="true">
        <div class="page-skeleton__container page-skeleton__hero-stage">
          <div class="page-skeleton__hero-rail">
            <SkeletonBlock
              v-for="index in 5"
              :key="`rail-${index}`"
              :height="52"
            />
          </div>
          <div class="page-skeleton__hero-body">
            <SkeletonBlock :width="'min(520px, 92%)'" :height="46" />
            <SkeletonBlock :width="'min(620px, 100%)'" :height="16" />
            <SkeletonBlock :width="'min(460px, 78%)'" :height="16" />
            <div class="page-skeleton__hero-actions">
              <SkeletonBlock :width="160" :height="48" radius="999" />
              <SkeletonBlock :width="132" :height="48" radius="999" />
            </div>
          </div>
        </div>
        <div class="page-skeleton__container">
          <div class="page-skeleton__hero-features">
            <SkeletonBlock
              v-for="index in 5"
              :key="`feature-${index}`"
              :height="116"
            />
          </div>
        </div>
      </section>

      <section class="page-skeleton__section" aria-hidden="true">
        <div class="page-skeleton__container page-skeleton__stack">
          <div class="page-skeleton__heading">
            <SkeletonBlock :width="'min(360px, 60%)'" :height="30" />
            <SkeletonBlock :width="'min(560px, 84%)'" :height="14" />
          </div>
          <div class="page-skeleton__tabs">
            <SkeletonBlock
              v-for="(width, index) in tabWidths"
              :key="`tab-${index}`"
              :width="width"
              :height="34"
              radius="999"
            />
          </div>
          <div class="page-skeleton__grid page-skeleton__grid--cards">
            <div
              v-for="index in 4"
              :key="`card-${index}`"
              class="page-skeleton__card"
            >
              <SkeletonBlock :width="'62%'" :height="18" />
              <SkeletonBlock :height="12" />
              <SkeletonBlock :width="'74%'" :height="12" />
              <SkeletonBlock
                :width="88"
                :height="12"
                class="page-skeleton__card-cta"
              />
            </div>
          </div>
        </div>
      </section>
    </template>

    <!-- 内容列表（公告 / 帮助中心）：宽版两栏，与 ContentListPage 同结构 -->
    <template v-else-if="variant === 'content-list'">
      <div class="page-skeleton__content-layout" aria-hidden="true">
        <div class="page-skeleton__content-main">
          <section class="page-skeleton__panel page-skeleton__hero-panel">
            <div class="page-skeleton__hero-copy">
              <SkeletonBlock :width="'min(240px, 48%)'" :height="26" />
              <SkeletonBlock :width="'min(560px, 96%)'" :height="13" />
              <div class="page-skeleton__keywords">
                <SkeletonBlock
                  v-for="index in 5"
                  :key="`keyword-${index}`"
                  :width="64 + index * 8"
                  :height="20"
                  radius="999"
                />
              </div>
            </div>
            <div class="page-skeleton__hero-search">
              <SkeletonBlock :height="40" radius="3" />
            </div>
          </section>

          <section class="page-skeleton__category-bar">
            <SkeletonBlock
              v-for="index in 5"
              :key="`category-${index}`"
              :width="index === 1 ? 96 : 72"
              :height="18"
              radius="999"
            />
          </section>

          <section class="page-skeleton__list-panel">
            <div
              v-for="index in 6"
              :key="`list-${index}`"
              class="page-skeleton__list-row"
            >
              <SkeletonBlock
                :width="`${58 + ((index * 7) % 24)}%`"
                :height="20"
              />
              <SkeletonBlock :width="'100%'" :height="14" />
              <SkeletonBlock :width="'76%'" :height="14" />
              <div class="page-skeleton__list-meta">
                <SkeletonBlock :width="72" :height="12" />
                <SkeletonBlock :width="88" :height="12" />
                <SkeletonBlock :width="64" :height="12" />
              </div>
            </div>
            <div class="page-skeleton__pager">
              <SkeletonBlock :width="260" :height="32" radius="3" />
            </div>
          </section>
        </div>

        <aside class="page-skeleton__content-sidebar">
          <section
            v-for="index in 3"
            :key="`sidebar-${index}`"
            class="page-skeleton__panel page-skeleton__sidebar-card"
          >
            <SkeletonBlock :width="'46%'" :height="16" />
            <div v-if="index === 1" class="page-skeleton__shortcut-grid">
              <div
                v-for="item in 3"
                :key="`shortcut-${item}`"
                class="page-skeleton__shortcut"
              >
                <SkeletonBlock :width="48" :height="48" />
                <SkeletonBlock :width="56" :height="12" />
              </div>
            </div>
            <template v-else>
              <SkeletonBlock
                v-for="item in 5"
                :key="`sidebar-row-${item}`"
                :width="`${64 + ((item * 6) % 28)}%`"
                :height="13"
              />
            </template>
          </section>
        </aside>
      </div>
    </template>

    <!-- 内容详情（公告详情 / 帮助详情）：面包屑 + 正文卡 + 右侧分类卡 -->
    <template v-else-if="variant === 'content-detail'">
      <div class="page-skeleton__content-main" aria-hidden="true">
        <div class="page-skeleton__breadcrumb">
          <SkeletonBlock :width="64" :height="13" />
          <SkeletonBlock :width="12" :height="12" radius="999" />
          <SkeletonBlock :width="72" :height="13" />
          <SkeletonBlock :width="12" :height="12" radius="999" />
          <SkeletonBlock :width="180" :height="13" />
        </div>

        <div class="page-skeleton__reader-layout">
          <section class="page-skeleton__panel page-skeleton__article">
            <SkeletonBlock :width="'min(420px, 72%)'" :height="30" />
            <div class="page-skeleton__article-meta">
              <SkeletonBlock :width="110" :height="12" />
              <SkeletonBlock :width="150" :height="12" />
              <SkeletonBlock :width="86" :height="12" />
            </div>
            <SkeletonBlock :height="1" radius="0" />
            <div class="page-skeleton__article-body">
              <template v-for="line in articleLines" :key="`line-${line}`">
                <SkeletonBlock
                  :width="lineWidths[(line - 1) % lineWidths.length]"
                  :height="15"
                />
                <span
                  v-if="line % 5 === 0 && line !== articleLines"
                  class="page-skeleton__article-gap"
                />
              </template>
            </div>
          </section>

          <aside class="page-skeleton__reader-sidebar">
            <section
              v-for="index in 2"
              :key="`reader-sidebar-${index}`"
              class="page-skeleton__panel page-skeleton__sidebar-card"
            >
              <SkeletonBlock :width="'52%'" :height="16" />
              <SkeletonBlock
                v-for="item in index === 1 ? 5 : 2"
                :key="`reader-row-${item}`"
                :width="`${70 + ((item * 5) % 24)}%`"
                :height="13"
              />
            </section>
          </aside>
        </div>
      </div>
    </template>

    <!-- 其他页面（关于、条款、SEO 落地页等）：标题区 + 内容卡 -->
    <template v-else>
      <div
        class="page-skeleton__container page-skeleton__generic"
        aria-hidden="true"
      >
        <div class="page-skeleton__generic-head">
          <SkeletonBlock :width="'min(420px, 64%)'" :height="36" />
          <SkeletonBlock :width="'min(680px, 92%)'" :height="15" />
          <SkeletonBlock :width="'min(520px, 76%)'" :height="15" />
        </div>
        <div class="page-skeleton__generic-grid">
          <div
            v-for="index in 6"
            :key="`generic-card-${index}`"
            class="page-skeleton__panel page-skeleton__generic-card"
          >
            <SkeletonBlock :width="44" :height="44" />
            <SkeletonBlock :width="'56%'" :height="18" />
            <SkeletonBlock :height="13" />
            <SkeletonBlock :width="'82%'" :height="13" />
          </div>
        </div>
      </div>
    </template>
  </div>
</template>

<script setup>
/**
 * 页面级骨架：和真实页面同形同尺寸，用于路由首次加载 / 异步组件加载期间占位，
 * 替换成真实内容时不再出现空白或高度跳动。
 *
 * 变体由调用方按当前路由决定（见 RouteLoadingFallback.vue）：
 *  - home：首页（Hero + 产品区块）
 *  - content-list：公告 / 帮助中心列表
 *  - content-detail：公告详情 / 帮助详情
 *  - generic：关于、条款、SEO 落地页等
 *
 * 所有色块都是 SkeletonBlock（统一 1.2s 扫光、reduced-motion 静止）。
 */
import SkeletonBlock from "@/components/common/SkeletonBlock.vue";

defineProps({
  variant: {
    type: String,
    default: "generic",
    validator: (value) =>
      ["home", "content-list", "content-detail", "generic"].includes(value),
  },
  // 整页加载时补顶栏骨架（布局组件尚未挂载的场景）
  withHeader: {
    type: Boolean,
    default: false,
  },
});

const navWidths = [44, 52, 80, 52, 72];
const tabWidths = [88, 104, 96, 118, 90];
const articleLines = 14;
const lineWidths = ["100%", "96%", "88%", "100%", "72%", "94%", "100%", "64%"];
</script>

<style scoped lang="scss">
.page-skeleton {
  min-height: min(72vh, 820px);
  background: $bg-color;
}

.page-skeleton__container {
  width: min(1200px, calc(100% - 48px));
  margin: 0 auto;
}

/* ---------- 顶栏（仅整页首次加载） ---------- */
.page-skeleton__topbar {
  height: 64px;
  background: #ffffff;
  border-bottom: 1px solid $divider-color;
}

.page-skeleton__topbar-inner {
  display: flex;
  align-items: center;
  height: 64px;
}

.page-skeleton__nav {
  display: flex;
  align-items: center;
  gap: 32px;
  margin-left: 40px;
}

.page-skeleton__topbar-actions {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-left: auto;
}

/* ---------- 首页 ---------- */
.page-skeleton__hero {
  // 与 HomeHeroCarousel 的 .hero-section 对齐：上移一个头部高度铺到顶栏下面
  margin-top: -64px;
  padding: 92px 0 44px;
  background: linear-gradient(135deg, #f8fbff 0%, #eef4ff 52%, #ffffff 100%);
}

.page-skeleton--with-header .page-skeleton__hero {
  margin-top: 0;
  padding-top: 28px;
}

.page-skeleton__hero-stage {
  display: grid;
  grid-template-columns: 220px minmax(0, 1fr) minmax(340px, 420px);
  column-gap: clamp(28px, 3.2vw, 48px);
  row-gap: 20px;
  align-items: center;
  min-height: 460px;
  padding: 40px 0 24px;
}

.page-skeleton__hero-rail {
  display: flex;
  flex-direction: column;
  gap: 8px;
  padding-top: 18px;
}

.page-skeleton__hero-body {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.page-skeleton__hero-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
  margin-top: 14px;
}

.page-skeleton__hero-features {
  display: grid;
  grid-template-columns: repeat(5, minmax(0, 1fr));
  gap: 1px;
  margin-top: 32px;
  background: #ffffff;
  border: 1px solid $border-color;
  border-radius: 6px;
  box-shadow: 0 2px 10px rgba(15, 23, 42, 0.06);
  overflow: hidden;
}

.page-skeleton__section {
  padding: 72px 0 80px;
  background: #ffffff;
}

.page-skeleton__stack {
  display: grid;
  gap: 28px;
}

.page-skeleton__heading {
  display: grid;
  justify-items: center;
  gap: 12px;
}

.page-skeleton__tabs {
  display: flex;
  flex-wrap: wrap;
  justify-content: center;
  gap: 12px;
}

.page-skeleton__grid--cards {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 16px;
}

.page-skeleton__card {
  display: flex;
  flex-direction: column;
  gap: 10px;
  min-height: 100px;
  padding: 14px 20px 12px;
  border: 1px solid #e5eaf3;
  border-radius: 6px;
  background: #ffffff;
}

.page-skeleton__card-cta {
  margin-top: auto;
  margin-left: auto;
}

/* ---------- 内容列表 ---------- */
.page-skeleton__content-layout {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 330px;
  gap: 18px;
  align-items: start;
}

.page-skeleton__content-main {
  min-width: 0;
}

.page-skeleton__panel {
  border: 1px solid $border-color;
  background: $bg-color-card;
  box-shadow: $shadow-sm;
}

.page-skeleton__hero-panel {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 360px;
  gap: 28px;
  min-height: 150px;
  margin-bottom: 18px;
  padding: 26px 30px;
}

.page-skeleton__hero-copy {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.page-skeleton__keywords {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  margin-top: 8px;
}

.page-skeleton__hero-search {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  width: 100%;

  > * {
    width: 100%;
  }
}

.page-skeleton__category-bar {
  display: flex;
  align-items: center;
  gap: 24px;
  min-height: 50px;
  margin-bottom: 8px;
  padding: 0 20px;
  border: 1px solid $border-color;
  background: $bg-color-card;
  box-shadow: $shadow-sm;
}

.page-skeleton__list-panel {
  background: $bg-color-card;
  border: 1px solid $border-color;
  box-shadow: $shadow-sm;
  overflow: hidden;
}

.page-skeleton__list-row {
  display: flex;
  flex-direction: column;
  gap: 12px;
  padding: 26px 30px 22px;
  border-bottom: 1px solid $divider-color;

  &:last-child {
    border-bottom: none;
  }
}

.page-skeleton__list-meta {
  display: flex;
  align-items: center;
  gap: 14px;
  margin-top: 2px;
}

.page-skeleton__pager {
  display: flex;
  justify-content: flex-end;
  padding: 20px 24px;
}

.page-skeleton__content-sidebar {
  display: grid;
  gap: 18px;
}

.page-skeleton__sidebar-card {
  display: flex;
  flex-direction: column;
  gap: 12px;
  padding: 20px;
}

.page-skeleton__shortcut-grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 14px;
}

.page-skeleton__shortcut {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 10px;
}

/* ---------- 内容详情 ---------- */
.page-skeleton--content-detail {
  padding-top: 24px;
}

.page-skeleton__breadcrumb {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px;
  margin-bottom: 16px;
}

.page-skeleton__reader-layout {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 320px;
  gap: 20px;
  align-items: start;
}

.page-skeleton__article {
  padding: 22px 20px 28px;
}

.page-skeleton__article-meta {
  display: flex;
  flex-wrap: wrap;
  gap: 12px 24px;
  margin: 18px 0;
  padding: 12px 14px;
  border: 1px solid $color-primary-border;
  background: $color-primary-soft;
}

.page-skeleton__article-body {
  display: flex;
  flex-direction: column;
  gap: 14px;
  margin-top: 18px;
}

.page-skeleton__article-gap {
  display: block;
  height: 14px;
}

.page-skeleton__reader-sidebar {
  display: grid;
  gap: 16px;
}

/* ---------- 通用 ---------- */
.page-skeleton__generic {
  padding: 64px 0 72px;
}

.page-skeleton__generic-head {
  display: flex;
  flex-direction: column;
  gap: 14px;
  margin-bottom: 32px;
}

.page-skeleton__generic-grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 20px;
}

.page-skeleton__generic-card {
  display: flex;
  flex-direction: column;
  gap: 12px;
  padding: 24px 20px;
}

/* ---------- 响应式：与真实页面断点保持一致 ---------- */
@media (max-width: 1260px) {
  .page-skeleton__content-layout {
    grid-template-columns: 1fr;
  }

  .page-skeleton__content-sidebar {
    grid-template-columns: repeat(3, minmax(0, 1fr));
  }
}

@media (max-width: 1180px) {
  .page-skeleton__hero-stage {
    grid-template-columns: 220px minmax(0, 1fr);
    min-height: 0;
    padding: 28px 0 16px;
    align-items: start;
  }

  .page-skeleton__hero-rail {
    padding-top: 8px;
  }

  .page-skeleton__hero-features {
    grid-template-columns: repeat(3, minmax(0, 1fr));
    margin-top: 24px;
  }

  .page-skeleton__grid--cards {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}

@media (max-width: 900px) {
  .page-skeleton__hero-panel {
    grid-template-columns: 1fr;
  }

  .page-skeleton__content-sidebar {
    grid-template-columns: 1fr;
  }
}

@media (max-width: 960px) {
  .page-skeleton__hero {
    padding: 84px 0 28px;
  }

  .page-skeleton--with-header .page-skeleton__hero {
    padding-top: 20px;
  }

  .page-skeleton__hero-stage {
    grid-template-columns: 1fr;
    gap: 18px;
    padding: 12px 0 4px;
  }

  .page-skeleton__hero-rail {
    flex-direction: row;
    padding: 0 0 4px;
    overflow: hidden;
  }

  .page-skeleton__hero-features {
    grid-template-columns: repeat(2, minmax(0, 1fr));
    margin-top: 22px;
  }

  .page-skeleton__nav,
  .page-skeleton__topbar-actions {
    display: none;
  }

  .page-skeleton__reader-layout {
    grid-template-columns: 1fr;
  }

  .page-skeleton__generic-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}

@media (max-width: 640px) {
  .page-skeleton__container {
    width: calc(100% - 24px);
  }

  .page-skeleton__hero {
    padding: 80px 0 24px;
  }

  .page-skeleton--with-header .page-skeleton__hero {
    padding-top: 16px;
  }

  .page-skeleton__hero-rail {
    display: none;
  }

  .page-skeleton__hero-features {
    grid-template-columns: 1fr;
    margin-top: 18px;
  }

  .page-skeleton__section {
    padding: 48px 0 56px;
  }

  .page-skeleton__grid--cards,
  .page-skeleton__generic-grid {
    grid-template-columns: 1fr;
  }

  .page-skeleton__hero-panel {
    padding: 20px 16px;
  }

  .page-skeleton__list-row {
    padding: 20px 16px;
  }

  .page-skeleton__category-bar {
    gap: 16px;
    overflow: hidden;
    padding: 0 12px;
  }

  .page-skeleton__generic {
    padding: 40px 0 56px;
  }
}
</style>
