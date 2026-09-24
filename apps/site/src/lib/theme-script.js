// The inline script that sets .dark on <html> before first paint (rendered by
// components/ThemeScript.astro). It lives here as one string because the
// meta CSP must carry its exact sha256: Astro hashes its own bundled scripts
// but not an is:inline one, and astro.config.mjs hashes this very string, so
// an edit here can never leave a stale hash behind. Without the hash the
// policy blocks the script whenever it runs after the <meta> CSP, which is
// what Cloudflare Rocket Loader does, and every page stays light.
//
// Dark is the default: no stored choice, an unknown value, or a storage that
// throws (private mode, blocked site data) all mean dark. "auto" follows the
// operating system and is only used when the visitor picked it.
export const THEME_SCRIPT = `
  (function () {
    var root = document.documentElement;
    var read = function () {
      try {
        var v = window.localStorage.getItem('theme');
        return v === 'light' || v === 'auto' ? v : 'dark';
      } catch (e) {
        return 'dark';
      }
    };
    var media = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;
    var apply = function (theme) {
      var isDark = theme === 'dark' || (theme === 'auto' && (!media || media.matches));
      if (isDark) {
        root.classList.add('dark');
      } else {
        root.classList.remove('dark');
      }
      root.setAttribute('data-theme', theme);
    };
    apply(read());
    if (media && media.addEventListener) {
      media.addEventListener('change', function () {
        if (read() === 'auto') apply('auto');
      });
    }
  })();
`;
