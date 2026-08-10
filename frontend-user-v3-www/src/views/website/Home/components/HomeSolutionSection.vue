<template>
  <section class="solution-section">
    <div class="container solution-section__head">
      <h2 class="solution-section__title">为千行百业提供全面的解决方案</h2>
      <p class="solution-section__desc">
        面向丰富的业务场景，提供覆盖行业与技术的解决方案。
      </p>
    </div>

    <div class="solution-stage">
      <div class="solution-stage__media" aria-hidden="true">
        <transition name="solution-image" mode="out-in">
          <img
            :key="activeIndustrySolution.image"
            class="solution-stage__image"
            :src="activeIndustrySolution.image"
            :alt="activeIndustrySolution.title"
            loading="lazy"
            decoding="async"
            fetchpriority="low"
          />
        </transition>
        <div class="solution-stage__overlay"></div>
      </div>

      <div class="container solution-stage__inner">
        <div class="solution-panel">
          <aside class="solution-panel__rail" role="tablist" aria-label="行业解决方案">
            <button
              v-for="item in industrySolutions"
              :key="item.key"
              type="button"
              role="tab"
              class="solution-panel__rail-item"
              :class="{ 'is-active': item.key === activeSolutionKey }"
              :aria-selected="item.key === activeSolutionKey"
              :tabindex="item.key === activeSolutionKey ? 0 : -1"
              @click="activeSolutionKey = item.key"
            >
              {{ item.label }}
            </button>
          </aside>

          <div class="solution-panel__content">
            <h3 class="solution-panel__title">{{ activeIndustrySolution.title }}</h3>
            <p class="solution-panel__summary">{{ activeIndustrySolution.description }}</p>

            <div class="solution-panel__section-title">解决方案</div>
            <ul class="solution-panel__feature-list">
              <li
                v-for="point in activeIndustrySolution.points"
                :key="point"
                class="solution-panel__feature"
              >
                <span class="solution-panel__feature-icon">
                  <el-icon><Select /></el-icon>
                </span>
                <span>{{ point }}</span>
              </li>
            </ul>

            <div class="solution-panel__section-title">相关产品</div>
            <div class="solution-panel__actions">
              <button
                type="button"
                class="solution-panel__action solution-panel__action--primary"
                @click="router.push(activeIndustrySolution.productPath)"
              >
                云服务器
              </button>
              <button
                type="button"
                class="solution-panel__action"
                @click="router.push(activeIndustrySolution.consultPath)"
              >
                了解更多
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>
  </section>
</template>

<script setup>
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'
import { ElIcon } from 'element-plus/es/components/icon/index.mjs'
import { Select } from '@element-plus/icons-vue'
import { industrySolutions } from '@/data/homeSolutions'

const router = useRouter()
const activeSolutionKey = ref(industrySolutions[0].key)

const activeIndustrySolution = computed(() => (
  industrySolutions.find((item) => item.key === activeSolutionKey.value) || industrySolutions[0]
))
</script>

<style scoped lang="scss">
.container {
  width: min(1200px, calc(100% - 48px));
  margin: 0 auto;
}

.solution-section {
  position: relative;
  padding: 72px 0 0;
  overflow: hidden;
  background: #f4f7fc;
}

.solution-section__head {
  text-align: center;
  margin-bottom: 48px;
}

.solution-section__title {
  margin: 0;
  color: #0f172a;
  font-size: clamp(26px, 2.4vw, 30px);
  font-weight: 700;
  line-height: 1.28;
  letter-spacing: -0.01em;
}

.solution-section__desc {
  max-width: 640px;
  margin: 12px auto 0;
  color: #5b6b82;
  font-size: 14px;
  line-height: 1.8;
}

.solution-stage {
  position: relative;
  padding: 160px 0 84px;
}

.solution-stage__media {
  position: absolute;
  inset: 0;
  overflow: hidden;
  z-index: 0;
}

.solution-stage__image {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  object-fit: cover;
  filter: saturate(0.92) brightness(0.96);
}

.solution-stage__overlay {
  position: absolute;
  inset: 0;
  background:
    linear-gradient(180deg, rgba(246, 248, 252, 0.9) 0%, rgba(246, 248, 252, 0.55) 28%, rgba(233, 240, 250, 0.65) 70%, rgba(220, 230, 245, 0.82) 100%),
    radial-gradient(circle at 70% 30%, rgba(22, 93, 255, 0.1), transparent 48%);

  /* 9.1 Visual Mode: Grain Texture for stage */
  &::before {
    content: "";
    position: absolute;
    inset: 0;
    opacity: 0.03;
    pointer-events: none;
    background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 200 200' xmlns='http://www.w3.org/2000/svg'%3%3Ffilter id='noiseFilter'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.65' numOctaves='3' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23noiseFilter)'/%3E%3C/svg%3E");
  }
}

.solution-stage__inner {
  position: relative;
  z-index: 1;
}

.solution-panel {
  display: grid;
  grid-template-columns: 220px minmax(0, 1fr);
  align-items: stretch;
  width: min(960px, 100%);
  margin-left: auto;
  background: #ffffff;
  border-radius: 12px;
  box-shadow: 0 24px 60px rgba(15, 23, 42, 0.12);
  overflow: hidden;
}

.solution-panel__rail {
  display: flex;
  flex-direction: column;
  gap: 4px;
  padding: 16px 0;
  background: #f4f6fb;
  border-right: 1px solid #e9edf5;
}

.solution-panel__rail-item {
  position: relative;
  display: block;
  width: 100%;
  padding: 16px 24px;
  border: none;
  background: transparent;
  color: #1f2937;
  font-size: 14px;
  font-weight: 500;
  text-align: left;
  cursor: pointer;
  transition: color 0.22s cubic-bezier(0.22, 1, 0.36, 1), background 0.22s cubic-bezier(0.22, 1, 0.36, 1);
}

.solution-panel__rail-item:hover {
  color: #2f5ef3;
  background: rgba(22, 93, 255, 0.04);
}

.solution-panel__rail-item.is-active {
  color: #ffffff;
  background: #2f5ef3;
  font-weight: 600;
}

.solution-panel__rail-item.is-active::after {
  content: "›";
  position: absolute;
  right: 20px;
  top: 50%;
  transform: translateY(-50%);
  color: #ffffff;
  font-size: 16px;
  font-weight: 400;
}

.solution-panel__content {
  padding: 28px 32px 32px;
  min-width: 0;
}

.solution-panel__title {
  margin: 0;
  color: #111827;
  font-size: 20px;
  font-weight: 700;
  line-height: 1.35;
}

.solution-panel__summary {
  margin: 12px 0 0;
  color: #4b5565;
  font-size: 14px;
  line-height: 1.9;
}

.solution-panel__section-title {
  margin-top: 24px;
  color: #1f2937;
  font-size: 14px;
  font-weight: 600;
  line-height: 1.5;
}

.solution-panel__feature-list {
  display: grid;
  gap: 10px;
  margin: 12px 0 0;
  padding: 0;
  list-style: none;
}

.solution-panel__feature {
  display: flex;
  align-items: center;
  gap: 10px;
  color: #4b5565;
  font-size: 13px;
  line-height: 1.7;
}

.solution-panel__feature-icon {
  display: grid;
  place-items: center;
  width: 16px;
  height: 16px;
  border-radius: 50%;
  background: rgba(22, 93, 255, 0.12);
  color: #2f5ef3;
  font-size: 10px;
  flex-shrink: 0;
}

.solution-panel__actions {
  display: flex;
  gap: 10px;
  flex-wrap: wrap;
  margin-top: 12px;
}

.solution-panel__action {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 110px;
  height: 36px;
  padding: 0 20px;
  border: 1px solid #d5dbe8;
  border-radius: 4px;
  background: #ffffff;
  color: #1f2937;
  font-size: 13px;
  font-weight: 500;
  cursor: pointer;
  transition:
    border-color 0.22s cubic-bezier(0.22, 1, 0.36, 1),
    background 0.22s cubic-bezier(0.22, 1, 0.36, 1),
    color 0.22s cubic-bezier(0.22, 1, 0.36, 1),
    box-shadow 0.22s cubic-bezier(0.22, 1, 0.36, 1);
}

.solution-panel__action:hover {
  border-color: rgba(22, 93, 255, 0.48);
  color: #2f5ef3;
}

.solution-panel__action--primary {
  border-color: #2f5ef3;
  background: #2f5ef3;
  color: #ffffff;
  box-shadow: 0 8px 18px rgba(22, 93, 255, 0.22);
}

.solution-panel__action--primary:hover {
  border-color: #2754e3;
  background: #2754e3;
  color: #ffffff;
}

.solution-image-enter-active,
.solution-image-leave-active {
  transition: opacity 0.3s cubic-bezier(0.22, 1, 0.36, 1);
}

.solution-image-enter-from,
.solution-image-leave-to {
  opacity: 0;
}

@media (max-width: 1180px) {
  .solution-stage {
    padding: 120px 0 64px;
  }

  .solution-panel {
    margin-left: 0;
    margin-right: auto;
  }
}

@media (max-width: 960px) {
  .solution-stage {
    padding: 56px 0 48px;
  }

  .solution-stage__media {
    opacity: 0.35;
  }

  .solution-panel {
    grid-template-columns: 1fr;
    width: 100%;
    margin: 0 auto;
  }

  .solution-panel__rail {
    flex-direction: row;
    flex-wrap: nowrap;
    gap: 8px;
    padding: 10px 12px;
    border-right: none;
    border-bottom: 1px solid #e9edf5;
    overflow-x: auto;
    scrollbar-width: none;
    -webkit-overflow-scrolling: touch;
  }

  .solution-panel__rail::-webkit-scrollbar {
    display: none;
  }

  .solution-panel__rail-item {
    flex: 0 0 auto;
    width: auto;
    padding: 8px 16px;
    border-radius: 999px;
    font-size: 13px;
  }

  .solution-panel__rail-item.is-active::after {
    display: none;
  }

  .solution-panel__content {
    padding: 22px 20px 24px;
  }
}

@media (max-width: 640px) {
  .solution-stage {
    padding: 40px 0 36px;
  }

  .solution-section__title {
    font-size: 20px;
  }

  .solution-section__desc {
    font-size: 13px;
  }

  .solution-panel__rail {
    padding: 8px;
  }

  .solution-panel__content {
    padding: 20px 18px 22px;
  }

  .solution-panel__title {
    font-size: 18px;
  }

  .solution-panel__summary,
  .solution-panel__feature {
    font-size: 13px;
  }
}
</style>
