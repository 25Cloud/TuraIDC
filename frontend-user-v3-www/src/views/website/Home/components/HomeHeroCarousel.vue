<template>
  <section class="hero-section">
    <div class="hero-bg" aria-hidden="true">
      <div class="hero-bg__cloud hero-bg__cloud--a"></div>
      <div class="hero-bg__cloud hero-bg__cloud--b"></div>
      <div class="hero-bg__cloud hero-bg__cloud--c"></div>

      <div class="hero-bg__video-wrap">
        <video
          v-if="heroVideoEnabled"
          ref="videoARef"
          class="hero-bg__video"
          :class="{ 'hero-bg__video--active': activeVideoSlot === 'a' && videoReady }"
          :src="resolvedVideoSrc(videoSlotA)"
          autoplay
          muted
          loop
          playsinline
          :preload="activeVideoSlot === 'a' ? 'metadata' : 'none'"
          @loadeddata="onVideoALoadedData"
          @canplay="onVideoACanPlay"
          @loadedmetadata="onVideoMetadata($event, 'a')"
          @playing="onVideoPlaying('a')"
          @pause="onVideoPaused('a')"
        ></video>
        <video
          v-if="heroVideoEnabled"
          ref="videoBRef"
          class="hero-bg__video"
          :class="{ 'hero-bg__video--active': activeVideoSlot === 'b' && videoReady }"
          :src="resolvedVideoSrc(videoSlotB)"
          autoplay
          muted
          loop
          playsinline
          :preload="activeVideoSlot === 'b' ? 'metadata' : 'none'"
          @loadeddata="onVideoBLoadedData"
          @canplay="onVideoBCanPlay"
          @loadedmetadata="onVideoMetadata($event, 'b')"
          @playing="onVideoPlaying('b')"
          @pause="onVideoPaused('b')"
        ></video>
        <div class="hero-bg__video-overlay"></div>
      </div>
    </div>

    <div class="container hero-stage">
      <aside class="hero-rail" role="tablist" aria-label="产品入口">
        <button
          v-for="(slide, index) in heroSlides"
          :key="slide.key"
          type="button"
          role="tab"
          class="hero-rail__item"
          :class="{ 'is-active': index === activeIndex }"
          :aria-selected="index === activeIndex"
          @click="activateSlide(index)"
        >
          <span
            v-if="slide.ribbon"
            class="hero-rail__ribbon"
            :class="`hero-rail__ribbon--${slide.ribbonType}`"
          >
            {{ slide.ribbon }}
          </span>
          <span class="hero-rail__label">{{ slide.railTitle }}</span>
          <el-icon class="hero-rail__arrow"><ArrowRight /></el-icon>
        </button>

      </aside>

      <div :key="activeSlide.key" class="hero-body">
        <h1 class="hero-title">{{ activeSlide.title }}</h1>
        <p class="hero-desc">{{ activeSlide.desc }}</p>
        <div class="hero-actions">
          <button
            type="button"
            class="hero-cta hero-cta--primary"
            @click="router.push(activeSlide.primaryPath)"
          >
            {{ activeSlide.primaryText }}
          </button>
          <button
            type="button"
            class="hero-cta hero-cta--secondary"
            @click="router.push(activeSlide.secondaryPath)"
          >
            {{ activeSlide.secondaryText }}
          </button>
        </div>
      </div>

      <div class="hero-dots" role="tablist" aria-label="轮播指示">
        <button
          v-for="(slide, index) in heroSlides"
          :key="`dot-${slide.key}`"
          type="button"
          role="tab"
          class="hero-dots__item"
          :class="{ 'is-active': index === activeIndex }"
          :aria-label="`切换到 ${slide.railTitle}`"
          :aria-selected="index === activeIndex"
          @click="activateSlide(index)"
        ></button>
      </div>

      <div class="hero-mobile-nav" role="group" aria-label="轮播控制">
        <div class="hero-mobile-nav__dots">
          <button
            v-for="(slide, index) in heroSlides"
            :key="`m-dot-${slide.key}`"
            type="button"
            class="hero-mobile-nav__dot"
            :class="{ 'is-active': index === activeIndex }"
            :aria-label="`切换到 ${slide.railTitle}`"
            :aria-current="index === activeIndex ? 'true' : undefined"
            @click="activateSlide(index)"
          ></button>
        </div>
      </div>
    </div>

    <div class="container">
      <div class="hero-feature-strip">
        <article
          v-for="feature in heroFeatures"
          :key="feature.key"
          class="hero-feature"
          @click="feature.path && router.push(feature.path)"
        >
          <span class="hero-feature__kicker">{{ feature.kicker }}</span>
          <strong class="hero-feature__title">{{ feature.title }}</strong>
          <p class="hero-feature__desc">{{ feature.desc }}</p>
        </article>
      </div>
    </div>
  </section>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, shallowRef, watch } from 'vue'
import { useRouter } from 'vue-router'
import { ElIcon } from 'element-plus/es/components/icon/index.mjs'
import { ArrowRight } from '@element-plus/icons-vue'
import { resolveApiAssetUrl } from '@/utils/apiAssetUrl'


const props = defineProps({
  hero: {
    type: Object,
    default: () => ({}),
  },
})


const router = useRouter()
const apiBaseUrl = String(import.meta.env.VITE_API_BASE_URL || '')
const videoARef = ref(null)
const videoBRef = ref(null)
const videoReady = ref(false)
const heroVideoEnabled = ref(false)
const activeVideoSlot = ref('a')
const videoSlotA = ref('')
const videoSlotB = ref('')
const videoDurations = new Map()
const activeIndex = ref(0)
const heroSlides = shallowRef(Object.freeze([]))
const heroFeatures = shallowRef(Object.freeze([]))

function resolvedVideoSrc(url) {
  return resolveApiAssetUrl(url, apiBaseUrl)
}


// 与管理端 HomeHeroService::defaultSlides() 保持一致，后端不可用时仍保留兜底
function freezeConfigList(list) {
  return Object.freeze(list.map((item) => Object.freeze({ ...item })))
}

function freezeNormalizedList(list, normalizer) {
  return Object.freeze(
    list.map((item, index) => Object.freeze(normalizer(item, index))),
  )
}

const DEFAULT_SLIDES = freezeConfigList([
  {
    key: 'refresh',
    railTitle: '官网换新',
    title: '官网焕新 · 云上新体验',
    desc: '产品目录、购买支付、自动开通、账单结算与服务支持统一打通；首页即是控制台入口，让资源采购和后续管理始终在同一条链路里完成。',
    primaryText: '立即体验',
    primaryPath: '/products',
    secondaryText: '查看详情',
    secondaryPath: '/about',
    shape: 'computer',
    video: '',
    ribbon: '',
    ribbonType: 'new',
  },
  {
    key: 'global',
    railTitle: '全球互联',
    title: '多地节点 · 全球低延迟互联',
    desc: '覆盖香港、美国与国内多地优质节点，三网 CN2 / BGP 线路优化回国；跨区域组网、跨境业务秒级响应，适合建站、代理、跨境电商与出海 SaaS。',
    primaryText: '选购节点',
    primaryPath: '/products',
    secondaryText: '查看线路',
    secondaryPath: '/help',
    shape: 'connection',
    video: '',
    ribbon: '',
    ribbonType: 'new',
  },
  {
    key: 'security',
    railTitle: '安全防护',
    title: '企业级安全 · 稳定可靠交付',
    desc: 'T3+ 数据中心 + BGP 多线接入 + 100G 抗 DDoS 防护；实名认证、权限分级与操作留痕保障账户安全，长期业务和合规场景稳定承载。',
    primaryText: '查看防护',
    primaryPath: '/products',
    secondaryText: '在线咨询',
    secondaryPath: '/help',
    shape: 'security',
    video: '',
    ribbon: '',
    ribbonType: 'new',
  },
  {
    key: 'value',
    railTitle: '实惠专区',
    title: '低门槛套餐 · 直享实惠价',
    desc: '新客首单 2 核 2G 云服务器 39 元/年起，轻量云电脑按月订阅即开即用；优惠券、折扣券灵活叠加，配置随业务弹性升级，先用后付更省心。',
    primaryText: '立即抢购',
    primaryPath: '/products',
    secondaryText: '查看优惠',
    secondaryPath: '/products',
    shape: 'value',
    video: '',
    ribbon: '',
    ribbonType: 'warm',
  },
  {
    key: 'support',
    railTitle: '企业客服',
    title: '企业客服 · 一对一专属服务',
    desc: '7×24 小时工单、官方QQ群与一对一商务对接，覆盖选型、部署、迁移、运维与结算；支持对公结算、批量采购、子账号协作与统一对账。',
    primaryText: '联系客服',
    primaryPath: '/help',
    secondaryText: '企业采购',
    secondaryPath: '/about',
    shape: 'support',
    video: '',
    ribbon: '',
    ribbonType: 'new',
  },
])

const DEFAULT_FEATURES = freezeConfigList([
  {
    key: 'dynamic',
    kicker: '产品动态',
    title: '香港 CN2 精品线路 上线',
    desc: '三网 CN2 GIA 优化回国，跨境业务低时延稳定承载。',
    path: '/products',
  },
  {
    key: 'activity',
    kicker: '活动内容',
    title: '新客首单 39 元/年',
    desc: '2H2G 云服务器覆盖建站、代理、轻量业务全场景。',
    path: '/products',
  },
  {
    key: 'enterprise',
    kicker: '企业专区',
    title: 'IDC 企业采购通道',
    desc: '统一账单、多子账号协作与对公结算能力同步上线。',
    path: '/about',
  },
  {
    key: 'cloud-desktop',
    kicker: '轻量产品',
    title: '西安云电脑 即开即用',
    desc: '西安节点低延迟，支持远程办公、外包协作、教学实训。',
    path: '/products',
  },
  {
    key: 'new',
    kicker: '新开产品',
    title: '十堰高防独立服务器',
    desc: '100G 抗 DDoS + BGP 多线，面向长期稳定业务承载。',
    path: '/products',
  },
])

const ALLOWED_SHAPES = new Set(['computer', 'connection', 'security', 'value', 'support'])
const ALLOWED_RIBBON_TYPES = new Set(['hot', 'warm', 'new'])

function pickString(value, fallback = '') {
  if (value === undefined || value === null) return fallback
  const text = String(value)
  return text.trim() === '' ? fallback : text
}

function normalizeSlide(raw, index = 0) {
  const source = raw && typeof raw === 'object' ? raw : {}
  const shape = pickString(source.shape, 'computer')
  const ribbonType = pickString(source.ribbon_type ?? source.ribbonType, 'new')

  return {
    key: pickString(source.key, `slide-${index}`),
    railTitle: pickString(source.rail_title ?? source.railTitle, '未命名'),
    title: pickString(source.title, ''),
    desc: pickString(source.desc, ''),
    primaryText: pickString(source.primary_text ?? source.primaryText, '了解更多'),
    primaryPath: pickString(source.primary_path ?? source.primaryPath, '/products'),
    secondaryText: pickString(source.secondary_text ?? source.secondaryText, '查看详情'),
    secondaryPath: pickString(source.secondary_path ?? source.secondaryPath, '/about'),
    shape: ALLOWED_SHAPES.has(shape) ? shape : 'computer',
    video: pickString(source.video, ''),
    ribbon: pickString(source.ribbon, ''),
    ribbonType: ALLOWED_RIBBON_TYPES.has(ribbonType) ? ribbonType : 'new',
  }
}

function normalizeFeature(raw, index = 0) {
  const source = raw && typeof raw === 'object' ? raw : {}
  return {
    key: pickString(source.key, `feature-${index}`),
    kicker: pickString(source.kicker, ''),
    title: pickString(source.title, ''),
    desc: pickString(source.desc, ''),
    path: pickString(source.path, ''),
  }
}

const EMPTY_SLIDES = Object.freeze([])
const EMPTY_FEATURES = Object.freeze([])
const EMPTY_HERO = Object.freeze({
  slides: EMPTY_SLIDES,
  features: EMPTY_FEATURES,
})

const hero = computed(() => (props.hero && typeof props.hero === 'object' ? props.hero : EMPTY_HERO))

function hasHeroField(data, key) {
  return Boolean(data && typeof data === 'object' && Object.prototype.hasOwnProperty.call(data, key))
}

function normalizeHeroSlides(data) {
  const slides = Array.isArray(data?.slides) ? data.slides : []
  if (hasHeroField(data, 'slides')) {
    return freezeNormalizedList(slides, normalizeSlide)
  }

  return freezeNormalizedList(DEFAULT_SLIDES, normalizeSlide)
}

function normalizeHeroFeatures(data) {
  const features = Array.isArray(data?.features) ? data.features : []
  if (hasHeroField(data, 'features')) {
    return freezeNormalizedList(features, normalizeFeature)
  }

  return freezeNormalizedList(DEFAULT_FEATURES, normalizeFeature)
}

watch(
  hero,
  (value) => {
    const nextSlides = normalizeHeroSlides(value)
    const nextFeatures = normalizeHeroFeatures(value)

    heroSlides.value = nextSlides
    heroFeatures.value = nextFeatures

    if (activeIndex.value >= nextSlides.length) {
      activeIndex.value = 0
    }
  },
  { immediate: true, deep: true },
)

const activeSlide = computed(() => heroSlides.value[activeIndex.value] || heroSlides.value[0] || DEFAULT_SLIDES[0])

const MIN_ROTATION_INTERVAL = 6000
const MAX_ROTATION_INTERVAL = 15000
const PLAYBACK_RETRY_DELAY = 400
const MAX_PLAYBACK_RETRIES = 3
const HERO_VIDEO_IDLE_TIMEOUT = 1200
const HERO_VIDEO_MOBILE_WIDTH = 768
const SLOW_CONNECTION_TYPES = new Set(['slow-2g', '2g', '3g'])
let rotationTimer = null
let playbackRetryTimer = null
let videoEnableTimer = null
let videoEnableIdleId = null
let playbackRetryCount = 0
let isUnmounting = false

function getNavigatorConnection() {
  if (typeof navigator === 'undefined') return null
  return navigator.connection || navigator.mozConnection || navigator.webkitConnection || null
}

function clearVideoSlots() {
  videoSlotA.value = ''
  videoSlotB.value = ''
  activeVideoSlot.value = 'a'
  videoReady.value = false
}

function shouldEnableHeroVideo() {
  if (typeof window === 'undefined') return false
  if (window.matchMedia?.(`(max-width: ${HERO_VIDEO_MOBILE_WIDTH}px)`).matches) {
    return false
  }
  if (window.matchMedia?.('(prefers-reduced-motion: reduce)').matches) {
    return false
  }

  const connection = getNavigatorConnection()
  if (connection?.saveData) {
    return false
  }

  return !SLOW_CONNECTION_TYPES.has(connection?.effectiveType)
}

function clearVideoEnableSchedule() {
  if (videoEnableTimer) {
    window.clearTimeout(videoEnableTimer)
    videoEnableTimer = null
  }

  if (videoEnableIdleId !== null && typeof window !== 'undefined' && typeof window.cancelIdleCallback === 'function') {
    window.cancelIdleCallback(videoEnableIdleId)
    videoEnableIdleId = null
  }
}

function disableHeroVideo() {
  clearVideoEnableSchedule()
  heroVideoEnabled.value = false
  resetPlaybackRetry()
  stopPlaybackRetry()
  pauseAllVideos()
  clearVideoSlots()
}

function enableHeroVideo() {
  if (!shouldEnableHeroVideo()) {
    disableHeroVideo()
    return
  }

  heroVideoEnabled.value = true
  switchToSlide(activeIndex.value)
}

function scheduleHeroVideoEnable() {
  if (!shouldEnableHeroVideo()) {
    disableHeroVideo()
    return
  }

  clearVideoEnableSchedule()

  const activate = () => {
    videoEnableTimer = null
    videoEnableIdleId = null
    if (!isUnmounting) {
      enableHeroVideo()
    }
  }

  if (typeof window !== 'undefined' && typeof window.requestIdleCallback === 'function') {
    videoEnableIdleId = window.requestIdleCallback(activate, { timeout: HERO_VIDEO_IDLE_TIMEOUT })
    return
  }

  videoEnableTimer = window.setTimeout(activate, HERO_VIDEO_IDLE_TIMEOUT)
}

function getRotationInterval() {
  const src = activeSlide.value.video
  if (!src) return MIN_ROTATION_INTERVAL
  const duration = videoDurations.get(src)
  if (duration && duration > 0) {
    return Math.min(MAX_ROTATION_INTERVAL, Math.max(MIN_ROTATION_INTERVAL, duration * 1000))
  }
  return MIN_ROTATION_INTERVAL
}

function stopRotation() {
  if (rotationTimer) {
    clearTimeout(rotationTimer)
    rotationTimer = null
  }
}

function stopPlaybackRetry() {
  if (playbackRetryTimer) {
    clearTimeout(playbackRetryTimer)
    playbackRetryTimer = null
  }
}

function resetPlaybackRetry() {
  playbackRetryCount = 0
  stopPlaybackRetry()
}

function isDocumentVisible() {
  return typeof document === 'undefined' || document.visibilityState !== 'hidden'
}

function getVideoElement(slot = activeVideoSlot.value) {
  return slot === 'a' ? videoARef.value : videoBRef.value
}

function prepareVideoElement(videoEl) {
  if (!videoEl) return
  videoEl.muted = true
  videoEl.loop = true
  videoEl.playsInline = true
  videoEl.setAttribute('playsinline', '')
}

function pauseVideo(videoEl) {
  if (videoEl && !videoEl.paused) {
    videoEl.pause()
  }
}

function pauseInactiveVideo() {
  const inactiveSlot = activeVideoSlot.value === 'a' ? 'b' : 'a'
  pauseVideo(getVideoElement(inactiveSlot))
}

function pauseAllVideos() {
  pauseVideo(videoARef.value)
  pauseVideo(videoBRef.value)
}

function ensureActiveVideoPlayback() {
  if (isUnmounting) return
  if (!isDocumentVisible()) return

  const videoEl = getVideoElement()
  if (!videoEl) return

  prepareVideoElement(videoEl)
  pauseInactiveVideo()

  if (!videoEl.currentSrc && !videoEl.src) return

  if (videoEl.readyState === 0) {
    videoEl.load?.()
    return
  }

  const playResult = videoEl.play?.()
  if (playResult && typeof playResult.catch === 'function') {
    playResult.catch(() => {
      if (!isDocumentVisible() || playbackRetryCount >= MAX_PLAYBACK_RETRIES) return
      playbackRetryCount += 1
      queueActiveVideoPlayback(PLAYBACK_RETRY_DELAY)
    })
  }
}

function queueActiveVideoPlayback(delay = 0) {
  if (isUnmounting) return
  stopPlaybackRetry()

  const run = () => {
    playbackRetryTimer = null
    nextTick(() => {
      ensureActiveVideoPlayback()
    })
  }

  if (delay > 0) {
    playbackRetryTimer = setTimeout(run, delay)
  } else {
    run()
  }
}

function startRotation() {
  stopRotation()
  rotationTimer = setTimeout(() => {
    const count = heroSlides.value.length || 1
    const nextIndex = (activeIndex.value + 1) % count
    switchToSlide(nextIndex, true)
  }, getRotationInterval())
}

function switchToSlide(index, auto = false) {
  if (index === activeIndex.value && auto) return
  activeIndex.value = index
  const slide = heroSlides.value[index]
  const videoSrc = slide?.video || ''
  if (!heroVideoEnabled.value) {
    clearVideoSlots()
    pauseAllVideos()
    if (auto) startRotation()
    return
  }

  if (!videoSrc) {
    resetPlaybackRetry()
    clearVideoSlots()
    pauseAllVideos()
    if (auto) startRotation()
    return
  }

  // 优化：检查当前活跃 slot 是否已加载该视频，避免重复加载
  const currentSlotSrc = activeVideoSlot.value === 'a' ? videoSlotA.value : videoSlotB.value
  if (currentSlotSrc === videoSrc) {
    // 当前 slot 已是目标视频，直接显示
    videoReady.value = true
    queueActiveVideoPlayback()
    if (auto) startRotation()
    return
  }

  // 优化：检查另一个 slot 是否已缓存该视频
  const otherSlot = activeVideoSlot.value === 'a' ? 'b' : 'a'
  const otherSlotSrc = otherSlot === 'a' ? videoSlotA.value : videoSlotB.value
  if (otherSlotSrc === videoSrc) {
    // 另一个 slot 已缓存该视频，直接切换
    resetPlaybackRetry()
    activeVideoSlot.value = otherSlot
    videoReady.value = true
    queueActiveVideoPlayback()
    if (auto) startRotation()
    return
  }

  // 需要加载新视频，使用非活跃 slot
  const nextSlot = otherSlot
  if (nextSlot === 'a') {
    videoSlotA.value = videoSrc
  } else {
    videoSlotB.value = videoSrc
  }
  resetPlaybackRetry()
  videoReady.value = false
  activeVideoSlot.value = nextSlot
  queueActiveVideoPlayback()
  if (auto) startRotation()
}

function activateSlide(index) {
  stopRotation()
  switchToSlide(index)
  startRotation()
}

function onVideoACanPlay() {
  markVideoReady('a')
}

function onVideoBCanPlay() {
  markVideoReady('b')
}

function onVideoALoadedData() {
  markVideoReady('a')
}

function onVideoBLoadedData() {
  markVideoReady('b')
}

function markVideoReady(slot) {
  if (activeVideoSlot.value !== slot) return
  videoReady.value = true
  queueActiveVideoPlayback()
}

function onVideoPlaying(slot) {
  if (activeVideoSlot.value === slot) {
    resetPlaybackRetry()
  }
}

function onVideoPaused(slot) {
  if (!isUnmounting && activeVideoSlot.value === slot && isDocumentVisible()) {
    queueActiveVideoPlayback(PLAYBACK_RETRY_DELAY)
  }
}

function onVideoMetadata(event, slot) {
  const videoEl = event.target
  const slotSrc = slot === 'a' ? videoSlotA.value : videoSlotB.value
  const src = slotSrc || (videoEl?.src || '').split('#')[0]
  const duration = videoEl?.duration
  if (src && duration && Number.isFinite(duration) && duration > 0) {
    videoDurations.set(src, duration)
  }
}

watch(activeSlide, (slide) => {
  if (!heroVideoEnabled.value) return
  if (!slide?.video) return
  const currentSlotSrc = activeVideoSlot.value === 'a' ? videoSlotA.value : videoSlotB.value
  if (currentSlotSrc === slide.video) {
    queueActiveVideoPlayback()
    return
  }
  switchToSlide(activeIndex.value)
})

function handleVisibilityChange() {
  if (typeof document === 'undefined') return
  if (document.visibilityState === 'hidden') {
    stopRotation()
    stopPlaybackRetry()
    pauseAllVideos()
  } else {
    startRotation()
    if (heroVideoEnabled.value) {
      queueActiveVideoPlayback()
    } else {
      scheduleHeroVideoEnable()
    }
  }
}

onMounted(() => {
  startRotation()
  scheduleHeroVideoEnable()
  if (typeof document !== 'undefined') {
    document.addEventListener('visibilitychange', handleVisibilityChange)
  }
})

onBeforeUnmount(() => {
  isUnmounting = true
  clearVideoEnableSchedule()
  stopRotation()
  stopPlaybackRetry()
  pauseAllVideos()
  if (typeof document !== 'undefined') {
    document.removeEventListener('visibilitychange', handleVisibilityChange)
  }
})
</script>

<style scoped lang="scss">
.container {
  width: min(1280px, calc(100% - 48px));
  margin: 0 auto;
}

@media (max-width: 960px) {
  .container {
    width: calc(100% - 32px);
  }
}

@media (max-width: 640px) {
  .container {
    width: calc(100% - 24px);
  }
}

.hero-section {
  position: relative;
  padding: 28px 0 44px;
  background: #f3f5f8;
  isolation: isolate;
}

.hero-section::after {
  content: '';
  position: absolute;
  left: 0;
  right: 0;
  bottom: 0;
  height: 96px;
  background: linear-gradient(180deg, rgba(255, 255, 255, 0) 0%, rgba(255, 255, 255, 0.72) 60%, #ffffff 100%);
  pointer-events: none;
  z-index: 1;
}

.hero-bg {
  position: absolute;
  inset: 0;
  overflow: hidden;
  pointer-events: none;
  z-index: 0;
}

.hero-bg__video-wrap {
  position: absolute;
  inset: 0;
  z-index: 0;
}

.hero-bg__video {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  object-fit: cover;
  opacity: 0;
  transition: opacity 0.8s cubic-bezier(0.4, 0, 0.2, 1);
  will-change: opacity;
}

.hero-bg__video--active {
  opacity: 1;
}

.hero-bg__video-overlay {
  position: absolute;
  inset: 0;
  background: linear-gradient(
    135deg,
    rgba(255, 255, 255, 0.68) 0%,
    rgba(255, 255, 255, 0.38) 52%,
    rgba(255, 255, 255, 0.18) 100%
  );
}

.hero-bg__cloud {
  position: absolute;
  z-index: 1;
  border-radius: 50%;
  filter: blur(60px);
  opacity: 0.46;
  mix-blend-mode: screen;
}

.hero-bg__cloud--a {
  top: -140px;
  left: -180px;
  width: 640px;
  height: 640px;
  background: radial-gradient(circle, rgba(255, 255, 255, 0.86), rgba(255, 255, 255, 0) 68%);
}

.hero-bg__cloud--b {
  top: 28%;
  left: 38%;
  width: 520px;
  height: 520px;
  background: radial-gradient(circle, rgba(236, 240, 245, 0.92), rgba(236, 240, 245, 0) 70%);
  opacity: 0.28;
}

.hero-bg__cloud--c {
  bottom: -260px;
  right: -220px;
  width: 780px;
  height: 780px;
  background: radial-gradient(circle, rgba(221, 227, 234, 0.72), rgba(221, 227, 234, 0) 68%);
  opacity: 0.24;
}

.hero-stage {
  position: relative;
  z-index: 2;
  display: grid;
  grid-template-columns: 220px minmax(0, 1fr) minmax(340px, 420px);
  column-gap: clamp(28px, 3.2vw, 48px);
  row-gap: 20px;
  align-items: center;
  padding: 40px 0 24px;
  min-height: 460px;
}

.hero-rail {
  display: flex;
  flex-direction: column;
  gap: 8px;
  padding-top: 18px;
}

.hero-rail__item {
  position: relative;
  display: flex;
  align-items: center;
  gap: 12px;
  width: 100%;
  min-height: 52px;
  padding: 14px 20px;
  border: none;
  border-radius: 12px;
  background: rgba(255, 255, 255, 0.36);
  color: #2c3654;
  font-size: 16px;
  font-weight: 500;
  text-align: left;
  cursor: pointer;
  transition:
    background 0.24s cubic-bezier(0.22, 1, 0.36, 1),
    color 0.24s cubic-bezier(0.22, 1, 0.36, 1),
    box-shadow 0.24s cubic-bezier(0.22, 1, 0.36, 1),
    transform 0.24s cubic-bezier(0.22, 1, 0.36, 1);
}

.hero-rail__item:hover {
  background: rgba(255, 255, 255, 0.7);
  color: #111a34;
  transform: translateX(2px);
}

.hero-rail__item:focus-visible {
  outline: none;
  box-shadow: 0 0 0 3px rgba(47, 94, 243, 0.22);
}

.hero-rail__item.is-active {
  background: #2f5ef3;
  color: #ffffff;
  box-shadow: 0 14px 28px rgba(47, 94, 243, 0.28);
  transform: none;
}

.hero-rail__item.is-active:hover {
  transform: translateX(0);
}

.hero-rail__item.is-active .hero-rail__label {
  color: #ffffff;
}

.hero-rail__ribbon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  min-width: 32px;
  height: 20px;
  padding: 0 7px;
  border-radius: 4px;
  color: #ffffff;
  font-size: 11px;
  font-weight: 700;
  line-height: 1;
  letter-spacing: 0.02em;
}

.hero-rail__ribbon--hot {
  background: #e8213a;
  box-shadow: 0 3px 8px rgba(232, 33, 58, 0.24);
}

.hero-rail__ribbon--warm {
  background: #f4651b;
  box-shadow: 0 3px 8px rgba(244, 101, 27, 0.22);
}

.hero-rail__ribbon--new {
  background: #2f5ef3;
  box-shadow: 0 3px 8px rgba(47, 94, 243, 0.22);
}

.hero-rail__item.is-active .hero-rail__ribbon {
  box-shadow: none;
}

.hero-rail__label {
  flex: 1;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.hero-rail__arrow {
  flex-shrink: 0;
  font-size: 12px;
  opacity: 0;
  transform: translateX(-4px);
  transition: opacity 0.2s ease, transform 0.2s ease;
}

.hero-rail__item.is-active .hero-rail__arrow,
.hero-rail__item:hover .hero-rail__arrow {
  opacity: 1;
  transform: translateX(0);
}

.hero-dots {
  grid-column: 1 / -1;
  display: flex;
  justify-content: center;
  align-items: center;
  gap: 10px;
  margin: 0;
  padding: 4px 0 0;
}

.hero-dots__item {
  width: 28px;
  height: 3px;
  padding: 0;
  border: none;
  border-radius: 999px;
  background: rgba(44, 54, 84, 0.22);
  cursor: pointer;
  transition:
    background 0.28s cubic-bezier(0.22, 1, 0.36, 1),
    width 0.36s cubic-bezier(0.22, 1, 0.36, 1),
    transform 0.18s cubic-bezier(0.22, 1, 0.36, 1);
}

.hero-dots__item:hover {
  background: rgba(44, 54, 84, 0.4);
}

.hero-dots__item:focus-visible {
  outline: none;
  box-shadow: 0 0 0 3px rgba(47, 94, 243, 0.22);
}

.hero-dots__item.is-active {
  width: 44px;
  background: #2f5ef3;
}

.hero-dots__item.is-active:hover {
  background: #2754e3;
}

.hero-body {
  position: relative;
  display: flex;
  flex-direction: column;
  justify-content: center;
  min-width: 0;
  padding: 8px 0 0;
  animation: hero-body-rise 0.42s cubic-bezier(0.22, 1, 0.36, 1);
}

.hero-title {
  margin: 0;
  color: #111a34;
  font-size: clamp(34px, 4.2vw, 52px);
  font-weight: 700;
  line-height: 1.14;
  letter-spacing: -0.02em;
  text-wrap: pretty;
}

.hero-desc {
  max-width: 540px;
  margin: 24px 0 0;
  color: rgba(31, 42, 77, 0.78);
  font-size: 15px;
  line-height: 1.9;
  text-wrap: pretty;
}

.hero-actions {
  display: flex;
  align-items: center;
  gap: 14px;
  margin-top: 36px;
}

.hero-cta {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 152px;
  height: 48px;
  padding: 0 28px;
  border: none;
  border-radius: 6px;
  font-size: 15px;
  font-weight: 600;
  cursor: pointer;
  transition:
    transform 0.22s cubic-bezier(0.22, 1, 0.36, 1),
    box-shadow 0.22s cubic-bezier(0.22, 1, 0.36, 1),
    background 0.22s cubic-bezier(0.22, 1, 0.36, 1),
    border-color 0.22s cubic-bezier(0.22, 1, 0.36, 1);
}

.hero-cta:focus-visible {
  outline: none;
  box-shadow: 0 14px 30px rgba(47, 94, 243, 0.34), 0 0 0 3px rgba(47, 94, 243, 0.32);
}

.hero-cta:active {
  transform: translateY(0) scale(0.98);
}

.hero-cta--primary {
  background: #2f5ef3;
  color: #ffffff;
  box-shadow: 0 14px 30px rgba(47, 94, 243, 0.34);
}

.hero-cta--primary:hover {
  transform: translateY(-2px);
  background: #2754e3;
  box-shadow: 0 20px 40px rgba(47, 94, 243, 0.45);
}

.hero-cta--secondary {
  background: #ffffff;
  color: #2f5ef3;
  box-shadow: 0 10px 24px rgba(47, 94, 243, 0.12);
  border: 1px solid rgba(47, 94, 243, 0.24);
}

.hero-cta--secondary:hover {
  transform: translateY(-2px);
  background: #f5f8ff;
  border-color: rgba(47, 94, 243, 0.44);
  box-shadow: 0 16px 30px rgba(47, 94, 243, 0.22);
}

.hero-mobile-nav {
  display: none;
}


@keyframes hero-body-rise {
  0% {
    opacity: 0;
    transform: translate3d(0, 14px, 0);
  }

  100% {
    opacity: 1;
    transform: translate3d(0, 0, 0);
  }
}

.hero-feature-strip {
  position: relative;
  z-index: 2;
  display: grid;
  grid-template-columns: repeat(5, minmax(0, 1fr));
  margin-top: 32px;
  background: rgba(255, 255, 255, 0.72);
  border: 1px solid rgba(22, 93, 255, 0.1);
  -webkit-backdrop-filter: blur(8px);
  backdrop-filter: blur(8px);
  border-radius: 12px;
  box-shadow: 0 12px 30px rgba(47, 94, 243, 0.08);
}

.hero-feature {
  position: relative;
  padding: 20px 22px;
  border-left: 1px solid rgba(22, 93, 255, 0.08);
  cursor: pointer;
  transition:
    background 0.24s cubic-bezier(0.22, 1, 0.36, 1),
    color 0.24s cubic-bezier(0.22, 1, 0.36, 1);
}

.hero-feature::before {
  content: "";
  position: absolute;
  left: 22px;
  right: 22px;
  bottom: 6px;
  height: 2px;
  border-radius: 999px;
  background: linear-gradient(90deg, rgba(47, 94, 243, 0.6), rgba(122, 155, 255, 0));
  transform: scaleX(0);
  transform-origin: left center;
  transition: transform 0.28s cubic-bezier(0.22, 1, 0.36, 1);
}

.hero-feature:first-child {
  border-left: none;
}

.hero-feature:hover {
  background: rgba(255, 255, 255, 0.82);
}

.hero-feature:hover .hero-feature__title {
  color: $color-primary;
}

.hero-feature:hover::before {
  transform: scaleX(1);
}

.hero-feature:focus-visible {
  outline: none;
  background: rgba(255, 255, 255, 0.86);
  box-shadow: inset 0 0 0 2px rgba(47, 94, 243, 0.34);
}

.hero-feature__kicker {
  display: block;
  color: rgba(31, 42, 77, 0.62);
  font-size: 12px;
  font-weight: 500;
  letter-spacing: 0.04em;
}

.hero-feature__title {
  display: block;
  margin-top: 8px;
  color: #111a34;
  font-size: 15px;
  font-weight: 600;
  line-height: 1.45;
}

.hero-feature__desc {
  margin: 8px 0 0;
  color: rgba(31, 42, 77, 0.68);
  font-size: 12px;
  line-height: 1.7;
  display: -webkit-box;
  overflow: hidden;
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 2;
}

@media (max-width: 1180px) {
  .hero-stage {
    grid-template-columns: 220px minmax(0, 1fr);
    grid-template-rows: auto auto auto;
    column-gap: clamp(20px, 3vw, 36px);
    row-gap: 16px;
    padding: 28px 0 16px;
    min-height: 0;
    align-items: start;
  }

  .hero-rail {
    padding-top: 8px;
  }

  .hero-rail__item {
    min-height: 46px;
    padding: 12px 16px;
    font-size: 15px;
  }

  .hero-body {
    grid-column: 2;
    grid-row: 1;
  }

  .hero-desc {
    margin-top: 18px;
  }

  .hero-actions {
    margin-top: 26px;
  }

  .hero-feature-strip {
    grid-template-columns: repeat(3, minmax(0, 1fr));
    margin-top: 24px;
  }

  .hero-feature:nth-child(4),
  .hero-feature:nth-child(5) {
    border-left: none;
    border-top: 1px solid rgba(22, 93, 255, 0.08);
  }

  .hero-feature:nth-child(5) {
    border-left: 1px solid rgba(22, 93, 255, 0.08);
  }
}

@media (max-width: 960px) {
  .hero-section {
    padding: 20px 0 28px;
  }

  .hero-stage {
    grid-template-columns: 1fr;
    gap: 18px;
    padding: 12px 0 4px;
    min-height: 0;
    align-items: start;
  }

  .hero-rail {
    flex-direction: row;
    flex-wrap: nowrap;
    gap: 8px;
    padding: 0 0 4px;
    overflow-x: auto;
    scrollbar-width: none;
    -webkit-overflow-scrolling: touch;
  }

  .hero-rail::-webkit-scrollbar {
    display: none;
  }

  .hero-rail__item {
    flex: 0 0 auto;
    width: auto;
    min-height: 0;
    padding: 8px 16px;
    border-radius: 999px;
    font-size: 14px;
    background: rgba(255, 255, 255, 0.58);
  }

  .hero-rail__item.is-active {
    background: #2f5ef3;
  }

  .hero-rail__arrow {
    display: none;
  }

  .hero-dots {
    display: none;
  }

  .hero-body {
    grid-column: 1;
    grid-row: auto;
    padding-top: 0;
  }

  .hero-desc {
    margin-top: 16px;
  }

  .hero-actions {
    margin-top: 22px;
  }

  .hero-feature-strip {
    grid-template-columns: repeat(2, minmax(0, 1fr));
    margin-top: 22px;
  }

  .hero-feature {
    padding: 16px 18px;
    border-left: none !important;
    border-top: 1px solid rgba(22, 93, 255, 0.08);
  }

  .hero-feature:nth-child(-n + 2) {
    border-top: none;
  }

  .hero-feature:nth-child(odd) {
    border-right: 1px solid rgba(22, 93, 255, 0.08);
  }

  .hero-feature:last-child:nth-child(odd) {
    grid-column: 1 / -1;
    border-right: none;
  }
}

@media (max-width: 640px) {
  .hero-section {
    padding: 16px 0 24px;
  }

  .hero-rail {
    display: none;
  }

  .hero-title {
    font-size: clamp(24px, 7vw, 32px);
  }

  .hero-desc {
    font-size: 14px;
    line-height: 1.8;
    margin-top: 14px;
  }

  .hero-actions {
    flex-wrap: nowrap;
    gap: 10px;
    margin-top: 20px;
  }

  .hero-cta {
    flex: 1 1 0;
    min-width: 0;
    height: 42px;
    padding: 0 14px;
    font-size: 14px;
    border-radius: 8px;
  }

  .hero-cta--primary {
    box-shadow: 0 8px 20px rgba(47, 94, 243, 0.22);
  }

  .hero-cta--secondary {
    box-shadow: 0 6px 16px rgba(47, 94, 243, 0.08);
  }

  .hero-mobile-nav {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 14px;
    padding: 6px 0 0;
  }

  .hero-mobile-nav__dots {
    display: flex;
    align-items: center;
    gap: 6px;
  }

  .hero-mobile-nav__dot {
    width: 6px;
    height: 6px;
    padding: 0;
    border: none;
    border-radius: 50%;
    background: rgba(44, 54, 84, 0.22);
    cursor: pointer;
    transition: background 0.22s ease, width 0.22s ease, border-radius 0.22s ease;
  }

  .hero-mobile-nav__dot.is-active {
    width: 20px;
    border-radius: 999px;
    background: #2f5ef3;
  }

  .hero-feature-strip {
    grid-template-columns: 1fr;
    margin-top: 18px;
    border-radius: 12px;
  }

  .hero-feature {
    padding: 16px 18px;
    border: none !important;
    border-top: 1px solid rgba(22, 93, 255, 0.08) !important;
  }

  .hero-feature:first-child {
    border-top: none !important;
  }

  .hero-feature:last-child:nth-child(odd) {
    grid-column: auto;
  }

  .hero-feature__kicker {
    font-size: 12px;
  }

  .hero-feature__title {
    margin-top: 6px;
    font-size: 14px;
  }

  .hero-feature__desc {
    margin-top: 6px;
    font-size: 13px;
  }
}

@media (max-width: 480px) {
  .hero-title {
    font-size: clamp(22px, 7.5vw, 28px);
    line-height: 1.22;
  }

  .hero-desc {
    margin-top: 12px;
  }

  .hero-cta {
    height: 40px;
    padding: 0 10px;
    font-size: 13px;
  }
}

@media (prefers-reduced-motion: reduce) {
  .hero-body {
    animation: none !important;
  }

  .hero-rail__item,
  .hero-dots__item,
  .hero-cta,
  .hero-feature,
  .hero-feature::before,
  .hero-rail__arrow {
    transition-duration: 0.01ms !important;
    animation-duration: 0.01ms !important;
  }

  .hero-rail__item:hover,
  .hero-cta:hover,
  .hero-cta:active {
    transform: none !important;
  }
}
</style>
