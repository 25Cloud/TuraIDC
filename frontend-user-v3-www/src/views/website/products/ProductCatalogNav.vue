<template>
  <div class="shop-nav">
    <!--
      目录侧栏：第一栏是一级分组（商品种类），第二栏是当前一级分组下的
      二 / 三级分组。选购页与卡片式配置页共用本组件，保证两页目录的
      结构、字号与交互完全一致。

      过渡说明：两栏都包一层「固定展开宽度」的 inner，整块收起 / 展开时只让
      外层宽度过渡、内容被裁切并轻微左移淡出，避免内容在宽度变化过程中被逐帧
      挤压重排（文字变省略号、换行抖动）——那正是此前看起来像「硬切」的原因。
    -->
    <Transition name="shop-nav">
      <aside
        v-if="visible"
        class="shop-sidebar"
        :class="{ collapsed }"
        :aria-label="$t('product.list.level1Nav')"
      >
        <div class="shop-sidebar__inner">
          <div class="sidebar-head">
            <span
              class="sidebar-head-text"
              :aria-hidden="collapsed ? 'true' : undefined"
              >{{ $t("product.list.level1Nav") }}</span
            >
            <button
              type="button"
              class="sidebar-toggle"
              :aria-label="
                collapsed
                  ? $t('product.list.expandLevel1Nav')
                  : $t('product.list.collapseLevel1Nav')
              "
              :aria-expanded="!collapsed"
              @click="toggleLevel1"
            >
              <!-- 展开 / 收起两个图标叠放，用旋转 + 淡入淡出过渡，避免图标瞬切 -->
              <span
                class="sidebar-toggle__icon"
                :class="{ 'is-collapsed': collapsed }"
                aria-hidden="true"
              >
                <el-icon
                  class="sidebar-toggle__glyph sidebar-toggle__glyph--expand"
                  ><Expand
                /></el-icon>
                <el-icon
                  class="sidebar-toggle__glyph sidebar-toggle__glyph--fold"
                  ><Fold
                /></el-icon>
              </span>
            </button>
          </div>

          <div class="sidebar-list">
            <button
              v-for="type in types"
              :key="type.value"
              type="button"
              class="sidebar-item"
              :class="{ active: isActiveType(type) }"
              :title="collapsed ? type.label : undefined"
              :aria-current="isActiveType(type) ? 'true' : undefined"
              @click="selectType(type)"
            >
              <span
                class="item-abbr"
                :class="`item-abbr--${iconOf(type).kind}`"
              >
                <el-icon
                  v-if="iconOf(type).kind === 'icon'"
                  class="item-abbr__glyph"
                  ><component :is="iconOf(type).component"
                /></el-icon>
                <span v-else class="item-abbr__text">{{
                  iconOf(type).text
                }}</span>
              </span>
              <!--
                文字区保留在 DOM 里：折叠时靠透明度和外层裁切退场，
                不再用 v-if 立刻抽掉——否则一级分组栏宽度还在从 216 收到 72，
                文字却已经消失，观感就是「一瞬间跳过去」。
              -->
              <span
                class="item-body"
                :aria-hidden="collapsed ? 'true' : undefined"
              >
                <span class="item-name">
                  <span class="item-name-icon" aria-hidden="true">
                    <el-icon v-if="iconOf(type).kind === 'icon'"
                      ><component :is="iconOf(type).component"
                    /></el-icon>
                    <template v-else>{{ iconOf(type).text }}</template>
                  </span>
                  <span class="item-name-text">{{ type.label }}</span>
                </span>
                <span class="item-sub">{{
                  Number(type.product_count || 0) > 0
                    ? $t("product.list.productCount", {
                        count: type.product_count,
                      })
                    : $t("product.list.empty")
                }}</span>
              </span>
            </button>
          </div>
        </div>
      </aside>
    </Transition>

    <Transition name="shop-nav">
      <aside
        v-if="visible"
        class="shop-catalog"
        :aria-label="$t('product.list.groupNav')"
      >
        <div class="shop-catalog__inner">
          <div class="shop-catalog__head">
            <span class="shop-catalog__title">{{ activeTypeLabel }}</span>
            <span class="shop-catalog__badge">{{
              $t("product.list.level2Nav")
            }}</span>
          </div>

          <div class="shop-catalog__body">
            <ProductGroupNav
              :groups="groups"
              :child-groups="childGroups"
              :active-group-id="activeGroupId"
              :active-child-id="activeChildId"
              :loading="loading"
              @select-group="(id) => emit('select-group', id)"
              @select-child="(id) => emit('select-child', id)"
            />
          </div>
        </div>
      </aside>
    </Transition>
  </div>
</template>

<script setup>
import { computed } from "vue";
import { Expand, Fold } from "@element-plus/icons-vue";
import { resolveGroupIcon } from "@/utils/productGroupIcon";
import ProductGroupNav from "./ProductGroupNav.vue";

const props = defineProps({
  types: { type: Array, default: () => [] },
  groups: { type: Array, default: () => [] },
  childGroups: { type: Array, default: () => [] },
  activeTypeValue: { type: [String, Number], default: "" },
  activeGroupId: { type: [Number, String], default: 0 },
  activeChildId: { type: [Number, String], default: 0 },
  /** 整块目录是否显示：false 时两栏走收起过渡后从 DOM 移除 */
  visible: { type: Boolean, default: true },
  /** 第一栏是否折成缩写方块 */
  collapsed: { type: Boolean, default: false },
  /** 正在加载分组商品：三级分组区域显示骨架占位 */
  loading: { type: Boolean, default: false },
});

const emit = defineEmits([
  "select-type",
  "select-group",
  "select-child",
  "update:collapsed",
]);

const activeTypeLabel = computed(
  () =>
    props.types.find(
      (type) =>
        String(type?.value ?? "") === String(props.activeTypeValue ?? ""),
    )?.label || "",
);

function iconOf(type) {
  return resolveGroupIcon(type?.icon_resolved, type?.label || type?.name || "");
}

function sameId(left, right) {
  return String(left ?? "") === String(right ?? "");
}

function isActiveType(type) {
  return sameId(type?.value, props.activeTypeValue);
}

function toggleLevel1() {
  emit("update:collapsed", !props.collapsed);
}

function selectType(type) {
  emit("select-type", type?.value);
}
</script>

<style scoped lang="scss">
/*
 * 两栏目录共用一个 flex 容器：
 *  - 宽度过渡用统一的 260ms cubic-bezier(.4,0,.2,1)，和站点版心过渡同一节奏；
 *  - 每栏内层固定为展开宽度，整块收起时内容只被裁切、不重排；
 *  - 一级分组折叠时内层跟着收窄，但文字区已脱离文档流，只做淡出。
 */
.shop-nav {
  --shop-nav-rail-w: 216px;
  --shop-nav-catalog-w: 244px;
  --shop-nav-rail-collapsed-w: 72px;
  --shop-nav-duration: 260ms;
  --shop-nav-ease: cubic-bezier(0.4, 0, 0.2, 1);

  display: flex;
  flex-shrink: 0;
  align-items: stretch;
}

/* ===== 第一个侧栏：一级分组（商品种类） ===== */
.shop-sidebar {
  width: var(--shop-nav-rail-w);
  flex-shrink: 0;
  display: flex;
  flex-direction: column;
  position: sticky;
  top: 64px;
  height: calc(100vh - 64px);
  box-sizing: border-box;
  border-right: 1px solid $divider-color;
  background: #fff;
  overflow: hidden;
  transition:
    width var(--shop-nav-duration) var(--shop-nav-ease),
    opacity 200ms var(--shop-nav-ease);

  &.collapsed {
    width: var(--shop-nav-rail-collapsed-w);
  }
}

/* 内层固定展开宽度：外层收窄时内容被裁切而不是逐帧挤压 */
.shop-sidebar__inner {
  display: flex;
  flex-direction: column;
  flex-shrink: 0;
  width: var(--shop-nav-rail-w);
  height: 100%;
  overflow-x: hidden;
  overflow-y: auto;
  transition:
    width var(--shop-nav-duration) var(--shop-nav-ease),
    transform var(--shop-nav-duration) var(--shop-nav-ease),
    opacity 200ms var(--shop-nav-ease);
}

.shop-sidebar.collapsed .shop-sidebar__inner {
  width: var(--shop-nav-rail-collapsed-w);
}

.sidebar-head {
  position: relative;
  display: flex;
  flex-shrink: 0;
  align-items: center;
  /* 始终靠右排按钮：折叠时只用内层宽度 + 右侧内边距把按钮平滑滑到栏中 */
  justify-content: flex-end;
  gap: 8px;
  min-height: 52px;
  padding: 0 12px 0 16px;
  border-bottom: 1px solid $divider-color;
  transition: padding var(--shop-nav-duration) var(--shop-nav-ease);

  .shop-sidebar.collapsed & {
    /* 72 宽 - 28 按钮 = 44，两侧各 22 = 居中 */
    padding-right: calc((var(--shop-nav-rail-collapsed-w) - 28px) / 2);
    padding-left: 0;
  }
}

.sidebar-head-text {
  position: absolute;
  top: 50%;
  left: 16px;
  max-width: calc(var(--shop-nav-rail-w) - 76px);
  overflow: hidden;
  color: $text-color-primary;
  font-size: 15px;
  font-weight: 700;
  text-overflow: ellipsis;
  white-space: nowrap;
  transform: translateY(-50%);
  transition: opacity 180ms var(--shop-nav-ease);

  .shop-sidebar.collapsed & {
    opacity: 0;
  }
}

.sidebar-toggle {
  display: inline-flex;
  flex-shrink: 0;
  align-items: center;
  justify-content: center;
  width: 28px;
  height: 28px;
  padding: 0;
  border: 1px solid $divider-color;
  border-radius: 6px;
  background: #fff;
  color: $text-color-secondary;
  cursor: pointer;
  transition:
    border-color $motion-fast ease,
    color $motion-fast ease;

  &:hover {
    border-color: $color-primary;
    color: $color-primary;
  }
}

/* 展开 / 收起图标叠放，旋转 + 淡入淡出切换 */
.sidebar-toggle__icon {
  position: relative;
  display: inline-flex;
  width: 16px;
  height: 16px;
  align-items: center;
  justify-content: center;
}

.sidebar-toggle__glyph {
  position: absolute;
  inset: 0;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-size: 16px;
  transition:
    opacity var(--shop-nav-duration) var(--shop-nav-ease),
    transform var(--shop-nav-duration) var(--shop-nav-ease);
}

.sidebar-toggle__glyph--expand {
  opacity: 0;
  transform: rotate(-90deg) scale(0.72);
}

.shop-sidebar.collapsed .sidebar-toggle__glyph--expand {
  opacity: 1;
  transform: rotate(0deg) scale(1);
}

.shop-sidebar.collapsed .sidebar-toggle__glyph--fold {
  opacity: 0;
  transform: rotate(90deg) scale(0.72);
}

.sidebar-list {
  display: flex;
  flex-direction: column;
  gap: 4px;
  padding: 10px 10px 16px;
  transition: padding var(--shop-nav-duration) var(--shop-nav-ease);

  .shop-sidebar.collapsed & {
    padding-right: 0;
    padding-left: 0;
  }
}

.sidebar-item {
  position: relative;
  display: flex;
  width: 100%;
  min-height: 56px;
  align-items: center;
  gap: 10px;
  padding: 8px 12px;
  overflow: hidden;
  border: 0;
  border-radius: 8px;
  background: transparent;
  color: $text-color-primary;
  font: inherit;
  text-align: left;
  cursor: pointer;
  transition:
    padding var(--shop-nav-duration) var(--shop-nav-ease),
    background-color $motion-fast ease,
    color $motion-fast ease;

  .shop-sidebar.collapsed & {
    /* 72 = 19 + 34(图标) + 19：图标正好落在窄栏中间 */
    padding-right: 19px;
    padding-left: 19px;
  }

  &:hover {
    background: $bg-color-soft;

    .item-name {
      color: $color-primary;
    }

    .item-abbr {
      background: $color-primary-soft;
      color: $color-primary;
    }
  }

  &.active {
    background: $color-primary-soft;

    .item-name {
      color: $color-primary;
      font-weight: 600;
    }

    .item-abbr {
      background: $color-primary;
      color: #fff;
    }
  }
}

.item-abbr {
  flex-shrink: 0;
  display: inline-flex;
  width: 34px;
  height: 26px;
  align-items: center;
  justify-content: center;
  border-radius: 6px;
  background: $bg-color-soft;
  color: $text-color-secondary;
  font-size: 12px;
  font-weight: 700;
  letter-spacing: 0.02em;
  transition: all $motion-fast ease;
}

.item-abbr__glyph {
  font-size: 16px;
}

.item-abbr__text {
  font-size: 12px;
  font-weight: 700;
  letter-spacing: 0.02em;
  white-space: nowrap;
}

.item-name {
  display: inline-flex;
  align-items: center;
  gap: 5px;
}

.item-name-icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 14px;
  color: $text-color-placeholder;
  font-size: 11px;
  font-weight: 700;

  .el-icon {
    font-size: 13px;
  }
}

.item-name-text {
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

/*
 * 文字区脱离文档流：位置与展开态完全一致（56 = 12 内边距 + 34 图标 + 10 间距），
 * 因此一级分组折叠时它不会重排，只是平滑左移并淡出，由外层裁切收尾。
 */
.item-body {
  position: absolute;
  top: 50%;
  left: 56px;
  display: flex;
  width: calc(var(--shop-nav-rail-w) - 88px);
  flex-direction: column;
  gap: 3px;
  transform: translateY(-50%);
  opacity: 1;
  transition:
    left var(--shop-nav-duration) var(--shop-nav-ease),
    opacity 160ms var(--shop-nav-ease);

  .shop-sidebar.collapsed & {
    left: 63px;
    opacity: 0;
  }
}

.item-name {
  overflow: hidden;
  color: $text-color-primary;
  font-size: 14px;
  font-weight: 500;
  text-overflow: ellipsis;
  white-space: nowrap;
  transition: color $motion-fast ease;
}

.item-sub {
  overflow: hidden;
  color: $text-color-placeholder;
  font-size: 12px;
  text-overflow: ellipsis;
  white-space: nowrap;
}

/* ===== 第二个侧栏：当前一级分组下的二 / 三级分组 ===== */
.shop-catalog {
  width: var(--shop-nav-catalog-w);
  flex-shrink: 0;
  display: flex;
  flex-direction: column;
  position: sticky;
  top: 64px;
  height: calc(100vh - 64px);
  box-sizing: border-box;
  border-right: 1px solid $divider-color;
  background: #fff;
  overflow: hidden;
  transition:
    width var(--shop-nav-duration) var(--shop-nav-ease),
    opacity 200ms var(--shop-nav-ease);
}

.shop-catalog__inner {
  display: flex;
  flex-direction: column;
  flex-shrink: 0;
  width: var(--shop-nav-catalog-w);
  height: 100%;
  overflow: hidden;
  transition:
    transform var(--shop-nav-duration) var(--shop-nav-ease),
    opacity 200ms var(--shop-nav-ease);
}

.shop-catalog__head {
  display: flex;
  flex-shrink: 0;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  min-height: 52px;
  padding: 0 16px;
  border-bottom: 1px solid $divider-color;
}

.shop-catalog__title {
  overflow: hidden;
  color: $text-color-primary;
  font-size: 15px;
  font-weight: 700;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.shop-catalog__badge {
  flex-shrink: 0;
  color: $text-color-placeholder;
  font-size: 12px;
}

.shop-catalog__body {
  flex: 1;
  min-height: 0;
  overflow-y: auto;
}

/* 窄一点的桌面把两条侧栏一起收窄，避免配置区被挤扁 */
@media (max-width: 1280px) {
  .shop-nav {
    --shop-nav-rail-w: 196px;
    --shop-nav-catalog-w: 224px;
  }
}

@media (max-width: 1120px) {
  .shop-nav {
    --shop-nav-rail-w: 180px;
    --shop-nav-catalog-w: 204px;
  }
}

/* ===== 整块收起 / 展开过渡（宽度 + 位移 + 透明度，不产生横向滚动条） ===== */
.shop-nav-enter-active,
.shop-nav-leave-active {
  /* 过渡期间必须夹住内容，否则窄到 0 的过程中会溢出成横向滚动条 */
  overflow: hidden !important;
  pointer-events: none;
  transition:
    width var(--shop-nav-duration) var(--shop-nav-ease),
    opacity 200ms var(--shop-nav-ease) !important;

  /*
   * 内容整体轻微左移 + 淡出/淡入：宽度收窄时观感是「被推走」，
   * 而不是原地被挤扁。内层宽度不变，所以文字不会重排。
   */
  .shop-sidebar__inner,
  .shop-catalog__inner {
    transition:
      transform var(--shop-nav-duration) var(--shop-nav-ease),
      opacity 200ms var(--shop-nav-ease) !important;
  }
}

.shop-nav-enter-from,
.shop-nav-leave-to {
  width: 0 !important;
  border-right-width: 0;
  opacity: 0;

  .shop-sidebar__inner,
  .shop-catalog__inner {
    opacity: 0;
    transform: translateX(-14px);
  }
}

/* 尊重系统「减少动态效果」：全部降为瞬时，不留半截动画 */
@media (prefers-reduced-motion: reduce) {
  .shop-sidebar,
  .shop-catalog,
  .shop-sidebar__inner,
  .shop-catalog__inner,
  .sidebar-head,
  .sidebar-head-text,
  .sidebar-list,
  .sidebar-item,
  .item-body,
  .sidebar-toggle__glyph {
    transition-duration: 1ms !important;
  }

  .shop-nav-enter-active,
  .shop-nav-leave-active {
    transition-duration: 1ms !important;
  }
}
</style>
