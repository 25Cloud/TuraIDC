<script setup lang="ts">
const guides = [
  {
    title: "快速开始",
    description: "从项目入口、本地环境和验证方式开始。",
    links: [
      ["文档首页", "/"],
      ["本地启动", "/参考资料/运维/本地启动指南"],
      ["测试指南", "/参考资料/运维/测试指南"],
    ],
  },
  {
    title: "开发指南",
    description: "后端、前端、数据库、设计与插件开发的当前规范。",
    links: [
      ["后端规范", "/BACKEND"],
      ["前端规范", "/FRONTEND"],
      ["插件开发", "/参考资料/集成/插件/"],
    ],
  },
  {
    title: "系统架构",
    description: "查看运行中的系统结构、模块边界与扩展架构。",
    links: [
      ["系统架构", "/ARCHITECTURE"],
      ["架构设计", "/设计文档/架构/"],
    ],
  },
  {
    title: "API 文档",
    description: "查看接口契约、业务域导航和后端 API 自动清单。",
    links: [
      ["API 格式规范", "/参考资料/接口/API格式规范"],
      ["后端 API 清单", "/自动生成/接口/后端API清单"],
    ],
  },
  {
    title: "系统运维",
    description: "覆盖部署调度、数据迁移、维护操作与执行计划。",
    links: [
      ["部署与调度", "/参考资料/运维/部署与调度指南"],
      ["数据库迁移", "/参考资料/数据库/"],
      ["执行计划", "/执行计划/"],
    ],
  },
];
</script>

<template>
  <div class="portal">
    <header class="portal-header">
      <a class="portal-brand" href="/" aria-label="TuraIDC 文档中心首页">
        <img src="/branding/turaidc-logo.png" alt="TuraIDC" />
        <span>文档中心</span>
      </a>
      <nav class="portal-nav" aria-label="主要导航">
        <a href="/ARCHITECTURE">系统架构</a>
        <a href="/自动生成/接口/后端API清单">API 参考</a>
        <a href="/参考资料/运维/部署与调度指南">部署运维</a>
        <a
          href="https://github.com/25Cloud/TuraIDC"
          target="_blank"
          rel="noreferrer"
          >GitHub</a
        >
      </nav>
    </header>

    <main>
      <section class="portal-hero">
        <div class="portal-hero-copy">
          <h1><span>TuraIDC</span><br />业务/财务系统</h1>
          <p>
            从生产部署到接口集成，围绕快速开始、开发指南、系统架构、API
            文档和系统运维组织项目知识。
          </p>
          <div class="portal-actions">
            <a class="portal-primary-action" href="/快速开始">快速开始</a>
            <a class="portal-secondary-action" href="/BACKEND">开发指南</a>
          </div>
        </div>
        <div class="portal-hero-visual">
          <img
            class="portal-hero-logo"
            src="/branding/turaidc-logo.png"
            alt="TuraIDC"
          />
        </div>
      </section>

      <section class="portal-section" aria-labelledby="portal-guides-title">
        <div class="portal-section-heading">
          <h2 id="portal-guides-title">按主题进入文档</h2>
        </div>
        <div class="portal-guide-grid">
          <section
            v-for="guide in guides"
            :key="guide.title"
            class="portal-guide"
          >
            <h3>{{ guide.title }}</h3>
            <p>{{ guide.description }}</p>
            <ul>
              <li v-for="[text, link] in guide.links" :key="link">
                <a :href="link">{{ text }}<span aria-hidden="true">→</span></a>
              </li>
            </ul>
          </section>
        </div>
      </section>
    </main>

    <footer class="portal-footer">
      <span>© TuraIDC Contributors</span>
      <a
        href="https://github.com/25Cloud/TuraIDC"
        target="_blank"
        rel="noreferrer"
        >GitHub 仓库</a
      >
    </footer>
  </div>
</template>
