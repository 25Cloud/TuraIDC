<template>
  <div class="route-loading-shell" role="status" aria-live="polite" aria-busy="true">
    <div class="route-loading-shell__hero">
      <span class="route-loading-shell__pill"></span>
      <span class="route-loading-shell__headline"></span>
      <span class="route-loading-shell__subline route-loading-shell__subline--wide"></span>
      <span class="route-loading-shell__subline"></span>
    </div>

    <div class="route-loading-shell__grid">
      <div v-for="index in 3" :key="index" class="route-loading-shell__card" :style="{ animationDelay: `${index * 60}ms` }">
        <span class="route-loading-shell__line route-loading-shell__line--title"></span>
        <span class="route-loading-shell__line"></span>
        <span class="route-loading-shell__line route-loading-shell__line--short"></span>
      </div>
    </div>
  </div>
</template>

<style scoped lang="scss">
.route-loading-shell {
  min-height: min(68vh, 720px);
  padding: 24px;
  background:
    linear-gradient(180deg, rgba(255, 255, 255, 0.95), rgba(248, 250, 252, 0.82)),
    radial-gradient(circle at top right, rgba($color-primary, 0.08), transparent 28%);
  animation: route-loading-appear 0.3s ease-out both;
  animation-delay: 120ms; /* 延迟出现，避免快速加载时闪烁 */
}

.route-loading-shell__hero,
.route-loading-shell__card {
  border: 1px solid rgba($color-primary, 0.08);
  border-radius: $lg-border-radius;
  background: rgba(255, 255, 255, 0.86);
  box-shadow: 0 18px 38px rgba(15, 23, 42, 0.05);
}

.route-loading-shell__hero {
  display: flex;
  flex-direction: column;
  gap: 14px;
  padding: 26px 24px;
  animation: route-loading-appear 0.3s ease-out both;
  animation-delay: 180ms;
}

.route-loading-shell__grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 18px;
  margin-top: 18px;
}

.route-loading-shell__card {
  display: flex;
  flex-direction: column;
  gap: 12px;
  min-height: 186px;
  padding: 22px 20px;
  animation: route-loading-appear 0.35s ease-out both;
}

.route-loading-shell__pill,
.route-loading-shell__headline,
.route-loading-shell__subline,
.route-loading-shell__line {
  display: block;
  position: relative;
  overflow: hidden;
  border-radius: 999px;
  background: rgba(148, 163, 184, 0.14);
}

.route-loading-shell__pill::after,
.route-loading-shell__headline::after,
.route-loading-shell__subline::after,
.route-loading-shell__line::after {
  content: '';
  position: absolute;
  inset: 0;
  transform: translateX(-100%);
  background: linear-gradient(
    90deg,
    transparent 0%,
    rgba(255, 255, 255, 0.5) 40%,
    rgba(255, 255, 255, 0.8) 50%,
    rgba(255, 255, 255, 0.5) 60%,
    transparent 100%
  );
  animation: route-loading-shimmer 1.4s cubic-bezier(0.4, 0, 0.2, 1) infinite;
  animation-delay: 300ms;
}

.route-loading-shell__pill {
  width: 96px;
  height: 12px;
}

.route-loading-shell__headline {
  width: min(360px, 72%);
  height: 28px;
}

.route-loading-shell__subline {
  width: 44%;
  height: 14px;
}

.route-loading-shell__subline--wide {
  width: min(540px, 88%);
}

.route-loading-shell__line {
  width: 100%;
  height: 14px;
}

.route-loading-shell__line--title {
  width: 54%;
  height: 20px;
}

.route-loading-shell__line--short {
  width: 72%;
}

@keyframes route-loading-shimmer {
  0% {
    transform: translateX(-100%);
  }

  100% {
    transform: translateX(100%);
  }
}

@keyframes route-loading-appear {
  from {
    opacity: 0;
    transform: translateY(6px);
  }

  to {
    opacity: 1;
    transform: translateY(0);
  }
}

@media (max-width: 960px) {
  .route-loading-shell {
    min-height: 52vh;
    padding: 18px 16px;
  }

  .route-loading-shell__hero {
    gap: 12px;
    padding: 22px 18px;
  }

  .route-loading-shell__grid {
    grid-template-columns: 1fr;
    gap: 14px;
  }

  .route-loading-shell__card {
    min-height: 148px;
    padding: 18px 16px;
  }

  .route-loading-shell__headline,
  .route-loading-shell__subline,
  .route-loading-shell__subline--wide,
  .route-loading-shell__line--title,
  .route-loading-shell__line--short {
    width: 100%;
  }
}
</style>
