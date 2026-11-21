import { describe, expect, it } from 'vitest';
import { pluralForm } from './plural';

describe('pluralForm', () => {
  it('gives Czech its three forms for whole counts', () => {
    expect([0, 1, 2, 4, 5, 11, 22].map((n) => pluralForm('cs', n))).toEqual([
      'other',
      'one',
      'few',
      'few',
      'other',
      'other',
      'other',
    ]);
  });

  it('gives English one and other', () => {
    expect([0, 1, 2, 5].map((n) => pluralForm('en', n))).toEqual(['other', 'one', 'other', 'other']);
  });
});
