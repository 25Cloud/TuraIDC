<script setup lang="ts">
import { LOCALES, getLocale } from "../i18n";
import { provideSiteContext } from "../i18n/context";
import DeploySection from "./DeploySection.vue";
import FaqSection from "./FaqSection.vue";
import FlowSection from "./FlowSection.vue";
import HeroSection from "./HeroSection.vue";
import HighlightsSection from "./HighlightsSection.vue";
import LiveSection from "./LiveSection.vue";
import MigrateSection from "./MigrateSection.vue";
import PartnersSection from "./PartnersSection.vue";
import PricingSection from "./PricingSection.vue";
import SecuritySection from "./SecuritySection.vue";
import SiteFooter from "./SiteFooter.vue";
import SiteHeader from "./SiteHeader.vue";

/**
 * 页面壳：解析当前语言、把文案注入给所有区块，并排出段落顺序。
 * 组件不直接 import 语言包，也不需要知道自己是哪一页。
 */
const props = defineProps<{ locale: string }>();

const entry = getLocale(props.locale);
const messages = entry.messages;

provideSiteContext({ messages, locale: entry, locales: LOCALES });
</script>

<template>
  <div class="site">
    <a class="skip-link" href="#main">{{ messages.skipToContent }}</a>
    <SiteHeader />
    <main id="main">
      <HeroSection />
      <PartnersSection />
      <LiveSection />
      <HighlightsSection />
      <FlowSection />
      <SecuritySection />
      <DeploySection />
      <MigrateSection />
      <PricingSection />
      <FaqSection />
    </main>
    <SiteFooter />
  </div>
</template>
