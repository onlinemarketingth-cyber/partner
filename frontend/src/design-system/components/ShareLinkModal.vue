<script setup lang="ts">
/**
 * ShareLinkModal — TASK-056 P3
 * Reusable share sheet: Link tab (copy + LINE + Email) and QR tab
 * (image + download + native share sheet). Used by ProductBrowseView
 * (product-share links), OrdersView/ClientsView (order payment links)
 * and MyTeamView (recruit links).
 *
 * TASK-212 — this component USED TO be purely presentational ("the caller
 * only ever hands over a plain https:// URL, this component never talks to
 * the API itself"). The Email button now does, deliberately (human,
 * 2026-08-19: "ระบบ อีเมล์ให้ส่งผ่านระบบ").
 *
 * `mailto:` never did what the button implied on the surface this app runs
 * on. On a phone it hands off to whatever mail client is installed — or to
 * nothing at all, silently, when none is — the message leaves from the
 * agent's personal address, and the platform has no record it happened.
 * The button now posts to /share-emails, which sends through the SMTP row
 * a Super Admin configured.
 *
 * The alternative shape — emit an event and let each host do the POST —
 * was rejected: it would put the same recipient field, the same in-flight
 * flag and the same error handling in four views. The endpoint is generic
 * by design (one route, a type + an id), so one caller here is enough.
 *
 * A host that passes no `emailType`/`emailTargetId` still gets the old
 * `mailto:` behaviour, so an unwired call site degrades instead of
 * rendering a button that cannot work.
 *
 * TASK-079 Phase 3 (UX audit): every action in this sheet was a ~40px
 * box with no `active:` press state — this is the single most-tapped
 * surface in the app (it is how an agent actually sends a link to a
 * customer), so it now meets the 44px minimum and answers a press.
 *
 * Usage:
 *   <ShareLinkModal v-model:show="showShare" :url="link.public_url"
 *                    :heading="product.name"
 *                    email-type="product_share" :email-target-id="link.id" />
 */
import { ref, computed, watch } from 'vue'
import Icon from './Icon.vue'
import { api, ApiError } from '@/api/client'
import { useI18n } from '../../composables/useI18n'
import { generateQrDataUrl } from '../../utils/qrCode'

type ShareEmailType = 'order' | 'product_share' | 'agent_invite'

const props = withDefaults(defineProps<{
    show?: boolean
    url?: string
    heading?: string
    /**
     * What the server should look up to rebuild this URL for itself. The
     * browser never sends the URL to be emailed — see the endpoint's
     * ShareLinkType docblock: mailing a caller-supplied URL from the
     * platform's own From: address would be an authenticated open relay.
     */
    emailType?: ShareEmailType | null
    emailTargetId?: number | null
    /** Prefill for the recipient box. The agent can always change it. */
    defaultEmail?: string | null
}>(), {
    show: false,
    url: '',
    heading: '',
    emailType: null,
    emailTargetId: null,
    defaultEmail: null,
})

const emit = defineEmits<{ 'update:show': [value: boolean] }>()

const { t } = useI18n()

const tab = ref('link') // 'link' | 'qr'
const copied = ref(false)
const qrDataUrl = ref('')
const qrLoading = ref(false)
const canNativeShare = computed(() => typeof navigator !== 'undefined' && !!navigator.share)
const canNativeShareFile = computed(() => typeof navigator !== 'undefined' && !!navigator.canShare)

const close = () => emit('update:show', false)

const selectInput = (event: Event) => {
    const target = event.target as HTMLInputElement | null
    target?.select()
}

const copyLink = async () => {
    try {
        await navigator.clipboard.writeText(props.url)
        copied.value = true
        setTimeout(() => { copied.value = false }, 1800)
    } catch {
        // clipboard API unavailable — silently ignore, the input is selectable
    }
}

const shareViaLine = () => {
    const lineUrl = `https://social-plugins.line.me/lineit/share?url=${encodeURIComponent(props.url)}`
    window.open(lineUrl, '_blank', 'noopener,noreferrer')
}

// ── Email, sent by the platform (TASK-212) ───────────────────────────
const canSendViaSystem = computed(() => !!props.emailType && !!props.emailTargetId)
const showEmailPanel = ref(false)
const emailTo = ref('')
const emailSending = ref(false)
const emailError = ref('')
const emailSent = ref('')

const shareViaEmail = () => {
    if (!canSendViaSystem.value) {
        // Unwired host — degrade to the pre-TASK-212 handoff rather than
        // render a form that has nothing to post to.
        const subject = props.heading || t('shareEmailSubject', 'ลิงก์ที่แชร์ให้คุณ', 'A link shared with you')
        const body = `${props.heading ? props.heading + '\n\n' : ''}${props.url}`
        window.location.href = `mailto:?subject=${encodeURIComponent(subject)}&body=${encodeURIComponent(body)}`

        return
    }

    emailError.value = ''
    emailSent.value = ''
    emailTo.value = props.defaultEmail ?? ''
    showEmailPanel.value = true
}

const sendEmail = async () => {
    if (!canSendViaSystem.value || emailSending.value) return

    const to = emailTo.value.trim()
    if (!to) {
        emailError.value = t('shareEmailRequired', 'กรุณากรอกอีเมลผู้รับ', 'Enter a recipient email')

        return
    }

    emailSending.value = true
    emailError.value = ''
    try {
        // No `url` in this body, on purpose — the server rebuilds it from
        // the target it just authorized.
        await api.post('/share-emails', {
            type: props.emailType,
            id: props.emailTargetId,
            email: to,
        })
        emailSent.value = to
        showEmailPanel.value = false
    } catch (e) {
        // The endpoint answers 422 with a Thai sentence for both things the
        // agent can act on — mail not configured yet, and SMTP refused the
        // address — so it is shown verbatim rather than replaced.
        emailError.value = e instanceof ApiError
            ? ((e.body as { message?: string } | undefined)?.message ?? `ส่งอีเมลไม่สำเร็จ (${e.status})`)
            : t('shareEmailFailed', 'ส่งอีเมลไม่สำเร็จ', 'Could not send the email')
    } finally {
        emailSending.value = false
    }
}

const shareLinkNative = async () => {
    if (!canNativeShare.value) return
    try {
        await navigator.share({ title: props.heading || undefined, url: props.url })
    } catch {
        // user cancelled the share sheet — no-op
    }
}

const downloadQr = () => {
    if (!qrDataUrl.value) return
    const a = document.createElement('a')
    a.href = qrDataUrl.value
    a.download = 'qr-code.png'
    a.click()
}

const shareQrNative = async () => {
    if (!qrDataUrl.value || !canNativeShare.value) return
    try {
        const res = await fetch(qrDataUrl.value)
        const blob = await res.blob()
        const file = new File([blob], 'qr-code.png', { type: 'image/png' })
        if (canNativeShareFile.value && navigator.canShare({ files: [file] })) {
            await navigator.share({ files: [file], title: props.heading || undefined })
        } else {
            await navigator.share({ title: props.heading || undefined, url: props.url })
        }
    } catch {
        // user cancelled — no-op
    }
}

const loadQr = async () => {
    if (!props.url) return
    qrLoading.value = true
    qrDataUrl.value = await generateQrDataUrl(props.url, 260)
    qrLoading.value = false
}

watch(() => props.show, (val) => {
    if (val) {
        tab.value = 'link'
        copied.value = false
        // A reopened sheet is a new send; leaving the previous target's
        // address and its "ส่งแล้ว" line on screen would be a lie about
        // the link now being shown.
        showEmailPanel.value = false
        emailTo.value = ''
        emailError.value = ''
        emailSent.value = ''
        loadQr()
    }
})
</script>

<template>
    <!-- TASK-098 / ADR-023: every colour in this sheet now comes from the
         surface/ink token layer (`bg-surface-card`, `text-ink-card*`,
         `bg-surface-chip`, `text-ink-primary`) instead of hardcoded slate /
         white / inline CSS vars. The backdrop keeps `bg-slate-500/30` — a
         scrim is a veil over the page behind, not a themed surface. -->
    <transition name="fade">
        <div v-if="show"
             class="fixed inset-0 z-[1000] flex items-center justify-center p-4 bg-slate-500/30 backdrop-blur-sm"
             @click.self="close">
            <div class="w-full max-w-sm rounded-2xl bg-surface-card/95 backdrop-blur-xl border border-line-card shadow-2xl p-6">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-bold text-ink-card truncate pr-2">
                        {{ heading || t('shareTitle', 'แชร์ลิงก์', 'Share link') }}
                    </h3>
                    <button @click="close" class="shrink-0 w-11 h-11 rounded-full flex items-center justify-center text-ink-card-subtle hover:bg-surface-chip hover:text-ink-card-muted transition-all active:scale-90">
                        <Icon name="x" :size="18" />
                    </button>
                </div>

                <!-- TASK-095 (2026-08-03, human: "ให้สีปุ่มเป็นสีหลัก
                     (Primary), สีตัวอักษรแถบเมนู ยกเว้นสีเขียวปุ่ม LINE").
                     Every button here takes its fill from the company's
                     Primary (`bg-brand-600` — the brand ramp IS generated
                     from primary_hex, see stores/theme.ts applyRamp).

                     History of the LABEL colour, and the correction:
                     it started as a hardcoded `text-white`, which breaks the
                     moment a tenant picks a light Primary — this company's
                     is #978A6E, on which white is already marginal. TASK-095
                     replaced it with an inline `color: var(--nav-text)`,
                     reasoning that the ramp utilities only covered
                     backgrounds and nav_text_hex had no ramp of its own.

                     That was the wrong fix, and TASK-098 / ADR-023 §2.4
                     undoes it: `--nav-text` is the ink for `--nav-bg`.
                     Nothing ever guaranteed it contrasts with the PRIMARY —
                     it only happened to look right on this tenant. It swapped
                     one unverified assumption for another.

                     The labels now use `text-ink-primary`, which IS derived
                     from the actual primary surface by WCAG contrast
                     (theme/contrast.ts pickInk), so it flips to dark ink on
                     a pale Primary instead of hoping. The inline style
                     bindings are gone with it.

                     LINE keeps #06C755 and its `text-white`: that green is
                     LINE's own brand asset, not ours to theme. -->

                <!-- Tabs -->
                <div class="flex gap-1 p-1 mb-4 rounded-xl bg-surface-chip">
                    <button @click="tab = 'link'"
                            class="flex-1 min-h-[44px] py-2 rounded-lg text-sm font-bold transition-all active:scale-95 flex items-center justify-center gap-1.5"
                            :class="tab === 'link' ? 'bg-brand-600 text-ink-primary shadow-sm' : 'text-ink-chip'">
                        <Icon name="link" :size="16" />
                        {{ t('shareTabLink', 'ลิงก์', 'Link') }}
                    </button>
                    <button @click="tab = 'qr'"
                            class="flex-1 min-h-[44px] py-2 rounded-lg text-sm font-bold transition-all active:scale-95 flex items-center justify-center gap-1.5"
                            :class="tab === 'qr' ? 'bg-brand-600 text-ink-primary shadow-sm' : 'text-ink-chip'">
                        <Icon name="qr_code" :size="16" />
                        QR Code
                    </button>
                </div>

                <!-- Link tab -->
                <div v-if="tab === 'link'">
                    <div class="flex items-center gap-2 mb-4">
                        <input :value="url" readonly
                               class="flex-1 min-w-0 min-h-[44px] px-3 py-2.5 rounded-xl border border-line-card bg-surface-chip text-xs text-ink-chip truncate"
                               @click="selectInput">
                        <button @click="copyLink"
                                class="shrink-0 min-h-[44px] px-3 py-2.5 rounded-xl text-sm font-bold shadow transition-all active:scale-95 bg-brand-600 hover:bg-brand-700 text-ink-primary">
                            <Icon :name="copied ? 'check' : 'copy'" :size="16" />
                        </button>
                    </div>

                    <div class="grid grid-cols-2 gap-2 mb-2">
                        <button @click="shareViaLine"
                                class="min-h-[44px] py-2.5 rounded-xl text-sm font-bold text-white bg-[#06C755] hover:brightness-95 transition-all active:scale-95 flex items-center justify-center gap-1.5">
                            LINE
                        </button>
                        <button @click="shareViaEmail"
                                class="min-h-[44px] py-2.5 rounded-xl text-sm font-bold bg-brand-600 hover:bg-brand-700 text-ink-primary transition-all active:scale-95 flex items-center justify-center gap-1.5">
                            <Icon name="mail" :size="16" />
                            {{ t('shareEmail', 'อีเมล', 'Email') }}
                        </button>
                    </div>

                    <!-- TASK-212 — the recipient box, shown only after the
                         Email button is pressed so the sheet still opens as
                         four taps, not a form. -->
                    <div v-if="showEmailPanel" class="mb-2 p-3 rounded-xl border border-line-card bg-surface-chip">
                        <label for="share-email-to" class="mb-1 block text-xs font-bold text-ink-chip">
                            {{ t('shareEmailTo', 'ส่งไปที่อีเมล', 'Send to email') }}
                        </label>
                        <input id="share-email-to" v-model="emailTo" type="email" inputmode="email"
                               autocomplete="email" placeholder="name@example.com"
                               class="w-full min-h-[44px] px-3 py-2.5 rounded-lg border border-line-card bg-surface-card text-sm text-ink-card"
                               @keyup.enter="sendEmail">
                        <p v-if="emailError" class="mt-1.5 text-xs font-bold text-ink-danger" role="alert">{{ emailError }}</p>
                        <div class="mt-2 flex gap-2">
                            <button :disabled="emailSending" @click="sendEmail"
                                    class="flex-1 min-h-[44px] py-2.5 rounded-xl text-sm font-bold bg-brand-600 hover:bg-brand-700 text-ink-primary transition-all active:scale-95 disabled:opacity-50 flex items-center justify-center gap-1.5">
                                <Icon name="mail" :size="16" />
                                {{ emailSending ? t('shareEmailSending', 'กำลังส่ง...', 'Sending...') : t('shareEmailSend', 'ส่งอีเมล', 'Send email') }}
                            </button>
                            <button class="min-h-[44px] px-3 py-2.5 rounded-xl text-sm font-bold text-ink-chip active:scale-95 transition-all"
                                    @click="showEmailPanel = false">
                                {{ t('cancel', 'ยกเลิก', 'Cancel') }}
                            </button>
                        </div>
                    </div>

                    <p v-if="emailSent" class="mb-2 px-3 py-2 rounded-xl bg-surface-chip text-xs font-bold text-ink-chip">
                        {{ t('shareEmailSentTo', 'ส่งอีเมลไปที่', 'Emailed to') }} {{ emailSent }}
                    </p>

                    <button v-if="canNativeShare" @click="shareLinkNative"
                            class="w-full min-h-[44px] py-2.5 rounded-xl text-sm font-bold bg-brand-600 hover:bg-brand-700 text-ink-primary transition-all active:scale-95 flex items-center justify-center gap-1.5">
                        <Icon name="share" :size="16" />
                        {{ t('shareMore', 'แชร์ผ่านแอปอื่น', 'Share via other apps') }}
                    </button>
                </div>

                <!-- QR tab -->
                <div v-else class="flex flex-col items-center">
                    <!-- The frame follows the card (`bg-surface-card`), not a
                         fixed white: the QR PNG itself is rendered opaque
                         white-on-black by `qrcode` (utils/qrCode.ts), so
                         scannability does not depend on this background —
                         only the letterboxing around a square image does. -->
                    <div class="w-56 h-56 rounded-xl border border-line-card bg-surface-card flex items-center justify-center mb-4 overflow-hidden">
                        <div v-if="qrLoading" class="text-xs text-ink-card-subtle">{{ t('loading', 'กำลังโหลด...', 'Loading...') }}</div>
                        <img v-else-if="qrDataUrl" :src="qrDataUrl" alt="QR code" class="w-full h-full object-contain">
                        <div v-else class="text-xs text-ink-danger">{{ t('qrError', 'สร้าง QR ไม่สำเร็จ', 'Could not generate QR') }}</div>
                    </div>

                    <div class="grid grid-cols-2 gap-2 w-full">
                        <button @click="downloadQr" :disabled="!qrDataUrl"
                                class="min-h-[44px] py-2.5 rounded-xl text-sm font-bold bg-brand-600 hover:bg-brand-700 text-ink-primary transition-all active:scale-95 disabled:opacity-40 flex items-center justify-center gap-1.5">
                            <Icon name="download" :size="16" />
                            {{ t('shareDownload', 'ดาวน์โหลด', 'Download') }}
                        </button>
                        <button v-if="canNativeShare" @click="shareQrNative" :disabled="!qrDataUrl"
                                class="min-h-[44px] py-2.5 rounded-xl text-sm font-bold bg-brand-600 hover:bg-brand-700 text-ink-primary transition-all active:scale-95 disabled:opacity-40 flex items-center justify-center gap-1.5">
                            <Icon name="share" :size="16" />
                            {{ t('shareSend', 'ส่ง', 'Share') }}
                        </button>
                    </div>
                    <p class="text-[11px] text-ink-card-subtle mt-3 text-center leading-relaxed">
                        {{ t('shareQrHint', 'ดาวน์โหลดหรือส่งรูป QR เพื่อแชร์ผ่าน LINE, อีเมล หรือแอปอื่น', 'Download or share the QR image via LINE, email, or other apps') }}
                    </p>
                </div>
            </div>
        </div>
    </transition>
</template>

<style scoped>
.fade-enter-active, .fade-leave-active { transition: opacity .2s ease; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
</style>
