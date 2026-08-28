<script setup lang="ts">
const options = [
  {
    kicker: "面板部署",
    title: "宝塔部署",
    description:
      "适用于使用宝塔面板管理 PHP、Nginx 与计划任务的服务器。",
    link: "/references/operations/bt-panel-deployment",
    action: "查看宝塔部署指南",
  },
  {
    kicker: "裸机部署",
    title: "裸机源码部署",
    description:
      "适用于不使用面板与容器、以 nginx + PHP-FPM + systemd + cron 原生服务运行的服务器。",
    link: "/references/operations/bare-metal-deployment",
    action: "查看裸机源码部署指南",
  },
  {
    kicker: "容器部署",
    title: "Docker / 1Panel",
    description:
      "适用于 Docker Compose 部署，或使用 1Panel 统一管理容器的环境。",
    link: "/references/operations/docker-and-1panel-deployment",
    action: "查看 Docker 与 1Panel 指南",
  },
];
</script>

<template>
  <div class="deployment-selector">
    <a
      v-for="(option, index) in options"
      :key="option.link"
      class="deployment-option"
      :href="option.link"
      :style="{ animationDelay: `${index * 70}ms` }"
    >
      <span class="deployment-option-kicker">{{ option.kicker }}</span>
      <strong>{{ option.title }}</strong>
      <span>{{ option.description }}</span>
      <em>{{ option.action }} →</em>
    </a>
  </div>
</template>

<style scoped>
/* 选择器配色令牌：跟随 VitePress 深浅色切换（html.dark） */
.deployment-selector {
  --selector-border: #e5eaf3;
  --selector-text-1: #1f2937;
  --selector-text-2: #5b6b82;
  --selector-brand: #165dff;
  --selector-shadow: rgba(22, 93, 255, 0.1);
  --selector-ease: cubic-bezier(0.22, 0.61, 0.36, 1);

  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 16px;
  margin: 28px 0 36px;
}

.dark .deployment-selector {
  --selector-border: #34343f;
  --selector-text-1: #e6e8ee;
  --selector-text-2: #9aa4b8;
  --selector-brand: #6ba3ff;
  --selector-shadow: rgba(107, 163, 255, 0.14);
}

/* 入场淡入上移关键帧 */
@keyframes selector-rise {
  from {
    opacity: 0;
    transform: translateY(16px);
  }

  to {
    opacity: 1;
    transform: translateY(0);
  }
}

.deployment-option {
  min-height: 218px;
  padding: 24px;
  display: flex;
  flex-direction: column;
  box-sizing: border-box;
  border: 1px solid var(--selector-border);
  border-radius: 8px;
  color: var(--selector-text-2);
  text-decoration: none;
  animation: selector-rise 0.5s var(--selector-ease) both;
  transition:
    border-color 0.25s ease,
    box-shadow 0.25s ease,
    transform 0.25s ease;
}

.deployment-option:hover {
  border-color: var(--selector-brand);
  box-shadow: 0 8px 24px var(--selector-shadow);
  transform: translateY(-3px);
}

.deployment-option-kicker {
  color: var(--selector-brand);
  font-size: 12px;
  font-weight: 700;
}

.deployment-option strong {
  margin-top: 12px;
  color: var(--selector-text-1);
  font-size: 20px;
}

.deployment-option > span:not(.deployment-option-kicker) {
  margin-top: 10px;
  font-size: 14px;
  line-height: 1.7;
}

.deployment-option em {
  margin-top: auto;
  padding-top: 20px;
  color: var(--selector-brand);
  font-size: 14px;
  font-style: normal;
  font-weight: 600;
  transition: transform 0.25s ease;
}

.deployment-option:hover em {
  transform: translateX(4px);
}

/* 系统减弱动效时关闭入场与过渡 */
@media (prefers-reduced-motion: reduce) {
  .deployment-option {
    animation: none;
  }

  .deployment-option,
  .deployment-option em {
    transition: none;
  }
}

@media (max-width: 640px) {
  .deployment-selector {
    grid-template-columns: 1fr;
  }

  .deployment-option {
    min-height: 0;
  }
}
</style>
