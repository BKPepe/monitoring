// The inline script that sets .dark on <html> before first paint (rendered by
// components/ThemeScript.astro). It lives here as one string because the
// meta CSP must carry its exact sha256: Astro hashes its own bundled scripts
// but not an is:inline one, and astro.config.mjs hashes this very string, so
// an edit here can never leave a stale hash behind. Without the hash the
// policy blocks the script whenever it runs after the <meta> CSP, which is
// what Cloudflare Rocket Loader does, and every page stays light.
export const THEME_SCRIPT = `
  (function () {
    const getThemePreference = () => {
      if (typeof localStorage !== 'undefined' && localStorage.getItem('theme')) {
        return localStorage.getItem('theme');
      }
      return 'auto';
    };

    const resolveTheme = (theme) => {
      if (theme === 'dark') return true;
      if (theme === 'light') return false;
      return window.matchMedia('(prefers-color-scheme: dark)').matches;
    };

    const setTheme = (theme) => {
      const isDark = resolveTheme(theme);
      if (isDark) {
        document.documentElement.classList.add('dark');
      } else {
        document.documentElement.classList.remove('dark');
      }
      document.documentElement.setAttribute('data-theme-resolved', isDark ? 'dark' : 'light');
    };

    const theme = getThemePreference();
    setTheme(theme);

    // Watch for system preference changes if auto
    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => {
      if (getThemePreference() === 'auto') {
        setTheme('auto');
      }
    });
  })();
`;
