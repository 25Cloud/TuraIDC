<template>
  <section class="partner-section">
    <div class="container partner-section__head">
      <h2 class="partner-section__title">优质合作伙伴 共建二五云计算生态</h2>
      <p class="partner-section__desc">以优质资源与服务领航开拓，助力更多企业高效上云</p>
    </div>

    <div class="partner-section__marquee-wrap">
      <div
        v-for="strip in partnerStrips"
        :key="strip.id"
        class="partner-section__marquee-row"
        :class="{ 'partner-section__marquee-row--reverse': strip.direction === 'reverse' }"
      >
        <div
          class="partner-section__marquee-track"
          :style="{ '--speed': strip.speed }"
        >
          <img
            class="partner-section__strip"
            :src="strip.src"
            :alt="strip.alt"
            loading="lazy"
            decoding="async"
          />
          <img
            class="partner-section__strip"
            :src="strip.src"
            :alt="strip.alt"
            loading="lazy"
            decoding="async"
            aria-hidden="true"
          />
        </div>
      </div>
    </div>
  </section>
</template>

<script setup lang="ts">
import { partnerStrips } from '@/data/homePartners'
</script>

<style scoped lang="scss">
@use '@/assets/styles/variables' as *;

.partner-section {
  background: #fff;
  padding: 72px 0 64px;

  &__head {
    text-align: center;
    margin-bottom: 40px;
  }

  &__title {
    font-size: clamp(22px, 3vw, 32px);
    font-weight: 700;
    color: $text-color-primary;
    line-height: 1.3;
    margin: 0 0 12px;
  }

  &__desc {
    font-size: clamp(14px, 1.6vw, 16px);
    color: $text-color-secondary;
    margin: 0;
    line-height: 1.6;
  }

  &__marquee-wrap {
    display: flex;
    flex-direction: column;
    gap: 10px;
    overflow: hidden;
  }

  &__marquee-row {
    overflow: hidden;
    width: 100%;

    .partner-section__marquee-track {
      display: flex;
      animation: partner-marquee-scroll var(--speed, 20s) linear infinite;
      width: max-content;
    }

    &--reverse .partner-section__marquee-track {
      animation-direction: reverse;
    }
  }

  &__strip {
    flex-shrink: 0;
    height: auto;
    width: auto;
    max-height: 80px;
    display: block;
  }
}

@keyframes partner-marquee-scroll {
  from { transform: translateX(0); }
  to { transform: translateX(-50%); }
}

@media (max-width: 640px) {
  .partner-section {
    padding: 48px 0 40px;

    &__head {
      margin-bottom: 28px;
    }

    &__marquee-wrap {
      gap: 6px;
    }

    &__strip {
      max-height: 56px;
    }
  }
}
</style>
