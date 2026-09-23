import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { RouterProvider } from 'react-router';
import '@fontsource-variable/inter';
import '@fontsource-variable/jetbrains-mono';
import './styles/theme.css';
import { router } from './routes';
import { LanguageProvider } from './context/language-context';
import { TooltipProvider } from '@/components/ui/tooltip';
import { installCsrfFetch } from './api/csrf-fetch';
import { installSessionGuards } from './lib/session-guard';
import { installStaleBuildRecovery } from './lib/stale-build';

// Must run before the first render - components fire POSTs from effects.
installCsrfFetch();
// A page restored from the back/forward cache, or a tab another tab signed out,
// reloads and asks the server who is signed in.
installSessionGuards();
// A deploy while the tab was open removes the files it would lazy-load next:
// reload once per build to get the new ones, in every browser (W1-F4).
installStaleBuildRecovery(__APP_VERSION__);

const rootElement = document.getElementById('root');
if (!rootElement) throw new Error('Chybí #root — zkontroluj index.html.');

createRoot(rootElement).render(
  <StrictMode>
    <LanguageProvider>
      <TooltipProvider delayDuration={200}>
        <RouterProvider router={router} />
      </TooltipProvider>
    </LanguageProvider>
  </StrictMode>
);
