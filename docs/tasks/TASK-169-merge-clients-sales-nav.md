# TASK-169 — merge ลูกค้า + ขาย into one screen; the freed nav slot becomes สินค้า

- **Owner:** ag-lead (spec) → ag-ui (all phases) → ag-qa (§7)
- **Date:** 2026-08-11
- **Human:** *"ผมต้องการรวม UI เป็นหน้าเดียวคือ ลูกค้า และ เมนูขาย เอา Product มาแทน"*
- **Related:** ADR-021 (page header), ADR-026 (per-product pipeline templates), TASK-048, TASK-079, TASK-141, BR-1, BR-4, BR-6

---

## 1. What this is and is not

**It is a UI reorganisation. No business logic moves.** `POST /referrals`, the pipeline
transition rules, the BR-1 certification gate, the BR-4 commission ledger and the BR-6
tenant scope all live in Laravel Services and are untouched. No migration, no new endpoint,
no schema change. If any phase below finds itself needing one, that is the signal that
something has been misread — stop and come back to ag-lead.

The bottom nav goes from

`หน้าหลัก · ลูกค้า · ขาย · Academy · ค่าคอม` → `หน้าหลัก · ลูกค้า · สินค้า · Academy · ค่าคอม`

## 2. Why the two screens can be merged at all

They are two granularities — a list of **people** and a list of **deals**, one person to
many deals — which is normally a reason to keep screens apart. Three things make the merge
safe here:

- `ClientsView` **already creates referrals** (`+ เพิ่มสินค้าที่สนใจ` posts to the same
  `POST /referrals` endpoint `ReferralsView` uses, BR-1 gate and all). Half the merge exists.
- `ClientResource` already returns each client's referrals with product and stage, so the
  data for a per-client deal list is already on the wire.
- `/products` is a finished 816-line screen (TASK-070/ADR-020) that has never had a nav
  slot. The swap gives it one — this is an IA gain, not just a rename.

## 3. Human decisions (2026-08-11)

**D1 — layout.** *"รายชื่อคนอย่างเดียว ดีลอยู่ข้างใน"*: the list is a list of PEOPLE. There
is no separate "ดีล" list. A client's deals live in that client's drawer.

**D2 — the Kanban board** (`/pipeline`, 544 lines) moves onto this screen rather than
staying a Home quick-link.

**D1 and D2 read together (ag-lead interpretation — CONFIRM BEFORE PHASE 3):** one screen,
**two view modes**, not three tabs:

| Mode | Shows | Answers |
|---|---|---|
| **รายชื่อลูกค้า** (default) | people; deals inside each drawer | *"what is going on with this person?"* |
| **ไปป์ไลน์** | Kanban across all clients | *"which deals are close to closing?"* |

The second mode is what a per-client drawer structurally cannot give: the cross-client view.
Dropping it would lose a capability the human did not ask to lose.

## 4. The merged screen

**List mode** — unchanged from today: search, category filter, one row per client.

**Client drawer** — everything today has (profile, PDPA `health_notes`, documents through
the authenticated download endpoint) **plus** the deal block absorbed from `ReferralsView`:

- one row per referral: product, current stage, created date
- **เก็บเงินเลย** (TASK-141) on the row — `POST /orders` then `ShareLinkModal`, one press
- `+ เพิ่มสินค้าที่สนใจ` — the existing creation form, BR-1 gate unchanged

**ไปป์ไลน์ mode** — `PipelineView`'s board, moved not rewritten. Its columns must keep
coming from **each referral's own template** (`referrals.pipeline_template_id`, ADR-026) —
a board that renders one fixed five-column medical journey would be a silent regression for
every direct-sale product.

## 5. Decided by ag-lead (not open for debate during build)

1. **New theme key `nav_products`. Do NOT reuse `nav_sales`.** A company that renamed
   "ขาย" through the Admin theme screen must not have that label reappear on สินค้า.
   `nav_sales` stays a valid key, simply no longer read here — exactly how `nav_profile`
   was retired in TASK-079.
2. **`/referrals` and `/pipeline` redirect, they are not deleted.** Agents bookmark URLs and
   `HomeView` links to `/pipeline`. A 404 is a worse outcome than a redirect.
   (`GET /referrals` the *API* is untouched — `OrdersView` calls it.)
3. ~~Remove "สินค้า" and "ไปป์ไลน์" from `HomeView`'s quick links.~~ **OVERRULED BY THE
   HUMAN, 2026-08-11: *"ไม่ลบ"*.** Both quick links stay. ag-lead's argument was that two
   entry points to one screen is clutter; the human's call stands and costs nothing:
   - `สินค้า → /products` is unaffected by this task.
   - `ไปป์ไลน์ → /pipeline` needs **no edit either**, because decision 2 already redirects
     that URL. The link keeps working by going through the redirect.

   **Consequence — the view mode must be addressable in the URL** (`?view=pipeline` or
   equivalent), or `/pipeline` has nowhere specific to redirect TO and the quick link would
   dump the agent on the client list instead of the board. Mode is therefore read from and
   written to the query string, not held in component state alone.
4. **`ReferralsView.vue` and `PipelineView.vue` are deleted only in Phase 4**, after their
   behaviour demonstrably lives in the merged screen — not before.

## 5b. ag-lead rulings from Phase 2 (2026-08-11)

Phase 2 found two capabilities in `ReferralsView` with no equivalent in the drawer. Both are
**blockers on Phase 4's deletion**, not opinions:

1. **TASK-026 co-agent editor** (`+ แบ่งคอมฯ` / `แก้ไขคอมฯ ร่วม`). **Must be rebuilt in the
   drawer before `ReferralsView` is deleted.** This edits who gets paid — BR-4 money. The
   row's `actions-start`/`footer` slots are taken by the drawer's product-detail expander,
   so it needs a different affordance; that is a design problem, not a reason to drop it.
   Silently losing a money control is the worst possible outcome of a UI merge.
2. **Cross-client status tabs** (`ทั้งหมด / รอดำเนินการ / เสร็จแล้ว`). ~~Subsumed by Phase 3's
   ไปป์ไลน์ mode.~~ **PARTLY WRONG — corrected after Phase 3 checked it (2026-08-11).**

   Phase 3 was asked to verify the ruling rather than assume it, and found two things:

   **(a) The existing tabs are BUGGY, not merely redundant.** Their predicate is a
   hardcoded `DONE_STAGE_KEYS = ['complete_payment','ongoing_next_meeting']` — written
   before ADR-026 and now wrong in *both* directions. A referral at `delivery` has paid
   and closed but is filed under **รอดำเนินการ**; a referral at `complete_payment` on a
   template with post-sale stages still has three steps left but is filed **เสร็จแล้ว**.
   This is a live defect in today's code, independent of this task.

   **(b) The board does not replace what the tabs DID.** It shows open/done as a KPI
   number and a per-row label — correct, template-aware, and not a filter. There is no tap
   sequence on the board that yields "my done deals": "done" is *each template's terminal
   stage*, so the stage axis cannot express it (one stage tab mixes open and done rows).
   Against §9's ≤3 clicks that is a real triage regression.

   **Ruling: build a template-aware open/done filter on the board (Phase 3b), then delete
   the tabs.** Not keep the tabs — that re-imports the bug in (a). The predicate is
   `next_stage !== null`, which the board already computes for its KPI; only the control is
   missing. Phase 4 stays blocked until 3b lands.

Also fixed during Phase 2 review (ag-lead, backend): `ClientController` had **three**
different eager-load shapes across `index`/`show`/`store`/`update`, so re-fetching one client
dropped the co-agent line the drawer now renders. Replaced with a single `self::RELATIONS`
constant — same "one predicate, not two" rule this codebase keeps re-learning.

## 6. Phases — this is deliberately not one commit

~2,476 lines are being reshaped. That is **larger than TASK-167** (2,011), and ag-lead broke
a smaller move than TASK-167 earlier the same day by editing a file by line number while the
build stayed green. Each phase ends green and shippable.

| Phase | Work | Ends with |
|---|---|---|
| **1** | Extract the deal row + the creation form out of `ReferralsView` into shared components. **No behaviour change.** Both old screens still work, now on shared parts | both screens identical to a user |
| **2** | Mount the deal block inside the client drawer, including เก็บเงินเลย | `ReferralsView` now redundant |
| **3** | Bring the Kanban in as the second view mode (**confirm §3 first**) | `PipelineView` now redundant |
| **4** | Bottom nav swap → สินค้า; redirects; drop the two Home quick links; delete the two dead views | the IA the human asked for |
| **5** | ag-qa (§7) | signed off |

Copy the tree to `/tmp` before starting so a real before/after baseline exists — two
"pre-existing" failures today turned out to be regressions introduced in the same session.

## 7. Verify by name — a green build proves none of this

Enumerate and tick off individually, before and after:

- [ ] **BR-1**: an agent without Basic cert sees the create button disabled with the honest
      reason, and the API still refuses regardless of what the UI shows
- [ ] **BR-6 / §5.4**: an agent sees only their own clients; another company's client id in
      the URL gives 403/404
- [ ] **PDPA**: `health_notes` appears only inside the drawer; every document is fetched
      through the authenticated endpoint, never a raw URL
- [ ] client search + category filter
- [ ] a client with **zero**, **one**, and **several** deals all render correctly
- [ ] **เก็บเงินเลย** creates the order and opens the share modal (TASK-141's ≤3 clicks)
- [ ] stage advancement validates against **that referral's own template** (ADR-026),
      including a direct-sale product whose template is two stages long
- [ ] every stage change still writes the audit log (backend — confirm it still fires)
- [ ] `/referrals` and `/pipeline` redirect rather than 404
- [ ] a company with a custom `nav_sales` label does **not** see it on สินค้า
- [ ] `vue-tsc`, `eslint src`, `vite build`, `php artisan test` clean

## 7b. CLOSED 2026-08-11 — all phases delivered

`ClientsView` is the single sales screen (list + pipeline modes); bottom nav is
`หน้าหลัก · ลูกค้า · สินค้า · Academy · ค่าคอม`; `ReferralsView.vue` and `PipelineView.vue`
are deleted with their URLs redirecting; `HomeView`'s quick links kept per the human.
vitest **56/56** (was 3 at the start of the day), both frontends build clean.

**Backend `php artisan test` was NOT re-run in Phase 4** — no PHP binary in that
environment. Phase 4 touched zero PHP; the last full run (after ag-lead's
`ClientController::RELATIONS` fix) was **1239 passing**. Re-run before merge.

### Defects found and fixed on the way (neither was in scope)

- **BR-1 gate broken in `ClientsView`.** TASK-067 fixed `hasPassedBasic` to filter
  `c.user_id === self` on three screens and missed this one, which still asked *"has anyone
  in this company passed Basic"*. Invisible for a plain Agent (the endpoint returns only
  their own rows) and **open for Company Admin / Super Admin**. Fixed, tested both ways.
- **`ClientController` eager-load drift** — three different shapes across four methods; one
  `self::RELATIONS` now.

### Open follow-ups

1. ~~BR-7 — co-agent edit cutoff.~~ **SETTLED by the human, 2026-08-11 (TASK-170).**

   **Rule: a co-agent split may be edited until the referral reaches `complete_payment`
   under ITS OWN template.** Not a new business value — it follows from BR-4: the ledger
   is written at Complete Payment and is immutable thereafter, so the last honest moment
   to change who gets paid is the moment before it is written.

   This replaces **both** of the disagreeing lists — the frontend's
   `!['complete_payment','ongoing_next_meeting']` deny-list and
   `ReferralService::setCoAgent()`'s allow-list of the three pre-payment *medical* stages.
   One predicate, computed from the referral's own journey, enforced server-side and
   *reflected* client-side — never two implementations.

   > **Correction (ag-lead, 2026-08-12).** This section originally claimed the old
   > allow-list "cannot even be satisfied by a direct-sale template". **That was wrong and
   > I did not verify it before writing it.** §4.3 pins `complete_registered` to position 0
   > of *every* template, so a direct-sale referral before payment is always sitting on a
   > stage the old list contained. The list was still the wrong rule — it named STAGES
   > where the rule names a POSITION, and said nothing about the post-sale stages — but it
   > was not broken for direct sales. `ReferralService::splitIsStillEditable()`'s docblock
   > already puts it correctly: *right by coincidence, not by construction*.

   **Status (verified by reading the tree, 2026-08-12):**
   - **Backend: DONE.** `setCoAgent()` gates on `splitIsStillEditable()`, which delegates to
     `PipelineService::isBeforeStage()` — sharing `offsetFrom()` with `hasReachedStage()`,
     so there is one implementation of "where is this referral relative to X". Unreadable
     template → refused (fail closed). `SetCoAgentTest` covers all five cases plus
     cross-tenant. **Not re-run** — see the environment note below.
   - **Frontend: NOT DONE.** `CoAgentEditor.vue` still carries the deny-list and the
     `// TODO: CONFIRM`, so the UI still offers the control on post-sale stages that the
     server now refuses.
   - **Known blocker for the frontend half:** `ClientsView`'s local `ReferralItem` type and
     `ClientsView.spec.ts`'s `referralFixture` both omit `pipeline`, although the wire
     always carries it. Making it required fails `vue-tsc` at the one binding site and
     reddens four editor tests. **ag-lead ruling: widen the boundary** — add the field to
     both, which is three small additions, rather than making `pipeline` optional and
     inventing a meaning for "absent".
2. **`ReferralCreateForm.vue` is orphaned** (Phase 1 extraction; the drawer kept its own
   inline form). Do not just delete it — its header is the only record of the SWS field
   mapping and the "BR-1 is not enforced here" rule. Move those notes into the drawer's
   form first, then delete.
3. `PUT /clients/{id}` has no test asserting the co-agent relation is loaded, so the
   eager-load drift above can silently return.

## 8. Out of scope

Admin (`frontend-admin`) keeps its own Client/Referral screens — this is the Agent Portal
only. No change to commission, orders, or the Academy. No new business values (BR-7).
