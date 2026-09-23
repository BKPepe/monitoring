/// <reference types="vitest/config" />
// Astro's Vite setup lets a test render a real .astro component (the Astro
// container API), so the CSP tests check what a page actually carries.
import { getViteConfig } from 'astro/config';

export default getViteConfig({ test: {} });
