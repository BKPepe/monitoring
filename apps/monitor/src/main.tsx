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

// Must run before the first render - components fire POSTs from effects.
installCsrfFetch();

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
