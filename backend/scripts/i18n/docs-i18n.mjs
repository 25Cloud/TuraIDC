/**
 * 文档站多语言维护脚本。
 *
 * 用法（在仓库根执行）：
 *   node backend/scripts/i18n/docs-i18n.mjs list                     # 待翻译页面清单（JSON）
 *   node backend/scripts/i18n/docs-i18n.mjs status                   # 各语言覆盖与过期状态
 *   node backend/scripts/i18n/docs-i18n.mjs stamp <locale> [path]    # 翻译完成后写入 sourceHash
 *   node backend/scripts/i18n/docs-i18n.mjs verify <locale>          # 结构比对（代码块/标题/链接）
 *   node backend/scripts/i18n/docs-i18n.mjs trad <zh-tw|zh-hk>       # 由中文生成繁体
 *   node backend/scripts/i18n/docs-i18n.mjs links <locale> [path]    # 改写跨语言链接
 *
 * 三种语言的治理方式不同，别混：
 *   - en：人工/AI 翻译，front matter 记录翻译时的中文源哈希；源变了标 stale，允许滞后；
 *   - zh-tw / zh-hk：由中文用 OpenCC 生成，不手写；源变了必须重跑 trad，否则 docs:check 失败。
 */
import { mkdirSync, readFileSync, rmSync, writeFileSync } from "node:fs";
import path from "node:path";

import OpenCC from "opencc-js";

import {
  countHan,
  countProseHan,
  docsRelative,
  docsRoot,
  expectedLocaleDestination,
  extractCodeFences,
  extractHeadingLevels,
  extractLinkDestinations,
  isFile,
  listSourcePages,
  localeTargetPath,
  LOCALE_DIRS,
  maskNonProse,
  parseFrontMatter,
  repoRoot,
  rewriteMarkdownLinks,
  sha256,
  walkMarkdown,
} from "./docs-i18n-lib.mjs";

const TRAD_LOCALES = new Set(["zh-tw", "zh-hk"]);

// 与 backend/scripts/i18n/build-zh-trad.mjs 保持同一套术语补丁。
const TW_TERMS = [
  ["退出登录", "登出"],
  ["代登录", "代理登入"],
  ["注册", "註冊"],
  ["注销", "註銷"],
  ["邮箱", "電子郵件"],
  ["信箱", "電子郵件"],
];

const HK_PRE_TERMS = [
  ["退出登录", "登出"],
  ["代登录", "代理登入"],
  ["注册", "註冊"],
  ["注销", "註銷"],
  ["邮箱", "電郵"],
  ["信箱", "電郵"],
  ["内存", "記憶體"],
  ["缓存", "快取"],
  ["软件", "軟件"],
  ["网络", "網絡"],
  ["数据", "數據"],
  ["默认", "默認"],
  ["视频", "視頻"],
];

const HK_POST_TERMS = [
  ["户", "戶"],
  ["税", "稅"],
  ["温", "溫"],
  ["脱", "脫"],
  ["啓", "啟"],
  ["衆", "眾"],
  ["裏", "裡"],
];

function makeConverter(locale) {
  const target = locale === "zh-tw" ? "twp" : "hkp";
  const pre = locale === "zh-tw" ? TW_TERMS : HK_PRE_TERMS;
  const post = locale === "zh-tw" ? [] : HK_POST_TERMS;
  const opencc = OpenCC.Converter({ from: "cn", to: target });

  return (input) => {
    let text = String(input);
    for (const [from, to] of pre) text = text.split(from).join(to);
    text = opencc(text);
    for (const [from, to] of post) text = text.split(from).join(to);
    return text;
  };
}

/**
 * 代码块、行内代码、链接目标、HTML 标签、裸 URL 都不能被 OpenCC 碰。
 * 用私用区占位符搬进搬出，既避免误转，也不改变正文边界。
 */
function convertMarkdownBody(body, convert) {
  const slots = [];
  const stash = (value) => {
    slots.push(value);
    return `\uE000${slots.length - 1}\uE001`;
  };

  let masked = body
    .replace(/^---[\s\S]*?\n---\n?/m, (match) => stash(match))
    .replace(/```[\s\S]*?(?:```|$)/g, (match) => stash(match))
    .replace(/`[^`\r\n]*`/g, (match) => stash(match))
    // 只遮蔽链接目标，链接文字仍要转换（整条链接一起遮蔽会让表格里的链接文案留简体）
    .replace(/\]\([^\r\n)]*\)/g, (match) => stash(match))
    .replace(/<[^>\r\n]+>/g, (match) => stash(match))
    .replace(/https?:\/\/[^\s)>]+/g, (match) => stash(match));

  masked = convert(masked);

  return masked.replace(
    /\uE000(\d+)\uE001/g,
    (_, index) => slots[Number(index)],
  );
}

function upsertFrontMatter(markdown, updates) {
  const source = markdown.replace(/^\uFEFF/, "");
  const { body, hasFrontMatter } = parseFrontMatter(source);
  const lines = source.split(/\r?\n/);
  const rendered = Object.entries(updates)
    .filter(([, value]) => value !== undefined && value !== null)
    .map(([key, value]) => `${key}: ${value}`);

  if (!hasFrontMatter) {
    return `---\n${rendered.join("\n")}\n---\n\n${body.replace(/^\n+/, "")}`;
  }

  let endIndex = -1;
  for (let index = 1; index < lines.length; index += 1) {
    if (lines[index].trim() === "---" || lines[index].trim() === "...") {
      endIndex = index;
      break;
    }
  }

  const pending = new Map(Object.entries(updates));
  const head = [];
  for (let index = 1; index < endIndex; index += 1) {
    const line = lines[index];
    const property = line.match(/^([A-Za-z][A-Za-z\d_-]*):/);
    if (property && pending.has(property[1])) {
      head.push(`${property[1]}: ${pending.get(property[1])}`);
      pending.delete(property[1]);
    } else if (line.trim()) {
      head.push(line);
    }
  }

  for (const [key, value] of pending) {
    if (value !== undefined && value !== null) {
      head.push(`${key}: ${value}`);
    }
  }

  return `---\n${head.join("\n")}\n---\n${lines.slice(endIndex + 1).join("\n")}`;
}

function sourceHashOf(absolutePath) {
  return sha256(readFileSync(absolutePath, "utf8"));
}

function readTargetPayload(absolutePath) {
  const { data, body } = parseFrontMatter(readFileSync(absolutePath, "utf8"));
  return { data, body };
}

/**
 * 站点外壳文案（首页门牌、部署方式选择器）的繁体版。
 *
 * 简体 JSON 是真源；这里转换全部展示文案，并把站内链接补上当前语言前缀，
 * 否则繁体首页点进去会落回简体页面。
 */
function generateSiteChrome(locale, convert) {
  const sourcePath = path.join(
    repoRoot,
    "docs-web/.vitepress/theme/data/siteChrome.zh_CN.json",
  );
  const source = JSON.parse(readFileSync(sourcePath, "utf8"));

  const convertNode = (node, key) => {
    if (Array.isArray(node)) {
      const isLinkTuple =
        node.length === 2 &&
        typeof node[0] === "string" &&
        typeof node[1] === "string" &&
        node[1].startsWith("/");
      if (isLinkTuple) {
        return [convert(node[0]), `/${locale}${node[1]}`];
      }
      return node.map((item) => convertNode(item, key));
    }

    if (node && typeof node === "object") {
      return Object.fromEntries(
        Object.entries(node).map(([childKey, value]) => [
          childKey,
          convertNode(value, childKey),
        ]),
      );
    }

    if (typeof node !== "string") {
      return node;
    }

    if ((key === "href" || key === "link") && node.startsWith("/")) {
      return `/${locale}${node}`;
    }

    return convert(node);
  };

  const converted = convertNode(source, "");
  converted.prefix = `/${locale}`;
  converted.lang = locale === "zh-tw" ? "繁體中文（台灣）" : "繁體中文（香港）";

  const targetPath = path.join(
    path.dirname(sourcePath),
    `siteChrome.${locale === "zh-tw" ? "zh_TW" : "zh_HK"}.json`,
  );
  writeFileSync(targetPath, `${JSON.stringify(converted, null, 2)}\n`, "utf8");
}

function generateTraditional(locale) {
  if (!TRAD_LOCALES.has(locale)) {
    throw new Error(`trad 只支持 zh-tw / zh-hk，收到：${locale}`);
  }

  const convert = makeConverter(locale);
  const sources = listSourcePages();
  const written = [];

  for (const { absolutePath, relativePath } of sources) {
    const raw = readFileSync(absolutePath, "utf8");
    const { data, body, hasFrontMatter } = parseFrontMatter(raw);
    const hash = sha256(raw);

    const frontMatter = {};
    if (hasFrontMatter) {
      for (const [key, value] of Object.entries(data)) {
        if (["source", "sourceHash", "generated"].includes(key)) continue;
        frontMatter[key] = convert(value);
      }
    }
    frontMatter.source = relativePath;
    frontMatter.sourceHash = hash;
    frontMatter.generated = locale;

    const convertedBody = convertMarkdownBody(body, convert);
    const rewritten = rewriteMarkdownLinks(convertedBody, relativePath);
    const notice = `<!-- 本檔案由 docs-i18n.mjs trad ${locale} 從 docs/${relativePath} 產生，請勿手動編輯。 -->`;
    const content = `---\n${Object.entries(frontMatter)
      .map(([key, value]) => `${key}: ${value}`)
      .join("\n")}\n---\n\n${notice}\n${rewritten.replace(/^\n+/, "")}`;

    const targetPath = localeTargetPath(locale, relativePath);
    mkdirSync(path.dirname(targetPath), { recursive: true });
    writeFileSync(targetPath, content, "utf8");
    written.push(relativePath);
  }

  // 清理已经没有中文源的旧生成物
  const expected = new Set(written);
  let pruned = 0;
  for (const absolutePath of walkMarkdown(path.join(docsRoot, locale))) {
    const relative = docsRelative(absolutePath).slice(locale.length + 1);
    if (!expected.has(relative)) {
      rmSync(absolutePath);
      pruned += 1;
    }
  }

  console.log(`${locale}: 生成 ${written.length} 篇，清理 ${pruned} 篇`);
  generateSiteChrome(locale, convert);
  console.log(`${locale}: 站点外壳文案已重生成`);
}

function stamp(locale, onlyPath) {
  const sources = listSourcePages().filter(({ relativePath }) =>
    onlyPath ? relativePath === onlyPath : true,
  );
  let updated = 0;
  let missing = 0;

  for (const { absolutePath, relativePath } of sources) {
    const target = localeTargetPath(locale, relativePath);
    if (!isFile(target)) {
      missing += 1;
      continue;
    }

    const markdown = readFileSync(target, "utf8");
    const { data } = parseFrontMatter(markdown);
    const next = upsertFrontMatter(markdown, {
      source: relativePath,
      sourceHash: sourceHashOf(absolutePath),
      translatedAt: data.translatedAt || new Date().toISOString().slice(0, 10),
    });

    if (next !== markdown) {
      writeFileSync(target, next, "utf8");
      updated += 1;
    }
  }

  console.log(`${locale}: 更新 ${updated} 篇 front matter，缺页 ${missing} 篇`);
}

function verify(locale) {
  const sources = listSourcePages();
  const problems = [];

  for (const { absolutePath, relativePath } of sources) {
    const target = localeTargetPath(locale, relativePath);
    if (!isFile(target)) {
      problems.push(`missing: ${relativePath}`);
      continue;
    }

    const sourceText = readFileSync(absolutePath, "utf8");
    const targetText = readFileSync(target, "utf8");
    const { data, body } = readTargetPayload(target);
    const sourceBody = parseFrontMatter(sourceText).body;

    if (!data.source) {
      problems.push(`front matter 缺 source: ${relativePath}`);
    } else if (data.source !== relativePath) {
      problems.push(
        `front matter source 不符: ${relativePath} -> ${data.source}`,
      );
    }

    const sourceFences = extractCodeFences(sourceBody);
    const targetFences = extractCodeFences(body);
    if (sourceFences.length !== targetFences.length) {
      problems.push(
        `代码块数量不一致: ${relativePath} (${sourceFences.length} -> ${targetFences.length})`,
      );
    } else {
      for (const [index, fence] of sourceFences.entries()) {
        if (fence.body.trim() !== targetFences[index].body.trim()) {
          problems.push(`代码块内容被改动: ${relativePath} #${index + 1}`);
        }
        if (fence.header !== targetFences[index].header) {
          problems.push(`代码块语言标记被改动: ${relativePath} #${index + 1}`);
        }
      }
    }

    const sourceHeadings = extractHeadingLevels(sourceBody).join(",");
    const targetHeadings = extractHeadingLevels(body).join(",");
    if (sourceHeadings !== targetHeadings) {
      problems.push(
        `标题层级结构不一致: ${relativePath} (${sourceHeadings} -> ${targetHeadings})`,
      );
    }

    if (locale === "en") {
      const expectedLinks = extractLinkDestinations(sourceBody).map(
        (destination) => expectedLocaleDestination(destination, relativePath),
      );
      const actualLinks = extractLinkDestinations(body);
      const counts = new Map();
      for (const link of expectedLinks)
        counts.set(link, (counts.get(link) ?? 0) + 1);
      for (const link of actualLinks)
        counts.set(link, (counts.get(link) ?? 0) - 1);
      const drifted = [...counts.entries()].filter(([, count]) => count !== 0);
      if (drifted.length > 0) {
        problems.push(
          `链接目标不一致: ${relativePath} (${drifted
            .map(([link, count]) => `${count > 0 ? "缺" : "多"}${link}`)
            .join(", ")})`,
        );
      }

      if (data.allowCjk !== "true") {
        const han = countProseHan(body);
        if (han > 0) {
          problems.push(`正文残留中文 ${han} 字: ${relativePath}`);
        }
      }
    }
  }

  if (problems.length === 0) {
    console.log(`${locale}: 结构比对通过（${sources.length} 篇）`);
    return;
  }

  console.log(`${locale}: 发现 ${problems.length} 个问题`);
  for (const problem of problems) console.log(`  - ${problem}`);
  process.exitCode = 1;
}

/**
 * 用中文源的代码块覆盖译文页面里的同名代码块。
 *
 * 译文里的代码必须逐字一致；人工/AI 翻译偶尔会顺手改注释或误伤 `[Foo]:` 这类行，
 * 这里做一次确定性回填，比事后逐页人工核对可靠。
 */
function syncCode(locale, onlyPath) {
  let fixed = 0;
  for (const { absolutePath, relativePath } of listSourcePages()) {
    if (onlyPath && relativePath !== onlyPath) continue;
    const target = localeTargetPath(locale, relativePath);
    if (!isFile(target)) continue;

    const sourceFences = extractCodeFences(readFileSync(absolutePath, "utf8"));
    const targetText = readFileSync(target, "utf8");
    const targetFences = extractCodeFences(targetText);
    if (sourceFences.length === 0 && targetFences.length === 0) continue;
    if (sourceFences.length !== targetFences.length) {
      console.log(`跳过 ${relativePath}：代码块数量不一致，需要人工处理`);
      continue;
    }

    const output = [];
    let fenceIndex = -1;
    let inFence = false;
    let marker = null;

    for (const line of targetText.split(/\r?\n/)) {
      const fenceMatch = line.match(/^\s*(`{3,}|~{3,})(.*)$/);
      if (!inFence && fenceMatch) {
        inFence = true;
        marker = fenceMatch[1][0];
        fenceIndex += 1;
        output.push(line);
        const body = sourceFences[fenceIndex].body;
        if (body) output.push(...body.split("\n"));
        continue;
      }

      if (inFence && fenceMatch && fenceMatch[1][0] === marker) {
        inFence = false;
        output.push(line);
        continue;
      }

      if (inFence) continue;
      output.push(line);
    }

    const next = output.join("\n");
    if (next !== targetText) {
      writeFileSync(target, next, "utf8");
      fixed += 1;
    }
  }

  console.log(`${locale}: 代码块回填 ${fixed} 篇`);
}

function links(locale, onlyPath) {
  let changed = 0;
  for (const absolutePath of walkMarkdown(path.join(docsRoot, locale))) {
    const relative = docsRelative(absolutePath).slice(locale.length + 1);
    if (onlyPath && relative !== onlyPath) continue;

    const markdown = readFileSync(absolutePath, "utf8");
    const next = rewriteMarkdownLinks(markdown, relative);
    if (next !== markdown) {
      writeFileSync(absolutePath, next, "utf8");
      changed += 1;
    }
  }
  console.log(`${locale}: 改写 ${changed} 篇的跨语言链接`);
}

function status() {
  const sources = listSourcePages();
  const report = { sourcePages: sources.length, locales: {} };

  for (const locale of LOCALE_DIRS) {
    const rows = [];
    let ok = 0;
    let stale = 0;
    let missing = 0;

    for (const { absolutePath, relativePath } of sources) {
      const target = localeTargetPath(locale, relativePath);
      const sourceText = readFileSync(absolutePath, "utf8");
      if (!isFile(target)) {
        missing += 1;
        rows.push({
          path: relativePath,
          state: "missing",
          han: countHan(sourceText),
        });
        continue;
      }

      const { data } = readTargetPayload(target);
      const isStale = data.sourceHash !== sha256(sourceText);
      if (isStale) {
        stale += 1;
        rows.push({
          path: relativePath,
          state: "stale",
          han: countHan(sourceText),
        });
      } else {
        ok += 1;
      }
    }

    report.locales[locale] = { ok, stale, missing, rows };
  }

  console.log(`中文源页面：${report.sourcePages} 篇`);
  for (const locale of LOCALE_DIRS) {
    const entry = report.locales[locale];
    console.log(
      `  ${locale}: 最新 ${entry.ok} / 过期 ${entry.stale} / 缺失 ${entry.missing}`,
    );
    for (const row of entry.rows) {
      console.log(`    - ${row.state}: ${row.path} (中文 ${row.han} 字)`);
    }
  }
}

function jsonList() {
  const rows = listSourcePages().map(({ absolutePath, relativePath }) => {
    const text = readFileSync(absolutePath, "utf8");
    return {
      path: relativePath,
      han: countHan(parseFrontMatter(text).body),
      fences: extractCodeFences(text).length,
    };
  });
  console.log(JSON.stringify(rows, null, 2));
}

const [command, first, second] = process.argv.slice(2);

switch (command) {
  case "list":
    jsonList();
    break;
  case "status":
    status();
    break;
  case "stamp":
    if (!first) throw new Error("stamp 需要 locale 参数");
    stamp(first, second);
    break;
  case "verify":
    if (!first) throw new Error("verify 需要 locale 参数");
    verify(first);
    break;
  case "trad":
    if (!first) throw new Error("trad 需要 zh-tw 或 zh-hk");
    generateTraditional(first);
    break;
  case "links":
    if (!first) throw new Error("links 需要 locale 参数");
    links(first, second);
    break;
  case "sync-code":
    if (!first) throw new Error("sync-code 需要 locale 参数");
    syncCode(first, second);
    break;
  default:
    console.log(
      "用法：docs-i18n.mjs <list|status|stamp|verify|trad|links|sync-code> [locale] [path]",
    );
    process.exitCode = command ? 1 : 0;
}
