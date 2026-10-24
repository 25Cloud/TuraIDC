<script setup lang="ts">
import { LogoGithubIcon } from "tdesign-icons-vue-next";

import { FOOTER_HREFS, LINKS } from "../data/site";
import { useSite } from "../i18n/context";

const { messages, locale } = useSite();
const footer = messages.footer;
</script>

<template>
  <footer class="ft">
    <div class="container ft-top">
      <div class="ft-brand">
        <a class="ft-logo" href="#top">
          <img
            src="/turaidc-logo.webp"
            alt="TuraIDC"
            width="139"
            height="26"
            loading="lazy"
            decoding="async"
          />
        </a>
        <p>{{ footer.tagline }}</p>
        <a
          class="ft-github"
          :href="LINKS.repo"
          target="_blank"
          rel="noopener noreferrer"
        >
          <LogoGithubIcon size="16px" aria-hidden="true" />
          25Cloud/TuraIDC
        </a>
      </div>

      <nav
        v-for="group in footer.groups"
        :key="group.title"
        class="ft-group"
        :aria-label="group.title"
      >
        <h2>{{ group.title }}</h2>
        <ul>
          <li v-for="link in group.links" :key="link.kind">
            <a :href="FOOTER_HREFS[link.kind]">{{ link.text }}</a>
          </li>
        </ul>
      </nav>
    </div>

    <div class="container ft-bottom">
      <p>
        <span class="license">AGPL-3.0-or-later</span>
        {{ footer.license }}
      </p>
      <p class="copy">
        {{ footer.copyright }} ·
        <a :href="LINKS.licensing">{{ messages.actions.licensing }}</a>
        ·
        <a :href="locale.path">{{ locale.label }}</a>
      </p>
    </div>
  </footer>
</template>

<style scoped>
.ft {
  padding: 64px 0 40px;
  border-top: 1px solid var(--border);
  background: var(--card);
}

.ft-top {
  display: grid;
  grid-template-columns: minmax(0, 1.4fr) repeat(3, minmax(0, 1fr));
  gap: 40px;
  padding-bottom: 40px;
  border-bottom: 1px solid var(--line);
}

.ft-logo {
  display: inline-flex;
  align-items: center;
}

.ft-logo img {
  height: 26px;
  width: auto;
}

.ft-brand p {
  max-width: 28em;
  margin-top: 14px;
  color: var(--text-2);
  font-size: 13px;
  line-height: 1.75;
}

.ft-github {
  display: inline-flex;
  align-items: center;
  gap: 7px;
  margin-top: 16px;
  padding: 6px 12px;
  border: 1px solid var(--border);
  border-radius: 999px;
  color: var(--text-2);
  font-size: 12.5px;
  transition:
    border-color 0.18s ease,
    color 0.18s ease;
}

.ft-github:hover {
  border-color: var(--brand);
  color: var(--brand);
}

.ft-group h2 {
  margin-bottom: 14px;
  color: var(--text);
  font-size: 13px;
  font-weight: 700;
}

.ft-group ul {
  display: flex;
  flex-direction: column;
  gap: 10px;
  margin: 0;
  padding: 0;
  list-style: none;
}

.ft-group a {
  color: var(--text-2);
  font-size: 13px;
  transition: color 0.18s ease;
}

.ft-group a:hover {
  color: var(--brand);
}

.ft-bottom {
  display: flex;
  flex-wrap: wrap;
  gap: 12px 24px;
  justify-content: space-between;
  padding-top: 24px;
  color: var(--text-3);
  font-size: 12px;
  line-height: 1.7;
}

.ft-bottom p {
  max-width: 64em;
}

.license {
  display: inline-block;
  margin-right: 8px;
  padding: 2px 8px;
  border: 1px solid var(--border);
  border-radius: 999px;
  color: var(--text-2);
  font-size: 11px;
  white-space: nowrap;
}

.ft-bottom a {
  color: var(--text-2);
}

.ft-bottom a:hover {
  color: var(--brand);
}

.copy {
  white-space: nowrap;
}

@media (max-width: 960px) {
  .ft-top {
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 32px;
  }

  .ft-brand {
    grid-column: 1 / -1;
  }
}

@media (max-width: 560px) {
  .ft-top {
    grid-template-columns: minmax(0, 1fr);
  }

  .copy {
    white-space: normal;
  }
}
</style>
