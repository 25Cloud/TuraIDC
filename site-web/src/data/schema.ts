import { FAQ_ITEMS, REPO_URL, SITE, SITE_URL } from "./content";

/**
 * 结构化数据与页面可见内容同源：FAQ 直接取首页 FAQ 数据，
 * 避免结构化数据和正文各写一份、日后对不上。
 */
export function buildJsonLd() {
  return {
    "@context": "https://schema.org",
    "@graph": [
      {
        "@type": "Organization",
        name: "TuraIDC",
        alternateName: "图拉云",
        url: `${SITE_URL}/`,
        logo: `${SITE_URL}/turaidc-logo.png`,
        email: "contact@turaidc.com",
        sameAs: [REPO_URL],
      },
      {
        "@type": "SoftwareApplication",
        name: "TuraIDC",
        applicationCategory: "BusinessApplication",
        applicationSubCategory: "IDC / 云服务业务财务系统",
        operatingSystem: "Linux",
        url: `${SITE_URL}/`,
        description: SITE.description,
        license: "https://www.gnu.org/licenses/agpl-3.0.html",
        codeRepository: REPO_URL,
        programmingLanguage: ["PHP", "TypeScript", "Vue"],
        offers: [
          {
            "@type": "Offer",
            name: "开源版",
            price: "0",
            priceCurrency: "CNY",
            description: "AGPL-3.0-or-later，功能与商业授权版一致。",
          },
          {
            "@type": "Offer",
            name: "商业授权",
            price: "200",
            priceCurrency: "CNY",
            description: "按年授权，用于闭源商用；不改变任何功能。",
          },
        ],
      },
      {
        "@type": "FAQPage",
        mainEntity: FAQ_ITEMS.map((item) => ({
          "@type": "Question",
          name: item.q,
          acceptedAnswer: { "@type": "Answer", text: item.a },
        })),
      },
    ],
  };
}
