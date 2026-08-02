import { Link, useNavigate } from 'react-router';
import { Card } from '@/components/ui/card';
import { FileQuestion, Home, ArrowLeft } from 'lucide-react';

export function NotFoundPage() {
  const navigate = useNavigate();

  return (
    <div className="min-h-[70vh] flex items-center justify-center p-4">
      <Card className="w-full max-w-lg p-8 text-center space-y-6 border-primary/20 shadow-2xl bg-secondary/30 backdrop-blur-xl">
        <div className="relative mx-auto w-20 h-20 rounded-2xl bg-primary/10 border border-primary/30 flex items-center justify-center">
          <FileQuestion className="size-10 text-primary" />
          <span className="absolute -top-1 -right-1 flex h-4 w-4">
            <span className="animate-ping absolute inline-flex h-full w-full rounded-full bg-amber-400 opacity-75"></span>
            <span className="relative inline-flex rounded-full h-4 w-4 bg-amber-500"></span>
          </span>
        </div>

        <div className="space-y-2">
          <BadgeText>CHYBA 404 — STRÁNKA NENALEZENA</BadgeText>
          <h1 className="text-2xl font-bold tracking-tight text-foreground">Požadovaná stránka neexistuje</h1>
          <p className="text-sm text-muted-foreground leading-relaxed max-w-md mx-auto">
            Adresa, kterou jste zadali, pravděpodobně nebyla nalezena, byla přejmenována nebo přesunuta.
          </p>
        </div>

        <div className="flex items-center justify-center gap-3 pt-2 flex-wrap sm:flex-nowrap">
          <button
            type="button"
            onClick={() => navigate(-1)}
            className="w-full sm:w-auto inline-flex items-center justify-center gap-2 rounded-md bg-secondary px-4 py-2.5 text-sm font-medium text-secondary-foreground hover:bg-secondary/80 transition-colors"
          >
            <ArrowLeft className="size-4" /> Zpět na předchozí stránku
          </button>

          <Link
            to="/"
            className="w-full sm:w-auto inline-flex items-center justify-center gap-2 rounded-md bg-primary px-5 py-2.5 text-sm font-semibold text-primary-foreground shadow hover:bg-primary/90 transition-colors"
          >
            <Home className="size-4" /> Hlavní Dashboard
          </Link>
        </div>
      </Card>
    </div>
  );
}

function BadgeText({ children }: { children: React.ReactNode }) {
  return (
    <span className="inline-block text-[10px] font-bold tracking-widest uppercase text-amber-400 bg-amber-400/10 px-3 py-1 rounded-full border border-amber-400/20">
      {children}
    </span>
  );
}
