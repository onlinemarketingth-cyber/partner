/**
 * academyBuilderCopy — every explanation the Academy course builder shows.
 *
 * TASK-188 §4.B3. Four explanations (บทเสริม, อนุญาตดาวน์โหลด, ลิงก์ embed,
 * ลากจัดลำดับ) were written out TWICE in different places, and the two copies
 * had already drifted:
 *
 *  - อนุญาตดาวน์โหลด: the CREATE form said "เมื่อเบราว์เซอร์เปิดไฟล์แล้ว ผู้ใช้ย่อมมี
 *    ข้อมูลนั้นอยู่ในเครื่อง" and warned that a downloadable file cannot use the
 *    ดู/อ่านให้ครบ gate. The EDIT form said neither, so an admin flipping the flag
 *    on an existing lesson was told less than one creating it — and flipping it
 *    on an existing lesson is exactly when it silently changes that lesson's
 *    completion gate.
 *  - ลิงก์ embed: the create form told the admin to press "ดูตัวอย่าง" after
 *    saving; that button was REMOVED (2026-08-09, replaced by the always-visible
 *    preview strip) and only the edit form's copy was updated.
 *
 * That is the same failure mode duplicated logic has, minus the compiler. So the
 * copy lives here, once, and both call sites read it.
 *
 * WHAT IS NOT HERE. Anything the screen COMPUTES stays in the template as data
 * (§4.B5): the effective pass mark, the URL the learner's iframe will load, the
 * name of the file about to replace the current one, a Section's lesson counts,
 * and every error message. A computed value is not an explanation.
 *
 * INTERNAL CITATIONS. §4.B2 — `ADR-029 §2.7`, `ERD-001 §Academy`, `(BR-1)` and
 * friends are OUR document references and mean nothing to a customer, so they
 * are not in these strings. Each one is preserved as a code comment beside the
 * string it used to be printed with.
 */

// ── The four that were written twice ────────────────────────────────────

/**
 * ADR-031 §2.4 — "shown, not counted". Rendered next to `is_optional` in the
 * lesson edit form AND in the wide-layout inspector.
 *
 * Both halves have to be said: counting an optional lesson would strand a
 * learner at "4/5" forever, and letting it gate the next lesson would make
 * "optional" mean "required, but we called it something else".
 */
export const OPTIONAL_LESSON_EXPLANATION =
  'ผู้เรียนยังเห็นและเข้าเรียนได้ตามปกติ แต่บทนี้จะไม่ถูกนับในความคืบหน้า (เช่น “3/5 บท” จะนับเฉพาะบทที่บังคับ) และไม่บล็อกบทถัดไป แม้ Section นี้จะตั้งให้เรียนตามลำดับก็ตาม'

/** The one-line version on the “บทเสริม” pill, in the outline and on the lesson row. */
export const OPTIONAL_LESSON_PILL_TITLE = 'ไม่นับในความคืบหน้า และไม่บล็อกบทถัดไป'

/**
 * ADR-028 §2.2 — per-file admin choice, stated honestly. Rendered next to
 * `is_downloadable` in the lesson create form AND the lesson edit form.
 *
 * R3 of the ADR-028 sprint plan: a label that reads as copy protection gets the
 * PR rejected, because a company may make real disclosure decisions about
 * confidential material on the strength of what we tell them here. The second
 * sentence is the completion-gate interaction the edit form used to omit.
 */
export const DOWNLOADABLE_EXPLANATION =
  'ถ้าไม่เลือก ระบบจะซ่อนปุ่มดาวน์โหลดในแอปเท่านั้น — ไม่ได้ทำให้ไฟล์คัดลอกไม่ได้ เมื่อเบราว์เซอร์เปิดไฟล์แล้ว ผู้ใช้ย่อมมีข้อมูลนั้นอยู่ในเครื่อง · ไฟล์ที่อนุญาตให้ดาวน์โหลดได้ จะใช้เกณฑ์ “ดู/อ่านให้ครบ” ไม่ได้ (ผู้เรียนอ่านนอกแอปได้) ระบบจะกลับไปใช้ปุ่ม “ทำเครื่องหมายว่าเรียนจบ” ตามเดิม'

/**
 * The embed-link authoring help, for the create form AND the edit form.
 *
 * GUIDANCE, NEVER A GATE (see isFramedLesson's note in the view): there is no
 * reliable way to know from JS whether an arbitrary host allows framing, so
 * "unknown" means unknown, not "wrong". A company's internal video portal may
 * embed perfectly.
 *
 * A function rather than three exported strings so the CONDITIONS live in one
 * place too — otherwise both call sites would rebuild the same v-if chain and
 * that is the next thing to drift.
 */
export function embedUrlExplanation(state: { rewritten: boolean; mayNotDisplay: boolean }): string {
  const parts = [
    'วางลิงก์วิดีโอจาก YouTube/Vimeo ได้ตามปกติ ระบบจะแปลงให้เป็นลิงก์ที่เล่นได้ในหน้าบทเรียนเอง',
  ]
  if (state.rewritten) {
    parts.push('ลิงก์ที่วางไว้ถูกปรับให้เป็นรูปแบบ embed แล้ว เพื่อให้เล่นได้ในหน้าบทเรียน')
  }
  if (state.mayNotDisplay) {
    parts.push(
      'ลิงก์นี้อาจแสดงในหน้าบทเรียนไม่ได้ เพราะบางเว็บไม่อนุญาตให้นำวิดีโอไปเล่นในเว็บอื่น บันทึกได้ตามปกติ แต่แนะนำให้ดูแถบตัวอย่างใต้แถวบทเรียนหลังบันทึก เพื่อตรวจว่าวิดีโอขึ้นจริงหรือไม่ — ถ้าไม่ขึ้น ผู้เรียนจะยังมีปุ่ม “เปิดลิงก์ในแท็บใหม่” ให้กดเสมอ',
    )
  }

  return parts.join(' · ')
}

/**
 * ADR-031 §2.1 — how ordering works. Rendered above the outline, above the
 * narrow-layout Section list, and above a Section's lesson list: three places,
 * one sentence.
 */
export const DRAG_REORDER_EXPLANATION =
  'ลากที่จับด้านซ้ายของแต่ละแถวเพื่อจัดลำดับ — Section จัดลำดับได้ภายในระดับใบรับรองเดียวกัน และบทเรียนจัดลำดับได้ภายใน Section เดียวกัน · หรือแก้ช่อง “ลำดับการแสดง” ในฟอร์มแก้ไขก็ได้'

/** The grab handle's own label. Two Section rows render it; both read this. */
export function dragSectionHandleTitle(tierName: string | null | undefined): string {
  return `ลากเพื่อจัดลำดับ Section ภายใน ${tierName ?? 'ระดับนี้'}`
}

/** The lesson grab handle's label — outline row and accordion row. */
export const DRAG_LESSON_HANDLE_TITLE = 'ลากเพื่อจัดลำดับบทเรียนใน Section นี้'

/** Only in the two-pane layout: what clicking a row in the outline does. */
export const OUTLINE_SELECT_EXPLANATION =
  'คลิกที่ Section หรือบทเรียนเพื่อดู/แก้การตั้งค่าทางขวา รายการด้านซ้ายจะไม่ถูกยุบ'

// ── Company-level completion thresholds ─────────────────────────────────

/** BR-7 + ADR-028 §4 — provisional config, and the learner never sees the numbers. */
export const COMPLETION_SETTINGS_EXPLANATION =
  'ค่าตั้งต้นของทั้งระบบคือค่าที่ตั้งไว้ในระบบหลังบ้าน แก้ตรงนี้เพื่อใช้เฉพาะบริษัทนี้ · ผู้เรียนไม่เห็นตัวเลขทั้งสามนี้ ระบบจะบอกผู้เรียนแค่ว่าต้องทำอะไรต่อ'

/** ADR-029 §2.4 — the company pass mark is the fallback end of the chain. */
export const COMPANY_QUIZ_PASS_PERCENT_EXPLANATION =
  'ใช้กับบทเรียนที่ไม่ได้ตั้งเกณฑ์ผ่านของตัวเองไว้'

// ── Section release controls (behind the gear) ──────────────────────────

/** TASK-152 §4 ruling — unpublishing shrinks the denominator, never revokes a completion. */
export const SECTION_PUBLISH_EXPLANATION =
  'ถ้าปิด: Section นี้จะหายไปจากรายการของผู้เรียน และบทเรียนในนั้นจะไม่ถูกนับเป็นตัวหารของความคืบหน้าอีก · ผลการเรียนที่บันทึกไว้แล้วจะไม่ถูกยกเลิก ทั้งตัวตั้งและตัวหารลดลงพร้อมกัน สัดส่วนของผู้เรียนจึงยังตรง'

/**
 * ADR-031 §2.2 — the longest block on the screen (384 characters), and the one
 * that names its own cost out loud rather than describing a flag.
 */
export const SECTION_SEQUENTIAL_EXPLANATION =
  'ถ้าเปิด: ผู้เรียนจะเปิดบทที่ 2 ไม่ได้จนกว่าจะเรียนบทที่ 1 จบ (จบจริงตามเกณฑ์ ไม่ใช่แค่กดเปิด) ไล่ไปทีละบทจนจบ Section · ผลข้างเคียงที่ต้องรู้: ถ้าบทใดบทหนึ่งมีปัญหา (ไฟล์เสีย เปิดไม่ได้) ผู้เรียนทุกคนที่อยู่หลังบทนั้นจะติดหมด · ทางออกเมื่อมีคนติด: ใช้ปุ่ม “ทำเครื่องหมายว่าเรียนจบให้” ในหัวข้อ “ความคืบหน้าผู้เรียน” ของบทนั้น (มี Audit Log) · บทที่ตั้งเป็น “บทเสริม” จะไม่บล็อกบทถัดไป'

/**
 * ADR-031 §2.3 / §4 ข้อ 1 — drip. §4.B4: "เว้นว่าง = เปิดให้เรียนทันที" stays as the
 * field's PLACEHOLDER (already the shortest possible version of that sentence);
 * only the paragraph moved here.
 */
export const SECTION_DRIP_EXPLANATION =
  'ถ้าใส่ 7: ทุกบทใน Section นี้จะเปิดให้ผู้เรียนแต่ละคน 7 วันหลังวันที่บัญชีของเขาได้รับอนุมัติ (ระบบยังไม่มีวันที่ “เริ่มเรียนคอร์ส” — รอยืนยัน) · ผู้เรียนจะเห็นบทเรียนอยู่ในรายการเสมอ พร้อมข้อความว่าจะเปิดเมื่อไร ไม่ได้ถูกซ่อน'

// ── Lesson settings ─────────────────────────────────────────────────────

/** BR-7 + ADR-028 §4 — there is no per-lesson watch/read threshold in the schema. */
export const LESSON_GATE_IS_COMPANY_LEVEL_EXPLANATION =
  'เป็นค่าระดับบริษัท แก้ที่ปุ่ม “เกณฑ์การเรียนจบของบริษัท” ด้านบน (ไม่มีค่าเฉพาะรายบทเรียน)'

/** Wide-layout inspector — where the fields this panel does NOT carry live. */
export const INSPECTOR_SCOPE_EXPLANATION =
  'ชื่อบท / ไฟล์ / ลิงก์ / XP แก้ได้ที่ปุ่มดินสอด้านบน'

/** ADR-028 §4 — the support readout the learner is deliberately never shown. */
export const LESSON_PROGRESS_EXPLANATION =
  'ตัวเลขนี้เห็นได้เฉพาะผู้ดูแลระบบ ผู้เรียนจะไม่เห็นความคืบหน้าของตัวเอง — “สูงสุด” คือค่าที่ใช้ตัดสินว่าเรียนจบหรือยัง ส่วน “ล่าสุด” คือจุดที่ค้างไว้'

/** ADR-029 §2.5 / §4 ข้อ 2 — score only; the chosen options are not stored at all. */
export const QUIZ_ATTEMPTS_EXPLANATION =
  'ระบบบันทึกเฉพาะคะแนนและผลผ่าน/ไม่ผ่านของแต่ละครั้ง ไม่ได้เก็บว่าผู้เรียนเลือกตัวเลือกใด · ทำซ้ำได้ไม่จำกัดครั้ง'

/**
 * ADR-029 §2.4 — NULL means inherit. §4.B4: "ใช้ค่าของบริษัท" stays as the field's
 * PLACEHOLDER, and the effective mark stays on screen as data; only the
 * sentence explaining the inheritance moved here.
 */
export const LESSON_QUIZ_PASS_PERCENT_EXPLANATION =
  'เว้นว่างไว้ = ใช้เกณฑ์ผ่านของบริษัท · ผู้เรียนไม่เห็นตัวเลขนี้ ระบบบอกผู้เรียนแค่ว่าตอบถูกกี่ข้อ และผ่านหรือไม่ผ่าน'

/** ADR-029 §2.6 + §3 — this gate sits on the BR-1 certification path. */
export const QUIZ_BLOCKS_COMPLETION_EXPLANATION =
  'ถ้าเปิด: ผู้เรียนจะกด “ทำเครื่องหมายว่าเรียนจบ” ไม่ได้จนกว่าจะทำแบบทดสอบผ่าน และบทเรียนนี้อยู่บนเส้นทางการได้ใบรับรอง จึงมีผลต่อสิทธิ์การขายด้วย · ถ้าปิด: บันทึกผลไว้ให้ผู้ดูแลดูเท่านั้น ไม่บล็อกอะไร · ผู้เรียนที่ติดจริง ๆ ให้ใช้ปุ่ม “ทำเครื่องหมายว่าเรียนจบให้” ในหัวข้อความคืบหน้าผู้เรียน (มี Audit Log)'

/** ADR-030 §2.5 — why a quiz an admin knows exists is missing from the picker. */
export const QUIZ_PICKER_EXPLANATION =
  'แสดงเฉพาะชุดที่ผูกได้จริงในตอนนี้ — ชุดที่บทเรียนอื่นใช้อยู่แล้วจะไม่ปรากฏที่นี่'

/** ADR-030 §3 — typing a question here creates and attaches a quiz by itself. */
export const QUIZ_SOURCE_EXPLANATION =
  'พิมพ์คำถามด้านล่างเพื่อสร้างชุดใหม่ให้บทเรียนนี้ หรือกด “เลือกจากคลัง” เพื่อผูกชุดที่เตรียมไว้ล่วงหน้า'

/** The create form's `quiz` branch — there is no content_ref to fill in. */
export const QUIZ_LESSON_TYPE_EXPLANATION =
  'บทเรียนประเภทนี้ไม่มีไฟล์หรือลิงก์ · บันทึกบทเรียนก่อน แล้วเพิ่มคำถาม/ตัวเลือกได้จากปุ่ม “แบบทดสอบท้ายบท” บนแถวบทเรียน'

// ── Quiz question editor (shared with the exam question bank) ────────────

/**
 * §4.B5 — the STATE ("ยังไม่ได้เลือกคำตอบที่ถูกต้อง") is an error and stays visible.
 * Only the how-to moved behind the ⓘ.
 */
export const QUIZ_NO_CORRECT_ANSWER_HOWTO =
  'คลิกวงกลมด้านซ้ายของตัวเลือกที่ถูกต้อง ระบบอนุญาตให้ถูกได้ข้อเดียวต่อหนึ่งคำถาม'

// ── Quiz library tab ────────────────────────────────────────────────────

/** ADR-030 §1 — preparation, not reuse: one quiz belongs to at most one lesson. */
export const QUIZ_LIBRARY_CREATE_EXPLANATION =
  'สร้างเปล่าไว้ก่อนได้ ยังไม่ต้องมีบทเรียน — คำถามเพิ่มทีหลังได้ที่ปุ่ม “แก้ไขคำถาม” ในรายการด้านล่าง'

export const QUIZ_LIBRARY_EXPLANATION =
  'เตรียมชุดคำถามไว้ล่วงหน้าได้ที่นี่ แล้วค่อยไปผูกกับบทเรียนภายหลังที่แท็บ “โมดูล” · หนึ่งชุดผูกได้กับบทเรียนเดียวเท่านั้น จนกว่าจะยกเลิกการเชื่อมโยง'

// ── Lesson preview strip ────────────────────────────────────────────────

/** ADR-028 §2.3 — an admin skimming lesson rows records no learner progress. */
export const PREVIEW_NOT_RECORDED_EXPLANATION =
  'การเปิดดูตรงนี้เป็นมุมมองของผู้ดูแลระบบ ไม่ถูกบันทึกเป็นความคืบหน้าของผู้เรียน'

// ── Changing a lesson's content type (TASK-188 Phase D) ─────────────────

/**
 * The confirmation body, built from GET
 * /module-lessons/{id}/content-type-change-impact.
 *
 * EVERY NUMBER HERE COMES FROM THAT RESPONSE. ag-dev measured what the retype
 * actually does (TASK-188 §6.D2); none of it is inferred in the browser, and
 * none of it is softened — an admin who is told "progress may be affected"
 * learns what happened from a support ticket instead.
 *
 * The reassuring half is stated as plainly as the destructive half:
 * `completions_are_kept` is always true, and admins assume the opposite.
 */
/**
 * Shown next to the content-type selector while a retype is pending. It says
 * only what the ADMIN has to DO; the consequences are stated in full in the
 * confirmation, where they cannot be missed.
 */
export const RETYPE_CONTENT_TYPE_EXPLANATION =
  'เปลี่ยนประเภทได้ แต่ต้องแนบเนื้อหาของประเภทใหม่มาพร้อมกันในการบันทึกครั้งเดียว (ไฟล์ใหม่สำหรับแบบอัปโหลด หรือลิงก์ใหม่สำหรับแบบลิงก์ ส่วนแบบทดสอบไม่ต้องแนบอะไร) · ระบบจะสรุปผลกระทบให้ยืนยันก่อนบันทึกจริง'

export interface ContentTypeChangeImpact {
  content_type: string
  learners_with_progress: number
  progress_will_be_reset: boolean
  learners_completed: number
  completions_are_kept: boolean
  stored_file_will_be_deleted: boolean
  is_downloadable_will_reset: boolean
  quiz_id: number | null
  quiz_stays_attached: boolean
}

export function contentTypeChangeConfirmBody(
  impact: ContentTypeChangeImpact,
  fromLabel: string,
  toLabel: string,
): string {
  const lines = [`เปลี่ยนประเภทเนื้อหาจาก “${fromLabel}” เป็น “${toLabel}”`]

  if (impact.stored_file_will_be_deleted) {
    lines.push('ไฟล์เดิมที่เก็บไว้จะถูกลบ และต้องแนบเนื้อหาใหม่ไปพร้อมกันในขั้นตอนนี้')
  }

  if (impact.progress_will_be_reset) {
    lines.push(
      impact.learners_with_progress > 0
        ? `ความคืบหน้าการดู/อ่านของผู้เรียน ${impact.learners_with_progress} คน จะถูกล้าง`
        : 'ความคืบหน้าการดู/อ่านจะถูกล้าง (ตอนนี้ยังไม่มีผู้เรียนคนใดเปิดบทเรียนนี้)',
    )
  }

  if (impact.completions_are_kept) {
    lines.push(
      impact.learners_completed > 0
        ? `ผู้เรียน ${impact.learners_completed} คนที่เรียนบทนี้จบไปแล้ว ยังนับว่าเรียนจบเหมือนเดิม ไม่ต้องเรียนซ้ำ`
        : 'ผู้เรียนที่เรียนบทนี้จบไปแล้ว จะยังนับว่าเรียนจบเหมือนเดิม (ตอนนี้ยังไม่มี)',
    )
  }

  if (impact.quiz_id !== null && impact.quiz_stays_attached) {
    lines.push('แบบทดสอบท้ายบทที่ผูกไว้ยังอยู่ครบ ไม่ถูกแตะต้อง')
  }

  if (impact.is_downloadable_will_reset) {
    lines.push('การตั้งค่า “อนุญาตให้ผู้เรียนดาวน์โหลดไฟล์นี้” จะถูกรีเซ็ต')
  }

  lines.push('ยืนยันหรือไม่?')

  return lines.join(' · ')
}
