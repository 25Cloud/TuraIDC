<template>
  <section class="register-bar-section">
    <div class="register-bar">
      <div class="register-bar__inner container">
        <h3 class="register-bar__title">
          现在注册，开启您的互联网高速稳定之旅！
        </h3>
        <button type="button" class="register-bar__button" @click="goRegister">
          立即注册
        </button>
      </div>
    </div>
  </section>
</template>

<script setup>
import { navigateToConsole } from "@/utils/consoleUrl";

function goRegister() {
  navigateToConsole("/client/register");
}
</script>

<style scoped lang="scss">
.container {
  width: min(1200px, calc(100% - 48px));
  margin: 0 auto;
}

.register-bar-section {
  padding: 24px 0 64px;
  background: #f4f7fc;
}

.register-bar {
  position: relative;
  overflow: hidden;
  border-radius: 6px;
  width: min(1200px, calc(100% - 48px));
  margin: 0 auto;
  background: $color-primary-hover;

  /* 9.1 Visual Mode: Grain Texture */
  &::before {
    content: "";
    position: absolute;
    inset: 0;
    opacity: 0.04;
    pointer-events: none;
    background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 200 200' xmlns='http://www.w3.org/2000/svg'%3%3Ffilter id='noiseFilter'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.65' numOctaves='3' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23noiseFilter)'/%3E%3C/svg%3E");
    z-index: 0;
  }
}

.register-bar__inner {
  position: relative;
  z-index: 2;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 24px;
  padding: 28px 40px;
}

.register-bar__title {
  margin: 0;
  color: #ffffff;
  font-size: clamp(18px, 2vw, 22px);
  font-weight: 600;
  letter-spacing: 0.01em;
  text-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
}

.register-bar__button {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 140px;
  height: 44px;
  padding: 0 28px;
  border: none;
  border-radius: 3px;
  background: #ffffff;
  color: $color-primary;
  font-size: 14px;
  font-weight: 600;
  cursor: pointer;
  transition:
    box-shadow 0.2s ease,
    background 0.2s ease;
}

.register-bar__button:hover {
  background: #f8faff;
  box-shadow: 0 6px 20px rgba(15, 23, 42, 0.08);
}

.register-bar__button:focus-visible {
  outline: none;
  box-shadow: 0 0 0 3px rgba(255, 255, 255, 0.6);
}

@media (max-width: 960px) {
  .container {
    width: min(100%, calc(100% - 32px));
  }

  .register-bar__inner {
    flex-direction: column;
    align-items: flex-start;
    gap: 16px;
    padding: 24px 20px;
  }

  .register-bar__button {
    align-self: stretch;
  }
}

@media (max-width: 640px) {
  .register-bar {
    width: calc(100% - 24px);
  }

  .register-bar__title {
    font-size: 15px;
  }
}
</style>
