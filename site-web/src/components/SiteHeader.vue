<script setup lang="ts">
import {
  ContrastIcon,
  LogoGithubIcon,
  MoonIcon,
  SunnyIcon,
  TranslateIcon,
} from "tdesign-icons-vue-next";

import { LINKS, NAV_LINKS } from "../data/site";
import { useSite } from "../i18n/context";

const { messages, locale, locales } = useSite();
</script>

<template>
  <header class="hd">
    <div class="hd-inner">
      <a class="hd-brand" href="#top">
        <img
          class="hd-logo"
          src="/turaidc-logo.webp"
          alt="TuraIDC"
          width="129"
          height="24"
          decoding="async"
        />
        <span class="hd-edition">AGPL-3.0</span>
      </a>

      <nav class="hd-nav" aria-label="Sections">
        <a v-for="link in NAV_LINKS" :key="link.key" :href="link.href">
          {{ messages.nav[link.key] }}
        </a>
      </nav>

      <div class="hd-tools">
        <details class="lang">
          <summary :title="messages.langLabel">
            <TranslateIcon size="16px" aria-hidden="true" />
            <span class="lang-current">{{ locale.label }}</span>
          </summary>
          <ul class="lang-list">
            <li v-for="item in locales" :key="item.code">
              <a
                :href="item.path"
                :hreflang="item.htmlLang"
                :lang="item.htmlLang"
                :class="{ active: item.code === locale.code }"
                :aria-current="item.code === locale.code ? 'page' : undefined"
              >
                {{ item.label }}
              </a>
            </li>
          </ul>
        </details>

        <div
          class="theme theme-switch"
          role="group"
          :aria-label="messages.theme.label"
        >
          <button
            type="button"
            data-theme-set="light"
            :title="messages.theme.light"
            :aria-label="messages.theme.light"
          >
            <SunnyIcon size="15px" aria-hidden="true" />
          </button>
          <button
            type="button"
            data-theme-set="dark"
            :title="messages.theme.dark"
            :aria-label="messages.theme.dark"
          >
            <MoonIcon size="15px" aria-hidden="true" />
          </button>
          <button
            type="button"
            data-theme-set="auto"
            :title="messages.theme.auto"
            :aria-label="messages.theme.auto"
          >
            <ContrastIcon size="15px" aria-hidden="true" />
          </button>
        </div>

        <a class="btn btn-ghost btn-sm hd-docs" :href="LINKS.docs">
          {{ messages.actions.viewDocs }}
        </a>
        <a
          class="btn btn-dark btn-sm"
          :href="LINKS.repo"
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
  background: var(--header-bg);
  border-bottom: 1px solid var(--border);
  backdrop-filter: saturate(180%) blur(10px);
}

.hd-inner {
  display: flex;
  align-items: center;
  gap: 20px;
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
  color: var(--text-3);
  font-size: 11px;
  white-space: nowrap;
}

.hd-nav {
  display: flex;
  align-items: center;
  gap: 2px;
  margin-left: 4px;
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

.hd-tools {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-left: auto;
}

/* 语言：无脚本下拉，靠原生 details 展开 */
.lang {
  position: relative;
}

.lang summary {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 7px 10px;
  border: 1px solid var(--border);
  border-radius: var(--radius-sm);
  color: var(--text-2);
  font-size: 13px;
  cursor: pointer;
  list-style: none;
}

.lang summary::-webkit-details-marker {
  display: none;
}

.lang summary:hover,
.lang[open] summary {
  border-color: var(--brand);
  color: var(--brand);
}

.lang-list {
  position: absolute;
  top: calc(100% + 8px);
  right: 0;
  z-index: 60;
  min-width: 150px;
  padding: 6px;
  border: 1px solid var(--border);
  border-radius: var(--radius-sm);
  background: var(--card);
  box-shadow: var(--shadow-md);
}

.lang-list a {
  display: block;
  padding: 8px 10px;
  border-radius: 6px;
  color: var(--text-2);
  font-size: 13px;
  white-space: nowrap;
}

.lang-list a:hover {
  background: var(--brand-soft);
  color: var(--brand);
}

.lang-list a.active {
  color: var(--brand);
  font-weight: 600;
}

/* 主题：三态按钮，状态由 <html data-theme-pref> 驱动 */
.theme {
  display: inline-flex;
  padding: 2px;
  border: 1px solid var(--border);
  border-radius: 999px;
  background: var(--bg-2);
}

.theme button {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 26px;
  height: 24px;
  padding: 0;
  border: 0;
  border-radius: 999px;
  background: transparent;
  color: var(--text-3);
  cursor: pointer;
  transition:
    background-color 0.18s ease,
    color 0.18s ease;
}

.theme button:hover {
  color: var(--brand);
}

@media (max-width: 1080px) {
  .hd-nav,
  .hd-edition,
  .lang-current {
    display: none;
  }
}

@media (max-width: 640px) {
  .hd-inner {
    gap: 10px;
    padding: 0 16px;
  }

  .hd-docs {
    display: none;
  }

  .theme button {
    width: 24px;
  }
}
</style>
