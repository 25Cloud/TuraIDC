import { expect, test } from "@playwright/test";

/**
 * 内容页接口失败回归：
 *
 * 之前内容列表/详情在接口失败时没有错误态，骨架会一直保持，用户看不到失败原因，
 * 也没有重试入口。现在必须显示错误文案 + 「重新加载」按钮；重试成功后恢复正常内容。
 */

type FailureSwitch = { fail: boolean };

async function mockSiteApi(
  page: import("@playwright/test").Page,
  failure: FailureSwitch,
) {
  await page.route("**/api/v2/site/**", async (route) => {
    const url = new URL(route.request().url());
    const pathname = url.pathname.replace(/^\/api/, "");
    const respond = (data: unknown) =>
      route.fulfill({
        contentType: "application/json; charset=utf-8",
        body: JSON.stringify({ code: 0, message: "ok", data }),
      });

    if (pathname === "/v2/site/config") {
      return respond({ site_name: "测试站点" });
    }

    if (pathname === "/v2/site/content/overview") {
      return respond({});
    }

    if (pathname === "/v2/site/help-articles") {
      if (failure.fail) {
        return route.fulfill({
          status: 500,
          contentType: "application/json; charset=utf-8",
          body: JSON.stringify({ code: 50000, message: "服务暂时不可用" }),
        });
      }

      return respond({
        list: [
          {
            id: 1,
            title: "测试帮助文档",
            excerpt: "这是一条用于验证重试的内容",
            category_id: 1,
            category_name: "新手入门",
            publish_at: "2026-10-21 00:00:00",
            view_count: 12,
          },
        ],
        total: 1,
      });
    }

    if (pathname === "/v2/site/help-articles/1") {
      if (failure.fail) {
        return route.fulfill({
          status: 500,
          contentType: "application/json; charset=utf-8",
          body: JSON.stringify({ code: 50000, message: "服务暂时不可用" }),
        });
      }

      return respond({
        id: 1,
        title: "测试帮助文档",
        content: "这是用于验证详情重试的正文内容。",
        category_id: 1,
        publish_at: "2026-10-21 00:00:00",
      });
    }

    return respond({ list: [], total: 0 });
  });
}

test("帮助列表失败显示错误态，重试后恢复列表", async ({ page }) => {
  const failure: FailureSwitch = { fail: true };
  await mockSiteApi(page, failure);

  await page.goto("/help", { waitUntil: "domcontentloaded" });

  const errorBlock = page.locator(".list-error");
  await expect(errorBlock).toBeVisible();
  await expect(errorBlock).toContainText("服务暂时不可用");

  failure.fail = false;
  await page.getByRole("button", { name: "重新加载" }).click();

  await expect(page.locator(".list-error")).toHaveCount(0);
  await expect(page.locator(".list-item__title")).toContainText("测试帮助文档");
});

test("帮助详情失败显示错误态，重试后恢复正文", async ({ page }) => {
  const failure: FailureSwitch = { fail: true };
  await mockSiteApi(page, failure);

  await page.goto("/help/1", { waitUntil: "domcontentloaded" });

  await expect(page.locator(".reader-error-block")).toBeVisible();
  await expect(page.locator(".reader-error-block")).toContainText(
    "服务暂时不可用",
  );

  failure.fail = false;
  await page.getByRole("button", { name: "重新加载" }).click();

  await expect(page.locator(".reader-error-block")).toHaveCount(0);
  await expect(page.locator(".reader-content")).toContainText(
    "详情重试的正文内容",
  );
});
