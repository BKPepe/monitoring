// @vitest-environment jsdom
import { afterEach, describe, expect, it } from 'vitest';
import { cleanup, render, screen } from '@testing-library/react';
import { LanguageProvider, useLanguage } from './language-context';

function Probe({ value }: { value: string }) {
  const { t } = useLanguage();
  return <p>{t('test.vars_probe', { name: value }, 'Ahoj {name}!')}</p>;
}

describe('t() a hodnoty proměnných', () => {
  afterEach(cleanup);

  it('dosadí hodnotu doslova, i se znaky $& $$ $1', () => {
    render(
      <LanguageProvider>
        <Probe value="A$&B $$ $1 $`" />
      </LanguageProvider>
    );
    expect(screen.getByText(/^Ahoj/).textContent).toBe('Ahoj A$&B $$ $1 $`!');
  });
});
