import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { RouterProvider } from 'react-router';
import '@fontsource-variable/inter';
import '@fontsource-variable/jetbrains-mono';
import './styles/theme.css';
import { router } from './routes';
import { LanguageProvider } from './context/language-context';

const rootElement = document.getElementById('root');
if (!rootElement) throw new Error('Chybí #root — zkontroluj index.html.');

createRoot(rootElement).render(
  <StrictMode>
    <LanguageProvider>
      <RouterProvider router={router} />
    </LanguageProvider>
  </StrictMode>
);
