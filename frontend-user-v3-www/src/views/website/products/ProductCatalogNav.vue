<template>
  <div class="shop-nav">
    <!--
      目录侧栏：第一栏是一级分组（商品种类），第二栏是当前一级分组下的
      二 / 三级分组。选购页与卡片式配置页共用本组件，保证两页目录的
      结构、字号与交互完全一致。
    -->
    <Transition name="shop-nav">
      <aside
        v-if="visible"
        class="shop-sidebar"
        :class="{ collapsed }"
        :aria-label="$t('product.list.level1Nav')"
      >
        <div class="sidebar-head">
          <span v-show="!collapsed" class="sidebar-head-text">{{
            $t("product.list.level1Nav")
          }}</span>
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
            <el-icon><Expand v-if="collapsed" /><Fold v-else /></el-icon>
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
            <span class="item-abbr" :class="`item-abbr--${iconOf(type).kind}`">
              <img
                v-if="iconOf(type).kind === 'image'"
                :src="iconOf(type).image"
                class="item-abbr__image"
                alt=""
              />
              <el-icon
                v-else-if="iconOf(type).kind === 'icon'"
                class="item-abbr__glyph"
                ><component :is="iconOf(type).component"
              /></el-icon>
              <span v-else class="item-abbr__text">{{
                iconOf(type).text
              }}</span>
            </span>
            <span v-if="!collapsed" class="item-body">
              <span class="item-name">
                <span class="item-name-icon" aria-hidden="true">
                  <img
                    v-if="iconOf(type).kind === 'image'"
                    :src="iconOf(type).image"
                    class="item-abbr__image"
                    alt=""
                  />
                  <el-icon v-else-if="iconOf(type).kind === 'icon'"
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
      </aside>
    </Transition>

    <Transition name="shop-nav">
      <aside
        v-if="visible"
        class="shop-catalog"
        :aria-label="$t('product.list.groupNav')"
      >
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
 * 两栏目录共用一个 flex 容器：收起时两栏同时做宽度 + 透明度过渡，
 * 收起结束再由 <Transition> 从 DOM 移除（宽高都不占位，不产生横向滚动条）。
 */
.shop-nav {
  display: flex;
  flex-shrink: 0;
  align-items: stretch;
}

/* ===== 第一个侧栏：一级分组（商品种类） ===== */
.shop-sidebar {
  width: 216px;
  flex-shrink: 0;
  display: flex;
  flex-direction: column;
  position: sticky;
  top: 64px;
  height: calc(100vh - 64px);
  box-sizing: border-box;
  border-right: 1px solid $divider-color;
  background: #fff;
  overflow-y: auto;
  transition:
    width $motion-base ease,
    opacity $motion-base ease;

  &.collapsed {
    width: 72px;
  }
}

.sidebar-head {
  display: flex;
  flex-shrink: 0;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  min-height: 52px;
  padding: 0 12px 0 16px;
  border-bottom: 1px solid $divider-color;

  .shop-sidebar.collapsed & {
    justify-content: center;
    padding: 0;
  }
}

.sidebar-head-text {
  color: $text-color-primary;
  font-size: 15px;
  font-weight: 700;
  white-space: nowrap;
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

.sidebar-list {
  display: flex;
  flex-direction: column;
  gap: 4px;
  padding: 10px 10px 16px;
}

.sidebar-item {
  display: flex;
  width: 100%;
  min-height: 56px;
  align-items: center;
  gap: 10px;
  padding: 8px 12px;
  border: 0;
  border-radius: 8px;
  background: transparent;
  color: $text-color-primary;
  font: inherit;
  text-align: left;
  cursor: pointer;
  transition:
    background-color $motion-fast ease,
    color $motion-fast ease;

  .shop-sidebar.collapsed & {
    justify-content: center;
    padding: 8px 0;
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

.item-abbr__image {
  width: 20px;
  height: 20px;
  object-fit: contain;
  border-radius: 3px;
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

  .item-abbr__image {
    width: 14px;
    height: 14px;
  }

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

.item-body {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 3px;
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
  width: 244px;
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
    width $motion-base ease,
    opacity $motion-base ease;
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
  .shop-sidebar {
    width: 196px;
  }

  .shop-catalog {
    width: 224px;
  }
}

@media (max-width: 1120px) {
  .shop-sidebar {
    width: 180px;
  }

  .shop-catalog {
    width: 204px;
  }
}

/* ===== 整块收起 / 展开过渡（宽度 + 透明度，不产生横向滚动条） ===== */
.shop-nav-enter-active,
.shop-nav-leave-active {
  /* 过渡期间必须夹住内容，否则窄到 0 的过程中会溢出成横向滚动条 */
  overflow: hidden !important;
  pointer-events: none;
  transition:
    width $motion-base ease,
    opacity $motion-base ease !important;
}

.shop-nav-enter-from,
.shop-nav-leave-to {
  width: 0 !important;
  border-right-width: 0;
  opacity: 0;
}

@media (prefers-reduced-motion: reduce) {
  .shop-sidebar,
  .shop-catalog {
    transition: none;
  }

  .shop-nav-enter-active,
  .shop-nav-leave-active {
    transition: none !important;
  }
}
</style>
