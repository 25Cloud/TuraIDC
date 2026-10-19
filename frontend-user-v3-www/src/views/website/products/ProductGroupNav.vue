<template>
  <nav class="shop-cat" :aria-label="$t('product.list.groupNav')">
    <template v-for="group in groups" :key="group.id">
      <button
        type="button"
        class="shop-cat__group"
        :class="{
          'is-active': isActiveGroup(group),
          'is-expanded': isActiveGroup(group) && childGroups.length,
        }"
        :aria-current="isActiveGroup(group) ? 'true' : undefined"
        :aria-expanded="
          hasChildren(group)
            ? isActiveGroup(group) && childGroups.length > 0
            : undefined
        "
        @click="selectGroup(group)"
      >
        <span class="shop-cat__name">{{ group.name }}</span>
        <span v-if="showCount(group)" class="shop-cat__count">{{
          group.product_count
        }}</span>
        <svg
          v-if="hasChildren(group)"
          class="shop-cat__chevron"
          viewBox="0 0 12 12"
          width="12"
          height="12"
          fill="none"
          aria-hidden="true"
        >
          <path
            d="M3 4.5l3 3 3-3"
            stroke="currentColor"
            stroke-width="1.5"
            stroke-linecap="round"
            stroke-linejoin="round"
          />
        </svg>
      </button>

      <div
        v-if="isActiveGroup(group) && childGroups.length"
        class="shop-cat__children"
      >
        <button
          v-for="child in childGroups"
          :key="child.id"
          type="button"
          class="shop-cat__child"
          :class="{ 'is-active': isActiveChild(child) }"
          :aria-current="isActiveChild(child) ? 'true' : undefined"
          @click="selectChild(child)"
        >
          <span class="shop-cat__name">{{ child.name }}</span>
          <span v-if="showCount(child)" class="shop-cat__count">{{
            child.product_count
          }}</span>
        </button>
      </div>
    </template>

    <p v-if="!groups.length" class="shop-cat__empty">
      {{ $t("product.list.groupEmpty") }}
    </p>
  </nav>
</template>

<script setup>
/**
 * 二 / 三级分组目录（桌面端第二个侧栏）。
 *
 * 一级分组（商品种类）在它左边独立成栏，这里只负责当前一级分组下的二级分组，
 * 以及当前二级分组展开的三级分组——两级不再和一级分组挤在同一列里。
 *
 * 只渲染当前已加载的 childGroups：切换二级分组会触发目录加载，加载完成后
 * 自然出现该分组的三级分组。
 */
const props = defineProps({
  groups: { type: Array, default: () => [] },
  childGroups: { type: Array, default: () => [] },
  activeGroupId: { type: [Number, String], default: 0 },
  activeChildId: { type: [Number, String], default: 0 },
});

const emit = defineEmits(["select-group", "select-child"]);

function sameId(left, right) {
  return String(left ?? "") === String(right ?? "");
}

function isActiveGroup(group) {
  return sameId(group?.id, props.activeGroupId);
}

function isActiveChild(child) {
  return sameId(child?.id, props.activeChildId);
}

function hasChildren(group) {
  return Number(group?.children_count ?? 0) > 0;
}

function showCount(group) {
  const count = Number(group?.product_count ?? 0);

  return Number.isFinite(count) && count > 0;
}

function selectGroup(group) {
  const id = Number(group?.id || 0);
  if (!id) {
    return;
  }

  emit("select-group", id);
}

function selectChild(child) {
  const id = Number(child?.id || 0);
  if (!id) {
    return;
  }

  emit("select-child", id);
}
</script>

<style scoped lang="scss">
.shop-cat {
  display: flex;
  flex-direction: column;
  gap: 2px;
  padding: 6px 10px 16px;
}

.shop-cat__group,
.shop-cat__child {
  display: flex;
  width: 100%;
  min-width: 0;
  align-items: center;
  gap: 8px;
  padding: 0 12px;
  border: 0;
  border-radius: 8px;
  background: transparent;
  color: $text-color-primary;
  text-align: left;
  cursor: pointer;
  transition:
    background-color $motion-fast ease,
    color $motion-fast ease;

  &:hover {
    background: $bg-color-hover;
    color: $color-primary;
  }

  &:focus-visible {
    outline: 2px solid rgba($color-primary, 0.45);
    outline-offset: -2px;
  }

  &.is-active {
    background: $color-primary-soft;
    color: $color-primary;
    font-weight: 600;
  }
}

.shop-cat__group {
  min-height: 44px;
  font-size: 14px;
  font-weight: 500;
}

.shop-cat__name {
  flex: 1;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.shop-cat__count {
  flex-shrink: 0;
  min-width: 22px;
  height: 18px;
  padding: 0 6px;
  border-radius: 9px;
  background: $bg-color-soft;
  color: $text-color-placeholder;
  font-size: 12px;
  font-weight: 500;
  line-height: 18px;
  text-align: center;

  .is-active > & {
    background: $color-primary;
    color: #fff;
  }
}

.shop-cat__chevron {
  flex-shrink: 0;
  color: $text-color-placeholder;
  transition: transform $motion-base ease;
}

.shop-cat__group.is-expanded .shop-cat__chevron {
  color: $color-primary;
  transform: rotate(180deg);
}

.shop-cat__children {
  display: flex;
  flex-direction: column;
  gap: 2px;
  margin: 2px 0 6px 14px;
  padding-left: 10px;
  border-left: 2px solid $divider-color;
}

.shop-cat__child {
  min-height: 38px;
  color: $text-color-secondary;
  font-size: 13.5px;
}

.shop-cat__empty {
  margin: 10px 12px 0;
  color: $text-color-placeholder;
  font-size: 13px;
  line-height: 1.7;
}
</style>
