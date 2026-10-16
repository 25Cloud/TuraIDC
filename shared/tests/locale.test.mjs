import assert from 'node:assert/strict';
import { describe, it } from 'node:test';

import {
  DEFAULT_LOCALE,
  SUPPORTED_LOCALES,
  applyDocumentLang,
  localeStorageKey,
  normalizeLocaleTag,
  readStoredLocale,
  resolveInitialLocale,
  writeStoredLocale,
} from '../locale/index.ts';

describe('normalizeLocaleTag', () => {
  it('accepts both underscore and hyphen spellings', () => {
    assert.equal(normalizeLocaleTag('zh_CN'), 'zh_CN');
    assert.equal(normalizeLocaleTag('zh-CN'), 'zh_CN');
    assert.equal(normalizeLocaleTag('zh_HK'), 'zh_HK');
    assert.equal(normalizeLocaleTag('zh-HK'), 'zh_HK');
  });

  it('strips the q value from Accept-Language fragments', () => {
    assert.equal(normalizeLocaleTag('zh-TW;q=0.9'), 'zh_TW');
    assert.equal(normalizeLocaleTag('en-US;q=1.0,en;q=0.8'), 'en_US');
  });

  it('falls back to the primary subtag for regional variants', () => {
    assert.equal(normalizeLocaleTag('en-GB'), 'en_US');
    assert.equal(normalizeLocaleTag('zh-Hant-HK-MO'), 'zh_HK');
    assert.equal(normalizeLocaleTag('zh-Hant'), 'zh_HK');
  });

  it('maps bare zh and zh-hans to simplified chinese', () => {
    assert.equal(normalizeLocaleTag('zh'), 'zh_CN');
    assert.equal(normalizeLocaleTag('zh-Hans-CN'), 'zh_CN');
    assert.equal(normalizeLocaleTag('zh-SG'), 'zh_CN');
  });

  it('is case insensitive and tolerates surrounding whitespace', () => {
    assert.equal(normalizeLocaleTag('  ZH-hk  '), 'zh_HK');
    assert.equal(normalizeLocaleTag('EN-US'), 'en_US');
  });

  it('returns null for unsupported or empty tags', () => {
    assert.equal(normalizeLocaleTag('ja'), null);
    assert.equal(normalizeLocaleTag('fr-FR'), null);
    assert.equal(normalizeLocaleTag(''), null);
    assert.equal(normalizeLocaleTag('*'), null);
    assert.equal(normalizeLocaleTag(undefined), null);
    assert.equal(normalizeLocaleTag(null), null);
    assert.equal(normalizeLocaleTag(42), null);
  });
});

describe('resolveInitialLocale', () => {
  const nav = (languages) => ({ languages, language: languages[0] ?? '' });

  it('prefers an explicit stored choice over browser preference', () => {
    const storage = new Map([['k', 'zh_HK']]);
    const locale = resolveInitialLocale({
      storageKey: 'k',
      storage: {
        getItem: (key) => storage.get(key) ?? null,
        setItem: (key, value) => storage.set(key, value),
        removeItem: (key) => storage.delete(key),
      },
      navigatorLike: nav(['en-US']),
    });
    assert.equal(locale, 'zh_HK');
  });

  it('falls back to the browser preference when nothing is stored', () => {
    const locale = resolveInitialLocale({
      storageKey: 'k',
      storage: { getItem: () => null, setItem: () => {}, removeItem: () => {} },
      navigatorLike: nav(['ja-JP', 'zh-TW', 'en-US']),
    });
    assert.equal(locale, 'zh_TW');
  });

  it('walks the whole preference list instead of only the first entry', () => {
    const locale = resolveInitialLocale({
      storageKey: 'k',
      storage: { getItem: () => null, setItem: () => {}, removeItem: () => {} },
      // 首选项不受支持时必须继续往后找，而不是直接回落默认
      navigatorLike: nav(['ja', 'ko', 'zh-Hant-HK']),
    });
    assert.equal(locale, 'zh_HK');
  });

  it('falls back to simplified chinese when nothing matches', () => {
    const locale = resolveInitialLocale({
      storageKey: 'k',
      storage: { getItem: () => null, setItem: () => {}, removeItem: () => {} },
      navigatorLike: nav(['ja-JP', 'fr-FR']),
    });
    assert.equal(locale, DEFAULT_LOCALE);
  });

  it('ignores a corrupt stored value and re-detects', () => {
    const locale = resolveInitialLocale({
      storageKey: 'k',
      storage: {
        getItem: () => 'not-a-locale',
        setItem: () => {},
        removeItem: () => {},
      },
      navigatorLike: nav(['en-US']),
    });
    assert.equal(locale, 'en_US');
  });

  it('survives a storage implementation that throws', () => {
    const locale = resolveInitialLocale({
      storageKey: 'k',
      storage: {
        getItem: () => {
          throw new Error('SecurityError');
        },
        setItem: () => {},
        removeItem: () => {},
      },
      navigatorLike: nav(['zh-TW']),
    });
    assert.equal(locale, 'zh_TW');
  });
});

describe('storage helpers', () => {
  it('round-trips a supported locale and drops an unsupported one', () => {
    const map = new Map();
    const storage = {
      getItem: (key) => map.get(key) ?? null,
      setItem: (key, value) => map.set(key, value),
      removeItem: (key) => map.delete(key),
    };

    const key = localeStorageKey('admin');
    assert.equal(readStoredLocale(key, storage), null);

    writeStoredLocale(key, 'zh_TW', storage);
    assert.equal(readStoredLocale(key, storage), 'zh_TW');

    map.set(key, 'bogus');
    assert.equal(readStoredLocale(key, storage), null);
  });

  it('gives each app its own key so co-hosted apps do not overwrite each other', () => {
    assert.notEqual(localeStorageKey('admin'), localeStorageKey('console'));
    assert.notEqual(localeStorageKey('console'), localeStorageKey('www'));
    for (const app of ['admin', 'console', 'www']) {
      assert.ok(localeStorageKey(app).startsWith('turaidc-locale:'));
    }
  });
});

describe('applyDocumentLang', () => {
  it('writes the hyphenated BCP 47 form', () => {
    const attributes = {};
    const doc = {
      documentElement: {
        setAttribute: (name, value) => {
          attributes[name] = value;
        },
      },
    };

    applyDocumentLang('zh_HK', doc);
    assert.equal(attributes.lang, 'zh-HK');

    applyDocumentLang('en_US', doc);
    assert.equal(attributes.lang, 'en-US');
  });

  it('does nothing when there is no document', () => {
    assert.doesNotThrow(() => applyDocumentLang('zh_CN', null));
  });
});

describe('supported locales', () => {
  it('keeps the four shipped languages in sync with the lang/*.json files', () => {
    assert.deepEqual([...SUPPORTED_LOCALES], ['zh_CN', 'zh_TW', 'zh_HK', 'en_US']);
  });
});