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

      <div ref="groupCol" class="mop-col" aria-label="系统">
        <div class="mop-spacer" :style="{ height: spacerH + 'px' }"></div>
        <button
          v-for="g in osGroups"
          :key="g.id"
          :data-id="g.id"
          type="button"
          class="mop-item"
          :class="{ 'is-active': tempGroupId === g.id }"
          @click="handleGroupClick(g)"
        >
          <img v-if="g.icon" :src="g.icon" :alt="g.label" class="mop-item-icon" />
          <span v-else class="mop-item-abbr">{{ g.label.slice(0, 2) }}</span>
          {{ g.label }}
        </button>
        <div class="mop-spacer" :style="{ height: spacerH + 'px' }"></div>
      </div>

      <div ref="verCol" class="mop-col" aria-label="版本">
        <div class="mop-spacer" :style="{ height: spacerH + 'px' }"></div>
        <button
          v-for="v in currentVersions"
          :key="v.id"
          :data-id="v.id"
          type="button"
          class="mop-item"
          :class="{ 'is-active': tempVersionId === v.id }"
          @click="handleVersionClick(v)"
        >{{ v.label }}</button>
        <div v-if="!currentVersions.length" class="mop-empty">请先选择系统</div>
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

const currentVersions = computed(() => {
  const group = props.osGroups.find((g) => g.id === tempGroupId.value)
  return group?.versions || []
})

watch(() => props.visible, (v) => {
  if (!v) {
    cleanup()
    return
  }
  tempGroupId.value = props.activeOsGroupId
  tempVersionId.value = props.activeOsVersionId
  nextTick(() => {
    updateSpacers()
    bindScrolls()
    resizeObs = new ResizeObserver(() => updateSpacers())
    resizeObs.observe(groupCol.value)
  })
})

function handleOpened() {
  updateSpacers()
  init()
}

watch(currentVersions, () => {
  if (!props.visible) return
  nextTick(() => {
    if (!verCol.value) return
    const item = verCol.value.querySelector(`[data-id="${tempVersionId.value}"]`)
      || verCol.value.querySelector('.mop-item')
    if (item) { setActive(verCol.value, item); updateScales(verCol.value) }
  })
}, { deep: true })

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
  setTimeout(onEnd, 500)
}

function updateScales(col) {
  if (!col) return
  const items = col.querySelectorAll('.mop-item')
  const halfH = col.clientHeight / 2
  items.forEach((item) => {
    const center = item.offsetTop + item.offsetHeight / 2 - col.scrollTop
    const ratio = Math.min(Math.abs(center - halfH) / halfH, 1)
    item.style.transform = `scale(${1 - ratio * 0.35})`
    item.style.opacity = 1 - ratio * 0.6
  })
}

function setActive(col, item) {
  col.querySelectorAll('.mop-item').forEach((el) => el.classList.toggle('is-active', el === item))
  centerItem(item)
}

function findClosest(col) {
  const items = col.querySelectorAll('.mop-item')
  const rect = col.getBoundingClientRect()
  const centerY = rect.top + rect.height / 2
  let closest = null
  let min = Infinity
  items.forEach((item) => {
    const r = item.getBoundingClientRect()
    const dist = Math.abs(r.top + r.height / 2 - centerY)
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

function syncClosestBeforeConfirm() {
  if (programmaticScrollCount > 0) return

  const groupItem = groupCol.value ? findClosest(groupCol.value) : null
  if (groupItem) {
    tempGroupId.value = groupItem.dataset.id
    groupCol.value.querySelectorAll('.mop-item').forEach((el) => el.classList.toggle('is-active', el === groupItem))
    updateScales(groupCol.value)
  }

  const versionItem = verCol.value ? findClosest(verCol.value) : null
  if (versionItem) {
    tempVersionId.value = versionItem.dataset.id
    verCol.value.querySelectorAll('.mop-item').forEach((el) => el.classList.toggle('is-active', el === versionItem))
    updateScales(verCol.value)
  }
}

function handleGroupClick(g) {
  tempGroupId.value = g.id
  setActive(groupCol.value, groupCol.value.querySelector(`[data-id="${g.id}"]`))
  if (g.versions?.length) {
    tempVersionId.value = g.versions[0].id
  }
}

function handleVersionClick(v) {
  tempVersionId.value = v.id
  setActive(verCol.value, verCol.value.querySelector(`[data-id="${v.id}"]`))
}

function handleConfirm() {
  syncClosestBeforeConfirm()
  emit('confirm', tempGroupId.value, tempVersionId.value)
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
    const item = groupCol.value.querySelector(`[data-id="${tempGroupId.value}"]`)
      || groupCol.value.querySelector('.mop-item')
    if (item) { setActive(groupCol.value, item); updateScales(groupCol.value) }
  }
  if (verCol.value) {
    const item = verCol.value.querySelector(`[data-id="${tempVersionId.value}"]`)
      || verCol.value.querySelector('.mop-item')
    if (item) { setActive(verCol.value, item); updateScales(verCol.value) }
  }
}
</script>

<style scoped lang="scss">
.mop-picker {
  position: relative;
  height: 242px;
  display: grid;
  grid-template-columns: 55% 45%;
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
  will-change: transform, opacity;
  transition: transform 200ms cubic-bezier(0.22, 1, 0.36, 1),
              opacity 200ms cubic-bezier(0.22, 1, 0.36, 1),
              color 160ms ease-out;
}

.mop-item.is-active {
  color: $color-primary;
  font-weight: 700;
}

.mop-item-icon {
  width: 20px;
  height: 20px;
  object-fit: contain;
  flex-shrink: 0;
}

.mop-item-abbr {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 20px;
  height: 20px;
  border-radius: 3px;
  background: #f0f2f5;
  color: #7c8593;
  font-size: 10px;
  font-weight: 700;
  flex-shrink: 0;
}

.mop-empty {
  padding: 20px 10px;
  text-align: center;
  font-size: 13px;
  color: $text-color-disabled;
}
</style>
