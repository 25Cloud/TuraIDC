<template>
  <section class="news-section">
    <div class="container">
      <header class="news-section__head">
        <h2 class="news-section__title">资讯动态</h2>
        <p class="news-section__desc">
          为您呈现行业动态、活动公告、产品发布
          <router-link to="/notices" class="news-section__more">
            查看更多
            <el-icon><ArrowRight /></el-icon>
          </router-link>
        </p>
      </header>

      <div class="news-board">
        <button
          v-if="featuredNotice"
          type="button"
          class="news-feature"
          @click="openNotice(featuredNotice)"
        >
          <div class="news-feature__cover">
            <img
              v-if="featuredNotice.cover_image"
              :src="featuredNotice.cover_image"
              :alt="featuredNotice.title"
              class="news-feature__cover-img"
              loading="lazy"
              decoding="async"
              fetchpriority="low"
            />
            <template v-else>
              <span class="news-feature__cover-text">公告</span>
              <div class="news-feature__cover-decor" aria-hidden="true"></div>
            </template>
          </div>
          <div class="news-feature__body">
            <div class="news-feature__date">
              <strong>{{ formatNoticeDay(featuredNotice.publish_at || featuredNotice.updated_at) }}</strong>
              <span>{{ formatNoticeMonthYear(featuredNotice.publish_at || featuredNotice.updated_at) }}</span>
            </div>
            <div class="news-feature__meta">
              <strong class="news-feature__title">{{ featuredNotice.title }}</strong>
              <p class="news-feature__summary">
                {{ featuredNotice.summary || featuredNotice.excerpt || '资讯动态主焦点卡片展示区域，点击查看完整内容。' }}
              </p>
            </div>
          </div>
        </button>

        <div class="news-list">
          <button
            v-for="item in newsListEntries"
            :key="`list-${item.id}`"
            type="button"
            class="news-list__item"
            @click="openNotice(item)"
          >
            <strong class="news-list__title">{{ item.title }}</strong>
            <div class="news-list__meta">
              <el-icon><Calendar /></el-icon>
              <span>{{ formatNoticeDate(item.publish_at || item.updated_at) }}</span>
            </div>
          </button>
        </div>
      </div>

      <div v-if="promoEntries.length" class="news-promos">
        <button
          v-for="(promo, promoIndex) in promoEntries"
          :key="`promo-${promo.id}`"
          type="button"
          class="news-promo"
          :class="`news-promo--tone-${promoIndex % 4}`"
          @click="openNotice(promo)"
        >
          <span class="news-promo__badge">推荐</span>
          <div class="news-promo__body">
            <strong class="news-promo__title">{{ promo.title }}</strong>
            <p class="news-promo__desc">
              {{ promo.summary || promo.excerpt || '点击查看详细内容。' }}
            </p>
          </div>
          <span class="news-promo__arrow" aria-hidden="true">
            <el-icon><ArrowRight /></el-icon>
          </span>
        </button>
      </div>
    </div>
  </section>
</template>

<script setup>
import { computed } from 'vue'
import { useRouter } from 'vue-router'
import { ElIcon } from 'element-plus/es/components/icon/index.mjs'
import { ArrowRight, Calendar } from '@element-plus/icons-vue'

const props = defineProps({
  notices: {
    type: Array,
    default: () => [],
  },
})

const router = useRouter()

const featuredNotice = computed(() => {
  return props.notices.find((n) => n.is_pinned === 1) || props.notices[0] || null
})

const promoEntries = computed(() => {
  return props.notices
    .filter((n) => n.is_recommended === 1 && n.is_pinned !== 1)
    .slice(0, 4)
})

const newsListEntries = computed(() => {
  const usedIds = new Set([
    featuredNotice.value?.id,
    ...promoEntries.value.map((n) => n.id),
  ])
  return props.notices
    .filter((n) => !usedIds.has(n.id))
    .sort((a, b) => {
      const da = a.publish_at || a.updated_at || ''
      const db = b.publish_at || b.updated_at || ''
      return da < db ? 1 : da > db ? -1 : 0 // Descending order for news list
    })
    .slice(0, 6)
})

function formatNoticeDate(value) {
  if (!value) return '--'
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) {
    return String(value).split(' ')[0] || String(value)
  }
  const pad = (number) => String(number).padStart(2, '0')
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`
}

function formatNoticeDay(value) {
  if (!value) return '--'
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return '--'
  return String(date.getDate()).padStart(2, '0')
}

function formatNoticeMonthYear(value) {
  if (!value) return ''
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return ''
  const pad = (number) => String(number).padStart(2, '0')
  return `${date.getFullYear()}.${pad(date.getMonth() + 1)}`
}

function openNotice(item) {
  if (!item?.id) {
    router.push('/notices')
    return
  }
  router.push(`/notices/${item.id}`)
}
</script>

<style scoped lang="scss">
.container {
  width: min(1200px, calc(100% - 48px));
  margin: 0 auto;
}

.news-section {
  position: relative;
  padding: 72px 0 40px;
  background: #ffffff;
}

.news-section__head {
  text-align: center;
  margin-bottom: 32px;
}

.news-section__title {
  margin: 0;
  color: #0f172a;
  font-size: clamp(26px, 2.4vw, 30px);
  font-weight: 700;
  line-height: 1.28;
  letter-spacing: -0.01em;
}

.news-section__desc {
  display: flex;
  justify-content: center;
  align-items: center;
  flex-wrap: wrap;
  gap: 6px;
  margin: 10px 0 0;
  color: #5b6b82;
  font-size: 14px;
  line-height: 1.8;
}

.news-section__more {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  color: #2f5ef3;
  font-weight: 500;
  text-decoration: none;
  transition: color 0.22s cubic-bezier(0.22, 1, 0.36, 1);
}

.news-section__more:hover {
  color: #2754e3;
}

.news-section__more .el-icon {
  font-size: 12px;
}

.news-board {
  display: grid;
  grid-template-columns: 340px minmax(0, 1fr);
  gap: 16px;
  margin-bottom: 20px;
}

.news-feature {
  display: flex;
  flex-direction: column;
  border: 1px solid #e5eaf3;
  border-radius: 12px;
  background: #ffffff;
  text-align: left;
  cursor: pointer;
  overflow: hidden;
  appearance: none;
  padding: 0;
  transition:
    transform 0.22s cubic-bezier(0.22, 1, 0.36, 1),
    box-shadow 0.22s cubic-bezier(0.22, 1, 0.36, 1),
    border-color 0.22s cubic-bezier(0.22, 1, 0.36, 1);
}

.news-feature:hover {
  transform: translateY(-2px);
  border-color: rgba(22, 93, 255, 0.24);
  box-shadow: 0 18px 36px rgba(22, 93, 255, 0.1);
}

.news-feature__cover {
  position: relative;
  display: flex;
  align-items: center;
  justify-content: center;
  height: 200px;
  background:
    radial-gradient(circle at 30% 30%, rgba(255, 255, 255, 0.18), transparent 55%),
    #1e49cf;
  overflow: hidden;
}

.news-feature__cover-img {
  display: block;
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.news-feature__cover-text {
  color: #ffffff;
  font-size: 72px;
  font-weight: 800;
  letter-spacing: 0.1em;
  line-height: 1;
  text-shadow: 0 4px 18px rgba(0, 0, 0, 0.3);
  font-family: "PingFang SC", "Microsoft YaHei", "SimHei", sans-serif;
}

.news-feature__cover-decor {
  position: absolute;
  inset: 12px;
  border: 1px solid rgba(255, 255, 255, 0.28);
  border-radius: 6px;
  pointer-events: none;
}

.news-feature__cover-decor::before,
.news-feature__cover-decor::after {
  content: "";
  position: absolute;
  width: 22px;
  height: 22px;
  border: 1px solid rgba(255, 255, 255, 0.35);
}

.news-feature__cover-decor::before {
  top: 6px;
  left: 6px;
  border-right: none;
  border-bottom: none;
}

.news-feature__cover-decor::after {
  bottom: 6px;
  right: 6px;
  border-left: none;
  border-top: none;
}

.news-feature__body {
  display: grid;
  grid-template-columns: 72px minmax(0, 1fr);
  gap: 16px;
  padding: 18px 20px 22px;
}

.news-feature__date {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  padding-right: 12px;
  border-right: 1px solid #e5eaf3;
}

.news-feature__date strong {
  color: #0f172a;
  font-size: 32px;
  font-weight: 700;
  line-height: 1;
}

.news-feature__date span {
  margin-top: 6px;
  color: #94a0b2;
  font-size: 11px;
  letter-spacing: 0.06em;
}

.news-feature__meta {
  min-width: 0;
}

.news-feature__title {
  display: block;
  color: #111827;
  font-size: 14px;
  font-weight: 600;
  line-height: 1.5;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.news-feature__summary {
  margin: 8px 0 0;
  color: #5b6b82;
  font-size: 13px;
  line-height: 1.78;
  display: -webkit-box;
  overflow: hidden;
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 3;
  line-clamp: 3;
}

.news-list {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 12px;
}

.news-list__item {
  display: flex;
  flex-direction: column;
  justify-content: space-between;
  gap: 14px;
  padding: 18px 20px;
  border: 1px solid #e5eaf3;
  border-radius: 8px;
  background: #ffffff;
  text-align: left;
  cursor: pointer;
  appearance: none;
  transition:
    background 0.22s cubic-bezier(0.22, 1, 0.36, 1),
    border-color 0.22s cubic-bezier(0.22, 1, 0.36, 1),
    box-shadow 0.22s cubic-bezier(0.22, 1, 0.36, 1),
    transform 0.22s cubic-bezier(0.22, 1, 0.36, 1);
}

.news-list__item:hover {
  border-color: rgba(22, 93, 255, 0.3);
  transform: translateY(-1px);
  box-shadow: 0 10px 22px rgba(22, 93, 255, 0.08);
}

.news-list__title {
  color: #1f2937;
  font-size: 13px;
  font-weight: 500;
  line-height: 1.6;
  display: -webkit-box;
  overflow: hidden;
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 2;
  line-clamp: 2;
}

.news-list__meta {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  color: #94a0b2;
  font-size: 12px;
}

.news-list__meta .el-icon {
  font-size: 12px;
}

.news-promos {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 16px;
}

.news-promo {
  position: relative;
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 20px 22px;
  border: none;
  border-radius: 10px;
  background: linear-gradient(135deg, #f0f5ff, #e0eaff);
  text-align: left;
  cursor: pointer;
  appearance: none;
  overflow: hidden;
  isolation: isolate;
  transition:
    transform 0.24s cubic-bezier(0.22, 1, 0.36, 1),
    box-shadow 0.24s cubic-bezier(0.22, 1, 0.36, 1);
}

.news-promo::before {
  content: "";
  position: absolute;
  inset: auto -30% -60% auto;
  width: 200px;
  height: 200px;
  border-radius: 50%;
  background: radial-gradient(circle, rgba(22, 93, 255, 0.18), rgba(22, 93, 255, 0) 70%);
  pointer-events: none;
  z-index: -1;
}

.news-promo:hover {
  transform: translateY(-2px);
  box-shadow: 0 16px 28px rgba(22, 93, 255, 0.16);
}

.news-promo__badge {
  position: absolute;
  top: 16px;
  right: 16px;
  display: inline-flex;
  align-items: center;
  min-height: 22px;
  padding: 0 10px;
  border-radius: 4px;
  background: rgba(22, 93, 255, 0.12);
  color: #2f5ef3;
  font-size: 11px;
  font-weight: 600;
  letter-spacing: 0.02em;
}

.news-promo__body {
  flex: 1;
  min-width: 0;
  padding-top: 30px;
}

.news-promo__title {
  display: block;
  color: #111827;
  font-size: 15px;
  font-weight: 600;
  line-height: 1.5;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.news-promo__desc {
  margin: 8px 0 0;
  color: #5b6b82;
  font-size: 12px;
  line-height: 1.7;
  display: -webkit-box;
  overflow: hidden;
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 2;
  line-clamp: 2;
}

.news-promo__arrow {
  flex-shrink: 0;
  display: grid;
  place-items: center;
  width: 30px;
  height: 30px;
  border-radius: 50%;
  background: rgba(22, 93, 255, 0.1);
  color: #2f5ef3;
  font-size: 12px;
  transition:
    background 0.22s cubic-bezier(0.22, 1, 0.36, 1),
    transform 0.22s cubic-bezier(0.22, 1, 0.36, 1);
}

.news-promo:hover .news-promo__arrow {
  background: #2f5ef3;
  color: #ffffff;
  transform: translateX(2px);
}

@media (max-width: 1180px) {
  .news-board {
    grid-template-columns: 1fr;
  }

  .news-feature__cover {
    height: 160px;
  }

  .news-promos {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}

@media (max-width: 960px) {
  .news-section {
    padding: 48px 0 32px;
  }

  .news-section__head {
    margin-bottom: 24px;
  }

  .news-list {
    grid-template-columns: 1fr;
  }

  .news-feature__cover-text {
    font-size: 56px;
  }

  .news-feature__body {
    grid-template-columns: 60px minmax(0, 1fr);
    gap: 12px;
    padding: 16px;
  }

  .news-feature__date strong {
    font-size: 26px;
  }
}

@media (max-width: 640px) {
  .news-section__title {
    font-size: 20px;
  }

  .news-board {
    gap: 12px;
  }

  .news-promos {
    grid-template-columns: 1fr;
    gap: 12px;
  }

  .news-promo {
    padding: 18px 20px;
  }
}
</style>
