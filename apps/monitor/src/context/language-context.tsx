import React, { createContext, useContext, useState } from 'react';

export type Language = 'cs' | 'en';

interface LanguageContextType {
  lang: Language;
  setLang: (lang: Language) => void;
  t: (key: string, fallbackEn?: string) => string;
}

const translations: Record<string, { cs: string; en: string }> = {
  'nav.dashboard': { cs: 'Přehled', en: 'Dashboard' },
  'nav.infrastructure': { cs: 'Infrastruktura', en: 'Infrastructure' },
  'nav.websites': { cs: 'Weby & HTTP', en: 'Websites & HTTP' },
  'nav.status-pages': { cs: 'Status Stránky', en: 'Status Pages' },
  'nav.incidents': { cs: 'Incidenty', en: 'Incidents' },
  'nav.insights': { cs: 'AI Insights', en: 'System Insights' },
  'nav.reports': { cs: 'SLA Výkazy', en: 'Reports & SLA' },
  'nav.users': { cs: 'Uživatelé', en: 'Users' },
  'nav.api-agents': { cs: 'API & Agenti', en: 'API & Agents' },
  'nav.settings': { cs: 'Nastavení', en: 'Settings' },
  'status.healthy': { cs: 'Všechny systémy v pořádku', en: 'All Systems Operational' },
  'status.degraded': { cs: 'Zhoršená latence / Výpadek', en: 'Degraded Performance / Outage' },
  'status.down': { cs: 'Detekován výpadek', en: 'Outage Detected' },
  'btn.login': { cs: 'Přihlásit se', en: 'Log In' },
  'btn.logout': { cs: 'Odhlásit se', en: 'Log Out' },
  'btn.save': { cs: 'Uložit změny', en: 'Save Changes' },
  'btn.test': { cs: 'Odeslat testovací notifikaci', en: 'Send Test Notification' },
  'btn.collapse': { cs: 'Sbalit navigaci', en: 'Collapse Sidebar' },
  'btn.expand': { cs: 'Rozbalit navigaci', en: 'Expand Sidebar' },
};

const LanguageContext = createContext<LanguageContextType>({
  lang: 'cs',
  setLang: () => {},
  t: (k) => k,
});

export function LanguageProvider({ children }: { children: React.ReactNode }) {
  const [lang, setLangState] = useState<Language>(() => {
    const saved = localStorage.getItem('bk_lang');
    return saved === 'en' ? 'en' : 'cs';
  });

  const setLang = (newLang: Language) => {
    setLangState(newLang);
    localStorage.setItem('bk_lang', newLang);
  };

  const t = (key: string, fallbackEn?: string): string => {
    if (translations[key]) {
      return translations[key][lang];
    }
    if (lang === 'en' && fallbackEn) {
      return fallbackEn;
    }
    return key;
  };

  return (
    <LanguageContext.Provider value={{ lang, setLang, t }}>
      {children}
    </LanguageContext.Provider>
  );
}

export function useLanguage() {
  return useContext(LanguageContext);
}
