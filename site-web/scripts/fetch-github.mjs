#!/usr/bin/env node
/**
 * 抓 GitHub 仓库的公开数据，写进 src/data/github.json，供首页「项目动态」区块使用。
 *
 * 跑在构建前（package.json 的 prebuild），所以每次部署都会刷新一次：
 * 这就是首页上版本号、Star、最近提交这些数字「跟得上仓库」的原因。
 *
 * 项目是静态站、零客户端 JS，不在浏览器里请求 GitHub：那样会把访客 IP 交给第三方，
 * 也会被未认证限流（每小时 60 次）打穿。改成构建时抓一次、产物里写死。
 *
 * 拿不到就用仓库里已有的快照继续构建，不让营销页因为 GitHub 抖动而发布失败。
 * 需要更高额度时给环境变量 GITHUB_TOKEN。
 */
import { existsSync, readFileSync, writeFileSync } from "node:fs";
import { dirname, resolve } from "node:path";
import { fileURLToPath } from "node:url";

const root = resolve(dirname(fileURLToPath(import.meta.url)), "..");
const target = resolve(root, "src/data/github.json");

const REPO = "25Cloud/TuraIDC";
const API = `https://api.github.com/repos/${REPO}`;
const RELEASE_COUNT = 5;

/** 只保留页面要用的字段：多存一份详情没有意义，还会让快照 diff 变噪音 */
function pick(repo, releases) {
  return {
    repo: {
      stars: repo.stargazers_count,
      forks: repo.forks_count,
      openIssues: repo.open_issues_count,
      license: repo.license?.spdx_id ?? "",
      pushedAt: repo.pushed_at ?? "",
      url: repo.html_url ?? `https://github.com/${REPO}`,
    },
    releases: releases.slice(0, RELEASE_COUNT).map((release) => ({
      tag: release.tag_name,
      publishedAt: release.published_at ?? release.created_at,
      url: release.html_url,
    })),
  };
}

async function getJson(url) {
  const response = await fetch(url, {
    headers: {
      Accept: "application/vnd.github+json",
      "User-Agent": "turaidc-site-build",
      ...(process.env.GITHUB_TOKEN
        ? { Authorization: `Bearer ${process.env.GITHUB_TOKEN}` }
        : {}),
    },
    signal: AbortSignal.timeout(15_000),
  });
  if (!response.ok) {
    throw new Error(`${url} → HTTP ${response.status}`);
  }
  return response.json();
}

const previous = existsSync(target)
  ? JSON.parse(readFileSync(target, "utf8").replace(/^\uFEFF/, ""))
  : null;

try {
  const [repo, releases] = await Promise.all([
    getJson(API),
    getJson(`${API}/releases?per_page=${RELEASE_COUNT}`),
  ]);
  const next = pick(repo, releases);

  if (next.releases.length === 0) {
    throw new Error("仓库没有返回任何 release");
  }

  // 数字没变就不动文件：避免每次构建都在工作区留一处无意义的改动
  if (previous && JSON.stringify(previous) === JSON.stringify(next)) {
    console.log(
      `GitHub 数据无变化：${next.releases[0].tag}，${next.repo.stars} stars`,
    );
  } else {
    writeFileSync(target, `${JSON.stringify(next, null, 2)}\n`, "utf8");
    console.log(
      `GitHub 数据已更新：${next.releases[0].tag}，${next.repo.stars} stars，${next.repo.forks} forks`,
    );
  }
} catch (error) {
  if (!previous) {
    console.error(`抓取 GitHub 数据失败，且没有历史快照：${error.message}`);
    process.exit(1);
  }
  console.warn(
    `抓取 GitHub 数据失败，沿用上一次快照（${previous.releases[0]?.tag}）：${error.message}`,
  );
}
