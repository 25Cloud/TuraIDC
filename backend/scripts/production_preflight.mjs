#!/usr/bin/env node

import { readFileSync } from "node:fs";
import path from "node:path";
import tls from "node:tls";
import { pathToFileURL } from "node:url";
import { getDomain, getPublicSuffix } from "tldts";

const PUBLIC_URL_KEYS = [
  ["APP_URL", "API"],
  ["FRONTEND_URL", "WWW"],
  ["CLIENT_CONSOLE_URL", "Console"],
  ["ADMIN_URL", "Admin"],
];

const NETWORK_ENV_KEYS = {
  APP_URL: "PREFLIGHT_API_URL",
  FRONTEND_URL: "PREFLIGHT_WWW_URL",
  CLIENT_CONSOLE_URL: "PREFLIGHT_CONSOLE_URL",
  ADMIN_URL: "PREFLIGHT_ADMIN_URL",
};

function stripQuotes(rawValue) {
  const value = String(rawValue ?? "").trim();
  if (
    value.length >= 2 &&
    ((value.startsWith('"') && value.endsWith('"')) ||
      (value.startsWith("'") && value.endsWith("'")))
  ) {
    return value.slice(1, -1);
  }
  return value;
}

export function parseEnv(text) {
  const values = {};
  for (const rawLine of String(text).split(/\r?\n/)) {
    const line = rawLine.trim();
    if (!line || line.startsWith("#")) {
      continue;
    }

    const normalized = line.startsWith("export ") ? line.slice(7).trim() : line;
    const separator = normalized.indexOf("=");
    if (separator <= 0) {
      continue;
    }

    const key = normalized.slice(0, separator).trim();
    const rawValue = normalized.slice(separator + 1).trim();
    if (/^[A-Za-z_][A-Za-z0-9_]*$/.test(key)) {
      values[key] = stripQuotes(rawValue);
    }
  }
  return values;
}

function isObviousPlaceholder(rawValue) {
  const value = stripQuotes(rawValue);
  return (
    value === "" ||
    /^(?:change[_-]?me|dummy|example|fake|password|placeholder|redacted|secret|test|testing|token)$/i.test(
      value,
    ) ||
    /^your[_-]/i.test(value) ||
    /^x{3,}$/i.test(value) ||
    /^\*{3,}$/.test(value) ||
    /^<[^>]+>$/.test(value)
  );
}

function addFailure(failures, check, message) {
  failures.push({ check, message });
}

function isPlaceholderHostname(hostname) {
  return (
    hostname === "localhost" ||
    hostname === "127.0.0.1" ||
    hostname === "0.0.0.0" ||
    hostname.endsWith(".localhost") ||
    hostname.endsWith(".test") ||
    hostname.endsWith(".invalid") ||
    hostname === "example.com" ||
    hostname.endsWith(".example.com") ||
    hostname.includes("your-domain")
  );
}

function belongsToDomain(hostname, domain) {
  return hostname === domain || hostname.endsWith(`.${domain}`);
}

function isRegistrableCookieDomain(domain) {
  return (
    getDomain(domain, { allowPrivateDomains: false }) === domain &&
    getPublicSuffix(domain, { allowPrivateDomains: false }) !== domain
  );
}

function isLaravelAppKey(rawValue) {
  const match = /^base64:([A-Za-z0-9+/]+={0,2})$/.exec(
    String(rawValue ?? "").trim(),
  );
  if (!match || match[1].length % 4 !== 0) {
    return false;
  }

  const decoded = Buffer.from(match[1], "base64");
  return decoded.length === 32 && decoded.toString("base64") === match[1];
}

export function normalizePublicOrigin(rawValue, label) {
  let url;
  try {
    url = new URL(String(rawValue ?? "").trim());
  } catch {
    throw new Error(`${label} 必须是有效的 HTTPS 根地址。`);
  }

  const hostname = url.hostname.toLowerCase();
  if (
    url.protocol !== "https:" ||
    !hostname ||
    url.username ||
    url.password ||
    url.pathname !== "/" ||
    url.search ||
    url.hash
  ) {
    throw new Error(`${label} 必须是无路径、无凭据的 HTTPS 根地址。`);
  }

  if (isPlaceholderHostname(hostname)) {
    throw new Error(`${label} 仍是本地或占位地址。`);
  }

  return url.origin;
}

function validateExactValue(values, failures, key, expected) {
  if (
    String(values[key] ?? "")
      .trim()
      .toLowerCase() !== expected
  ) {
    addFailure(failures, key, `${key} 必须为 ${expected}。`);
  }
}

function validateRequiredValue(values, failures, key) {
  if (isObviousPlaceholder(values[key])) {
    addFailure(failures, key, `${key} 未设置或仍是占位值。`);
  }
}

function validateCredential(values, failures, key, minimumLength) {
  const value = String(values[key] ?? "").trim();
  if (isObviousPlaceholder(value) || value.length < minimumLength) {
    addFailure(failures, key, `${key} 未设置、仍是占位值或长度不足。`);
  }
}

export function validateProductionEnv(values) {
  const failures = [];

  validateExactValue(values, failures, "APP_ENV", "production");
  validateExactValue(values, failures, "APP_DEBUG", "false");
  validateExactValue(values, failures, "DB_CONNECTION", "mysql");
  validateExactValue(values, failures, "CACHE_STORE", "redis");
  validateExactValue(values, failures, "SESSION_DRIVER", "file");
  validateExactValue(values, failures, "QUEUE_CONNECTION", "database");
  validateExactValue(values, failures, "SESSION_SECURE_COOKIE", "true");
  validateExactValue(values, failures, "SESSION_HTTP_ONLY", "true");
  validateExactValue(values, failures, "SESSION_SAME_SITE", "lax");

  validateRequiredValue(values, failures, "DB_HOST");
  validateRequiredValue(values, failures, "DB_DATABASE");
  validateRequiredValue(values, failures, "DB_USERNAME");
  validateRequiredValue(values, failures, "REDIS_HOST");
  validateCredential(values, failures, "DB_PASSWORD", 12);
  validateCredential(values, failures, "REDIS_PASSWORD", 12);

  if (!isLaravelAppKey(values.APP_KEY)) {
    addFailure(
      failures,
      "APP_KEY",
      "APP_KEY 必须是已生成的 Laravel base64 应用密钥。",
    );
  }

  const origins = {};
  for (const [key, label] of PUBLIC_URL_KEYS) {
    try {
      origins[key] = normalizePublicOrigin(values[key], key);
    } catch (error) {
      addFailure(
        failures,
        key,
        error instanceof Error ? error.message : `${key} 无效。`,
      );
    }
  }

  const originValues = Object.values(origins);
  if (
    originValues.length === PUBLIC_URL_KEYS.length &&
    new Set(originValues).size !== originValues.length
  ) {
    addFailure(failures, "PUBLIC_URLS", "四个公开地址必须使用不同的 origin。");
  }

  const cookieDomain = String(values.CLIENT_SESSION_COOKIE_DOMAIN ?? "")
    .trim()
    .toLowerCase();
  if (
    !/^\.[a-z0-9.-]+$/.test(cookieDomain) ||
    isPlaceholderHostname(cookieDomain.slice(1)) ||
    !isRegistrableCookieDomain(cookieDomain.slice(1))
  ) {
    addFailure(
      failures,
      "CLIENT_SESSION_COOKIE_DOMAIN",
      "CLIENT_SESSION_COOKIE_DOMAIN 必须是正式主域名，格式如 .domain.com。",
    );
  } else {
    for (const key of ["FRONTEND_URL", "CLIENT_CONSOLE_URL"]) {
      if (
        origins[key] &&
        !belongsToDomain(new URL(origins[key]).hostname, cookieDomain.slice(1))
      ) {
        addFailure(
          failures,
          "CLIENT_SESSION_COOKIE_DOMAIN",
          "官网和控制台域名必须属于 CLIENT_SESSION_COOKIE_DOMAIN。",
        );
        break;
      }
    }
  }

  const sessionDomain = String(values.SESSION_DOMAIN ?? "")
    .trim()
    .toLowerCase();
  if (sessionDomain !== cookieDomain) {
    addFailure(
      failures,
      "SESSION_DOMAIN",
      "SESSION_DOMAIN 必须与 CLIENT_SESSION_COOKIE_DOMAIN 一致。",
    );
  }

  return failures;
}

function readHeader(headers, name) {
  if (typeof headers?.get === "function") {
    return String(headers.get(name) ?? "");
  }

  const target = name.toLowerCase();
  const entry = Object.entries(headers ?? {}).find(
    ([key]) => key.toLowerCase() === target,
  );
  return String(entry?.[1] ?? "");
}

export function validateCorsResponse(origin, status, headers) {
  const failures = [];
  if (status < 200 || status >= 300) {
    failures.push(`预检请求返回 HTTP ${status}。`);
  }

  if (readHeader(headers, "access-control-allow-origin") !== origin) {
    failures.push("Access-Control-Allow-Origin 未精确返回请求 Origin。");
  }

  const methods = readHeader(
    headers,
    "access-control-allow-methods",
  ).toUpperCase();
  if (!methods.split(/\s*,\s*/).includes("GET")) {
    failures.push("Access-Control-Allow-Methods 未允许 GET。");
  }

  const allowedHeaders = readHeader(
    headers,
    "access-control-allow-headers",
  ).toLowerCase();
  const headerNames = allowedHeaders.split(/\s*,\s*/);
  if (
    !headerNames.includes("authorization") ||
    !headerNames.includes("content-type")
  ) {
    failures.push(
      "Access-Control-Allow-Headers 未允许 Authorization 和 Content-Type。",
    );
  }

  if (
    readHeader(headers, "access-control-allow-credentials").toLowerCase() !==
    "true"
  ) {
    failures.push("Access-Control-Allow-Credentials 必须为 true。");
  }

  return failures;
}

export function isReadyPayload(payload) {
  const checks = payload?.data?.checks;
  return (
    payload?.code === 0 &&
    payload?.data?.status === "ready" &&
    typeof checks === "object" &&
    checks !== null &&
    ["database", "cache", "storage", "scheduler"].every(
      (name) => checks[name] === true,
    )
  );
}

function checkTls(origin, minimumCertificateDays) {
  const url = new URL(origin);
  const port = Number(url.port || 443);

  return new Promise((resolve, reject) => {
    const socket = tls.connect({
      host: url.hostname,
      port,
      rejectUnauthorized: true,
      servername: url.hostname,
    });

    socket.setTimeout(10_000);
    socket.once("secureConnect", () => {
      const certificate = socket.getPeerCertificate();
      socket.end();

      if (!certificate || !certificate.valid_to) {
        reject(new Error("TLS 未返回可验证的服务器证书。"));
        return;
      }

      const expiresAt = Date.parse(certificate.valid_to);
      const remainingDays = (expiresAt - Date.now()) / 86_400_000;
      if (
        !Number.isFinite(remainingDays) ||
        remainingDays < minimumCertificateDays
      ) {
        reject(new Error(`TLS 证书有效期不足 ${minimumCertificateDays} 天。`));
        return;
      }

      resolve();
    });
    socket.once("timeout", () => socket.destroy(new Error("TLS 连接超时。")));
    socket.once("error", reject);
  });
}

async function checkFrontend(origin) {
  const response = await fetch(origin, {
    headers: { "user-agent": "turaidc-production-preflight/1.0" },
    redirect: "follow",
    signal: AbortSignal.timeout(10_000),
  });
  const finalUrl = new URL(response.url);
  const contentType = String(
    response.headers.get("content-type") ?? "",
  ).toLowerCase();
  await response.body?.cancel();

  if (!response.ok) {
    throw new Error(`站点返回 HTTP ${response.status}。`);
  }
  if (finalUrl.protocol !== "https:") {
    throw new Error("站点重定向到了非 HTTPS 地址。");
  }
  if (finalUrl.origin !== origin) {
    throw new Error("站点重定向到了未声明的 origin。");
  }
  if (!contentType.includes("text/html")) {
    throw new Error("站点未返回 HTML 内容。");
  }
}

async function checkApiHealth(apiOrigin) {
  const response = await fetch(`${apiOrigin}/api/ready`, {
    headers: {
      accept: "application/json",
      "user-agent": "turaidc-production-preflight/1.0",
    },
    redirect: "follow",
    signal: AbortSignal.timeout(10_000),
  });
  const contentType = String(
    response.headers.get("content-type") ?? "",
  ).toLowerCase();
  const finalUrl = new URL(response.url);

  if (!response.ok) {
    await response.body?.cancel();
    throw new Error(`API 就绪检查返回 HTTP ${response.status}。`);
  }
  if (!contentType.includes("application/json")) {
    await response.body?.cancel();
    throw new Error("API 就绪检查未返回 JSON。");
  }
  if (finalUrl.protocol !== "https:" || finalUrl.origin !== apiOrigin) {
    await response.body?.cancel();
    throw new Error("API 就绪检查重定向到了未声明或非 HTTPS 的 origin。");
  }

  const payload = await response.json();
  if (!isReadyPayload(payload)) {
    throw new Error("API 就绪检查响应不符合 ready 契约。");
  }
}

async function checkCors(apiOrigin, frontendOrigin) {
  const response = await fetch(`${apiOrigin}/api/ready`, {
    method: "OPTIONS",
    headers: {
      origin: frontendOrigin,
      "access-control-request-method": "GET",
      "access-control-request-headers": "authorization,content-type",
      "user-agent": "turaidc-production-preflight/1.0",
    },
    redirect: "manual",
    signal: AbortSignal.timeout(10_000),
  });
  const failures = validateCorsResponse(
    frontendOrigin,
    response.status,
    response.headers,
  );
  await response.body?.cancel();

  if (failures.length > 0) {
    throw new Error(failures.join(" "));
  }
}

export async function runNetworkChecks(origins, minimumCertificateDays = 14) {
  const checks = [];
  for (const [key, label] of PUBLIC_URL_KEYS) {
    if (origins[key]) {
      checks.push({
        name: `TLS:${label}`,
        run: () => checkTls(origins[key], minimumCertificateDays),
      });
    }
  }

  if (origins.APP_URL) {
    checks.push({
      name: "HTTP:API ready",
      run: () => checkApiHealth(origins.APP_URL),
    });
  }
  for (const [key, label] of PUBLIC_URL_KEYS.slice(1)) {
    if (origins[key]) {
      checks.push({
        name: `HTTP:${label}`,
        run: () => checkFrontend(origins[key]),
      });
      if (origins.APP_URL) {
        checks.push({
          name: `CORS:${label}`,
          run: () => checkCors(origins.APP_URL, origins[key]),
        });
      }
    }
  }

  return Promise.all(
    checks.map(async (check) => {
      try {
        await check.run();
        return { check: check.name, ok: true };
      } catch (error) {
        return {
          check: check.name,
          ok: false,
          message: error instanceof Error ? error.message : "检查失败。",
        };
      }
    }),
  );
}

function parseArguments(argv) {
  const options = {
    envPath: "",
    json: false,
    minimumCertificateDays: 14,
    network: false,
  };

  for (let index = 0; index < argv.length; index += 1) {
    const argument = argv[index];
    if (argument === "--network") {
      options.network = true;
    } else if (argument === "--json") {
      options.json = true;
    } else if (argument === "--env") {
      options.envPath = argv[index + 1] ?? "";
      index += 1;
    } else if (argument.startsWith("--env=")) {
      options.envPath = argument.slice("--env=".length);
    } else if (argument.startsWith("--min-cert-days=")) {
      options.minimumCertificateDays = Number(
        argument.slice("--min-cert-days=".length),
      );
    } else {
      throw new Error(`不支持的参数：${argument}`);
    }
  }

  if (
    !Number.isInteger(options.minimumCertificateDays) ||
    options.minimumCertificateDays < 1 ||
    options.minimumCertificateDays > 365
  ) {
    throw new Error("--min-cert-days 必须是 1 到 365 的整数。");
  }
  if (!options.envPath && !options.network) {
    throw new Error("至少指定 --env=<path> 或 --network。");
  }

  return options;
}

function loadEnvValues(envPath) {
  const fileValues = parseEnv(readFileSync(path.resolve(envPath), "utf8"));
  const values = { ...fileValues };
  const relevantKeys = [
    ...PUBLIC_URL_KEYS.map(([key]) => key),
    "APP_DEBUG",
    "APP_ENV",
    "APP_KEY",
    "CACHE_STORE",
    "CLIENT_SESSION_COOKIE_DOMAIN",
    "DB_CONNECTION",
    "DB_DATABASE",
    "DB_HOST",
    "DB_PASSWORD",
    "DB_USERNAME",
    "QUEUE_CONNECTION",
    "REDIS_HOST",
    "REDIS_PASSWORD",
    "SESSION_DOMAIN",
    "SESSION_DRIVER",
    "SESSION_HTTP_ONLY",
    "SESSION_SAME_SITE",
    "SESSION_SECURE_COOKIE",
  ];

  for (const key of relevantKeys) {
    if (process.env[key] !== undefined) {
      values[key] = process.env[key];
    }
  }
  return values;
}

export function loadNetworkOrigins(values, runtimeEnvironment = process.env) {
  const origins = {};
  const failures = [];
  for (const [key, label] of PUBLIC_URL_KEYS) {
    const rawValue = values[key] || runtimeEnvironment[NETWORK_ENV_KEYS[key]];
    try {
      origins[key] = normalizePublicOrigin(rawValue, NETWORK_ENV_KEYS[key]);
    } catch (error) {
      failures.push({
        check: `NETWORK_URL:${label}`,
        ok: false,
        message:
          error instanceof Error
            ? error.message
            : `${NETWORK_ENV_KEYS[key]} 无效。`,
      });
    }
  }
  return { origins, failures };
}

async function main() {
  const options = parseArguments(process.argv.slice(2));
  const envValues = options.envPath ? loadEnvValues(options.envPath) : {};
  const results = [];

  if (options.envPath) {
    const failures = validateProductionEnv(envValues);
    if (failures.length === 0) {
      results.push({ check: "production-env", ok: true });
    } else {
      results.push(...failures.map((failure) => ({ ...failure, ok: false })));
    }
  }

  if (options.network) {
    const networkConfiguration = loadNetworkOrigins(envValues);
    results.push(...networkConfiguration.failures);
    if (Object.keys(networkConfiguration.origins).length > 0) {
      results.push(
        ...(await runNetworkChecks(
          networkConfiguration.origins,
          options.minimumCertificateDays,
        )),
      );
    }
  }

  const failed = results.filter((result) => !result.ok);
  if (options.json) {
    console.log(JSON.stringify({ ok: failed.length === 0, results }, null, 2));
  } else {
    for (const result of results) {
      if (result.ok) {
        console.log(`[PASS] ${result.check}`);
      } else {
        console.error(`[FAIL] ${result.check}: ${result.message}`);
      }
    }
  }

  if (failed.length > 0) {
    process.exitCode = 1;
  }
}

const invokedPath = process.argv[1]
  ? pathToFileURL(path.resolve(process.argv[1])).href
  : "";
if (import.meta.url === invokedPath) {
  main().catch((error) => {
    console.error(error instanceof Error ? error.message : String(error));
    process.exitCode = 2;
  });
}
