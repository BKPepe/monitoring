import { describe, expect, it } from 'vitest';
import { contributorsUnavailableHtml } from './github';

describe('contributorsUnavailableHtml (R38)', () => {
  it('says the list failed and links it, with no made-up contributor', () => {
    const html = contributorsUnavailableHtml('cs', true);
    expect(html).toContain('Seznam přispěvatelů se teď nepodařilo načíst.');
    expect(html).toContain('https://github.com/BKPepe/monitoring/graphs/contributors');
    expect(html).not.toContain('BKPepe</h5>');
  });
  it('an empty answer is told apart from a failure, in English too', () => {
    expect(contributorsUnavailableHtml('en', false)).toContain('GitHub returned no contributors right now.');
    expect(contributorsUnavailableHtml('en', true)).toContain('could not be loaded');
  });
});
