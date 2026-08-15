import assert from "node:assert/strict";
import test from "node:test";

import {
  isReadyPayload,
  loadNetworkOrigins,
  normalizePublicOrigin,
  parseEnv,
  validateCorsResponse,
  validateProductionEnv,
} from "./production_preflight.mjs";

function validProductionEnv() {
  return {
    APP_ENV: "production",
    APP_DEBUG: "false",
    APP_KEY: `base64:${"A".repeat(43)}=`,
    APP_URL: "https://api.release-domain.com",
    FRONTEND_URL: "https://www.release-domain.com",
    CLIENT_CONSOLE_URL: "https://console.release-domain.com",
    ADMIN_URL: "https://admin.release-domain.com",
    CLIENT_SESSION_COOKIE_DOMAIN: ".release-domain.com",
    SESSION_DOMAIN: ".release-domain.com",
    SESSION_SECURE_COOKIE: "true",
    SESSION_HTTP_ONLY: "true",
    SESSION_SAME_SITE: "lax",
    DB_CONNECTION: "mysql",
    DB_HOST: "127.0.0.1",
    DB_DATABASE: "turaidc",
    DB_USERNAME: "TURAIDC_app",
    DB_PASSWORD: ["database", "credential", "987!"].join("-"),
    REDIS_HOST: "127.0.0.1",
    REDIS_PASSWORD: ["redis", "credential", "987!"].join("-"),
    CACHE_STORE: "redis",
    SESSION_DRIVER: "file",
    QUEUE_CONNECTION: "database",
  };
}

test("parses quoted dotenv values without logging or expanding them", () => {
  assert.deepEqual(
    parseEnv(
      [
        "# comment",
        "export APP_ENV=production",
        'APP_DEBUG="false"',
        "DB_HOST='127.0.0.1'",
      ].join("\n"),
    ),
    {
      APP_ENV: "production",
      APP_DEBUG: "false",
      DB_HOST: "127.0.0.1",
    },
  );
});

test("accepts a complete production configuration", () => {
  assert.deepEqual(validateProductionEnv(validProductionEnv()), []);
});

test("rejects unsafe production values without returning credential contents", () => {
  const values = validProductionEnv();
  const secret = ["short", "credential"].join("-");
  values.APP_DEBUG = "true";
  values.APP_URL = "http://127.0.0.1:8000";
  values.DB_PASSWORD = secret.slice(0, 8);

  const failures = validateProductionEnv(values);
  assert.equal(
    failures.some((failure) => failure.check === "APP_DEBUG"),
    true,
  );
  assert.equal(
    failures.some((failure) => failure.check === "APP_URL"),
    true,
  );
  assert.equal(
    failures.some((failure) => failure.check === "DB_PASSWORD"),
    true,
  );
  assert.equal(JSON.stringify(failures).includes(values.DB_PASSWORD), false);
});

test("rejects cookie-domain suffix lookalikes", () => {
  const values = validProductionEnv();
  values.FRONTEND_URL = "https://www.evilrelease-domain.com";

  const failures = validateProductionEnv(values);
  assert.equal(
    failures.some(
      (failure) => failure.check === "CLIENT_SESSION_COOKIE_DOMAIN",
    ),
    true,
  );
});

test("rejects public-suffix cookie domains and malformed Laravel keys", () => {
  const values = validProductionEnv();
  values.CLIENT_SESSION_COOKIE_DOMAIN = ".com";
  values.SESSION_DOMAIN = ".com";
  values.APP_KEY = "base64:not-a-32-byte-laravel-key=";

  const failures = validateProductionEnv(values);
  assert.equal(
    failures.some(
      (failure) => failure.check === "CLIENT_SESSION_COOKIE_DOMAIN",
    ),
    true,
  );
  assert.equal(
    failures.some((failure) => failure.check === "APP_KEY"),
    true,
  );
});

test("normalizes HTTPS roots and rejects paths or placeholders", () => {
  assert.equal(
    normalizePublicOrigin("https://api.release-domain.com", "APP_URL"),
    "https://api.release-domain.com",
  );
  assert.throws(() =>
    normalizePublicOrigin("https://api.release-domain.com/api", "APP_URL"),
  );
  assert.throws(() =>
    normalizePublicOrigin("https://api.example.test", "APP_URL"),
  );
});

test("collects every invalid public network URL", () => {
  const resolved = loadNetworkOrigins(
    {},
    {
      PREFLIGHT_API_URL: "http://127.0.0.1:8000",
      PREFLIGHT_WWW_URL: "",
      PREFLIGHT_CONSOLE_URL: "https://console.example.test",
      PREFLIGHT_ADMIN_URL: "https://admin.release-domain.com/path",
    },
  );

  assert.equal(resolved.failures.length, 4);
  assert.deepEqual(resolved.origins, {});
});

test("validates exact credentialed CORS preflight headers", () => {
  const origin = "https://www.release-domain.com";
  const goodHeaders = {
    "access-control-allow-origin": origin,
    "access-control-allow-methods": "GET, POST, OPTIONS",
    "access-control-allow-headers": "Authorization, Content-Type",
    "access-control-allow-credentials": "true",
  };
  assert.deepEqual(validateCorsResponse(origin, 204, goodHeaders), []);

  const failures = validateCorsResponse(origin, 405, {
    ...goodHeaders,
    "access-control-allow-origin": "*",
  });
  assert.equal(failures.length, 2);

  const wildcardFailures = validateCorsResponse(origin, 204, {
    ...goodHeaders,
    "access-control-allow-methods": "*",
    "access-control-allow-headers": "*",
  });
  assert.equal(wildcardFailures.length, 2);
});

test("requires the API readiness response contract", () => {
  assert.equal(
    isReadyPayload({
      code: 0,
      data: {
        status: "ready",
        checks: {
          database: true,
          cache: true,
          storage: true,
          scheduler: true,
        },
      },
    }),
    true,
  );
  assert.equal(
    isReadyPayload({ code: 0, data: { status: "alive", checks: {} } }),
    false,
  );
  assert.equal(
    isReadyPayload({
      code: 0,
      data: {
        status: "ready",
        checks: {
          database: true,
          cache: false,
          storage: true,
          scheduler: true,
        },
      },
    }),
    false,
  );
});
