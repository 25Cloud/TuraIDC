<template>
  <MobileSheet
    :visible="visible"
    size="282px"
    :title="title"
    cancel-text="取消"
    confirm-text="确定"
    :close-on-press-modal="false"
    @close="$emit('close')"
    @cancel="$emit('close')"
    @confirm="handleConfirm"
    @opened="handleOpened"
  >
    <div class="mopt-picker">
      <div class="mopt-frame" aria-hidden="true"></div>
      <div ref="col" class="mopt-col">
        <div class="mopt-spacer" :style="{ height: spacerH + 'px' }"></div>
        <button
          v-for="opt in options"
          :key="opt.id"
          :data-id="opt.id"
          type="button"
          class="mopt-item"
          :class="{ 'is-active': tempId === opt.id }"
          @click="handleClick(opt)"
        >
          {{ opt.label }}
        </button>
        <div class="mopt-spacer" :style="{ height: spacerH + 'px' }"></div>
      </div>
    </div>
  </MobileSheet>
</template>

<script setup>
import { ref, watch, onBeforeUnmount, nextTick } from "vue";
import MobileSheet from "@/components/MobileSheet.vue";

const props = defineProps({
  visible: { type: Boolean, default: false },
  title: { type: String, default: "请选择" },
  options: { type: Array, default: () => [] },
  activeId: { type: String, default: "" },
});

const emit = defineEmits(["close", "confirm"]);

const col = ref(null);
const tempId = ref("");
const spacerH = ref(120);
let scrollBound = false;
let resizeObs = null;
let removeScrollListener = null;
let programmaticScrollCount = 0;
let centerTimers = [];
let cachedItems = [];

watch(
  () => props.visible,
  (v) => {
    if (!v) {
      cleanup();
      return;
    }
    tempId.value = props.activeId;
    nextTick(() => {
      updateSpacers();
      refreshCache();
      bindScroll();
      resizeObs = new ResizeObserver(() => updateSpacers());
      resizeObs.observe(col.value);
    });
  },
);

function handleOpened() {
  updateSpacers();
  refreshCache();
  init();
}

onBeforeUnmount(() => {
  cleanup();
});

function cleanup() {
  scrollBound = false;
  removeScrollListener?.();
  removeScrollListener = null;
  resizeObs?.disconnect();
  resizeObs = null;
  programmaticScrollCount = 0;
  centerTimers.forEach((t) => clearTimeout(t));
  centerTimers = [];
  cachedItems = [];
}

// 缓存 DOM 查询结果，避免滚动每帧 querySelectorAll
function refreshCache() {
  cachedItems = col.value
    ? Array.from(col.value.querySelectorAll(".mopt-item"))
    : [];
}

function updateSpacers() {
  if (!col.value) return;
  const h = (col.value.clientHeight - 34) / 2;
  if (h > 0) spacerH.value = h;
}

function centerItem(item) {
  const c = item.closest(".mopt-col");
  if (!c) return;
  programmaticScrollCount += 1;
  c.scrollTo({
    top: item.offsetTop - (c.clientHeight - item.offsetHeight) / 2,
    behavior: "smooth",
  });
  let ended = false;
  const onEnd = () => {
    if (ended) return;
    ended = true;
    c.removeEventListener("scrollend", onEnd);
    updateScales(c);
    programmaticScrollCount = Math.max(programmaticScrollCount - 1, 0);
  };
  c.addEventListener("scrollend", onEnd, { once: true });
  // 兜底定时器存句柄，cleanup 时统一清理；不清前一个，保证 programmaticScrollCount 正确递减
  centerTimers.push(setTimeout(onEnd, 500));
}

function updateScales(c) {
  if (!c) return;
  const items = cachedItems;
  const halfH = c.clientHeight / 2;
  const scrollTop = c.scrollTop;
  items.forEach((item) => {
    const center = item.offsetTop + item.offsetHeight / 2 - scrollTop;
    const ratio = Math.min(Math.abs(center - halfH) / halfH, 1);
    item.style.transform = `scale(${1 - ratio * 0.35})`;
    item.style.opacity = 1 - ratio * 0.6;
  });
}

function setActive(c, item) {
  cachedItems.forEach((el) => el.classList.toggle("is-active", el === item));
  centerItem(item);
}

function findClosest() {
  const items = cachedItems;
  if (!items.length) return null;
  const halfH = col.value.clientHeight / 2;
  const scrollTop = col.value.scrollTop;
  let closest = null;
  let min = Infinity;
  items.forEach((item) => {
    const center = item.offsetTop + item.offsetHeight / 2 - scrollTop;
    const dist = Math.abs(center - halfH);
    if (dist < min) {
      min = dist;
      closest = item;
    }
  });
  return closest;
}

function snapToClosest() {
  const closest = findClosest();
  if (!closest) return;
  setActive(col.value, closest);
  tempId.value = closest.dataset.id;
}

function handleClick(opt) {
  tempId.value = opt.id;
  const item = cachedItems.find((el) => el.dataset.id === String(opt.id));
  if (item) setActive(col.value, item);
}

function handleConfirm() {
  if (programmaticScrollCount === 0) {
    const closest = findClosest();
    if (closest) {
      tempId.value = closest.dataset.id;
      cachedItems.forEach((el) =>
        el.classList.toggle("is-active", el === closest),
      );
      updateScales(col.value);
    }
  }
  emit("confirm", tempId.value);
}

function bindScroll() {
  if (scrollBound) return;
  scrollBound = true;
  let timer = null;
  let raf = null;
  const handleScroll = () => {
    if (raf) cancelAnimationFrame(raf);
    raf = requestAnimationFrame(() => updateScales(col.value));
    if (programmaticScrollCount > 0) {
      return;
    }
    if (timer) clearTimeout(timer);
    timer = setTimeout(snapToClosest, 150);
  };
  col.value.addEventListener("scroll", handleScroll, { passive: true });
  removeScrollListener = () => {
    if (raf) cancelAnimationFrame(raf);
    if (timer) clearTimeout(timer);
    col.value?.removeEventListener("scroll", handleScroll);
  };
}

function init() {
  const item =
    cachedItems.find((el) => el.dataset.id === String(tempId.value)) ||
    cachedItems[0];
  if (item) {
    setActive(col.value, item);
    updateScales(col.value);
  }
}
</script>

<style scoped lang="scss">
.mopt-picker {
  position: relative;
  height: 242px;
  overflow: hidden;
}

.mopt-frame {
  position: absolute;
  left: 0;
  right: 0;
  top: 50%;
  z-index: 20;
  height: 34px;
  transform: translateY(-50%);
  border-top: 1px solid $color-primary;
  border-bottom: 1px solid $color-primary;
  background: rgba($color-primary, 0.035);
  pointer-events: none;
}

.mopt-col {
  height: 100%;
  overflow-y: auto;
  overscroll-behavior: contain;
  scroll-snap-type: y mandatory;
  scrollbar-width: none;
  -webkit-overflow-scrolling: touch;
  display: flex;
  flex-direction: column;
}

.mopt-col::-webkit-scrollbar {
  display: none;
}

.mopt-spacer {
  flex-shrink: 0;
  pointer-events: none;
}

.mopt-item {
  width: 100%;
  height: 34px;
  flex-shrink: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  border: 0;
  padding: 0 10px;
  background: transparent;
  color: $text-color-disabled;
  font-size: 15px;
  line-height: 34px;
  white-space: nowrap;
  cursor: pointer;
  scroll-snap-align: center;
  transform-origin: center center;
  transition:
    transform 200ms cubic-bezier(0.22, 1, 0.36, 1),
    opacity 200ms cubic-bezier(0.22, 1, 0.36, 1),
    color 160ms ease-out;
}

.mopt-item.is-active {
  color: $color-primary;
  font-weight: 700;
}
</style>
