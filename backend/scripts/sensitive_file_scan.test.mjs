import assert from "node:assert/strict";
import test from "node:test";

import { isPlaceholder, scanText } from "./sensitive_file_scan.mjs";

test("accepts placeholders and environment references", () => {
  assert.equal(isPlaceholder("your_password"), true);
  assert.equal(isPlaceholder("${DB_PASSWORD}"), true);
  assert.equal(isPlaceholder("Temp-" + "Only-987!"), false);

  const findings = scanText(
    [
      "DB_PASSWORD=your_password",
      "REDIS_PASSWORD=${REDIS_PASSWORD}",
      "APP_KEY=",
    ].join("\n"),
    "backend/.env.example",
  );

  assert.deepEqual(findings, []);
});

test("does not treat short literal credentials as placeholders", () => {
  assert.equal(isPlaceholder("abc"), false);
  assert.deepEqual(scanText("DB_PASSWORD=abc", "backend/.env"), [
    {
      path: "backend/.env",
      line: 1,
      rule: "credential-assignment",
    },
  ]);
});

test("finds environment, XML and PowerShell credentials without returning values", () => {
  const secret = ["Actual", "Credential", "987!"].join("-");
  const cases = [
    ["backend/.env", `DB_PASSWORD=${secret}`],
    ["backend/phpunit.xml", `<env name="DB_PASSWORD" value="${secret}"/>`],
    ["backend/scripts/check.ps1", `$databasePassword = "${secret}"`],
  ];

  for (const [file, content] of cases) {
    const findings = scanText(content, file);
    assert.equal(findings.length, 1, file);
    assert.equal(JSON.stringify(findings).includes(secret), false);
  }
});

test("finds credential pairs in operational documentation", () => {
  const secret = ["Release", "Password", "246!"].join("-");
  const findings = scanText(
    `管理员：release-admin / ${secret}`,
    "docs/runbook.md",
  );

  assert.deepEqual(findings, [
    {
      path: "docs/runbook.md",
      line: 1,
      rule: "credential-pair-in-prose",
    },
  ]);
});

test("finds private keys and credential-bearing URLs", () => {
  const keyHeader = ["-----BEGIN ", "PRIVATE KEY-----"].join("");
  const password = ["url", "password", "246!"].join("-");
  const findings = scanText(
    [keyHeader, `mysql://release:${password}@db.internal/database`].join("\n"),
    "config.txt",
  );

  assert.deepEqual(
    findings.map((finding) => finding.rule),
    ["private-key", "credential-in-url"],
  );
});

test("finds literal credentials in PHP, JavaScript, TypeScript and Vue source", () => {
  const cases = [
    ["backend/config/provider.php", "'api_key' => 'SourceSecret7!',"],
    ["frontend/src/provider.js", "const apiToken = 'SourceSecret7!';"],
    ["frontend/src/provider.ts", "clientSecret: 'SourceSecret7!',"],
    ["frontend/src/Provider.vue", "password = `SourceSecret7!`;"],
  ];

  for (const [file, content] of cases) {
    assert.equal(scanText(content, file).length, 1, file);
  }

  assert.deepEqual(
    scanText("const apiToken = process.env.API_TOKEN;", "frontend/src/provider.ts"),
    [],
  );
  assert.deepEqual(
    scanText("apiKey: config.apiKey,", "frontend/src/provider.ts"),
    [],
  );
  assert.equal(
    scanText(
      "'password' => Hash::make('SourceSecret7!'),",
      "backend/scripts/seed.php",
    ).length,
    1,
  );
});

test("does not treat translation messages as credential assignments", () => {
  const path = "backend/lang/zh_CN/validation.php";

  assert.deepEqual(scanText("'password' => '密码错误。',", path), []);

  const accessKey = ["AKIA", "A".repeat(16)].join("");
  assert.deepEqual(scanText(accessKey, path), [
    {
      path,
      line: 1,
      rule: "aws-access-key",
    },
  ]);
});

test("does not exempt high-entropy credentials in test files", () => {
  const findings = scanText(
    "const apiToken = 'A1b2C3d4E5f6G7h8I9j0K1l2M3n4O5p6';",
    "frontend/tests/security.spec.ts",
  );

  assert.equal(findings.length, 1);
});

test("does not allow an inline marker to suppress a credential", () => {
  const password = ["Review", "Required", "Password", "246!"].join("-");
  const findings = scanText(
    `PASSWORD=${password} # sensitive-scan: allow`,
    "docs/example.md",
  );

  assert.equal(findings.length, 1);
});
