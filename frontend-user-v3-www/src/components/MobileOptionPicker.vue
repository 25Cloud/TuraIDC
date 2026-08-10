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
        >{{ opt.label }}</button>
        <div class="mopt-spacer" :style="{ height: spacerH + 'px' }"></div>
      </div>
    </div>
  </MobileSheet>
</template>

<script setup>
import { ref, watch, onBeforeUnmount, nextTick } from 'vue'
import MobileSheet from '@/components/MobileSheet.vue'

const props = defineProps({
  visible: { type: Boolean, default: false },
  title: { type: String, default: '请选择' },
  options: { type: Array, default: () => [] },
  activeId: { type: String, default: '' },
})

const emit = defineEmits(['close', 'confirm'])

const col = ref(null)
const tempId = ref('')
const spacerH = ref(120)
let scrollBound = false
let resizeObs = null
let removeScrollListener = null
let programmaticScrollCount = 0

watch(() => props.visible, (v) => {
  if (!v) {
    cleanup()
    return
  }
  tempId.value = props.activeId
  nextTick(() => {
    updateSpacers()
    bindScroll()
    resizeObs = new ResizeObserver(() => updateSpacers())
    resizeObs.observe(col.value)
  })
})

function handleOpened() {
  updateSpacers()
  init()
}

onBeforeUnmount(() => {
  cleanup()
})

function cleanup() {
  scrollBound = false
  removeScrollListener?.()
  removeScrollListener = null
  resizeObs?.disconnect()
  resizeObs = null
  programmaticScrollCount = 0
}

function updateSpacers() {
  if (!col.value) return
  const h = (col.value.clientHeight - 34) / 2
  if (h > 0) spacerH.value = h
}

function centerItem(item) {
  const c = item.closest('.mopt-col')
  if (!c) return
  programmaticScrollCount += 1
  c.scrollTo({ top: item.offsetTop - (c.clientHeight - item.offsetHeight) / 2, behavior: 'smooth' })
  let ended = false
  const onEnd = () => {
    if (ended) return
    ended = true
    c.removeEventListener('scrollend', onEnd)
    updateScales(c)
    programmaticScrollCount = Math.max(programmaticScrollCount - 1, 0)
  }
  c.addEventListener('scrollend', onEnd, { once: true })
  setTimeout(onEnd, 500)
}

function updateScales(c) {
  if (!c) return
  const items = c.querySelectorAll('.mopt-item')
  const halfH = c.clientHeight / 2
  items.forEach((item) => {
    const center = item.offsetTop + item.offsetHeight / 2 - c.scrollTop
    const ratio = Math.min(Math.abs(center - halfH) / halfH, 1)
    item.style.transform = `scale(${1 - ratio * 0.35})`
    item.style.opacity = 1 - ratio * 0.6
  })
}

function setActive(c, item) {
  c.querySelectorAll('.mopt-item').forEach((el) => el.classList.toggle('is-active', el === item))
  centerItem(item)
}

function findClosest() {
  const items = col.value.querySelectorAll('.mopt-item')
  const rect = col.value.getBoundingClientRect()
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

function snapToClosest() {
  const closest = findClosest()
  if (!closest) return
  setActive(col.value, closest)
  tempId.value = closest.dataset.id
}

function handleClick(opt) {
  tempId.value = opt.id
  setActive(col.value, col.value.querySelector(`[data-id="${opt.id}"]`))
}

function handleConfirm() {
  if (programmaticScrollCount === 0) {
    const closest = findClosest()
    if (closest) {
      tempId.value = closest.dataset.id
      col.value.querySelectorAll('.mopt-item').forEach((el) => el.classList.toggle('is-active', el === closest))
      updateScales(col.value)
    }
  }
  emit('confirm', tempId.value)
}

function bindScroll() {
  if (scrollBound) return
  scrollBound = true
  let timer = null
  let raf = null
    const handleScroll = () => {
      if (raf) cancelAnimationFrame(raf)
      raf = requestAnimationFrame(() => updateScales(col.value))
      if (programmaticScrollCount > 0) {
        return
      }
      if (timer) clearTimeout(timer)
      timer = setTimeout(snapToClosest, 150)
    }
  col.value.addEventListener('scroll', handleScroll, { passive: true })
  removeScrollListener = () => {
    if (raf) cancelAnimationFrame(raf)
    if (timer) clearTimeout(timer)
    col.value?.removeEventListener('scroll', handleScroll)
  }
}

function init() {
  const item = col.value.querySelector(`[data-id="${tempId.value}"]`)
    || col.value.querySelector('.mopt-item')
  if (item) { setActive(col.value, item); updateScales(col.value) }
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

.mopt-col::-webkit-scrollbar { display: none; }

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
  will-change: transform, opacity;
  transition: transform 200ms cubic-bezier(0.22, 1, 0.36, 1),
              opacity 200ms cubic-bezier(0.22, 1, 0.36, 1),
              color 160ms ease-out;
}

.mopt-item.is-active {
  color: $color-primary;
  font-weight: 700;
}
</style>
