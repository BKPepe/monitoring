/**
 * The head of the public status page, the one page of the app meant for search
 * engines (owner decision 2026-09-22, W1-G4).
 *
 * public.html ships the static head (title, description, canonical, og:url),
 * which is what a crawler without JavaScript sees. The page then refines two
 * things a static file cannot know: a custom status page (?page=slug) is its
 * own canonical URL, and a running outage leads the tab title.
 */

/** The address search engines should list; ?lang= and other params fold into it. */
export const PUBLIC_CANONICAL_URL = 'https://bloodkings.eu/app/public';

/**
 * The canonical URL of the page being shown. A custom status page keeps its
 * slug, since it lists other monitors than the main page (the root sitemap
 * lists it under exactly this URL, see root/lib.mjs). Anything else - ?lang=en,
 * tracking params - folds into the main address.
 */
export function publicCanonicalUrl(slug: string | null): string {
  return slug ? `${PUBLIC_CANONICAL_URL}?page=${encodeURIComponent(slug)}` : PUBLIC_CANONICAL_URL;
}

/**
 * Points the static canonical and og:url of public.html at the page shown.
 * React would add a second <link rel="canonical"> next to the static one, and
 * two canonicals that disagree make search engines ignore both - so the
 * existing tags are updated in place. Served from index.html (the page opened
 * from inside the app) there is no canonical to update, and none is added.
 */
export function syncPublicCanonical(doc: Pick<Document, 'querySelector'>, slug: string | null): void {
  const url = publicCanonicalUrl(slug);
  doc.querySelector<HTMLLinkElement>('link[rel="canonical"]')?.setAttribute('href', url);
  doc.querySelector<HTMLMetaElement>('meta[property="og:url"]')?.setAttribute('content', url);
}
