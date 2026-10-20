<template>
  <div class="m-cat">
    <div
      class="m-cat__types"
      role="tablist"
      :aria-label="$t('product.list.level1Nav')"
    >
      <button
        v-for="type in types"
        :key="type.value"
        type="button"
        role="tab"
        class="m-cat__type"
        :class="{ 'is-active': type.value === activeType }"
        :aria-selected="type.value === activeType"
        @click="pickType(type.value)"
      >
        {{ type.label }}
      </button>
    </div>

    <!--
      目录层级切换用 keyed 面板：一级列表 → 钻取二/三级分组 → 返回上级时
      重新挂载并播放一次轻微淡入上移，避免列表内容「啪」地整块换掉。
    -->
    <div class="m-cat__body">
      <div
        v-if="loading"
        class="m-cat__panel"
        key="m-cat-loading"
        aria-hidden="true"
      >
        <div class="m-cat__skeleton">
          <span v-for="index in 4" :key="index" class="m-cat__skeleton-row">
            <span class="m-cat__skeleton-line" />
          </span>
        </div>
      </div>

      <div
        v-else-if="drilledGroup"
        class="m-cat__panel"
        :key="`m-cat-group-${drilledGroup.id}`"
      >
        <button type="button" class="m-cat__back" @click="drilledId = 0">
          <svg
            viewBox="0 0 12 12"
            width="12"
            height="12"
            fill="none"
            aria-hidden="true"
          >
            <path
              d="M7.5 2.5 4 6l3.5 3.5"
              stroke="currentColor"
              stroke-width="1.5"
              stroke-linecap="round"
              stroke-linejoin="round"
            />
          </svg>
          <span>{{ $t("product.list.catalogBackUp") }}</span>
        </button>

        <button
          v-for="child in childGroups"
          :key="child.id"
          type="button"
          class="m-cat__row"
          :class="{ 'is-active': isActiveChild(child) }"
          :aria-current="isActiveChild(child) ? 'true' : undefined"
          @click="pickChild(child)"
        >
          <span class="m-cat__name">{{ child.name }}</span>
          <span v-if="showCount(child)" class="m-cat__count">{{
            child.product_count
          }}</span>
        </button>

        <p v-if="!loading && !childGroups.length" class="m-cat__empty">
          {{ $t("product.list.groupEmpty") }}
        </p>
      </div>

      <div v-else class="m-cat__panel" key="m-cat-root">
        <button
          v-for="group in groups"
          :key="group.id"
          type="button"
          class="m-cat__row"
          :class="{ 'is-active': isActiveGroup(group) }"
          :aria-current="isActiveGroup(group) ? 'true' : undefined"
          @click="pickGroup(group)"
        >
          <span class="m-cat__name">{{ group.name }}</span>
          <span v-if="showCount(group)" class="m-cat__count">{{
            group.product_count
          }}</span>
          <svg
            v-if="hasChildren(group)"
            class="m-cat__chevron"
            viewBox="0 0 12 12"
            width="12"
            height="12"
            fill="none"
            aria-hidden="true"
          >
            <path
              d="M4.5 2.5 8 6l-3.5 3.5"
              stroke="currentColor"
              stroke-width="1.5"
              stroke-linecap="round"
              stroke-linejoin="round"
            />
          </svg>
        </button>

        <p v-if="!loading && !groups.length" class="m-cat__empty">
          {{ $t("product.list.groupEmpty") }}
        </p>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, ref, watch } from "vue";

/**
 * 手机端目录选择器（底部抽屉内容）。
 *
 * 一级分组是顶部横向标签，二级分组是列表；点进有三级分组的二级分组后，
 * 列表原地换成它的三级分组（带返回），选完自动收起抽屉。这样手机上
 * 只占一个入口，不再有「先整页选一级、再顶部折叠面板选二/三级」两套交互。
 */
const props = defineProps({
  types: { type: Array, default: () => [] },
  activeType: { type: [String, Number], default: "" },
  groups: { type: Array, default: () => [] },
  childGroups: { type: Array, default: () => [] },
  activeGroupId: { type: [Number, String], default: 0 },
  activeChildId: { type: [Number, String], default: 0 },
  loading: { type: Boolean, default: false },
  visible: { type: Boolean, default: false },
});

const emit = defineEmits([
  "select-type",
  "select-group",
  "select-child",
  "done",
]);

const drilledId = ref(0);

watch(
  () => props.visible,
  (visible) => {
    if (visible) {
      drilledId.value = 0;
    }
  },
);

// 换了一级分组，之前钻进去的二级分组已经不属于当前目录
watch(
  () => props.activeType,
  () => {
    drilledId.value = 0;
  },
);

const drilledGroup = computed(() => {
  if (!drilledId.value) {
    return null;
  }

  return (
    props.groups.find(
      (group) => Number(group.id) === Number(drilledId.value),
    ) || null
  );
});

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

function pickType(value) {
  if (sameId(value, props.activeType)) {
    return;
  }

  drilledId.value = 0;
  emit("select-type", value);
}

function pickGroup(group) {
  const id = Number(group?.id || 0);
  if (!id) {
    return;
  }

  emit("select-group", id);

  if (hasChildren(group)) {
    drilledId.value = id;
    return;
  }

  emit("done");
}

function pickChild(child) {
  const id = Number(child?.id || 0);
  if (!id) {
    return;
  }

  emit("select-child", id);
  emit("done");
}
</script>

<style scoped lang="scss">
.m-cat {
  display: flex;
  flex-direction: column;
  min-height: 100%;
}

.m-cat__types {
  display: flex;
  flex-shrink: 0;
  gap: 8px;
  overflow-x: auto;
  padding: 12px 14px;
  border-bottom: 1px solid $divider-color;
  scrollbar-width: none;

  &::-webkit-scrollbar {
    display: none;
  }
}

.m-cat__type {
  flex-shrink: 0;
  height: 34px;
  padding: 0 16px;
  border: 1px solid $divider-color;
  border-radius: 17px;
  background: #fff;
  color: $text-color-secondary;
  font-size: 14px;
  cursor: pointer;
  transition:
    border-color $motion-fast ease,
    background-color $motion-fast ease,
    color $motion-fast ease;

  &.is-active {
    border-color: $color-primary;
    background: $color-primary-soft;
    color: $color-primary;
    font-weight: 600;
  }
}

.m-cat__body {
  flex: 1;
  min-height: 0;
  overflow-y: auto;
  padding: 6px 0 20px;
}

/* 目录层级切换时的入场过渡：轻微上移 + 淡入，不做大幅位移 */
.m-cat__panel {
  display: flex;
  flex-direction: column;
  animation: m-cat-panel-in 240ms cubic-bezier(0.4, 0, 0.2, 1) both;
}

@keyframes m-cat-panel-in {
  from {
    opacity: 0;
    transform: translateY(-4px);
  }

  to {
    opacity: 1;
    transform: translateY(0);
  }
}

.m-cat__back {
  display: flex;
  width: 100%;
  align-items: center;
  gap: 6px;
  padding: 12px 16px;
  border: 0;
  background: transparent;
  color: $text-color-secondary;
  font-size: 13px;
  text-align: left;
  cursor: pointer;
  transition: color $motion-fast ease;
}

.m-cat__row {
  display: flex;
  width: 100%;
  min-height: 50px;
  align-items: center;
  gap: 10px;
  padding: 0 16px;
  border: 0;
  border-bottom: 1px solid rgba($divider-color, 0.7);
  background: transparent;
  color: $text-color-primary;
  font-size: 15px;
  text-align: left;
  cursor: pointer;
  transition:
    background-color 180ms cubic-bezier(0.4, 0, 0.2, 1),
    color 180ms cubic-bezier(0.4, 0, 0.2, 1);

  &.is-active {
    background: $color-primary-soft;
    color: $color-primary;
    font-weight: 600;
  }
}

.m-cat__name {
  flex: 1;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.m-cat__count {
  flex-shrink: 0;
  color: $text-color-placeholder;
  font-size: 12.5px;
}

.m-cat__chevron {
  flex-shrink: 0;
  color: $text-color-placeholder;
  transition: transform 240ms cubic-bezier(0.4, 0, 0.2, 1);
}

.m-cat__row:hover .m-cat__chevron {
  transform: translateX(2px);
}

.m-cat__empty {
  margin: 18px 16px;
  color: $text-color-placeholder;
  font-size: 13.5px;
  text-align: center;
}

/* 抽屉里切换目录时的行骨架：不显示转圈遮罩，保持列表结构可预期 */
.m-cat__skeleton {
  display: flex;
  flex-direction: column;
}

.m-cat__skeleton-row {
  display: flex;
  align-items: center;
  min-height: 50px;
  padding: 0 16px;
  border-bottom: 1px solid rgba($divider-color, 0.7);
}

.m-cat__skeleton-line {
  display: block;
  width: 46%;
  height: 13px;
  border-radius: 4px;
  background: linear-gradient(
    90deg,
    rgba(15, 23, 42, 0.05) 25%,
    rgba(15, 23, 42, 0.09) 37%,
    rgba(15, 23, 42, 0.05) 63%
  );
  background-size: 400% 100%;
  animation: m-cat-skeleton-shimmer 1.4s ease infinite;
}

@keyframes m-cat-skeleton-shimmer {
  0% {
    background-position: 100% 50%;
  }

  100% {
    background-position: 0 50%;
  }
}

@media (prefers-reduced-motion: reduce) {
  .m-cat__panel {
    animation: none;
  }

  .m-cat__skeleton-line {
    animation: none;
  }

  .m-cat__chevron,
  .m-cat__row {
    transition-duration: 1ms;
  }
}
</style>
