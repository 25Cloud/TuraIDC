<template>
  <section
    v-if="variant === 'sidebar'"
    class="pg-nav pg-nav--sidebar"
    :aria-label="$t('product.list.groupNav')"
  >
    <div class="pg-nav__list">
      <div v-for="group in groups" :key="group.id" class="pg-nav__group">
        <button
          type="button"
          class="pg-nav__group-head"
          :class="{ 'is-active': isActiveGroup(group) }"
          :aria-current="isActiveGroup(group) ? 'true' : undefined"
          @click="selectGroup(group)"
        >
          <span class="pg-nav__group-name">{{ group.name }}</span>
          <span v-if="showCount(group)" class="pg-nav__count">{{
            group.product_count
          }}</span>
          <svg
            v-if="isActiveGroup(group) && childGroups.length"
            class="pg-nav__chevron is-expanded"
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
          class="pg-nav__children"
        >
          <button
            v-for="child in childGroups"
            :key="child.id"
            type="button"
            class="pg-nav__child"
            :class="{ 'is-active': isActiveChild(child) }"
            :aria-current="isActiveChild(child) ? 'true' : undefined"
            @click="selectChild(child)"
          >
            <span class="pg-nav__child-name">{{ child.name }}</span>
            <span v-if="showCount(child)" class="pg-nav__count">{{
              child.product_count
            }}</span>
          </button>
        </div>
      </div>
    </div>
  </section>

  <section
    v-else
    class="pg-nav pg-nav--top"
    :aria-label="$t('product.list.groupNav')"
  >
    <button
      type="button"
      class="pg-nav__toggle"
      :aria-expanded="open"
      @click="open = !open"
    >
      <span class="pg-nav__toggle-title">{{
        $t("product.list.groupNav")
      }}</span>
      <span class="pg-nav__path">{{ activePathText }}</span>
      <svg
        class="pg-nav__chevron"
        :class="{ 'is-expanded': open }"
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

    <div v-if="open" class="pg-nav__panel">
      <div class="pg-nav__list">
        <div v-for="group in groups" :key="group.id" class="pg-nav__group">
          <button
            type="button"
            class="pg-nav__group-head"
            :class="{ 'is-active': isActiveGroup(group) }"
            :aria-current="isActiveGroup(group) ? 'true' : undefined"
            @click="selectGroup(group)"
          >
            <span class="pg-nav__group-name">{{ group.name }}</span>
            <span v-if="showCount(group)" class="pg-nav__count">{{
              group.product_count
            }}</span>
            <svg
              v-if="isActiveGroup(group) && childGroups.length"
              class="pg-nav__chevron is-expanded"
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
            class="pg-nav__children"
          >
            <button
              v-for="child in childGroups"
              :key="child.id"
              type="button"
              class="pg-nav__child"
              :class="{ 'is-active': isActiveChild(child) }"
              :aria-current="isActiveChild(child) ? 'true' : undefined"
              @click="selectChild(child)"
            >
              <span class="pg-nav__child-name">{{ child.name }}</span>
              <span v-if="showCount(child)" class="pg-nav__count">{{
                child.product_count
              }}</span>
            </button>
          </div>
        </div>
      </div>
    </div>
  </section>
</template>

<script setup>
import { computed, ref, watch } from "vue";

/**
 * 分组导航（二级分组 + 三级分组）。
 *
 * 两种形态：
 *  - top：页面顶部可折叠面板。二级分组纵向排列，点开的二级分组下纵向展开
 *    它的三级分组；面板限高滚动，不用横向 tab，也不会把很长的三级分组挤成
 *    只能点箭头的横条。手机端默认收起，展开时向下推内容而不是浮层遮挡。
 *  - sidebar：同一份结构放进左侧栏（桌面端），入口在展示方式切换条右侧。
 *
 * 只渲染当前已加载的 childGroups —— 切换二级分组会触发目录加载，加载完成后
 * 自然出现该分组的三级分组。
 */
const props = defineProps({
  groups: { type: Array, default: () => [] },
  childGroups: { type: Array, default: () => [] },
  activeGroupId: { type: [Number, String], default: 0 },
  activeChildId: { type: [Number, String], default: 0 },
  activeGroupName: { type: String, default: "" },
  activeChildName: { type: String, default: "" },
  variant: { type: String, default: "top" },
  startOpen: { type: Boolean, default: true },
  autoClose: { type: Boolean, default: false },
});

const emit = defineEmits(["select-group", "select-child"]);

const open = ref(props.startOpen);

// 页面初始化时 isMobile 还是 false（移动端要到 onMounted 才按视口修正），
// 首次渲染可能先按桌面展开。等 startOpen 变成 false 时自动收起，避免手机上
// 一进页面就被展开的分组面板占掉半屏。
watch(
  () => props.startOpen,
  (value) => {
    open.value = value;
  },
);

const activePathText = computed(() => {
  const parts = [props.activeGroupName, props.activeChildName].filter(Boolean);

  return parts.length ? parts.join(" › ") : "";
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
  if (props.autoClose) {
    open.value = false;
  }
}
</script>

<style scoped lang="scss">
.pg-nav {
  min-width: 0;
  color: $text-color-primary;
}

.pg-nav__caption {
  padding: 8px 14px 6px;
  color: $text-color-placeholder;
  font-size: 11px;
  letter-spacing: 0.04em;
}

.pg-nav__list {
  display: flex;
  flex-direction: column;
  gap: 2px;
  padding: 0 8px 8px;
}

.pg-nav__group {
  min-width: 0;
}

.pg-nav__group-head,
.pg-nav__child {
  display: flex;
  width: 100%;
  min-width: 0;
  align-items: center;
  gap: 6px;
  padding: 8px 8px;
  border: 0;
  border-radius: 4px;
  background: transparent;
  color: $text-color-primary;
  font-size: 13px;
  text-align: left;
  cursor: pointer;
  transition:
    background-color $motion-fast ease,
    color $motion-fast ease;

  &:hover {
    background: $bg-color-hover;
    color: $color-primary;
  }

  &.is-active {
    background: $color-primary-soft;
    color: $color-primary;
    font-weight: 600;
  }
}

.pg-nav__group-name,
.pg-nav__child-name {
  flex: 1;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.pg-nav__count {
  flex-shrink: 0;
  min-width: 20px;
  color: $text-color-placeholder;
  font-size: 11px;
  text-align: right;
}

.pg-nav__chevron {
  flex-shrink: 0;
  color: $text-color-placeholder;
  transition: transform $motion-fast ease;

  &.is-expanded {
    transform: rotate(180deg);
  }
}

.pg-nav__children {
  display: flex;
  flex-direction: column;
  gap: 2px;
  margin: 2px 0 4px 10px;
  padding-left: 8px;
  border-left: 1px solid $divider-color;

  .pg-nav__caption {
    padding: 4px 8px;
  }
}

.pg-nav__child {
  padding: 7px 8px;
  color: $text-color-secondary;
  font-size: 12.5px;
}

/* ===== 顶部折叠形态 ===== */
.pg-nav--top {
  border-bottom: 1px solid $divider-color;
  background: #fff;
}

.pg-nav__toggle {
  display: flex;
  width: 100%;
  align-items: center;
  gap: 10px;
  padding: 12px 20px;
  border: 0;
  background: transparent;
  color: $text-color-primary;
  font-size: 13px;
  text-align: left;
  cursor: pointer;
}

.pg-nav__toggle-title {
  flex-shrink: 0;
  font-weight: 600;
}

.pg-nav__path {
  flex: 1;
  min-width: 0;
  overflow: hidden;
  color: $text-color-secondary;
  font-size: 12.5px;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.pg-nav__panel {
  max-height: 300px;
  overflow-y: auto;
  border-top: 1px solid rgba($divider-color, 0.7);
  background: $bg-color-soft;
  padding: 4px 8px 6px;
}

/* ===== 侧栏形态 ===== */
.pg-nav--sidebar {
  flex-shrink: 0;
  border-top: 1px solid $divider-color;
  padding-top: 4px;

  .pg-nav__list {
    padding: 0 6px 10px;
  }

  .pg-nav__group-head {
    font-size: 12.5px;
  }
}

@media (max-width: 900px) {
  .pg-nav__toggle {
    padding: 10px 14px;
  }

  .pg-nav__panel {
    max-height: 52vh;
  }
}
</style>
