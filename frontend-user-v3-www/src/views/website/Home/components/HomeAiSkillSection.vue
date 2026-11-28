<template>
  <section class="ai-skill-section" v-reveal>
    <div class="container ai-skill-section__head">
      <h2 class="ai-skill-section__title">{{ $t("home.aiSkill.title") }}</h2>
      <p class="ai-skill-section__desc">{{ $t("home.aiSkill.subtitle") }}</p>
    </div>

    <div class="container ai-skill-section__grid">
      <article v-for="point in points" :key="point.key" class="ai-skill-card">
        <h3 class="ai-skill-card__title">{{ point.title }}</h3>
        <p class="ai-skill-card__desc">{{ point.desc }}</p>
      </article>
    </div>

    <div class="container ai-skill-section__cta">
      <div v-if="command" class="ai-skill-section__console">
        <span class="ai-skill-section__console-label">{{ $t("home.aiSkill.commandLabel") }}</span>
        <code class="ai-skill-section__code">{{ command }}</code>
      </div>
      <a class="ai-skill-section__button" :href="keysUrl">{{ $t("home.aiSkill.cta") }}</a>
      <p class="ai-skill-section__hint">{{ $t("home.aiSkill.hint") }}</p>
    </div>
  </section>
</template>

<script setup lang="ts">
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";

import siteApi from "@/api/site";
import { buildConsoleUrl } from "@/utils/consoleUrl";

/**
 * 首页「自带 AI 助手接入」特色位。
 *
 * 安装命令从后端 install.json 取真实内容，不在前端硬编码域名或品牌；
 * 取不到就只留按钮，绝不因为一个装饰性区块把首页搞出错误提示。
 */
const { t } = useI18n();

const keysUrl = buildConsoleUrl("/client/api-keys");
const command = ref("");

const points = computed(() => [
  {
    key: "install",
    title: t("home.aiSkill.point1Title"),
    desc: t("home.aiSkill.point1Desc"),
  },
  {
    key: "key",
    title: t("home.aiSkill.point2Title"),
    desc: t("home.aiSkill.point2Desc"),
  },
  {
    key: "scope",
    title: t("home.aiSkill.point3Title"),
    desc: t("home.aiSkill.point3Desc"),
  },
]);

siteApi
  .aiSkillInstall()
  .then((response: any) => {
    const install = response?.data?.commands;
    command.value = typeof install?.curl === "string" ? install.curl : "";
  })
  .catch(() => {
    command.value = "";
  });
</script>

<style scoped>
.ai-skill-section {
  padding: 72px 0;
  background: #ffffff;
}

.ai-skill-section__head {
  text-align: center;
  margin-bottom: 40px;
}

.ai-skill-section__title {
  margin: 0;
  color: #0f172a;
  font-size: clamp(26px, 2.4vw, 30px);
  font-weight: 700;
  line-height: 1.28;
  letter-spacing: -0.01em;
}

.ai-skill-section__desc {
  max-width: 680px;
  margin: 12px auto 0;
  color: #5b6b82;
  font-size: 14px;
  line-height: 1.8;
}

.ai-skill-section__grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 20px;
}

.ai-skill-card {
  padding: 24px;
  border: 1px solid #e6ecf5;
  border-radius: 14px;
  background: #f8fafd;
}

.ai-skill-card__title {
  margin: 0 0 10px;
  color: #0f172a;
  font-size: 16px;
  font-weight: 600;
}

.ai-skill-card__desc {
  margin: 0;
  color: #5b6b82;
  font-size: 13px;
  line-height: 1.75;
}

.ai-skill-section__cta {
  margin-top: 32px;
  text-align: center;
}

.ai-skill-section__console {
  display: flex;
  align-items: center;
  gap: 10px;
  max-width: 760px;
  margin: 0 auto 18px;
  padding: 12px 16px;
  border: 1px solid #e6ecf5;
  border-radius: 10px;
  background: #0f172a;
  text-align: left;
}

.ai-skill-section__console-label {
  flex: 0 0 auto;
  color: #9db0c9;
  font-size: 12px;
}

.ai-skill-section__code {
  flex: 1;
  min-width: 0;
  overflow-x: auto;
  color: #e6edf7;
  font-family: ui-monospace, SFMono-Regular, Consolas, monospace;
  font-size: 12px;
  white-space: nowrap;
}

.ai-skill-section__button {
  display: inline-block;
  padding: 11px 26px;
  border-radius: 999px;
  background: #165dff;
  color: #ffffff;
  font-size: 14px;
  font-weight: 600;
  text-decoration: none;
  transition: opacity 0.2s ease;
}

.ai-skill-section__button:hover {
  opacity: 0.9;
}

.ai-skill-section__hint {
  margin: 12px 0 0;
  color: #8695a9;
  font-size: 12px;
}

@media (max-width: 900px) {
  .ai-skill-section {
    padding: 52px 0;
  }

  .ai-skill-section__grid {
    grid-template-columns: 1fr;
  }

  .ai-skill-section__console {
    flex-direction: column;
    align-items: flex-start;
    gap: 6px;
  }
}
</style>