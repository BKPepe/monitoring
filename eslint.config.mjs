import js from '@eslint/js';
import tseslint from 'typescript-eslint';
import reactHooks from 'eslint-plugin-react-hooks';

/**
 * Sdílená ESLint konfigurace pro celý monorepo.
 *
 * Pravidla jsou vybraná podle chyb, které tenhle projekt opravdu vyrobil -
 * ne podle univerzálního "recommended" balíku. Proto jsou zapnuté hooks
 * pravidla (komponenta definovaná uvnitř renderu zabíjela fokus v
 * nastavení) a hlídání nepoužitého kódu (repo neslo mrtvé komponenty
 * i mrtvé exporty měsíce).
 */
export default tseslint.config(
  {
    ignores: [
      '**/dist/**',
      '**/node_modules/**',
      '**/.astro/**',
      '**/.wrangler/**',
      'apps/status/**',
      'apps/server/**',
      'agents/**',
    ],
  },
  js.configs.recommended,
  ...tseslint.configs.recommended,
  {
    files: ['**/*.{ts,tsx}'],
    plugins: { 'react-hooks': reactHooks },
    rules: {
      ...reactHooks.configs.recommended.rules,
      // Mrtvý kód je v tomhle repu opakovaný problém - hlásí se, ale
      // neblokuje build (podtržítko = záměrně nepoužité).
      '@typescript-eslint/no-unused-vars': ['warn', { argsIgnorePattern: '^_', varsIgnorePattern: '^_' }],
      // `any` je tu na hraně: details z agenta jsou opravdu dynamické.
      '@typescript-eslint/no-explicit-any': 'off',
      // Prázdný catch skryl chybu Prometheus tokenu na celé týdny.
      'no-empty': ['error', { allowEmptyCatch: false }],
      // Zavedeno majorem react-hooks 7. Zbylých 13 nálezů jsou resety stavu
      // při změně vstupu (vyprázdnit data před novým fetchem, vrátit se na
      // první stránku po změně filtru) - fungují správně, jen stojí jeden
      // render navíc. Přepis znamená přestavbu fetch hooků, což je vlastní
      // úkol; do té doby ať jsou vidět jako varování, ne zamlčené.
      'react-hooks/set-state-in-effect': 'warn',
      eqeqeq: ['error', 'always', { null: 'ignore' }],
    },
  },
  {
    // UX wave 2 guard rail: no emoji in a section title. They rendered as
    // colour glyphs of different sizes per platform, doubled the meaning of
    // the lucide icon beside them and made every title as loud as an alarm.
    // Headings and the `title` of a component (Section, Card, Faceplate...)
    // use a lucide icon instead; a native `title` tooltip is not a heading.
    files: ['apps/monitor/src/**/*.tsx'],
    rules: {
      'no-restricted-syntax': [
        'error',
        ...[
          ':matches(JSXText, Literal)[value=/\\p{Extended_Pictographic}/u]',
          'TemplateElement[value.raw=/\\p{Extended_Pictographic}/u]',
        ].flatMap((node) => [
          {
            selector: `JSXElement[openingElement.name.name=/^(h[1-6]|CardTitle|DialogTitle)$/] ${node}`,
            message: 'Emoji v nadpisu sekce: použijte ikonu z lucide-react.',
          },
          {
            selector: `JSXOpeningElement[name.name=/^[A-Z]/] > JSXAttribute[name.name='title'] ${node}`,
            message: 'Emoji v titulku sekce: použijte ikonu z lucide-react.',
          },
        ]),
      ],
    },
  },
  {
    files: ['**/*.astro', '**/*.js'],
    rules: { '@typescript-eslint/no-unused-vars': 'off' },
  }
);
