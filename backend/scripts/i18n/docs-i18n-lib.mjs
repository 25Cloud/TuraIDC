/**
 * 文档站多语言共享工具。
 *
 * 这里只放「文档语言层」的通用能力：扫描、遮蔽、哈希、链接改写、结构抽取。
 * `check_docs.mjs`（校验）与 `docs-i18n.mjs`（生成/维护）都从本文件取实现，
 * 避免两套口径各说各话。
 */
import { createHash } from "node:crypto";
import { readFileSync, readdirSync, statSync } from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

const libDir = path.dirname(fileURLToPath(import.meta.url));

export const repoRoot = path.resolve(libDir, "../../..");
export const docsRoot = path.join(repoRoot, "docs");

/** 与 VitePress locales 的目录名一致：中文占根路径，其余各占一个子树。 */
export const LOCALE_DIRS = ["en", "zh-tw", "zh-hk"];

export const LOCALE_LABELS = {
  en: "English",
  "zh-tw": "繁體中文（台灣）",
  "zh-hk": "繁體中文（香港）",
};

/** 由脚本从实库/路由生成的中文文档：不进译文镜像，英文侧改用中文页直链。 */
export const GENERATED_PAGES = new Set([
  "develop/database.md",
  "develop/api/backend-api-catalog.md",
]);

/** 维护者内部记录在站外，译文里一律改成 GitHub 链接。 */
export const REPO_BLOB_BASE = "https://github.com/25Cloud/TuraIDC/blob/main";

export function toPosix(value) {
  return value.split(path.sep).join("/");
}

export function sha256(value) {
  return createHash("sha256").update(value, "utf8").digest("hex");
}

export function isDirectory(target) {
  try {
    return statSync(target).isDirectory();
  } catch {
    return false;
  }
}

export function isFile(target) {
  try {
    return statSync(target).isFile();
  } catch {
    return false;
  }
}

export function walkMarkdown(directory) {
  if (!isDirectory(directory)) {
    return [];
  }

  const files = [];
  const entries = readdirSync(directory, { withFileTypes: true }).sort(
    (left, right) => left.name.localeCompare(right.name),
  );
  for (const entry of entries) {
    const entryPath = path.join(directory, entry.name);
    if (entry.isDirectory()) {
      files.push(...walkMarkdown(entryPath));
    } else if (entry.isFile() && /\.md$/i.test(entry.name)) {
      files.push(entryPath);
    }
  }

  return files;
}

/** docs/ 相对路径（posix 分隔符），例如 `guide/deploy/bt-panel.md`。 */
export function docsRelative(absolutePath) {
  return toPosix(path.relative(docsRoot, absolutePath));
}

export function isLocalePath(relativePath) {
  const [head] = relativePath.split("/");
  return LOCALE_DIRS.includes(head);
}

/**
 * 参与翻译的页面：根路径下除 internal/ 与生成物以外的全部 Markdown。
 */
export function listSourcePages() {
  return walkMarkdown(docsRoot)
    .map((absolutePath) => ({
      absolutePath,
      relativePath: docsRelative(absolutePath),
    }))
    .filter(({ relativePath }) => !isLocalePath(relativePath))
    .filter(({ relativePath }) => !relativePath.startsWith("internal/"))
    .filter(({ relativePath }) => !relativePath.includes("/internal/"))
    .filter(({ relativePath }) => !GENERATED_PAGES.has(relativePath));
}

export function localeTargetPath(locale, sourceRelativePath) {
  return path.join(docsRoot, locale, sourceRelativePath);
}

/** front matter 只需支持单行标量：文档里实际只用到 status/updated/owner/title 这类。 */
export function parseFrontMatter(markdown) {
  const source = markdown.replace(/^\uFEFF/, "");
  const lines = source.split(/\r?\n/);
  if (lines[0]?.trim() !== "---") {
    return { data: {}, body: source, hasFrontMatter: false };
  }

  const data = {};
  let endIndex = -1;
  for (let index = 1; index < lines.length; index += 1) {
    const line = lines[index];
    if (line.trim() === "---" || line.trim() === "...") {
      endIndex = index;
      break;
    }

    const property = line.match(/^([A-Za-z][A-Za-z\d_-]*):(?:\s*(.*))?$/);
    if (property) {
      const raw = (property[2] ?? "").trim();
      const quoted =
        (raw.startsWith('"') && raw.endsWith('"')) ||
        (raw.startsWith("'") && raw.endsWith("'"));
      data[property[1]] = quoted
        ? raw.slice(1, -1)
        : raw.replace(/\s+#.*$/, "").trim();
    }
  }

  if (endIndex === -1) {
    return { data: {}, body: source, hasFrontMatter: false };
  }

  return {
    data,
    body: lines.slice(endIndex + 1).join("\n"),
    hasFrontMatter: true,
  };
}

/**
 * 把代码块、行内代码、注释替换成等长空白，保留换行。
 *
 * 判断「这段中文是不是给人看的正文」时，唯一可靠的办法是先把不可翻译的部分
 * 挖掉；否则代码里的中文注释、变量说明会一直误报。
 */
export function maskNonProse(markdown) {
  const maskCharacters = (value) => value.replace(/[^\r\n]/g, " ");
  let masked = markdown.replace(/<!--[\s\S]*?-->/g, maskCharacters);
  const lines = masked.split(/(?<=\n)/);
  let fence = null;

  masked = lines
    .map((line) => {
      const fenceMatch = line.match(/^\s*(`{3,}|~{3,})/);
      if (fenceMatch) {
        const marker = fenceMatch[1];
        if (!fence) {
          fence = marker[0];
        } else if (fence === marker[0]) {
          fence = null;
        }

        return maskCharacters(line);
      }

      if (fence) {
        return maskCharacters(line);
      }

      return line;
    })
    .join("");

  return (
    masked
      .replace(/`[^`\r\n]*`/g, maskCharacters)
      // `<code v-pre>{{变量}}</code>` 这类行内 HTML 代码也是代码，不是待翻译正文
      .replace(/<code\b[^>]*>[\s\S]*?<\/code>/gi, maskCharacters)
  );
}

const HAN_PATTERN = /[\u3400-\u4dbf\u4e00-\u9fff\uf900-\ufaff]/gu;

export function countHan(text) {
  return (text.match(HAN_PATTERN) ?? []).length;
}

export function extractCodeFences(markdown) {
  const fences = [];
  const lines = markdown.split(/\r?\n/);
  let current = null;

  for (const line of lines) {
    const match = line.match(/^\s*(`{3,}|~{3,})(.*)$/);
    if (!current && match) {
      current = { marker: match[1][0], header: match[2].trim(), lines: [] };
      continue;
    }

    if (current && match && match[1][0] === current.marker) {
      fences.push({ header: current.header, body: current.lines.join("\n") });
      current = null;
      continue;
    }

    if (current) {
      current.lines.push(line);
    }
  }

  if (current) {
    fences.push({ header: current.header, body: current.lines.join("\n") });
  }

  return fences;
}

/** 标题层级序列（只看层级，不看文字）：翻译后结构必须一致。 */
export function extractHeadingLevels(markdown) {
  return maskNonProse(markdown)
    .split(/\r?\n/)
    .map((line) => line.match(/^(#{1,6})\s+\S/))
    .filter(Boolean)
    .map((match) => match[1].length);
}

function parseMarkdownDestination(value) {
  const trimmed = value.trim();
  if (!trimmed) {
    return "";
  }
  if (trimmed.startsWith("<")) {
    const closing = trimmed.indexOf(">");
    return closing === -1 ? trimmed : trimmed.slice(1, closing).trim();
  }

  let destination = "";
  let escaped = false;
  for (const character of trimmed) {
    if (escaped) {
      destination += character;
      escaped = false;
      continue;
    }
    if (character === "\\") {
      escaped = true;
      continue;
    }
    if (/\s/.test(character)) {
      break;
    }
    destination += character;
  }

  return destination;
}

function isExternalDestination(destination) {
  return (
    !destination ||
    destination.startsWith("//") ||
    /^[a-z][a-z\d+.-]*:/i.test(destination) ||
    destination.startsWith("/") ||
    destination.startsWith("#") ||
    destination.startsWith("?")
  );
}

/** 抽取正文里全部链接目标（含引用式定义），顺序即出现顺序。 */
export function extractLinkDestinations(markdown) {
  const prose = maskNonProse(markdown);
  const destinations = [];
  const definitionPattern = /^\s{0,3}\[([^\]\r\n]+)]:\s*(.+?)\s*$/gm;
  let match;

  while ((match = definitionPattern.exec(prose))) {
    destinations.push(parseMarkdownDestination(match[2]));
  }

  const inlinePattern = /!?\[[^\]\r\n]*]\(([^\r\n)]*)\)/g;
  while ((match = inlinePattern.exec(prose))) {
    destinations.push(parseMarkdownDestination(match[1]));
  }

  return destinations;
}

/**
 * 译文页面上的站内链接应该长什么样。
 *
 * 三条改写规则，都是「中文页面里能点、镜像子树里点不到」的情况：
 * 1. internal/ 与仓库根文件 → GitHub 链接（站上本来就没有这些页面）；
 * 2. 脚本生成物 → 中文页的站点绝对路径（译文不镜像生成物）；
 * 3. 其余同构页面 → 原样保留，靠目录镜像解析。
 */
export function expectedLocaleDestination(destination, sourceRelativePath) {
  if (isExternalDestination(destination)) {
    return destination;
  }

  const hashIndex = destination.search(/[?#]/);
  const target =
    hashIndex === -1 ? destination : destination.slice(0, hashIndex);
  const suffix = hashIndex === -1 ? "" : destination.slice(hashIndex);
  if (!target) {
    return destination;
  }

  const resolved = path.resolve(
    docsRoot,
    path.dirname(sourceRelativePath),
    target,
  );
  const docsRelativePath = toPosix(path.relative(docsRoot, resolved));
  const repoRelativePath = toPosix(path.relative(repoRoot, resolved));

  if (repoRelativePath.startsWith("..")) {
    return destination;
  }

  if (!docsRelativePath.startsWith("..")) {
    if (
      docsRelativePath.startsWith("internal/") ||
      docsRelativePath.includes("/internal/")
    ) {
      return `${REPO_BLOB_BASE}/docs/${docsRelativePath}${suffix}`;
    }
    if (GENERATED_PAGES.has(docsRelativePath)) {
      return `/${docsRelativePath.replace(/\.md$/i, "")}${suffix}`;
    }
    // 只有 Markdown 页面会被镜像；catalog.json 这类仓库内文件站点上没有副本
    if (!/\.(md|png|jpe?g|gif|svg|webp)$/i.test(docsRelativePath)) {
      return `${REPO_BLOB_BASE}/docs/${docsRelativePath}${suffix}`;
    }
    return destination;
  }

  return `${REPO_BLOB_BASE}/${repoRelativePath}${suffix}`;
}

/** 把 Markdown 里所有链接目标按上方规则就地改写。 */
export function rewriteMarkdownLinks(markdown, sourceRelativePath) {
  const rewriteDestination = (rawDestination) => {
    const destination = parseMarkdownDestination(rawDestination);
    const expected = expectedLocaleDestination(destination, sourceRelativePath);
    if (expected === destination) {
      return null;
    }

    return rawDestination.replace(destination, expected);
  };

  const lines = markdown.split(/\r?\n/);
  const result = [];
  let fence = null;

  for (const line of lines) {
    const fenceMatch = line.match(/^\s*(`{3,}|~{3,})/);
    if (fenceMatch) {
      if (!fence) {
        fence = fenceMatch[1][0];
      } else if (fence === fenceMatch[1][0]) {
        fence = null;
      }
      result.push(line);
      continue;
    }

    // 代码块里的 `[...]:` 是 shell/PowerShell 语法，不是 Markdown 链接；
    // 行内代码同理，先搬进占位符再改写。
    if (fence) {
      result.push(line);
      continue;
    }

    const slots = [];
    const guarded = line.replace(/`[^`\r\n]*`/g, (match) => {
      slots.push(match);
      return `\uE000${slots.length - 1}\uE001`;
    });

    const rewritten = guarded
      .replace(
        /(!?\[[^\]\r\n]*]\()([^\r\n)]*)\)/g,
        (whole, prefix, rawDestination) => {
          const next = rewriteDestination(rawDestination);
          return next === null ? whole : `${prefix}${next})`;
        },
      )
      .replace(
        /^(\s{0,3}\[[^\]\r\n]+]:\s*)(.+?)\s*$/,
        (whole, prefix, rawDestination) => {
          const next = rewriteDestination(rawDestination);
          return next === null ? whole : `${prefix}${next}`;
        },
      );

    result.push(
      rewritten.replace(
        /\uE000(\d+)\uE001/g,
        (_, index) => slots[Number(index)],
      ),
    );
  }

  return result.join("\n");
}

/**
 * 正文里的汉字数：代码块、行内代码、HTML 代码与链接目标都不算正文。
 *
 * 链接目标是 URL，`#中文锚点` 或仓库文件路径都可能是中文，但它不是待翻译内容。
 */
export function countProseHan(markdown) {
  let prose = maskNonProse(markdown);
  prose = prose.replace(/\]\([^)\r\n]*\)/g, (match) =>
    " ".repeat(match.length),
  );
  prose = prose.replace(/^\s{0,3}\[[^\]\r\n]+]:\s*(.+)$/gm, (match) =>
    " ".repeat(match.length),
  );

  return countHan(prose);
}
