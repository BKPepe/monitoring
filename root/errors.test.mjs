// The static error pages behind the root ErrorDocument lines. They are plain
// committed HTML (no build step on the way to the server), so this is what
// keeps them self-contained, bilingual and identical in look.
/* global URL */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const CODES = ['403', '404', '410', '500', '503'];
const read = (code) => readFileSync(new URL(`./errors/${code}.html`, import.meta.url), 'utf8');
const style = (html) => /<style>([\s\S]*?)<\/style>/.exec(html)?.[1] ?? '';

for (const code of CODES) {
  test(`chybová stránka ${code}: kód v titulku i v obsahu, česky i anglicky, noindex`, () => {
    const html = read(code);
    assert.match(html, /^<!doctype html>/i);
    assert.match(html, /<html lang="cs">/);
    assert.match(html, /<div class="en" lang="en">/);
    assert.match(html, new RegExp(`<title>${code} · [^<]+ \\| Blood Kings</title>`));
    assert.match(html, new RegExp(`<p class="code">${code}</p>`));
    assert.match(html, /<meta name="robots" content="noindex">/);
    assert.match(html, /<meta name="viewport" content="width=device-width, initial-scale=1">/);
    assert.ok(!/litespeed|apache|nginx|php/i.test(html), 'neprozrazuje software serveru');
  });

  test(`chybová stránka ${code}: žádný externí požadavek, odkazy jen na web a stav služeb`, () => {
    const html = read(code);
    assert.ok(!/\ssrc=|<script|@import|url\(|<link rel="stylesheet"/i.test(html), 'nic se nedotahuje');
    const hrefs = [...html.matchAll(/href="([^"]*)"/g)].map((m) => m[1]);
    assert.deepEqual(hrefs, ['data:,', 'https://bloodkings.eu/', 'https://bloodkings.eu/app/public']);
  });
}

test('chybové stránky: sdílejí jeden a tentýž styl ve značkové červené', () => {
  const first = style(read(CODES[0]));
  assert.ok(first.includes('--brand: #a30d18;') && first.includes('--brand: #d62b30;'));
  for (const code of CODES.slice(1)) assert.equal(style(read(code)), first, code);
});
