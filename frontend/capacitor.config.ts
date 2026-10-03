/**
 * MOB-01 (2026-10-02) — Capacitor shell for the agent portal (iOS + Android).
 *
 * appId is the bundle id on both stores and CANNOT be changed after the
 * first store upload. Confirm it before running `npx cap add ios|android`
 * (plan decision D2) — edit it here first if it should be different.
 *
 * webDir is dist-app, NOT dist: `npm run build:app` builds the app with
 * .env.app (absolute production API URL) into its own folder, so the web
 * build that scripts/deploy.sh rsyncs from dist/ is never mixed up with it.
 */
import type { CapacitorConfig } from '@capacitor/cli'

const config: CapacitorConfig = {
  appId: 'io.syncvision.partner',
  appName: 'Live to 100 Club Partner',
  webDir: 'dist-app',
  plugins: {
    /*
     * MOB-26 (2026-10-02) — the native launch screen stays up until the
     * portal has painted; src/platform/nativeBoot.ts hides it right after
     * app.mount(). Auto-hide would drop it on a timer instead, showing a
     * blank WebView (and then the HTML splash) in between on a slow phone.
     */
    SplashScreen: {
      launchAutoHide: false,
    },
    /*
     * MOB-28 — iOS: how a push that arrives while the app is OPEN is shown.
     * The plugin's own default, written out so it is a decision rather than
     * an accident: show the banner, update the badge, play the sound — the
     * same as when the app is closed. (Android shows nothing in the
     * foreground; the app refreshes the bell count instead.)
     */
    FirebaseMessaging: {
      presentationOptions: ['alert', 'badge', 'sound'],
    },
    /*
     * MOB-25 — Capacitor 8's built-in SystemBars: tell Android up front that
     * index.html asks for viewport-fit=cover (the app build adds it — see
     * vite.config.ts), which avoids a layout jump while it detects the tag.
     * insetsHandling stays at its default ('css').
     */
    SystemBars: {
      initialViewportFitValueHint: 'cover',
    },
  },
  /*
   * MOB-28 — required by @capacitor-firebase/messaging when iOS uses Swift
   * Package Manager (Capacitor 8's default): without the symlink, SwiftPM
   * reports a package identity collision and the iOS build fails. Needs
   * Capacitor CLI 8.4+ (this project pins 8.5).
   */
  experimental: {
    ios: {
      spm: {
        packageOptions: {
          '@capacitor-firebase/messaging': {
            symlink: true,
          },
        },
      },
    },
  },
}

export default config
