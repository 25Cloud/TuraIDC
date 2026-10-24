<script setup lang="ts">
import { LogoGithubIcon } from "tdesign-icons-vue-next";

import { DOCS_URL, NAV_LINKS, REPO_URL, SITE } from "../data/content";
</script>

<template>
  <header class="hd">
    <div class="hd-inner">
      <a class="hd-brand" href="#top">
        <img
          class="hd-logo"
          src="/turaidc-logo.webp"
          alt="TuraIDC 图拉云"
          width="129"
          height="24"
          decoding="async"
        />
        <span class="hd-edition">{{ SITE.edition }}</span>
      </a>

      <nav class="hd-nav" aria-label="页面内导航">
        <a v-for="link in NAV_LINKS" :key="link.href" :href="link.href">
          {{ link.text }}
        </a>
      </nav>

      <div class="hd-actions">
        <a class="btn btn-ghost btn-sm hd-docs" :href="DOCS_URL">文档</a>
        <a
          class="btn btn-dark btn-sm"
          :href="REPO_URL"
          target="_blank"
          rel="noopener noreferrer"
        >
          <LogoGithubIcon size="16px" aria-hidden="true" />
          <span>GitHub</span>
        </a>
      </div>
    </div>
  </header>
</template>

<style scoped>
.hd {
  position: sticky;
  top: 0;
  z-index: 50;
  background: rgba(255, 255, 255, 0.92);
  border-bottom: 1px solid var(--border);
  backdrop-filter: saturate(180%) blur(8px);
}

.hd-inner {
  display: flex;
  align-items: center;
  gap: 24px;
  width: 100%;
  max-width: var(--maxw);
  height: var(--header-h);
  margin: 0 auto;
  padding: 0 24px;
}

.hd-brand {
  display: inline-flex;
  align-items: center;
  gap: 10px;
  flex: none;
}

.hd-logo {
  height: 24px;
  width: auto;
}

.hd-edition {
  padding: 3px 8px;
  border: 1px solid var(--border);
  border-radius: 999px;
  color: var(--text-2);
  font-size: 11px;
  white-space: nowrap;
}

.hd-nav {
  display: flex;
  align-items: center;
  gap: 4px;
  margin-left: 8px;
}

.hd-nav a {
  padding: 6px 10px;
  border-radius: var(--radius-sm);
  color: var(--text-2);
  font-size: 14px;
  transition:
    color 0.18s ease,
    background-color 0.18s ease;
}

.hd-nav a:hover {
  color: var(--brand);
  background: var(--brand-soft);
}

.hd-actions {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-left: auto;
}

@media (max-width: 960px) {
  .hd-nav,
  .hd-edition {
    display: none;
  }
}

@media (max-width: 560px) {
  .hd-inner {
    gap: 12px;
    padding: 0 20px;
  }

  .hd-docs {
    display: none;
  }
}
</style>
