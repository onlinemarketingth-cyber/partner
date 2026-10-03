/**
 * MOB-01 (2026-10-02) — the ONE place in the agent portal that knows whether
 * this build is running inside the Capacitor app shell (iOS / Android) or in
 * an ordinary browser tab.
 *
 * Owner: "ทำระยะที่ 1 ได้เลย" — mobile app plan, phase 1 (proof of concept).
 *
 * Every difference between the web portal and the app goes through this
 * folder, never through a check scattered in a view. The web portal must
 * behave exactly as it did before the app existed (owner question: "ยังเข้า
 * ผ่านการแชร์ link ปรกติผ่าน browser ได้ไหม" — yes, and this is how): in a
 * browser every function here takes the web path, which is the code that was
 * already there.
 */
import { Capacitor } from '@capacitor/core'
import type { Component, InjectionKey } from 'vue'

/**
 * MOB-27/29/31 (2026-10-02) — how App.vue receives the app-only overlays
 * (platform/components/NativeAppLayer.vue) without importing them. main.ts
 * provides the component inside the app only; in a browser App.vue injects
 * null and renders nothing, and the component is not in the web bundle.
 */
export const NATIVE_LAYER_KEY: InjectionKey<Component> = Symbol('native-app-layer')

/** True only inside the installed iOS / Android app, never in a browser. */
export function isNativeApp(): boolean {
  return Capacitor.isNativePlatform()
}

/**
 * MOB-28 (2026-10-02) — which store build this is. The server keeps one push
 * token per device and needs to know which push service (APNs via FCM, or
 * FCM directly) and which store version policy applies to it.
 *
 * 'web' in a browser. Capacitor.getPlatform() is the source of truth rather
 * than the user agent: an iPad in desktop mode reports a Mac user agent.
 */
export function nativePlatform(): 'ios' | 'android' | 'web' {
  const platform = Capacitor.getPlatform()

  return platform === 'ios' || platform === 'android' ? platform : 'web'
}
