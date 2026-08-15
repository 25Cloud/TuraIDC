<template>
  <MobileSheet
    :visible="visible"
    size="282px"
    title="选择操作系统"
    cancel-text="取消"
    confirm-text="确定"
    :close-on-press-modal="false"
    @close="$emit('close')"
    @cancel="$emit('close')"
    @confirm="handleConfirm"
    @opened="handleOpened"
  >
    <div class="mop-picker">
      <div class="mop-frame" aria-hidden="true"></div>

      <div
        ref="groupCol"
        class="mop-col"
        aria-label="系统"
        tabindex="0"
        @keydown="handleGroupKeydown"
      >
        <div class="mop-spacer" :style="{ height: spacerH + 'px' }"></div>
        <button
          v-for="g in osGroups"
          :key="g.id"
          :data-id="g.id"
          type="button"
          class="mop-item"
          :class="{ 'is-active': tempGroupId === g.id }"
          tabindex="-1"
          @click="handleGroupClick(g)"
        >
          <img v-if="g.icon" :src="g.icon" :alt="g.label" class="mop-item-icon" />
          <span v-else class="mop-item-abbr">{{ g.label.slice(0, 2) }}</span>
          {{ g.label }}
        </button>
        <div v-if="!osGroups.length" class="mop-empty">暂无可用系统</div>
        <div class="mop-spacer" :style="{ height: spacerH + 'px' }"></div>
      </div>

      <div
        ref="verCol"
        class="mop-col"
        aria-label="版本"
        tabindex="0"
        @keydown="handleVerKeydown"
      >
        <div class="mop-spacer" :style="{ height: spacerH + 'px' }"></div>
        <button
          v-for="v in currentVersions"
          :key="v.id"
          :data-id="v.id"
          type="button"
          class="mop-item"
          :class="{ 'is-active': tempVersionId === v.id }"
          tabindex="-1"
          @click="handleVersionClick(v)"
        >{{ v.label }}</button>
        <div v-if="!osGroups.length" class="mop-empty">请先选择系统</div>
        <div v-else-if="!currentVersions.length" class="mop-empty">暂无可用版本</div>
        <div class="mop-spacer" :style="{ height: spacerH + 'px' }"></div>
      </div>
    </div>
  </MobileSheet>
</template>

<script setup>
import { ref, computed, watch, onBeforeUnmount, nextTick } from 'vue'
import MobileSheet from '@/components/MobileSheet.vue'

const props = defineProps({
  visible: { type: Boolean, default: false },
  osGroups: { type: Array, default: () => [] },
  activeOsGroupId: { type: String, default: '' },
  activeOsVersionId: { type: String, default: '' },
})

const emit = defineEmits(['close', 'confirm'])

const groupCol = ref(null)
const verCol = ref(null)
const tempGroupId = ref('')
const tempVersionId = ref('')
const spacerH = ref(104)
let scrollBound = false
let resizeObs = null
let removeScrollListeners = null
let programmaticScrollCount = 0

// 缓存 DOM 查询结果，避免每帧 querySelectorAll
let cachedGroupItems = []
let cachedVerItems = []

const currentVersions = computed(() => {
  const group = props.osGroups.find((g) => g.id === tempGroupId.value)
  return group?.versions || []
})

function refreshCache() {
  cachedGroupItems = groupCol.value
    ? Array.from(groupCol.value.querySelectorAll('.mop-item'))
    : []
  cachedVerItems = verCol.value
    ? Array.from(verCol.value.querySelectorAll('.mop-item'))
    : []
}

watch(() => props.visible, (v) => {
  if (!v) {
    cleanup()
    return
  }
  tempGroupId.value = props.activeOsGroupId
  tempVersionId.value = props.activeOsVersionId
  nextTick(() => {
    updateSpacers()
    refreshCache()
    bindScrolls()
    resizeObs = new ResizeObserver(() => updateSpacers())
    resizeObs.observe(groupCol.value)
  })
})

function handleOpened() {
  updateSpacers()
  refreshCache()
  init()
}

// 版本列表变化时刷新缓存并定位
watch(currentVersions, () => {
  if (!props.visible) return
  nextTick(() => {
    refreshCache()
    if (!verCol.value) return
    const item = cachedVerItems.find((el) => el.dataset.id === tempVersionId.value)
      || cachedVerItems[0]
    if (item) { setActive(verCol.value, item); updateScales(verCol.value) }
  })
})

onBeforeUnmount(() => {
  cleanup()
})

function cleanup() {
  scrollBound = false
  removeScrollListeners?.()
  removeScrollListeners = null
  resizeObs?.disconnect()
  resizeObs = null
  programmaticScrollCount = 0
  cachedGroupItems = []
  cachedVerItems = []
}

function updateSpacers() {
  const col = groupCol.value
  if (!col) return
  const h = (col.clientHeight - 34) / 2
  if (h > 0) spacerH.value = h
}

function centerItem(item) {
  const col = item.closest('.mop-col')
  if (!col) return
  programmaticScrollCount += 1
  col.scrollTo({ top: item.offsetTop - (col.clientHeight - item.offsetHeight) / 2, behavior: 'smooth' })
  let ended = false
  const onEnd = () => {
    if (ended) return
    ended = true
    col.removeEventListener('scrollend', onEnd)
    updateScales(col)
    programmaticScrollCount = Math.max(programmaticScrollCount - 1, 0)
  }
  col.addEventListener('scrollend', onEnd, { once: true })
  setTimeout(onEnd, 800)
}

function getCachedItems(col) {
  return col === groupCol.value ? cachedGroupItems : cachedVerItems
}

function updateScales(col) {
  if (!col) return
  const items = getCachedItems(col)
  const halfH = col.clientHeight / 2
  const scrollTop = col.scrollTop
  items.forEach((item) => {
    const center = item.offsetTop + item.offsetHeight / 2 - scrollTop
    const ratio = Math.min(Math.abs(center - halfH) / halfH, 1)
    const scale = Math.max(1 - ratio * 0.35, 0.78)
    const opacity = Math.max(1 - ratio * 0.6, 0.5)
    item.style.transform = `scale(${scale})`
    item.style.opacity = String(opacity)
  })
}

function setActive(col, item) {
  getCachedItems(col).forEach((el) => el.classList.toggle('is-active', el === item))
  centerItem(item)
}

function findClosest(col) {
  const items = getCachedItems(col)
  if (!items.length) return null
  const halfH = col.clientHeight / 2
  const scrollTop = col.scrollTop
  let closest = null
  let min = Infinity
  items.forEach((item) => {
    const center = item.offsetTop + item.offsetHeight / 2 - scrollTop
    const dist = Math.abs(center - halfH)
    if (dist < min) { min = dist; closest = item }
  })
  return closest
}

function snapToClosest(col, isGroup) {
  const closest = findClosest(col)
  if (!closest) return
  setActive(col, closest)
  if (isGroup) {
    const id = closest.dataset.id
    tempGroupId.value = id
    const group = props.osGroups.find((g) => g.id === id)
    if (group?.versions?.length) {
      tempVersionId.value = group.versions[0].id
    }
  } else {
    tempVersionId.value = closest.dataset.id
  }
}

// 确认前同步：程序化滚动中跳过视觉同步，直接使用已设置的目标值
function syncClosestBeforeConfirm() {
  if (programmaticScrollCount > 0) return

  const groupItem = groupCol.value ? findClosest(groupCol.value) : null
  if (groupItem) {
    tempGroupId.value = groupItem.dataset.id
    cachedGroupItems.forEach((el) => el.classList.toggle('is-active', el === groupItem))
    updateScales(groupCol.value)
  }

  const versionItem = verCol.value ? findClosest(verCol.value) : null
  if (versionItem) {
    tempVersionId.value = versionItem.dataset.id
    cachedVerItems.forEach((el) => el.classList.toggle('is-active', el === versionItem))
    updateScales(verCol.value)
  }
}

function handleGroupClick(g) {
  tempGroupId.value = g.id
  const item = cachedGroupItems.find((el) => el.dataset.id === g.id)
  if (item) setActive(groupCol.value, item)
  if (g.versions?.length) {
    tempVersionId.value = g.versions[0].id
  }
}

function handleVersionClick(v) {
  tempVersionId.value = v.id
  const item = cachedVerItems.find((el) => el.dataset.id === v.id)
  if (item) setActive(verCol.value, item)
}

function handleConfirm() {
  syncClosestBeforeConfirm()
  emit('confirm', tempGroupId.value, tempVersionId.value)
}

// ---- 键盘导航 ----

function navigateItem(col, items, direction, isGroup) {
  if (!items.length) return
  const activeItem = col.querySelector('.mop-item.is-active')
  const idx = activeItem ? items.indexOf(activeItem) : -1
  const nextIdx = idx + direction
  if (nextIdx < 0 || nextIdx >= items.length) return
  if (isGroup) {
    const group = props.osGroups.find((g) => g.id === items[nextIdx].dataset.id)
    if (group) handleGroupClick(group)
  } else {
    handleVersionClick({ id: items[nextIdx].dataset.id })
  }
}

function handleGroupKeydown(e) {
  if (e.key === 'ArrowUp') { e.preventDefault(); navigateItem(groupCol.value, cachedGroupItems, -1, true) }
  else if (e.key === 'ArrowDown') { e.preventDefault(); navigateItem(groupCol.value, cachedGroupItems, 1, true) }
}

function handleVerKeydown(e) {
  if (e.key === 'ArrowUp') { e.preventDefault(); navigateItem(verCol.value, cachedVerItems, -1, false) }
  else if (e.key === 'ArrowDown') { e.preventDefault(); navigateItem(verCol.value, cachedVerItems, 1, false) }
}

function bindScrolls() {
  if (scrollBound) return
  scrollBound = true
  const listeners = []
  ;[
    [groupCol.value, true],
    [verCol.value, false],
  ].forEach(([col, isGroup]) => {
    if (!col) return
    let timer = null
    let raf = null
    const handleScroll = () => {
      if (raf) cancelAnimationFrame(raf)
      raf = requestAnimationFrame(() => updateScales(col))
      if (programmaticScrollCount > 0) {
        return
      }
      if (timer) clearTimeout(timer)
      timer = setTimeout(() => snapToClosest(col, isGroup), 150)
    }
    col.addEventListener('scroll', handleScroll, { passive: true })
    listeners.push(() => {
      if (raf) cancelAnimationFrame(raf)
      if (timer) clearTimeout(timer)
      col.removeEventListener('scroll', handleScroll)
    })
  })
  removeScrollListeners = () => listeners.forEach((remove) => remove())
}

function init() {
  if (groupCol.value) {
    const item = cachedGroupItems.find((el) => el.dataset.id === tempGroupId.value)
      || cachedGroupItems[0]
    if (item) { setActive(groupCol.value, item); updateScales(groupCol.value) }
  }
  if (verCol.value) {
    const item = cachedVerItems.find((el) => el.dataset.id === tempVersionId.value)
      || cachedVerItems[0]
    if (item) { setActive(verCol.value, item); updateScales(verCol.value) }
  }
}
</script>

<style scoped lang="scss">
.mop-picker {
  position: relative;
  height: 242px;
  display: grid;
  grid-template-columns: 1fr 1fr;
  overflow: hidden;
}

.mop-frame {
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

.mop-col {
  height: 100%;
  overflow-y: auto;
  overscroll-behavior: contain;
  scroll-snap-type: y mandatory;
  scrollbar-width: none;
  -webkit-overflow-scrolling: touch;
  display: flex;
  flex-direction: column;
  outline: none;

  &:focus-visible {
    box-shadow: inset 0 0 0 2px $color-primary;
    border-radius: 2px;
  }
}

.mop-col::-webkit-scrollbar { display: none; }

.mop-spacer {
  flex-shrink: 0;
  pointer-events: none;
}

.mop-item {
  width: 100%;
  height: 34px;
  flex-shrink: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
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
  transition: transform 200ms cubic-bezier(0.22, 1, 0.36, 1),
              opacity 200ms cubic-bezier(0.22, 1, 0.36, 1),
              color 160ms ease-out;
  overflow: hidden;
  text-overflow: ellipsis;
}

.mop-item.is-active {
  color: $color-primary;
  font-weight: 600;
}

.mop-item-icon {
  width: 20px;
  height: 20px;
  object-fit: contain;
  flex-shrink: 0;
}

.mop-item-abbr {
  width: 20px;
  height: 20px;
  flex-shrink: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 12px;
  font-weight: 600;
  background: rgba($color-primary, 0.08);
  color: $color-primary;
  border-radius: 4px;
}

.mop-empty {
  height: 34px;
  flex-shrink: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  color: $text-color-disabled;
  font-size: 13px;
  scroll-snap-align: center;
}
</style>

