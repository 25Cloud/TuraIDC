import assert from "node:assert/strict";
import { createHash } from "node:crypto";
import {
  mkdtemp,
  mkdir,
  readFile,
  readdir,
  rm,
  writeFile,
} from "node:fs/promises";
import { tmpdir } from "node:os";
import path from "node:path";
import { test } from "node:test";
import { brotliDecompressSync, gunzipSync } from "node:zlib";

import {
  createPrecompressedAssetsPlugin,
  resolveAssetFileName,
} from "../vite/buildAssets.mjs";

async function fixture(t) {
  const directory = await mkdtemp(path.join(tmpdir(), "tura-build-assets-"));
  assert.equal(path.dirname(directory), path.resolve(tmpdir()));
  t.after(() => rm(directory, { recursive: true, force: true }));
  return directory;
}

function configuredPlugin(root, outDir = "dist") {
  const plugin = createPrecompressedAssetsPlugin();
  plugin.configResolved({ root, build: { outDir } });
  return plugin;
}

test("静态资源按类型输出，并兼容大写扩展名与缺少名称", () => {
  for (const name of ["app.css", "APP.CSS"]) {
    assert.equal(
      resolveAssetFileName({ name }),
      "assets/css/[name]-[hash][extname]",
    );
  }
  for (const extension of [
    "png",
    "jpg",
    "jpeg",
    "webp",
    "gif",
    "svg",
    "avif",
  ]) {
    assert.equal(
      resolveAssetFileName({ name: "logo." + extension }),
      "assets/img/[name]-[hash][extname]",
    );
  }
  for (const extension of ["woff", "woff2", "ttf", "otf", "eot"]) {
    assert.equal(
      resolveAssetFileName({ name: "font." + extension }),
      "assets/fonts/[name]-[hash][extname]",
    );
  }
  for (const name of ["data.bin", "LICENSE", undefined]) {
    assert.equal(
      resolveAssetFileName({ name }),
      "assets/misc/[name]-[hash][extname]",
    );
  }
});

test("递归生成可还原的 gzip/Brotli 副本，并保留原文件", async (t) => {
  const root = await fixture(t);
  const directory = path.join(root, "dist", "assets");
  await mkdir(directory, { recursive: true });
  const content = Buffer.from("图拉云构建资源".repeat(200));
  await writeFile(path.join(directory, "app.JS"), content);
  // 恰好达到阈值也应压缩，防止边界从 >= 变成 >。
  await writeFile(path.join(directory, "threshold.css"), "a".repeat(1024));
  await writeFile(path.join(directory, "small.js"), "a".repeat(1023));
  await writeFile(path.join(directory, "image.png"), content);
  await writeFile(path.join(directory, "existing.gz"), content);

  await configuredPlugin(root).closeBundle();

  assert.deepEqual(await readFile(path.join(directory, "app.JS")), content);
  assert.deepEqual(
    gunzipSync(await readFile(path.join(directory, "app.JS.gz"))),
    content,
  );
  assert.deepEqual(
    brotliDecompressSync(await readFile(path.join(directory, "app.JS.br"))),
    content,
  );
  assert.equal(
    gunzipSync(await readFile(path.join(directory, "threshold.css.gz"))).length,
    1024,
  );
  const names = await readdir(directory);
  assert.equal(names.includes("small.js.gz"), false);
  assert.equal(names.includes("image.png.gz"), false);
  assert.equal(names.includes("existing.gz.gz"), false);
});

test("不可压缩的资源不生成更大的副本", async (t) => {
  const root = await fixture(t);
  await mkdir(path.join(root, "dist"));
  const content = Buffer.concat(
    Array.from({ length: 32 }, (_, index) =>
      createHash("sha256").update(String(index)).digest(),
    ),
  );
  await writeFile(path.join(root, "dist", "entropy.js"), content);

  await configuredPlugin(root).closeBundle();

  assert.deepEqual(await readdir(path.join(root, "dist")), ["entropy.js"]);
});

test("输出目录尚未生成或是普通文件时安全跳过", async (t) => {
  const root = await fixture(t);
  await configuredPlugin(root).closeBundle();
  await writeFile(path.join(root, "dist"), "不是目录");
  await configuredPlugin(root).closeBundle();
});

test("真实 Vite 构建遵循相对和绝对 outDir，并压缩最终产物", async (t) => {
  const { build } = await import("vite");
  const root = await fixture(t);
  const content = "构建集成测试正文".repeat(200);
  await writeFile(
    path.join(root, "index.html"),
    "<html><body>" + content + "</body></html>",
  );

  for (const outDir of ["custom-output", path.join(root, "absolute-output")]) {
    await build({
      configFile: false,
      root,
      logLevel: "silent",
      plugins: [createPrecompressedAssetsPlugin()],
      build: { outDir, emptyOutDir: true },
    });
    const directory = path.resolve(root, outDir);
    const original = await readFile(path.join(directory, "index.html"));
    assert.ok(original.toString().includes(content));
    assert.deepEqual(
      gunzipSync(await readFile(path.join(directory, "index.html.gz"))),
      original,
    );
    assert.deepEqual(
      brotliDecompressSync(
        await readFile(path.join(directory, "index.html.br")),
      ),
      original,
    );
  }
});
