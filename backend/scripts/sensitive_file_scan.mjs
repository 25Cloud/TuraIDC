#!/usr/bin/env node

import { execFileSync } from "node:child_process";
import { lstatSync, readFileSync } from "node:fs";
import path from "node:path";
import { pathToFileURL } from "node:url";

const MAX_TEXT_FILE_BYTES = 10 * 1024 * 1024;

const TOKEN_PATTERNS = [
  [
    "private-key",
    /^[ \t]*-----BEGIN (?:RSA |EC |DSA |OPENSSH |PGP )?PRIVATE KEY-----[ \t]*$/gm,
  ],
  ["aws-access-key", /\b(?:AKIA|ASIA)[A-Z0-9]{16}\b/g],
  ["github-token", /\bgh[pousr]_[A-Za-z0-9]{30,255}\b/g],
  ["gitlab-token", /\bglpat-[A-Za-z0-9_-]{20,255}\b/g],
  ["slack-token", /\bxox[baprs]-[A-Za-z0-9-]{20,255}\b/g],
  ["stripe-live-key", /\bsk_live_[A-Za-z0-9]{20,255}\b/g],
  ["google-api-key", /\bAIza[0-9A-Za-z_-]{30,255}\b/g],
];

const GENERIC_CONFIG_EXTENSIONS = new Set([
  ".bat",
  ".cmd",
  ".conf",
  ".env",
  ".ini",
  ".js",
  ".json",
  ".md",
  ".mjs",
  ".cjs",
  ".php",
  ".properties",
  ".ps1",
  ".sh",
  ".ts",
  ".tsx",
  ".toml",
  ".txt",
  ".vue",
  ".xml",
  ".yaml",
  ".yml",
]);
const SCANNABLE_TEXT_EXTENSIONS = new Set([
  ...GENERIC_CONFIG_EXTENSIONS,
  ".dump",
  ".jsx",
  ".key",
  ".mts",
  ".cts",
  ".pem",
  ".sql",
]);

const SAFE_LITERAL_VALUES = new Set([
  "",
  "changeme",
  "change_me",
  "dummy",
  "example",
  "fake",
  "false",
  "hashed",
  "masked",
  "none",
  "null",
  "password",
  "passwd",
  "placeholder",
  "redacted",
  "sample",
  "secret",
  "test",
  "testing",
  "token",
  "true",
]);

function lineNumberAt(text, index) {
  let line = 1;
  for (let cursor = 0; cursor < index; cursor += 1) {
    if (text.charCodeAt(cursor) === 10) {
      line += 1;
    }
  }
  return line;
}

function stripWrappingQuotes(rawValue) {
  let value = String(rawValue ?? "").trim();
  value = value
    .replace(/(?:^|\s+)#.*$/, "")
    .replace(/[;,]\s*$/, "")
    .trim();

  if (value.length >= 2) {
    const first = value[0];
    const last = value.at(-1);
    if (
      (first === '"' && last === '"') ||
      (first === "'" && last === "'") ||
      (first === "`" && last === "`")
    ) {
      value = value.slice(1, -1).trim();
    }
  }

  return value;
}

export function isPlaceholder(rawValue) {
  const value = stripWrappingQuotes(rawValue);
  const lowered = value.toLowerCase();

  if (
    SAFE_LITERAL_VALUES.has(lowered) ||
    /^x{3,}$/i.test(value) ||
    /^\*{3,}$/.test(value) ||
    /^<[^>]+>$/.test(value) ||
    /^your[-_]/i.test(value) ||
    /^(?:你的|请填写|请设置|替换为|示例)/.test(value) ||
    /^\$\{.+}$/.test(value) ||
    /^\$\(/.test(value) ||
    /^\$[A-Za-z_][A-Za-z0-9_]*(?:->\w+)?$/.test(value) ||
    /^%[^%]+%$/.test(value) ||
    /^\{\{.+}}$/.test(value) ||
    /^(?:env|getenv)\(.+\)$/i.test(value) ||
    /^process\.env(?:\.|\[)/i.test(value) ||
    /^(?:sometimes\|)?(?:required|nullable|present|filled)(?:\||$)/i.test(value) ||
    /^[a-z][a-z0-9.-]*_(?:api_?key|private_?key|secret|token|password)$/i.test(value)
  ) {
    return true;
  }

  return false;
}

function isSourceCode(relativePath) {
  return new Set([".cjs", ".js", ".mjs", ".php", ".ts", ".tsx", ".vue"]).has(
    path.posix.extname(relativePath.replaceAll("\\", "/").toLowerCase()),
  );
}

function sourceCredentialLiteral(rawValue) {
  const value = String(rawValue ?? "")
    .trim()
    .replace(/[;,]\s*$/, "")
    .trim();
  const quote = value[0];
  if (
    value.length >= 2 &&
    (quote === '"' || quote === "'" || quote === "`") &&
    value.at(-1) === quote
  ) {
    if (quote === "`" && value.includes("${")) {
      return null;
    }
    return value.slice(1, -1);
  }

  const wrapped = value.match(
    /^(?:Hash::make|bcrypt|password_hash)\(\s*(["'`])([\s\S]*?)\1(?:\s*,[\s\S]*)?\)$/,
  );
  if (wrapped) {
    return wrapped[2];
  }
  return null;
}

function isFixturePath(relativePath) {
    const normalized = relativePath.replaceAll("\\", "/").toLowerCase();
    return (
        normalized.includes("/tests/") ||
    normalized.includes("/fixtures/") ||
    normalized.startsWith("backend/tests/") ||
    /\.(?:spec|test)\.[cm]?[jt]sx?$/.test(path.posix.basename(normalized))
    );
}

function isTranslationPath(relativePath) {
    const normalized = relativePath.replaceAll("\\", "/").toLowerCase();
    return normalized.startsWith("backend/lang/") || normalized.startsWith("lang/");
}

function isSourceMetadataLiteral(value) {
  const normalized = String(value ?? "").trim();
  return (
    /^[\p{Script=Han}\s]+$/u.test(normalized) ||
    /^(?:array|boolean|datetime|hashed|integer|public|relay|string|text)$/i.test(
      normalized,
    ) ||
    (/^[a-z][a-z0-9_.:-]*$/i.test(normalized) &&
      /[_.:]/.test(normalized) &&
      /(?:key|password|secret|token)/i.test(normalized))
  );
}

function looksLikeHighEntropyCredential(value) {
  const normalized = String(value ?? "").trim();
  if (/^[a-f0-9]{24,}$/i.test(normalized)) {
    return true;
  }
  if (/^[A-Za-z0-9+/_=-]{32,}$/.test(normalized)) {
    return true;
  }
  const characterClasses = [/[a-z]/, /[A-Z]/, /\d/, /[^A-Za-z0-9]/].filter(
    (pattern) => pattern.test(normalized),
  ).length;
  return normalized.length >= 16 && characterClasses >= 3;
}

function isObviousFixtureCredential(value) {
  const normalized = String(value ?? "").trim();
  return (
    /^(?:demo|dummy|example|fake|sample|test)(?:[-_.]|[A-Z0-9])/i.test(
      normalized,
    ) ||
    /^temp@.+-wrong$/i.test(normalized) ||
    /^provisionsecret\d+$/i.test(normalized)
  );
}

function isCredentialKey(rawKey) {
  const key = String(rawKey ?? "")
    .replace(/([a-z0-9])([A-Z])/g, "$1_$2")
    .replace(/[^A-Za-z0-9_]/g, "_")
    .toUpperCase();

  if (
    /(?:ALLOW_EMPTY|CONFIRMATION|FIELD|LABEL|MAX|MIN|PLACEHOLDER|REQUIRED|RULE)/.test(
      key,
    )
  ) {
    return false;
  }

  return /(?:^|_)(?:APP_KEY|API_KEY|ACCESS_KEY|CLIENT_SECRET|PASSWORD|PASSWD|PRIVATE_KEY|SECRET_KEY|SECRET|TOKEN)$/.test(
    key,
  );
}

function shouldInspectGenericAssignments(relativePath) {
  const normalized = relativePath.replaceAll("\\", "/").toLowerCase();
  const basename = path.posix.basename(normalized);

  if (
    basename === "package-lock.json" ||
    basename === "composer.lock" ||
    basename.endsWith(".snap") ||
    normalized.startsWith("backend/bootstrap/cache/") ||
    normalized.startsWith("docs/generated/") ||
    normalized.includes("/src/locales/")
  ) {
    return false;
  }

  if (basename.startsWith(".env") || normalized.includes(".xml")) {
    return true;
  }

  return (
    normalized.startsWith("docs/") ||
    normalized.startsWith(".github/workflows/") ||
    GENERIC_CONFIG_EXTENSIONS.has(path.posix.extname(normalized))
  );
}

function addFinding(findings, relativePath, line, rule) {
  if (
    !findings.some(
      (finding) =>
        finding.path === relativePath &&
        finding.line === line &&
        finding.rule === rule,
    )
  ) {
    findings.push({ path: relativePath, line, rule });
  }
}

function scanGenericAssignments(text, relativePath, findings) {
    if (
        !shouldInspectGenericAssignments(relativePath) ||
        isTranslationPath(relativePath)
    ) {
        return;
    }

  const lines = text.split(/\r?\n/);
  const sourceCode = isSourceCode(relativePath);
  lines.forEach((lineText, index) => {
    const candidates = [];
    const envAssignment = lineText.match(
      /^\s*(?:export\s+)?([A-Za-z][A-Za-z0-9_]*)\s*=\s*(.*?)\s*$/,
    );
    if (envAssignment) {
      candidates.push([
        envAssignment[1],
        envAssignment[2],
        "credential-assignment",
        false,
      ]);
    }

    const xmlEnvironment = lineText.match(
      /<env\b[^>]*\bname=["']([^"']+)["'][^>]*\bvalue=["']([^"']*)["'][^>]*>/i,
    );
    if (xmlEnvironment) {
      candidates.push([
        xmlEnvironment[1],
        xmlEnvironment[2],
        "credential-assignment",
        false,
      ]);
    }

    const structuredAssignment = lineText.match(
      /^\s*["']?([A-Za-z][A-Za-z0-9_.-]*)["']?\s*:\s*(.+?)\s*,?\s*$/,
    );
    if (structuredAssignment) {
      candidates.push([
        structuredAssignment[1],
        structuredAssignment[2],
        "credential-assignment",
        sourceCode,
      ]);
    }

    const phpArrayAssignment = lineText.match(
      /^\s*["']?([A-Za-z][A-Za-z0-9_.-]*)["']?\s*=>\s*(.+?)\s*,?\s*$/,
    );
    if (phpArrayAssignment) {
      candidates.push([
        phpArrayAssignment[1],
        phpArrayAssignment[2],
        "credential-assignment",
        true,
      ]);
    }

    const sourceAssignment = lineText.match(
      /^\s*(?:(?:const|let|var)\s+)?([A-Za-z_$][A-Za-z0-9_$]*)\s*=\s*(.+?)\s*;?\s*$/,
    );
    if (sourceAssignment) {
      candidates.push([
        sourceAssignment[1],
        sourceAssignment[2],
        "credential-assignment",
        true,
      ]);
    }

    const powershellAssignment = lineText.match(
      /^\s*\$([A-Za-z][A-Za-z0-9_]*)\s*=\s*(.*?)\s*$/,
    );
    if (powershellAssignment) {
      candidates.push([
        powershellAssignment[1],
        powershellAssignment[2],
        "credential-assignment",
        true,
      ]);
    }

    if (
      candidates.length === 0 &&
      [".md", ".rst", ".txt"].includes(
        path.posix.extname(relativePath.replaceAll("\\", "/").toLowerCase()),
      )
    ) {
      const labelledValue = lineText.match(
        /(?:密码|口令|password|passwd|secret|token|api[ _-]?key|client[ _-]?secret)\s*(?::|：|=(?!>))\s*["'`]?([^\s"'`,;]+)/i,
      );
      if (labelledValue) {
        candidates.push([
          "password",
          labelledValue[1],
          "credential-in-prose",
          false,
        ]);
      }

      const prosePair = lineText.match(
        /(?:管理员|用户|账号|administrator|account|user(?:name)?)\s*[:：]\s*[`]?[^`/\s]+[`]?\s*\/\s*[`]?([^`\s]+)/i,
      );
      if (prosePair) {
        candidates.push([
          "password",
          prosePair[1],
          "credential-pair-in-prose",
          false,
        ]);
      }
    }

    for (const [key, value, rule, literalOnly] of candidates) {
      const inspectedValue = literalOnly ? sourceCredentialLiteral(value) : value;
      if (
        isCredentialKey(key) &&
        inspectedValue !== null &&
        !isPlaceholder(inspectedValue) &&
        !(literalOnly && isSourceMetadataLiteral(inspectedValue)) &&
        !(
          literalOnly &&
          isFixturePath(relativePath) &&
          (isObviousFixtureCredential(inspectedValue) ||
            !looksLikeHighEntropyCredential(inspectedValue))
        )
      ) {
        addFinding(findings, relativePath, index + 1, rule);
      }
    }
  });
}

export function scanText(text, relativePath = "<memory>") {
  const findings = [];

  for (const [rule, pattern] of TOKEN_PATTERNS) {
    pattern.lastIndex = 0;
    let match;
    while ((match = pattern.exec(text)) !== null) {
      const line = lineNumberAt(text, match.index);
      addFinding(findings, relativePath, line, rule);
    }
  }

  const credentialUrlPattern =
    /\b[a-z][a-z0-9+.-]*:\/\/[^\s:@/]+:([^@\s/]+)@[^\s/]+/gi;
  let credentialUrl;
  while ((credentialUrl = credentialUrlPattern.exec(text)) !== null) {
    const line = lineNumberAt(text, credentialUrl.index);
    if (!isPlaceholder(credentialUrl[1])) {
      addFinding(findings, relativePath, line, "credential-in-url");
    }
  }

  scanGenericAssignments(text, relativePath, findings);

  return findings.sort(
    (left, right) =>
      left.line - right.line || left.rule.localeCompare(right.rule),
  );
}

function listCurrentTreeFiles(repositoryRoot) {
  const output = execFileSync(
    "git",
    ["ls-files", "-z", "--cached", "--others", "--exclude-standard"],
    {
      cwd: repositoryRoot,
      encoding: "utf8",
      maxBuffer: 64 * 1024 * 1024,
      windowsHide: true,
    },
  );

  return output.split("\0").filter(Boolean);
}

function looksBinary(buffer) {
  const inspected = buffer.subarray(0, Math.min(buffer.length, 8192));
  return inspected.includes(0);
}

function isInstructionTemplate(relativePath) {
  const normalized = relativePath.replaceAll("\\", "/").toLowerCase();
  return (
    normalized.startsWith(".codex/agents/") ||
    normalized.startsWith(".github/agents/")
  );
}

export function scanRepository(repositoryRoot) {
  const root = path.resolve(repositoryRoot);
  const findings = [];

  for (const relativePath of listCurrentTreeFiles(root)) {
    if (isInstructionTemplate(relativePath)) {
      continue;
    }

    const absolutePath = path.resolve(root, relativePath);
    if (!absolutePath.startsWith(`${root}${path.sep}`)) {
      continue;
    }

    let fileStats;
    try {
      fileStats = lstatSync(absolutePath);
    } catch {
      continue;
    }

    if (!fileStats.isFile() || fileStats.isSymbolicLink()) {
      continue;
    }

    if (fileStats.size > MAX_TEXT_FILE_BYTES) {
      const extension = path.extname(relativePath).toLowerCase();
      if (
        relativePath.toLowerCase().endsWith(".env") ||
        SCANNABLE_TEXT_EXTENSIONS.has(extension)
      ) {
        addFinding(findings, relativePath, 1, "oversized-unscanned-text");
      }
      continue;
    }

    const buffer = readFileSync(absolutePath);
    if (looksBinary(buffer)) {
      continue;
    }

    findings.push(...scanText(buffer.toString("utf8"), relativePath));
  }

  return findings.sort(
    (left, right) =>
      left.path.localeCompare(right.path) ||
      left.line - right.line ||
      left.rule.localeCompare(right.rule),
  );
}

function findRepositoryRoot(startDirectory) {
  return execFileSync("git", ["rev-parse", "--show-toplevel"], {
    cwd: startDirectory,
    encoding: "utf8",
    windowsHide: true,
  }).trim();
}

function main() {
  const repositoryRoot = findRepositoryRoot(process.cwd());
  const findings = scanRepository(repositoryRoot);

  if (findings.length === 0) {
    console.log(
      "Sensitive-file scan passed: current Git tree contains no high-confidence credential findings.",
    );
    return;
  }

  console.error(
    `Sensitive-file scan failed with ${findings.length} finding(s).`,
  );
  for (const finding of findings) {
    console.error(`- ${finding.path}:${finding.line} [${finding.rule}]`);
  }
  console.error(
    "Only tracked and non-ignored files in the current Git tree were scanned; Git history was not inspected.",
  );
  process.exitCode = 1;
}

const invokedPath = process.argv[1]
  ? pathToFileURL(path.resolve(process.argv[1])).href
  : "";
if (import.meta.url === invokedPath) {
  main();
}
