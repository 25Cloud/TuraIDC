<script setup lang="ts">
import { useSite } from "../i18n/context";
import { LINKS, SHOTS } from "../data/site";

/**
 * Hero 右侧的系统实拍。
 *
 * 图由 `scripts/capture-shots.mjs` 用无头 Chrome 打开二五云线上站点截取，不是画的
 * 示意图：图里的商品、分组、库存和价格都来自运行中的 TuraIDC。换版式后重跑脚本，
 * 命令见 `docs/internal/governance/site-web-maintenance.md`。
 */
const { messages } = useSite();
const shot = messages.shot;
</script>

<template>
  <figure class="shot">
    <div class="frame">
      <div class="chrome">
        <span class="dots" aria-hidden="true"><i></i><i></i><i></i></span>
        <span class="url">{{ shot.url }}</span>
        <span class="live">{{ shot.live }}</span>
      </div>
      <img
        class="image"
        :src="SHOTS.catalog"
        width="1600"
        height="1000"
        :alt="shot.alt"
        loading="eager"
        fetchpriority="high"
        decoding="async"
      />
    </div>

    <figcaption class="caption">
      <a :href="LINKS.demo" target="_blank" rel="noopener noreferrer">
        {{ shot.caption }} →
      </a>
    </figcaption>
  </figure>
</template>

<style scoped>
.shot {
  margin: 0;
}

.frame {
  overflow: hidden;
  border: 1px solid var(--border);
  border-radius: var(--radius-lg);
  background: var(--card);
  box-shadow: var(--shadow-lg);
}

.chrome {
  display: flex;
  align-items: center;
  gap: 8px;
  height: 34px;
  padding: 0 12px;
  border-bottom: 1px solid var(--line);
  background: var(--bg-2);
}

.dots {
  display: flex;
  gap: 5px;
}

.dots i {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: var(--border);
}

.url {
  flex: 1;
  overflow: hidden;
  color: var(--text-3);
  font-size: 11.5px;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.live {
  flex: none;
  padding: 2px 8px;
  border-radius: 999px;
  background: var(--success-soft);
  color: var(--ok-ink);
  font-size: 10.5px;
  font-weight: 600;
}

.image {
  display: block;
  width: 100%;
  height: auto;
}

.caption {
  margin-top: 12px;
  color: var(--text-3);
  font-size: 12px;
  text-align: right;
}

.caption a {
  color: var(--text-3);
}

.caption a:hover {
  color: var(--brand);
  text-decoration: underline;
  text-underline-offset: 3px;
}

@media (max-width: 720px) {
  .caption {
    text-align: left;
  }
}
</style>
