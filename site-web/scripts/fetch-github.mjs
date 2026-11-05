#!/usr/bin/env node
/**
 * 抓 GitHub 仓库的公开数据，写进 src/data/github.json，供首页的「项目动态」与「社区」区块使用。
 *
 * 跑在构建前（package.json 的 prebuild），所以每次部署都会刷新一次：
 * 这就是首页上版本号、发布日期与语言构成「跟得上仓库」的原因。
 *
 * 注意：首页的 Star / Fork / Issue 数字已经不在构建时抓了——那些改成 shields.io 的
 * 实时徽章图片，访问即最新。这里只留页面确实需要「构建期快照」的部分：release 列表
 * 和语言构成（语言要按字节算占比，shields 给不了）。
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
/** 语言只展示前五种，其余合并成 Other：一长串 0.1% 的条目对访客没有意义 */
const LANGUAGE_LIMIT = 5;

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

/**
 * `/languages` 返回 { 语言名: 字节数 }，这里换成按字节降序的百分比列表。
 * 百分比保留一位小数，占比条直接用宽度百分比渲染，不需要再算一次。
 */
function shapeLanguages(raw) {
  const entries = Object.entries(raw ?? {})
    .map(([name, bytes]) => ({ name, bytes: Number(bytes) }))
    .filter((item) => Number.isFinite(item.bytes) && item.bytes > 0)
    .sort((a, b) => b.bytes - a.bytes);

  if (entries.length === 0) return [];

  const total = entries.reduce((sum, item) => sum + item.bytes, 0);
  const top = entries.slice(0, LANGUAGE_LIMIT);
  const rest = entries
    .slice(LANGUAGE_LIMIT)
    .reduce((sum, item) => sum + item.bytes, 0);
  const rows = rest > 0 ? [...top, { name: "Other", bytes: rest }] : top;

  return rows.map((item) => ({
    name: item.name,
    bytes: item.bytes,
    percent: Math.round((item.bytes / total) * 1000) / 10,
  }));
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

/**
 * 语言构成单独抓：这一个接口挂了不该把已经拿到的 release 与仓库数据一起丢掉，
 * 拿不到就沿用上一份快照里的 languages（首次运行且抓不到时退化成空数组）。
 */
async function loadLanguages(fallback) {
  try {
    const shaped = shapeLanguages(await getJson(`${API}/languages`));
    if (shaped.length === 0) {
      throw new Error("语言接口返回空对象");
    }
    return shaped;
  } catch (error) {
    console.warn(`抓取语言构成失败，沿用上一次快照：${error.message}`);
    return fallback;
  }
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

  next.languages = await loadLanguages(previous?.languages ?? []);

  // 数字没变就不动文件：避免每次构建都在工作区留一处无意义的改动
  if (previous && JSON.stringify(previous) === JSON.stringify(next)) {
    console.log(
      `GitHub 数据无变化：${next.releases[0].tag}，${next.repo.stars} stars`,
    );
  } else {
    writeFileSync(target, `${JSON.stringify(next, null, 2)}\n`, "utf8");
    console.log(
      `GitHub 数据已更新：${next.releases[0].tag}，${next.repo.stars} stars，${next.repo.forks} forks，${next.languages.length} 个语言条目`,
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
