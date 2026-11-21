/// <reference types="vite/client" />

/**
 * The build version injected in vite.config.ts (package.json version + git hash).
 * The deployed code's real identity - not a hand-maintained constant.
 */
declare const __APP_VERSION__: string;
