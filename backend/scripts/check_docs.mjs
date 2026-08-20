import { existsSync, readFileSync, readdirSync, statSync } from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

const scriptDir = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(scriptDir, "../..");
const docsRoot = path.join(root, "docs");
const flags = new Set(process.argv.slice(2));
const knownFlags = new Set(["--freshness", "--strict-freshness"]);
const checkFreshness =
  flags.has("--freshness") || flags.has("--strict-freshness");
const strictFreshness = flags.has("--strict-freshness");

const errors = [];
const freshnessFindings = [];

const catalogStatuses = new Set([
  "current",
  "needs-review",
  "active",
  "进行中",
  "completed",
  "tech-debt",
  "generated",
  "archived",
  "template",
  "experimental",
]);

const requiredGeneratedCatalogPaths = new Set([
  "DATABASE.md",
  "generated/api/backend-api-catalog.md",
]);
const statusesRequiringReviewBy = new Set(["current", "active"]);
const requiredDirectories = [
  "designs",
  "product-specs",
  "execution-plans",
  "execution-plans/active",
  "execution-plans/completed",
  "execution-plans/tech-debt",
  "references",
  "generated",
];
const requiredFiles = [
  "README.md",
  "designs/index.md",
  "product-specs/README.md",
  "execution-plans/README.md",
  "references/README.md",
  "generated/README.md",
  "catalog.json",
];

for (const flag of flags) {
  if (!knownFlags.has(flag)) {
    errors.push(`unknown option: ${flag}`);
  }
}

function toRepoPath(filePath) {
  return path.relative(root, filePath).split(path.sep).join("/");
}

function toDocsPath(filePath) {
  return path.relative(docsRoot, filePath).split(path.sep).join("/");
}

function isWithin(basePath, candidatePath) {
  const relative = path.relative(basePath, candidatePath);

  return (
    relative === "" ||
    (!relative.startsWith(`..${path.sep}`) &&
      relative !== ".." &&
      !path.isAbsolute(relative))
  );
}

function isFile(filePath) {
  try {
    return statSync(filePath).isFile();
  } catch {
    return false;
  }
}

function isDirectory(filePath) {
  try {
    return statSync(filePath).isDirectory();
  } catch {
    return false;
  }
}

function walkMarkdown(directoryPath) {
  if (!isDirectory(directoryPath)) {
    return [];
  }

  const files = [];
  const entries = readdirSync(directoryPath, { withFileTypes: true }).sort(
    (left, right) => left.name.localeCompare(right.name),
  );

  for (const entry of entries) {
    const entryPath = path.join(directoryPath, entry.name);
    if (entry.isDirectory()) {
      files.push(...walkMarkdown(entryPath));
    } else if (entry.isFile() && /\.md$/i.test(entry.name)) {
      files.push(entryPath);
    }
  }

  return files;
}

function isIndexMarkdown(filePath) {
  return /^(readme|index)\.md$/i.test(path.basename(filePath));
}

function maskCharacters(value) {
  return value.replace(/[^\r\n]/g, " ");
}

function maskNonProse(markdown) {
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

  return masked.replace(/`[^`\r\n]*`/g, maskCharacters);
}

function lineNumberAt(markdown, index) {
  let line = 1;
  for (let position = 0; position < index; position += 1) {
    if (markdown[position] === "\n") {
      line += 1;
    }
  }

  return line;
}

function parseMarkdownDestination(value) {
  const trimmed = value.trim();
  if (!trimmed) {
    return "";
  }

  if (trimmed.startsWith("<")) {
    const closingIndex = trimmed.indexOf(">");
    return closingIndex === -1
      ? trimmed
      : trimmed.slice(1, closingIndex).trim();
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

function normaliseReferenceLabel(label) {
  return label.trim().replace(/\s+/g, " ").toLocaleLowerCase();
}

function isExternalDestination(destination) {
  return (
    destination.startsWith("//") ||
    /^[a-z][a-z\d+.-]*:/i.test(destination) ||
    destination.startsWith("#") ||
    destination.startsWith("?")
  );
}

function decodeLinkPath(value) {
  try {
    return decodeURIComponent(value);
  } catch {
    return value;
  }
}

function validateLocalLink(sourceFile, destination, lineNumber) {
  if (!destination || isExternalDestination(destination)) {
    return;
  }

  const targetWithoutFragment = destination.split(/[?#]/, 1)[0];
  if (!targetWithoutFragment) {
    return;
  }

  const decodedTarget = decodeLinkPath(targetWithoutFragment).replace(
    /\\/g,
    "/",
  );
  let targetPath;
  if (decodedTarget.startsWith("/docs/")) {
    targetPath = path.resolve(root, decodedTarget.slice(1));
  } else if (decodedTarget.startsWith("/")) {
    return;
  } else if (
    path.win32.isAbsolute(decodedTarget) ||
    path.posix.isAbsolute(decodedTarget)
  ) {
    errors.push(
      `${toRepoPath(sourceFile)}:${lineNumber} local link must not use an absolute filesystem path: ${destination}`,
    );
    return;
  } else if (decodedTarget.startsWith("docs/")) {
    targetPath = path.resolve(root, decodedTarget);
  } else {
    targetPath = path.resolve(path.dirname(sourceFile), decodedTarget);
  }

  if (!isWithin(root, targetPath)) {
    errors.push(
      `${toRepoPath(sourceFile)}:${lineNumber} local link escapes the repository: ${destination}`,
    );
    return;
  }

  if (!existsSync(targetPath)) {
    errors.push(
      `${toRepoPath(sourceFile)}:${lineNumber} broken local link: ${destination}`,
    );
    return;
  }

  if (isDirectory(targetPath)) {
    errors.push(
      `${toRepoPath(sourceFile)}:${lineNumber} local link points to a directory: ${destination}`,
    );
  }
}

function checkMarkdownLinks(markdownFiles) {
  for (const markdownFile of markdownFiles) {
    const source = readFileSync(markdownFile, "utf8");
    const prose = maskNonProse(source);
    const definitions = new Map();
    const definitionPattern = /^\s{0,3}\[([^\]\r\n]+)]:\s*(.+?)\s*$/gm;
    let match;

    while ((match = definitionPattern.exec(prose))) {
      const label = normaliseReferenceLabel(match[1]);
      const destination = parseMarkdownDestination(match[2]);
      definitions.set(label, destination);
      validateLocalLink(
        markdownFile,
        destination,
        lineNumberAt(source, match.index),
      );
    }

    const inlinePattern = /!?\[[^\]\r\n]*]\(([^\r\n)]*)\)/g;
    while ((match = inlinePattern.exec(prose))) {
      const destination = parseMarkdownDestination(match[1]);
      validateLocalLink(
        markdownFile,
        destination,
        lineNumberAt(source, match.index),
      );
    }

    const referencePattern = /!?\[([^\]\r\n]+)]\[([^\]\r\n]*)]/g;
    while ((match = referencePattern.exec(prose))) {
      const label = normaliseReferenceLabel(match[2] || match[1]);
      const lineNumber = lineNumberAt(source, match.index);
      if (!definitions.has(label)) {
        errors.push(
          `${toRepoPath(markdownFile)}:${lineNumber} undefined reference link: ${match[0]}`,
        );
        continue;
      }

      validateLocalLink(markdownFile, definitions.get(label), lineNumber);
    }
  }
}

function parseYamlScalar(value) {
  const trimmed = value.trim();
  if (!trimmed) {
    return "";
  }

  if (
    (trimmed.startsWith('"') && trimmed.endsWith('"')) ||
    (trimmed.startsWith("'") && trimmed.endsWith("'"))
  ) {
    return trimmed.slice(1, -1).trim();
  }

  return trimmed.replace(/\s+#.*$/, "").trim();
}

function parseFrontMatter(markdown) {
  const source = markdown.replace(/^\uFEFF/, "");
  const lines = source.split(/\r?\n/);
  if (lines[0]?.trim() !== "---") {
    return null;
  }

  const properties = {};
  for (let index = 1; index < lines.length; index += 1) {
    const line = lines[index];
    if (line.trim() === "---" || line.trim() === "...") {
      return properties;
    }

    const property = line.match(/^([A-Za-z][A-Za-z\d_-]*):(?:\s*(.*))?$/);
    if (property) {
      properties[property[1]] = parseYamlScalar(property[2] ?? "");
    }
  }

  return null;
}

function isValidDate(value) {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(value)) {
    return false;
  }

  const date = new Date(`${value}T00:00:00.000Z`);
  return (
    !Number.isNaN(date.getTime()) && date.toISOString().slice(0, 10) === value
  );
}

function checkExecPlanRecords() {
  for (const folder of ["active", "completed", "tech-debt"]) {
    const directoryPath = path.join(docsRoot, "execution-plans", folder);
    for (const markdownFile of walkMarkdown(directoryPath)) {
      if (isIndexMarkdown(markdownFile)) {
        continue;
      }

      const source = readFileSync(markdownFile, "utf8");
      const frontMatter = parseFrontMatter(source);
      const displayPath = toRepoPath(markdownFile);
      if (!frontMatter) {
        errors.push(`${displayPath} must start with YAML front matter`);
      } else {
        for (const property of ["status", "updated", "owner"]) {
          if (!frontMatter[property]) {
            errors.push(`${displayPath} front matter is missing ${property}`);
          }
        }

        if (frontMatter.status && !catalogStatuses.has(frontMatter.status)) {
          errors.push(
            `${displayPath} has unsupported status: ${frontMatter.status}`,
          );
        }

        if (frontMatter.updated && !isValidDate(frontMatter.updated)) {
          errors.push(`${displayPath} updated must use YYYY-MM-DD`);
        }
      }

      const prose = maskNonProse(source);
      for (const heading of ["进度", "决策日志"]) {
        const headingPattern = new RegExp(`^##\\s+${heading}\\s*#*\\s*$`, "m");
        if (!headingPattern.test(prose)) {
          errors.push(
            `${displayPath} is missing required heading: ## ${heading}`,
          );
        }
      }
    }
  }
}

function resolveCatalogPath(recordPath, recordLabel) {
  if (typeof recordPath !== "string" || !recordPath.trim()) {
    errors.push(`${recordLabel} path must be a non-empty string`);
    return null;
  }

  const normalized = recordPath.trim().replace(/\\/g, "/");
  if (normalized.startsWith("/") || path.win32.isAbsolute(normalized)) {
    errors.push(`${recordLabel} path must be relative to docs/: ${recordPath}`);
    return null;
  }

  const resolved = path.resolve(docsRoot, normalized);
  if (!isWithin(docsRoot, resolved)) {
    errors.push(`${recordLabel} path escapes docs/: ${recordPath}`);
    return null;
  }

  return resolved;
}

function checkCatalog(markdownFiles) {
  const catalogPath = path.join(docsRoot, "catalog.json");
  if (!isFile(catalogPath)) {
    return [];
  }

  let catalog;
  try {
    catalog = JSON.parse(readFileSync(catalogPath, "utf8"));
  } catch (error) {
    errors.push(`docs/catalog.json is not valid JSON: ${error.message}`);
    return [];
  }

  if (!catalog || typeof catalog !== "object" || Array.isArray(catalog)) {
    errors.push("docs/catalog.json must be an object");
    return [];
  }

  if (catalog.version !== 1) {
    errors.push("docs/catalog.json version must be 1");
  }

  if (!Array.isArray(catalog.records)) {
    errors.push("docs/catalog.json records must be an array");
    return [];
  }

  const catalogPaths = new Set();
  const validRecords = [];
  for (const [index, record] of catalog.records.entries()) {
    const recordLabel = `docs/catalog.json records[${index}]`;
    if (!record || typeof record !== "object" || Array.isArray(record)) {
      errors.push(`${recordLabel} must be an object`);
      continue;
    }

    const targetPath = resolveCatalogPath(record.path, recordLabel);
    if (targetPath) {
      const normalizedPath = toDocsPath(targetPath);
      if (catalogPaths.has(normalizedPath)) {
        errors.push(
          `${recordLabel} duplicates catalog path: ${normalizedPath}`,
        );
      }
      catalogPaths.add(normalizedPath);

      if (!/\.md$/i.test(targetPath)) {
        errors.push(
          `${recordLabel} path must point to a Markdown record: ${record.path}`,
        );
      } else if (!isFile(targetPath)) {
        errors.push(`${recordLabel} points to a missing file: ${record.path}`);
      }
    }

    if (
      typeof record.status !== "string" ||
      !catalogStatuses.has(record.status)
    ) {
      errors.push(
        `${recordLabel} has unsupported status: ${record.status ?? "(missing)"}`,
      );
    }

    if (typeof record.summary !== "string" || !record.summary.trim()) {
      errors.push(`${recordLabel} summary must be a non-empty string`);
    }

    if (
      targetPath &&
      typeof record.status === "string" &&
      catalogStatuses.has(record.status)
    ) {
      validRecords.push({ index, path: toDocsPath(targetPath), record });
    }
  }

  for (const markdownFile of markdownFiles) {
    if (isIndexMarkdown(markdownFile)) {
      continue;
    }

    const relativePath = toDocsPath(markdownFile);
    if (!catalogPaths.has(relativePath)) {
      errors.push(`docs/catalog.json is missing a record for ${relativePath}`);
    }
  }

  for (const generatedPath of requiredGeneratedCatalogPaths) {
    const record = validRecords.find(({ path: recordPath }) => recordPath === generatedPath);
    if (!record) {
      errors.push(`docs/catalog.json is missing generated record for ${generatedPath}`);
      continue;
    }

    if (record.record.status !== "generated") {
      errors.push(
        `docs/catalog.json record for ${generatedPath} must use status generated`,
      );
    }
  }

  return validRecords;
}

function checkCatalogFreshness(records) {
  const today = new Date().toISOString().slice(0, 10);
  for (const { index, path: recordPath, record } of records) {
    const label = `docs/catalog.json records[${index}] (${recordPath})`;
    const reviewBy = record.review_by;
    if (typeof reviewBy !== "string" || !reviewBy.trim()) {
      if (statusesRequiringReviewBy.has(record.status)) {
        freshnessFindings.push(`${label} is missing required review_by`);
      }
      continue;
    }

    if (!isValidDate(reviewBy)) {
      freshnessFindings.push(`${label} review_by must use YYYY-MM-DD`);
    } else if (reviewBy < today) {
      freshnessFindings.push(`${label} review_by expired on ${reviewBy}`);
    }
  }
}

if (!isDirectory(docsRoot)) {
  errors.push("required docs/ directory is missing");
} else {
  for (const directory of requiredDirectories) {
    if (!isDirectory(path.join(docsRoot, directory))) {
      errors.push(`required docs directory is missing: docs/${directory}`);
    }
  }

  for (const file of requiredFiles) {
    if (!isFile(path.join(docsRoot, file))) {
      errors.push(`required docs file is missing: docs/${file}`);
    }
  }

  const markdownFiles = walkMarkdown(docsRoot);
  const navigationFiles = [path.join(root, "AGENTS.md"), ...markdownFiles]
    .filter(isFile);
  checkMarkdownLinks(navigationFiles);
  const catalogRecords = checkCatalog(markdownFiles);
  checkExecPlanRecords();
  if (checkFreshness) {
    checkCatalogFreshness(catalogRecords);
  }
}

if (freshnessFindings.length > 0) {
  console.log("Documentation freshness findings:");
  for (const finding of [...new Set(freshnessFindings)].sort()) {
    console.log(`  - ${finding}`);
  }
  console.log("");
}

if (errors.length > 0 || (strictFreshness && freshnessFindings.length > 0)) {
  console.log("Documentation check failed:");
  for (const error of [...new Set(errors)].sort()) {
    console.log(`  - ${error}`);
  }
  if (strictFreshness && freshnessFindings.length > 0) {
    console.log(
      "  - freshness findings fail because --strict-freshness is enabled",
    );
  }
  process.exit(1);
}

if (freshnessFindings.length > 0) {
  console.log(
    "Documentation check passed with non-blocking freshness findings.",
  );
} else {
  console.log("Documentation check passed.");
}
