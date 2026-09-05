/**
 * TASK-258 — every action the audit trail can record, in Thai, grouped.
 *
 * ── WHY THIS IS A FILE AND NOT A `<select>` FULL OF `<option>`s ──
 *
 * The filter it feeds used to be a free-text box whose placeholder read
 * "เช่น commission_rule.created". That is a search box you can only use if
 * you already know the answer: nobody types `user.team_leader_changed` from
 * memory, so in practice the activity filter was unusable and the screen was
 * "everything, newest first" forever.
 *
 * Turning it into a list means the list has to be COMPLETE, or the filter
 * lies by omission — an action missing from here would simply be
 * unselectable, and the row it hides is the one somebody came to find. So
 * this file is generated from the actual `'action' => '…'` strings written
 * anywhere in the backend (grep, 2026-09-05), and the panel still renders an
 * unmapped action as its raw key rather than hiding the row.
 *
 * ── THE GROUP PREFIXES ARE PART OF THE CONTRACT ──
 *
 * Each group can be selected as a whole, which sends its prefix (e.g.
 * `user.`) to the API. That works because AuditLogController filters with
 * `action LIKE %…%` — the same reason `commission` matches every commission
 * action. If that filter ever becomes an exact match, these group options
 * break and the per-action ones do not; the test asserts the prefix is what
 * gets sent, so the change cannot land silently.
 */

export interface AuditActionGroup {
  /** Thai heading, also the label of the "everything in this group" option. */
  label: string
  /** What "everything in this group" sends. Omitted for a group whose members share no prefix. */
  prefix?: string
  actions: { value: string; label: string }[]
}

export const AUDIT_ACTION_GROUPS: AuditActionGroup[] = [
  {
    label: 'ผู้ใช้และสิทธิ์',
    prefix: 'user.',
    actions: [
      { value: 'user.created', label: 'สร้างผู้ใช้' },
      { value: 'user.role_changed', label: 'เปลี่ยนบทบาท' },
      { value: 'user.team_leader_changed', label: 'เปลี่ยนสถานะหัวหน้าทีม' },
      { value: 'user.manager_changed', label: 'เปลี่ยนหัวหน้า' },
      { value: 'user.deactivated', label: 'ปิดบัญชี' },
      { value: 'user.restored', label: 'กู้คืนบัญชี' },
      { value: 'user.password_changed', label: 'เจ้าของเปลี่ยนรหัสผ่านเอง' },
      { value: 'user.password_reset_by_admin', label: 'แอดมินรีเซ็ตรหัสผ่านให้' },
      { value: 'user.api_tokens_revoked', label: 'ถอนสิทธิ์เข้าใช้งานที่ค้างอยู่ (token)' },
      { value: 'user.super_admin_created', label: 'สร้าง Super Admin (จากบรรทัดคำสั่ง)' },
      { value: 'user.super_admin_granted', label: 'เลื่อนเป็น Super Admin (จากบรรทัดคำสั่ง)' },
    ],
  },
  {
    label: 'การอนุมัติตัวแทน',
    prefix: 'agent_approval.',
    actions: [
      { value: 'agent_approval.approved', label: 'อนุมัติตัวแทน' },
      { value: 'agent_approval.approved_by_leader', label: 'หัวหน้าทีมอนุมัติตัวแทน' },
      { value: 'agent_approval.rejected', label: 'ปฏิเสธตัวแทน' },
      { value: 'agent_approval.revoked', label: 'ถอนการอนุมัติตัวแทน' },
      { value: 'move_to_company', label: 'ย้ายบริษัท' },
    ],
  },
  {
    label: 'เข้าสู่ระบบ',
    prefix: 'auth.',
    actions: [
      { value: 'auth.login', label: 'เข้าสู่ระบบสำเร็จ' },
      { value: 'auth.login_failed', label: 'เข้าสู่ระบบไม่สำเร็จ' },
      { value: 'auth.login_blocked', label: 'รหัสผ่านถูกต้อง แต่บัญชีเข้าใช้งานไม่ได้' },
      { value: 'auth.lockout', label: 'ถูกล็อกชั่วคราวจากการพยายามเข้าสู่ระบบหลายครั้ง' },
    ],
  },
  {
    label: 'เงินและคอมมิชชั่น',
    actions: [
      { value: 'commission_rule.created', label: 'สร้างกฎคอมมิชชั่น' },
      { value: 'commission_rule.updated', label: 'แก้ไขกฎคอมมิชชั่น' },
      { value: 'commission_split_setting.updated', label: 'แก้ไขการแบ่งคอมมิชชั่นตัวแทนร่วม' },
      { value: 'commission_ledger.marked_paid', label: 'ทำเครื่องหมายว่าจ่ายคอมมิชชั่นแล้ว' },
      { value: 'commission_withdrawal.requested', label: 'ขอเบิกค่าคอมมิชชั่น' },
      { value: 'settings.commission_withdrawal_minimum_updated', label: 'แก้ไขยอดขั้นต่ำในการเบิก' },
      { value: 'platform_commission_settings.updated', label: 'แก้ไขค่าคอมมิชชั่นระดับแพลตฟอร์ม' },
      { value: 'agent_promotion_credit.paid', label: 'จ่ายเครดิตโปรโมชั่นให้ตัวแทน' },
    ],
  },
  {
    label: 'คำสั่งซื้อและการชำระเงิน',
    actions: [
      { value: 'order.refunded', label: 'คืนเงินคำสั่งซื้อ' },
      { value: 'order.slip_uploaded_by_staff', label: 'แอดมินอัปโหลดสลิปแทนลูกค้า' },
      { value: 'order.gateway_payment_failed', label: 'ชำระเงินไม่สำเร็จ (ช่องทางออนไลน์)' },
      { value: 'order.gateway_checkout_expired', label: 'ลูกค้าไม่ได้ชำระเงินภายในเวลาที่กำหนด' },
      { value: 'order.gateway_refund_reported', label: 'ผู้ให้บริการแจ้งการคืนเงิน' },
      { value: 'order.gateway_payment_unmatched', label: 'มีการชำระเงินที่จับคู่คำสั่งซื้อไม่ได้' },
      { value: 'webhook.unhandled_event_type', label: 'ได้รับ event ที่ระบบยังไม่มีตัวจัดการ' },
      { value: 'webhook.unmatched_non_payment', label: 'ได้รับ event ที่จับคู่คำสั่งซื้อไม่ได้' },
      { value: 'voucher.redeem', label: 'ใช้สิทธิ์ voucher' },
    ],
  },
  {
    label: 'ข้อมูลส่วนบุคคล (PDPA)',
    actions: [
      { value: 'user.bank_account_updated', label: 'แก้ไขบัญชีธนาคาร' },
      { value: 'user.national_id_updated', label: 'แก้ไขเลขบัตรประชาชน' },
      { value: 'user.id_document_updated', label: 'แก้ไขเอกสารยืนยันตัวตน' },
      { value: 'user.view_super_admin_record', label: 'เปิดดูข้อมูลของ Super Admin' },
      { value: 'team_client_file.view', label: 'หัวหน้าทีมเปิดแฟ้มลูกค้าของลูกทีม' },
      { value: 'audit_log.exported', label: 'ส่งออกบันทึกการใช้งานเป็นไฟล์ CSV' },
    ],
  },
  {
    label: 'Academy',
    actions: [
      { value: 'module_completion.admin_override', label: 'แอดมินกดผ่านบทเรียนแทน' },
      { value: 'module_lesson.content_type_changed', label: 'เปลี่ยนชนิดเนื้อหาบทเรียน' },
      { value: 'user_certification.manual_grant', label: 'ให้ใบรับรองด้วยมือ' },
    ],
  },
  {
    label: 'สินค้าและแคตตาล็อก',
    actions: [
      { value: 'catalog_item.propagated', label: 'เพิ่มสินค้าจากแคตตาล็อกกลางให้ทุกบริษัท' },
      { value: 'company.catalog_provisioned', label: 'บริษัทใหม่ได้รับสินค้าจากแคตตาล็อกกลาง' },
    ],
  },
  {
    label: 'ตั้งค่าระบบ',
    actions: [
      { value: 'platform_mail_settings.updated', label: 'แก้ไขการตั้งค่าอีเมล' },
      { value: 'platform_mail_settings.test_sent', label: 'ส่งอีเมลทดสอบ' },
    ],
  },
]

/** action key → Thai label, flattened once at module load. */
const LABELS: Record<string, string> = Object.fromEntries(
  AUDIT_ACTION_GROUPS.flatMap((group) => group.actions.map((a) => [a.value, a.label])),
)

/**
 * An unmapped action shows its raw key rather than being hidden or blanked.
 *
 * A new action shipped by the backend before its label lands here is then a
 * cosmetic gap on one row — never a missing row, and never a row that reads
 * as "something happened, we won't say what".
 */
export function auditActionLabel(action: string): string {
  return LABELS[action] ?? action
}
