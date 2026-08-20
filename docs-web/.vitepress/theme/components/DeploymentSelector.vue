<template>
  <div class="deployment-selector">
    <a
      class="deployment-option"
      href="/references/operations/bt-panel-deployment"
    >
      <span class="deployment-option-kicker">面板部署</span>
      <strong>宝塔部署</strong>
      <span>适用于使用宝塔面板管理 PHP、Nginx 与计划任务的服务器。</span>
      <em>查看宝塔部署指南 →</em>
    </a>
    <a
      class="deployment-option"
      href="/references/operations/docker-and-1panel-deployment"
    >
      <span class="deployment-option-kicker">容器部署</span>
      <strong>Docker / 1Panel</strong>
      <span
        >适用于 Docker Compose 部署，或使用 1Panel 统一管理容器的环境。</span
      >
      <em>查看 Docker 与 1Panel 指南 →</em>
    </a>
  </div>
</template>

<style scoped>
.deployment-selector {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 16px;
  margin: 28px 0 36px;
}

.deployment-option {
  min-height: 218px;
  padding: 24px;
  display: flex;
  flex-direction: column;
  box-sizing: border-box;
  border: 1px solid #e5eaf3;
  border-radius: 8px;
  color: #5b6b82;
  text-decoration: none;
}

.deployment-option:hover {
  border-color: #165dff;
  box-shadow: 0 8px 24px rgba(22, 93, 255, 0.1);
}

.deployment-option-kicker {
  color: #165dff;
  font-size: 12px;
  font-weight: 700;
}

.deployment-option strong {
  margin-top: 12px;
  color: #1f2937;
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
  color: #165dff;
  font-size: 14px;
  font-style: normal;
  font-weight: 600;
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
