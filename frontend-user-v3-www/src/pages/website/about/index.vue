<template>
  <div class="about-page">
    <section class="about-hero">
      <div class="container">
        <p class="about-kicker">关于我们</p>
        <h1>{{ appStore.siteName || '图拉云' }}</h1>
        <p class="about-desc">
          我们专注于云服务器、独立服务器与相关基础设施服务，提供从选型、购买、开通到运维支持的一体化体验。
        </p>
      </div>
    </section>

    <section class="about-section">
      <div class="container about-grid">
        <article class="about-card">
          <h2>服务能力</h2>
          <p>覆盖产品展示、在线购买、统一账单、自动开通、售后支持与服务控制台。</p>
        </article>
        <article class="about-card">
          <h2>联系方式</h2>
          <p>客服邮箱：{{ appStore.serviceEmail || '暂无配置' }}</p>
          <p>服务时间：{{ appStore.serviceHours || '以站内通知为准' }}</p>
          <p>
            官方群号：
            <a
              v-if="appStore.supportGroupLink"
              :href="appStore.supportGroupLink"
              target="_blank"
              rel="noopener noreferrer"
            >{{ appStore.serviceQqGroup || '暂无配置' }}</a>
            <span v-else>{{ appStore.serviceQqGroup || '暂无配置' }}</span>
          </p>
        </article>
      </div>
    </section>
  </div>
</template>

<script setup lang="ts">
import { useAppStore } from '@/stores/app'

const appStore = useAppStore()
</script>

<style scoped lang="scss">
.container {
  width: min(1200px, calc(100% - 48px));
  margin: 0 auto;
}

.about-hero {
  padding: 64px 0 32px;
  background: linear-gradient(180deg, #fff 0%, #f5f8fd 100%);
}

.about-kicker {
  margin: 0;
  color: #2f5ef3;
  font-weight: 600;
}

h1 {
  margin: 12px 0 0;
  color: #0f172a;
  font-size: 40px;
}

.about-desc {
  max-width: 720px;
  margin-top: 16px;
  color: #64748b;
  line-height: 1.9;
}

.about-section {
  padding: 24px 0 72px;
}

.about-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 20px;
}

@media (max-width: 768px) {
  .about-grid {
    grid-template-columns: 1fr;
  }
}

.about-card {
  padding: 28px;
  border: 1px solid #e5eaf3;
  border-radius: 18px;
  background: #fff;
  box-shadow: 0 18px 36px rgba(15, 23, 42, 0.06);
}

.about-card h2 {
  margin: 0 0 12px;
  color: #0f172a;
}

.about-card p {
  margin: 8px 0 0;
  color: #64748b;
  line-height: 1.8;
}
</style>
