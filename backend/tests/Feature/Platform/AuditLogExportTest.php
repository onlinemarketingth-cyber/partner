<?php

namespace Tests\Feature\Platform;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * TASK-242 — the audit trail as a file.
 *
 * An export is a different kind of object from a screen: it leaves the
 * building. Everything asserted here follows from that one fact.
 *
 *   • It must answer the SAME question as the screen it was exported from
 *     (BR-6 included), because the discrepancy is discovered by the auditor
 *     you handed it to, not by you.
 *   • It must be bounded. "Every action ever recorded, with personal data,
 *     as one file" should not be one click away.
 *   • It must itself be recorded. Until this task, the one thing the audit
 *     log could not tell you was who had read the audit log.
 *   • It must be safe to open. The cells carry names and JSON that people
 *     outside the reading company typed, and the reader opens them in Excel
 *     precisely because their own system produced the file.
 */
class AuditLogExportTest extends TestCase
{
    use RefreshDatabase;

    private Company $thaiLife;

    private Company $genesenn;

    private User $thaiLifeAdmin;

    private User $genesennAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->thaiLife = Company::factory()->create();
        $this->genesenn = Company::factory()->create();

        $this->thaiLifeAdmin = User::factory()->companyAdmin()->create([
            'company_id' => $this->thaiLife->id,
            'first_name' => 'เกรียงยศ',
            'last_name' => 'ผู้ดูแล',
        ]);
        $this->genesennAdmin = User::factory()->companyAdmin()->create(['company_id' => $this->genesenn->id]);
    }

    private function writeRow(User $actor, Company $company, string $action, ?array $newValues = null): AuditLog
    {
        return AuditLog::create([
            'company_id' => $company->id,
            'actor_user_id' => $actor->id,
            'action' => $action,
            'auditable_type' => User::class,
            'auditable_id' => $actor->id,
            'old_values' => null,
            'new_values' => $newValues,
            'ip_address' => '127.0.0.1',
        ]);
    }

    /** The streamed body, which only exists once the response is sent. */
    private function csv(TestResponse $response): string
    {
        return $response->streamedContent();
    }

    public function test_a_company_admin_can_export_their_own_companys_trail(): void
    {
        $this->writeRow($this->thaiLifeAdmin, $this->thaiLife, 'commission_rule.created');

        $response = $this->actingAs($this->thaiLifeAdmin)
            ->get('/api/v1/audit-logs/export')
            ->assertOk();

        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('commission_rule.created', $this->csv($response));
    }

    public function test_an_agent_cannot_export_the_trail_at_all(): void
    {
        $agent = User::factory()->agent()->create(['company_id' => $this->thaiLife->id]);

        $this->actingAs($agent)->get('/api/v1/audit-logs/export')->assertForbidden();
    }

    public function test_another_companys_rows_are_not_in_the_file(): void
    {
        /*
         * BR-6, and the reason it is asserted on the export separately from
         * the screen: a second code path that forgets the company narrowing
         * does not look broken — it produces a bigger file, which reads as a
         * more complete one.
         */
        $this->writeRow($this->thaiLifeAdmin, $this->thaiLife, 'commission_rule.created');
        $this->writeRow($this->genesennAdmin, $this->genesenn, 'user.role_changed');

        $csv = $this->csv(
            $this->actingAs($this->thaiLifeAdmin)->get('/api/v1/audit-logs/export')->assertOk(),
        );

        $this->assertStringContainsString('commission_rule.created', $csv);
        $this->assertStringNotContainsString('user.role_changed', $csv);
    }

    public function test_the_file_honours_the_same_filters_as_the_screen(): void
    {
        // The export is taken FROM a filtered screen. A file that quietly
        // widens the question is the one that gets forwarded.
        $this->writeRow($this->thaiLifeAdmin, $this->thaiLife, 'user.role_changed');
        $other = User::factory()->companyAdmin()->create(['company_id' => $this->thaiLife->id]);
        $this->writeRow($other, $this->thaiLife, 'user.deactivated');

        $csv = $this->csv(
            $this->actingAs($this->thaiLifeAdmin)
                ->get("/api/v1/audit-logs/export?actor_user_id={$this->thaiLifeAdmin->id}")
                ->assertOk(),
        );

        $this->assertStringContainsString('user.role_changed', $csv);
        $this->assertStringNotContainsString('user.deactivated', $csv);
    }

    public function test_a_range_wider_than_a_year_is_refused_with_the_number_of_days(): void
    {
        /*
         * The bound is not about query cost — TASK-240's indexes made this
         * query cheap. It is about what one click can put in a file.
         *
         * The message carries the width asked for: "too wide" alone leaves
         * the admin guessing by how much, and a person who cannot tell will
         * retry rather than narrow.
         */
        $response = $this->actingAs($this->thaiLifeAdmin)
            ->getJson('/api/v1/audit-logs/export?date_from=2020-01-01&date_to=2026-01-01')
            ->assertStatus(422);

        $this->assertStringContainsString('366', $response->json('message'));
    }

    public function test_a_range_of_exactly_a_year_is_allowed(): void
    {
        // The boundary belongs to the caller: "the last year" is the single
        // most likely thing anybody types here and must not be refused.
        $this->actingAs($this->thaiLifeAdmin)
            ->get('/api/v1/audit-logs/export?date_from=2026-01-01&date_to=2026-12-31')
            ->assertOk();
    }

    public function test_asking_for_no_dates_at_all_still_produces_a_bounded_file(): void
    {
        /*
         * The default has to be a WINDOW, not "everything". An unbounded
         * default is the same defect as no limit, reached by the path
         * everybody actually takes — pressing the button without typing
         * dates.
         */
        $old = $this->writeRow($this->thaiLifeAdmin, $this->thaiLife, 'ancient.action');
        $old->forceFill(['created_at' => now()->subYears(3)])->save();
        $this->writeRow($this->thaiLifeAdmin, $this->thaiLife, 'recent.action');

        $csv = $this->csv(
            $this->actingAs($this->thaiLifeAdmin)->get('/api/v1/audit-logs/export')->assertOk(),
        );

        $this->assertStringContainsString('recent.action', $csv);
        $this->assertStringNotContainsString('ancient.action', $csv);
    }

    public function test_a_backwards_range_is_refused_rather_than_returning_an_empty_file(): void
    {
        // An empty CSV reads as "nothing happened in that period", which is a
        // conclusion, not an error.
        $this->actingAs($this->thaiLifeAdmin)
            ->getJson('/api/v1/audit-logs/export?date_from=2026-06-01&date_to=2026-01-01')
            ->assertStatus(422);
    }

    public function test_the_export_is_itself_recorded_with_the_filters_it_used(): void
    {
        /*
         * The point of the whole task. "Somebody exported the audit log" is
         * nearly useless a year later; "somebody exported every action by
         * user #7 between these dates, and got N rows" is an answer.
         */
        $this->writeRow($this->thaiLifeAdmin, $this->thaiLife, 'user.role_changed');

        $this->actingAs($this->thaiLifeAdmin)
            ->get("/api/v1/audit-logs/export?actor_user_id={$this->thaiLifeAdmin->id}&date_from=2026-01-01&date_to=2026-12-31")
            ->assertOk();

        $row = AuditLog::where('action', 'audit_log.exported')->firstOrFail();

        $this->assertSame($this->thaiLifeAdmin->id, $row->actor_user_id);
        $this->assertSame($this->thaiLife->id, $row->company_id);
        $this->assertSame('2026-01-01', $row->new_values['date_from']);
        $this->assertSame('2026-12-31', $row->new_values['date_to']);
        $this->assertSame($this->thaiLifeAdmin->id, $row->new_values['actor_user_id']);
        $this->assertSame(1, $row->new_values['row_count']);
    }

    public function test_the_file_does_not_contain_the_record_of_its_own_export(): void
    {
        /*
         * The export writes an `audit_log.exported` row that matches its own
         * filters — same company, same actor, inside the window — so without
         * a snapshot ceiling the file describes an event that happened after
         * the moment it claims to cover, and the row_count recorded beside it
         * is one short of the rows in the file. Small, self-inflicted, and
         * exactly the sort of thing that discredits a document produced for
         * an auditor.
         */
        $this->writeRow($this->thaiLifeAdmin, $this->thaiLife, 'commission_rule.created');

        $csv = $this->csv(
            $this->actingAs($this->thaiLifeAdmin)->get('/api/v1/audit-logs/export')->assertOk(),
        );

        $this->assertStringNotContainsString('audit_log.exported', $csv);
        // One header line + one data row + the trailing newline.
        $this->assertCount(3, explode("\n", $csv));
        $this->assertSame(
            1,
            AuditLog::where('action', 'audit_log.exported')->firstOrFail()->new_values['row_count'],
        );
    }

    public function test_an_export_that_matches_nothing_stays_empty(): void
    {
        // The mirror of the test above: with a NULL ceiling there is nothing
        // to cap, and the naive fix ("no ceiling then") would hand back a
        // file containing exactly one row — the export of itself.
        $this->actingAs($this->thaiLifeAdmin)
            ->get('/api/v1/audit-logs/export')
            ->assertOk();

        $csv = $this->csv(
            $this->actingAs($this->thaiLifeAdmin)->get('/api/v1/audit-logs/export?action=nothing.matches')->assertOk(),
        );

        $this->assertStringNotContainsString('audit_log.exported', $csv);
    }

    public function test_a_refused_export_leaves_no_audit_row(): void
    {
        // Nothing was read, so nothing is recorded. A row for an export that
        // never produced a file would make the trail's own history wrong in
        // the direction of accusing somebody.
        $this->actingAs($this->thaiLifeAdmin)
            ->getJson('/api/v1/audit-logs/export?date_from=2020-01-01&date_to=2026-01-01')
            ->assertStatus(422);

        $this->assertSame(0, AuditLog::where('action', 'audit_log.exported')->count());
    }

    public function test_a_super_admin_exporting_every_company_records_that_it_was_every_company(): void
    {
        /*
         * company_id null on the export row is meaningful, not missing: a
         * cross-tenant export is exactly the one worth noticing later, and it
         * is the only export a Company Admin can never produce.
         */
        $superAdmin = User::factory()->superAdmin()->create();
        $this->writeRow($this->thaiLifeAdmin, $this->thaiLife, 'commission_rule.created');
        $this->writeRow($this->genesennAdmin, $this->genesenn, 'commission_rule.created');

        $csv = $this->csv(
            $this->actingAs($superAdmin)->get('/api/v1/audit-logs/export')->assertOk(),
        );

        $this->assertSame(2, substr_count($csv, 'commission_rule.created'));

        $row = AuditLog::where('action', 'audit_log.exported')->firstOrFail();
        $this->assertNull($row->company_id);
    }

    public function test_a_formula_in_a_name_is_neutralised_before_it_reaches_a_spreadsheet(): void
    {
        /*
         * CSV injection. `=HYPERLINK(...)` in a cell runs when the reader
         * clicks it — in Excel, not here, which is why this application must
         * not hand the file over armed. An agent's own name is free text
         * they typed, and the audit values are whatever was recorded about
         * them.
         */
        $attacker = User::factory()->agent()->create([
            'company_id' => $this->thaiLife->id,
            'first_name' => '=HYPERLINK("http://attacker.example","เปิด")',
            'last_name' => 'x',
        ]);
        $this->writeRow($attacker, $this->thaiLife, 'user.bank_account_updated');

        $csv = $this->csv(
            $this->actingAs($this->thaiLifeAdmin)->get('/api/v1/audit-logs/export')->assertOk(),
        );

        $this->assertStringNotContainsString('"=HYPERLINK', $csv);
        $this->assertStringContainsString("'=HYPERLINK", $csv);
    }

    public function test_thai_text_survives_the_round_trip(): void
    {
        /*
         * The BOM and JSON_UNESCAPED_UNICODE together. Without the first,
         * Excel renders Thai as mojibake; without the second, the values
         * column is a wall of \uXXXX escapes. Both produce a file that is
         * technically correct and useless to the person who asked for it.
         */
        $this->writeRow($this->thaiLifeAdmin, $this->thaiLife, 'user.role_changed', ['role' => 'หัวหน้าทีม']);

        $csv = $this->csv(
            $this->actingAs($this->thaiLifeAdmin)->get('/api/v1/audit-logs/export')->assertOk(),
        );

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('เกรียงยศ', $csv);
        $this->assertStringContainsString('หัวหน้าทีม', $csv);
        // …and not the escaped form of the same string.
        $this->assertStringNotContainsString('\\u0e2b', $csv);
    }
}
