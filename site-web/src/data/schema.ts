import { SITE_URL, absoluteUrl } from "../i18n";
import type { LocaleEntry } from "../i18n";
import { CONTACT_MAIL, REPO_URL } from "./site";

/**
 * 结构化数据与页面可见内容同源：FAQ 直接取当前语言的 FAQ 数据，
 * offers 取定价卡片的真实数值，避免结构化数据和正文两套口径。
 */
export function buildJsonLd(entry: LocaleEntry) {
  const { messages } = entry;
  const offers = messages.pricing.plans.map((plan) => ({
    "@type": "Offer",
    name: plan.name,
    price: plan.price,
    priceCurrency: "CNY",
    description: plan.license,
  }));

  return {
    "@context": "https://schema.org",
    "@graph": [
      {
        "@type": "Organization",
        name: "TuraIDC",
        alternateName: "图拉云",
        url: `${SITE_URL}/`,
        logo: `${SITE_URL}/turaidc-logo.png`,
        email: CONTACT_MAIL,
        sameAs: [REPO_URL],
      },
      {
        "@type": "SoftwareApplication",
        name: "TuraIDC",
        applicationCategory: "BusinessApplication",
        operatingSystem: "Linux",
        url: absoluteUrl(entry),
        inLanguage: entry.htmlLang,
        description: messages.meta.description,
        license: "https://www.gnu.org/licenses/agpl-3.0.html",
        codeRepository: REPO_URL,
        programmingLanguage: ["PHP", "TypeScript", "Vue"],
        offers,
      },
      {
        "@type": "FAQPage",
        inLanguage: entry.htmlLang,
        mainEntity: messages.faq.items.map((item) => ({
          "@type": "Question",
          name: item.q,
          acceptedAnswer: { "@type": "Answer", text: item.a },
        })),
      },
    ],
  };
}
