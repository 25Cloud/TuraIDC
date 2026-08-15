<template>
  <MobileSheet
    :visible="visible"
    size="282px"
    title="选择地区及可用区"
    cancel-text="取消"
    confirm-text="确定"
    :close-on-press-modal="false"
    @close="$emit('close')"
    @cancel="$emit('close')"
    @confirm="handleConfirm"
    @opened="handleOpened"
  >
    <div class="mrp-picker">
      <div class="mrp-frame" aria-hidden="true"></div>

      <div ref="regionCol" class="mrp-col" aria-label="地区">
        <div class="mrp-spacer" :style="{ height: spacerH + 'px' }"></div>
        <button
          v-for="g in regions"
          :key="g.id"
          :data-id="g.id"
          type="button"
          class="mrp-item"
          :class="{ 'is-active': tempGroupId === g.id }"
          @click="handleRegionClick(g)"
        >
          {{ g.name }}
        </button>
        <div class="mrp-spacer" :style="{ height: spacerH + 'px' }"></div>
      </div>

      <div ref="zoneCol" class="mrp-col" aria-label="可用区">
        <div class="mrp-spacer" :style="{ height: spacerH + 'px' }"></div>
        <button
          v-for="z in zoneMap"
          :key="z.id"
          :data-id="z.id"
          type="button"
          class="mrp-item"
          :class="{ 'is-active': tempZoneId === z.id }"
          @click="handleZoneClick(z)"
        >
          {{ z.name }}
        </button>
        <div class="mrp-spacer" :style="{ height: spacerH + 'px' }"></div>
      </div>
    </div>
  </MobileSheet>
</template>

<script setup>
import { ref, watch, onBeforeUnmount, nextTick } from "vue";
import MobileSheet from "@/components/MobileSheet.vue";

const props = defineProps({
  visible: { type: Boolean, default: false },
  regions: { type: Array, default: () => [] },
  zoneMap: { type: Array, default: () => [] },
  activeGroupId: { type: Number, default: 0 },
  activeZoneId: { type: Number, default: 0 },
});

const emit = defineEmits(["close", "confirm", "change"]);

const regionCol = ref(null);
const zoneCol = ref(null);
const tempGroupId = ref(0);
const tempZoneId = ref(0);
const spacerH = ref(104);
let scrollBound = false;
let resizeObs = null;
let removeScrollListeners = null;
let programmaticScrollCount = 0;
let centerTimers = [];
let cachedRegionItems = [];
let cachedZoneItems = [];

watch(
  () => props.visible,
  (v) => {
    if (!v) {
      cleanup();
      return;
    }
    tempGroupId.value = props.activeGroupId;
    tempZoneId.value = props.activeZoneId;
    nextTick(() => {
      updateSpacers();
      refreshCache();
      bindScrolls();
      resizeObs = new ResizeObserver(() => updateSpacers());
      resizeObs.observe(regionCol.value);
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
  removeScrollListeners?.();
  removeScrollListeners = null;
  resizeObs?.disconnect();
  resizeObs = null;
  programmaticScrollCount = 0;
  centerTimers.forEach((t) => clearTimeout(t));
  centerTimers = [];
  cachedRegionItems = [];
  cachedZoneItems = [];
}

// 缓存 DOM 查询结果，避免滚动每帧 querySelectorAll
function refreshCache() {
  cachedRegionItems = regionCol.value
    ? Array.from(regionCol.value.querySelectorAll(".mrp-item"))
    : [];
  cachedZoneItems = zoneCol.value
    ? Array.from(zoneCol.value.querySelectorAll(".mrp-item"))
    : [];
}

function getCachedItems(col) {
  return col === regionCol.value ? cachedRegionItems : cachedZoneItems;
}

function updateSpacers() {
  const col = regionCol.value;
  if (!col) return;
  const h = (col.clientHeight - 34) / 2;
  if (h > 0) spacerH.value = h;
}

function centerItem(item) {
  const col = item.closest(".mrp-col");
  if (!col) return;
  programmaticScrollCount += 1;
  col.scrollTo({
    top: item.offsetTop - (col.clientHeight - item.offsetHeight) / 2,
    behavior: "smooth",
  });
  let ended = false;
  const onEnd = () => {
    if (ended) return;
    ended = true;
    col.removeEventListener("scrollend", onEnd);
    updateScales(col);
    programmaticScrollCount = Math.max(programmaticScrollCount - 1, 0);
  };
  col.addEventListener("scrollend", onEnd, { once: true });
  // 兜底定时器存句柄，cleanup 时清理，避免抽屉关闭后仍对隐藏 DOM 写样式
  centerTimers.push(setTimeout(onEnd, 500));
}

function updateScales(col) {
  if (!col) return;
  const items = getCachedItems(col);
  const halfH = col.clientHeight / 2;
  const scrollTop = col.scrollTop;
  items.forEach((item) => {
    const center = item.offsetTop + item.offsetHeight / 2 - scrollTop;
    const ratio = Math.min(Math.abs(center - halfH) / halfH, 1);
    item.style.transform = `scale(${1 - ratio * 0.35})`;
    item.style.opacity = 1 - ratio * 0.6;
  });
}

function setActive(col, item) {
  getCachedItems(col).forEach((el) =>
    el.classList.toggle("is-active", el === item),
  );
  centerItem(item);
}

function findClosest(col) {
  const items = getCachedItems(col);
  if (!items.length) return null;
  const halfH = col.clientHeight / 2;
  const scrollTop = col.scrollTop;
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

function snapToClosest(col, isRegion) {
  const closest = findClosest(col);
  if (!closest) return;
  setActive(col, closest);
  const nextId = Number(closest.dataset.id);
  if (isRegion) {
    tempGroupId.value = nextId;
    tempZoneId.value = 0;
    emit("change", nextId);
  } else {
    tempZoneId.value = nextId;
  }
}

function handleRegionClick(g) {
  tempGroupId.value = g.id;
  const item = cachedRegionItems.find((el) => el.dataset.id === String(g.id));
  if (item) setActive(regionCol.value, item);
  emit("change", g.id);
}

function handleZoneClick(z) {
  tempZoneId.value = z.id;
  const item = cachedZoneItems.find((el) => el.dataset.id === String(z.id));
  if (item) setActive(zoneCol.value, item);
}

function handleConfirm() {
  if (programmaticScrollCount === 0) {
    const regionItem = regionCol.value ? findClosest(regionCol.value) : null;
    if (regionItem) {
      tempGroupId.value = Number(regionItem.dataset.id);
      cachedRegionItems.forEach((el) =>
        el.classList.toggle("is-active", el === regionItem),
      );
      updateScales(regionCol.value);
    }

    const zoneItem = zoneCol.value ? findClosest(zoneCol.value) : null;
    if (zoneItem) {
      tempZoneId.value = Number(zoneItem.dataset.id);
      cachedZoneItems.forEach((el) =>
        el.classList.toggle("is-active", el === zoneItem),
      );
      updateScales(zoneCol.value);
    }
  }

  emit("confirm", tempGroupId.value, tempZoneId.value);
}

function bindScrolls() {
  if (scrollBound) return;
  scrollBound = true;
  const listeners = [];
  [
    [regionCol.value, true],
    [zoneCol.value, false],
  ].forEach(([col, isRegion]) => {
    if (!col) return;
    let timer = null;
    let raf = null;
    const handleScroll = () => {
      if (raf) cancelAnimationFrame(raf);
      raf = requestAnimationFrame(() => updateScales(col));
      if (programmaticScrollCount > 0) {
        return;
      }
      if (timer) clearTimeout(timer);
      timer = setTimeout(() => snapToClosest(col, isRegion), 150);
    };
    col.addEventListener("scroll", handleScroll, { passive: true });
    listeners.push(() => {
      if (raf) cancelAnimationFrame(raf);
      if (timer) clearTimeout(timer);
      col.removeEventListener("scroll", handleScroll);
    });
  });
  removeScrollListeners = () => listeners.forEach((remove) => remove());
}

function init() {
  if (regionCol.value) {
    const item =
      cachedRegionItems.find(
        (el) => el.dataset.id === String(tempGroupId.value),
      ) || cachedRegionItems[0];
    if (item) {
      setActive(regionCol.value, item);
      updateScales(regionCol.value);
    }
  }
  if (zoneCol.value) {
    const item =
      cachedZoneItems.find(
        (el) => el.dataset.id === String(tempZoneId.value),
      ) || cachedZoneItems[0];
    if (item) {
      setActive(zoneCol.value, item);
      updateScales(zoneCol.value);
    }
  }
}

watch(
  () => props.zoneMap,
  () => {
    if (!props.visible) return;
    nextTick(() => {
      if (!zoneCol.value) return;
      refreshCache();
      if (!props.zoneMap.some((zone) => zone.id === tempZoneId.value)) {
        tempZoneId.value = props.zoneMap[0]?.id || 0;
      }
      const item =
        cachedZoneItems.find(
          (el) => el.dataset.id === String(tempZoneId.value),
        ) || cachedZoneItems[0];
      if (item) {
        setActive(zoneCol.value, item);
        updateScales(zoneCol.value);
      }
    });
  },
  { deep: true },
);
</script>

<style scoped lang="scss">
.mrp-picker {
  position: relative;
  height: 242px;
  display: grid;
  grid-template-columns: 55% 45%;
  overflow: hidden;
}

.mrp-frame {
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

.mrp-col {
  height: 100%;
  overflow-y: auto;
  overscroll-behavior: contain;
  scroll-snap-type: y mandatory;
  scrollbar-width: none;
  -webkit-overflow-scrolling: touch;
  display: flex;
  flex-direction: column;
}

.mrp-col::-webkit-scrollbar {
  display: none;
}

.mrp-spacer {
  flex-shrink: 0;
  pointer-events: none;
}

.mrp-item {
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
  font-size: 16px;
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

.mrp-item.is-active {
  color: $color-primary;
  font-weight: 700;
}
</style>
