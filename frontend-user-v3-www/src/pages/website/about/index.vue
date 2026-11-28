<template>
  <div class="about-page">
    <section class="about-hero">
      <div class="container">
        <p class="about-kicker">{{ $t("layout.nav.aboutUs") }}</p>
        <!-- H1 描述页面本身而非站点名；与后端 SEO 快照（renderStaticBody）口径一致 -->
        <h1>{{ $t("layout.nav.aboutUs") }}</h1>
        <p v-if="companyName" class="about-company">
          {{ companyName }}
        </p>
        <p class="about-desc">
          {{ $t("content.about.intro") }}
        </p>
        <p class="about-platform">
          {{ $t("content.about.platformIntro") }}
        </p>

        <!-- 关键数字：只在后台填了对应值时才出现，不留空占位 -->
        <dl v-if="stats.length > 0" class="about-stats">
          <div v-for="stat in stats" :key="stat.key" class="about-stat">
            <dt>{{ $t(stat.label) }}</dt>
            <dd>{{ stat.value }}</dd>
          </div>
        </dl>
      </div>
    </section>

    <section v-reveal class="about-section">
      <div class="container">
        <div class="about-grid">
          <article class="about-card">
            <h2>{{ $t("content.about.openSourceTitle") }}</h2>
            <p>{{ $t("content.about.openSourceDesc") }}</p>
          </article>
          <article class="about-card">
            <h2>{{ $t("content.about.opsCommitmentTitle") }}</h2>
            <p>{{ $t("content.about.opsCommitmentDesc") }}</p>
          </article>
          <article class="about-card">
            <h2>{{ $t("content.about.supportCommitmentTitle") }}</h2>
            <p>{{ $t("content.about.supportCommitmentDesc") }}</p>
          </article>
          <article class="about-card">
            <h2>{{ $t("content.about.serviceCapabilities") }}</h2>
            <p>{{ $t("content.about.serviceCapabilitiesDesc") }}</p>
          </article>
        </div>
      </div>
    </section>

    <!-- 公司简介：站长在后台填写，未填则整块不渲染 -->
    <section v-if="summaryParagraphs.length > 0" v-reveal class="about-section about-section--alt">
      <div class="container">
        <h2 class="about-section__title">{{ $t("content.about.companyProfileTitle") }}</h2>
        <p v-for="(paragraph, index) in summaryParagraphs" :key="index" class="about-prose">
          {{ paragraph }}
        </p>
      </div>
    </section>

    <!-- 发展历程：每行「年份|标题|说明」 -->
    <section v-if="milestones.length > 0" v-reveal class="about-section">
      <div class="container">
        <h2 class="about-section__title">{{ $t("content.about.milestonesTitle") }}</h2>
        <ol class="about-timeline">
          <li v-for="item in milestones" :key="item.year + item.title" class="about-timeline__item">
            <span class="about-timeline__year">{{ item.year }}</span>
            <div class="about-timeline__body">
              <h3 v-if="item.title" class="about-timeline__title">{{ item.title }}</h3>
              <p v-if="item.desc" class="about-timeline__desc">{{ item.desc }}</p>
            </div>
          </li>
        </ol>
      </div>
    </section>

    <!-- 服务承诺 -->
    <section v-if="slaParagraphs.length > 0" v-reveal class="about-section about-section--alt">
      <div class="container">
        <h2 class="about-section__title">{{ $t("content.about.serviceCommitmentTitle") }}</h2>
        <p v-for="(paragraph, index) in slaParagraphs" :key="index" class="about-prose">
          {{ paragraph }}
        </p>
      </div>
    </section>

    <!-- 资质与许可：证号来自站点配置，没配就不出现这一块 -->
    <section v-if="credentials.length > 0" v-reveal class="about-section">
      <div class="container">
        <h2 class="about-section__title">{{ $t("content.about.credentialsTitle") }}</h2>
        <ul class="about-credentials">
          <li v-for="item in credentials" :key="item.label" class="about-credential">
            <span class="about-credential__label">{{ $t(item.label) }}</span>
            <a
              v-if="item.link"
              class="about-credential__value"
              :href="item.link"
              target="_blank"
              rel="noopener noreferrer"
              >{{ item.value }}</a
            >
            <span v-else class="about-credential__value">{{ item.value }}</span>
          </li>
        </ul>
      </div>
    </section>

    <section v-reveal class="about-section about-section--alt">
      <div class="container">
        <div class="about-grid">
          <article class="about-card">
            <h2>{{ $t("content.about.contactInformation") }}</h2>
            <p>
              {{ $t("content.about.serviceEmailLabel") }}
              <SkeletonBlock
                v-if="!appStore.brandingResolved"
                class="about-pending-value"
                :width="180"
                :height="14"
              />
              <template v-else>{{
                appStore.serviceEmail || $t("content.about.notConfigured")
              }}</template>
            </p>
            <p>
              {{ $t("content.about.serviceHoursLabel") }}
              <SkeletonBlock
                v-if="!appStore.brandingResolved"
                class="about-pending-value"
                :width="150"
                :height="14"
              />
              <template v-else>{{
                appStore.serviceHours ||
                $t("content.about.serviceHoursFallback")
              }}</template>
            </p>
            <p>
              {{ $t("content.about.officialGroupLabel") }}
              <SkeletonBlock
                v-if="!appStore.brandingResolved"
                class="about-pending-value"
                :width="120"
                :height="14"
              />
              <template v-else>
                <a
                  v-if="appStore.supportGroupLink"
                  :href="appStore.supportGroupLink"
                  target="_blank"
                  rel="noopener noreferrer"
                  >{{
                    appStore.serviceQqGroup || $t("content.about.notConfigured")
                  }}</a
                >
                <span v-else>{{
                  appStore.serviceQqGroup || $t("content.about.notConfigured")
                }}</span>
              </template>
            </p>
          </article>
        </div>
      </div>
    </section>
  </div>
</template>

<script setup lang="ts">
import { computed } from "vue";
import { useAppStore } from "@/stores/app";
import SkeletonBlock from "@/components/common/SkeletonBlock.vue";

const appStore = useAppStore();

const companyName = computed(() => String(appStore.aboutCompanyName || "").trim());

/** 多行文本按空行/换行拆段落，后台直接粘贴纯文本即可 */
function toParagraphs(raw: unknown): string[] {
  return String(raw || "")
    .split(/\r?\n/)
    .map((line) => line.trim())
    .filter((line) => line.length > 0);
}

const summaryParagraphs = computed(() => toParagraphs(appStore.aboutSummary));
const slaParagraphs = computed(() => toParagraphs(appStore.aboutSla));

/** 关键数字：值为空就整项丢弃，避免出现「成立于 」这种半截文案 */
const stats = computed(() => {
  const items = [
    { key: "founded", label: "content.about.statFoundedYear", value: appStore.aboutFoundedYear },
    { key: "team", label: "content.about.statTeamSize", value: appStore.aboutTeamSize },
    { key: "customers", label: "content.about.statCustomerCount", value: appStore.aboutCustomerCount },
    { key: "datacenters", label: "content.about.statDatacenterCount", value: appStore.aboutDatacenterCount },
  ];

  return items
    .map((item) => ({ ...item, value: String(item.value || "").trim() }))
    .filter((item) => item.value.length > 0);
});

/** 发展历程：每行「年份|标题|说明」，缺列容错 */
interface Milestone {
  year: string;
  title: string;
  desc: string;
}

const milestones = computed<Milestone[]>(() => {
  const raw = String(appStore.aboutMilestones || "").trim();
  if (!raw) return [];

  return raw
    .split(/\r?\n/)
    .map((line) => line.split("|").map((cell) => cell.trim()))
    .map(([year = "", title = "", desc = ""]) => ({ year, title, desc }))
    .filter((item) => item.year || item.title);
});

/** 资质与许可：复用站点配置里的证号，没配就不显示 */
const credentials = computed(() => {
  const items = [
    {
      label: "content.about.credentialTelecom",
      value: String(appStore.valueAddedLicense || "").trim(),
      link: appStore.valueAddedLicenseLink,
    },
    {
      label: "content.about.credentialIcp",
      value: String(appStore.icpRecord || "").trim(),
      link: appStore.icpFilingLink,
    },
  ];

  return items.filter((item) => item.value.length > 0);
});
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
  color: $color-primary;
  font-weight: 600;
}

h1 {
  margin: 12px 0 0;
  color: #0f172a;
  font-size: 40px;
}

.about-company {
  margin: 10px 0 0;
  color: #1f2937;
  font-size: 16px;
  font-weight: 600;
}

.about-desc {
  max-width: 720px;
  margin-top: 16px;
  color: #64748b;
  line-height: 1.9;
}

.about-platform {
  max-width: 720px;
  margin-top: 12px;
  color: #475569;
  line-height: 1.9;
  font-size: 15px;
}

/* 关键数字条 */
.about-stats {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
  gap: 16px;
  margin: 32px 0 0;
  padding: 24px;
  border: 1px solid #e5eaf3;
  border-radius: 6px;
  background: #fff;
  box-shadow: 0 2px 10px rgba(15, 23, 42, 0.06);
}

.about-stat dt {
  color: #64748b;
  font-size: 13px;
}

.about-stat dd {
  margin: 8px 0 0;
  color: #0f172a;
  font-size: 24px;
  font-weight: 700;
  line-height: 1.2;
}

.about-section {
  padding: 24px 0 56px;
}

.about-section--alt {
  background: #f7f9fd;
}

.about-section__title {
  margin: 0 0 20px;
  color: #0f172a;
  font-size: 24px;
  font-weight: 700;
}

.about-prose {
  max-width: 860px;
  margin: 0 0 14px;
  color: #4b5565;
  font-size: 15px;
  line-height: 1.9;
}

.about-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 20px;

  & + .about-grid {
    margin-top: 20px;
  }
}

@media (max-width: 768px) {
  .about-grid {
    grid-template-columns: 1fr;
  }

  .about-timeline__year {
    width: 64px;
  }
}

.about-card {
  padding: 28px;
  border: 1px solid #e5eaf3;
  border-radius: 6px;
  background: #fff;
  box-shadow: 0 2px 10px rgba(15, 23, 42, 0.06);
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

/* 发展历程 */
.about-timeline {
  display: grid;
  gap: 0;
  margin: 0;
  padding: 0;
  list-style: none;
}

.about-timeline__item {
  position: relative;
  display: flex;
  gap: 20px;
  padding: 0 0 26px;

  /* 竖线连成时间轴 */
  &::before {
    content: "";
    position: absolute;
    left: 96px;
    top: 10px;
    bottom: 0;
    width: 1px;
    background: #e2e8f2;
  }

  &:last-child::before {
    display: none;
  }

  /* 时间轴节点 */
  &::after {
    content: "";
    position: absolute;
    left: 92px;
    top: 8px;
    width: 9px;
    height: 9px;
    border-radius: 50%;
    background: $color-primary;
    box-shadow: 0 0 0 4px rgba($color-primary, 0.12);
  }
}

.about-timeline__year {
  flex: 0 0 96px;
  color: $color-primary;
  font-size: 15px;
  font-weight: 700;
  line-height: 1.6;
}

.about-timeline__body {
  flex: 1 1 auto;
  min-width: 0;
  padding-left: 26px;
}

.about-timeline__title {
  margin: 0;
  color: #0f172a;
  font-size: 15px;
  font-weight: 600;
  line-height: 1.6;
}

.about-timeline__desc {
  margin: 6px 0 0;
  color: #64748b;
  font-size: 14px;
  line-height: 1.8;
}

/* 资质与许可 */
.about-credentials {
  display: grid;
  gap: 12px;
  margin: 0;
  padding: 0;
  list-style: none;
}

.about-credential {
  display: flex;
  flex-wrap: wrap;
  align-items: baseline;
  gap: 4px 12px;
  padding: 16px 20px;
  border: 1px solid #e5eaf3;
  border-radius: 6px;
  background: #fff;
}

.about-credential__label {
  color: #64748b;
  font-size: 13px;
}

.about-credential__value {
  color: #0f172a;
  font-size: 14px;
  font-weight: 600;
  text-decoration: none;

  &:is(a):hover {
    color: $color-primary;
    text-decoration: underline;
  }
}

/* 配置未返回时的行内骨架：与正文同高，避免「未配置」闪一下再变真实值 */
.about-pending-value {
  display: inline-block;
  vertical-align: -2px;
}
</style>