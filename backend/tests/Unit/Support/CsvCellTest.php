<?php

namespace Tests\Unit\Support;

use App\Support\CsvCell;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The one place the CSV-injection rule is asserted (2026-09-05).
 *
 * It used to be a private static on the commission payout controller, tested
 * only through that endpoint's own feature tests. TASK-242 needed the same
 * rule for the audit-log export, and a security rule that exists in two files
 * is a security rule that gets fixed in one of them.
 */
class CsvCellTest extends TestCase
{
    public static function dangerousValues(): array
    {
        return [
            'formula' => ['=1+1'],
            'hyperlink' => ['=HYPERLINK("http://attacker.example","เปิด")'],
            'plus' => ['+1+1'],
            'minus' => ['-1+1'],
            // The DDE lead-in on older Office builds.
            'at' => ['@SUM(A1)'],
            // Both of these can push a payload into a neighbouring cell.
            'tab' => ["\tmoved"],
            'carriage return' => ["\rmoved"],
        ];
    }

    #[DataProvider('dangerousValues')]
    public function test_a_cell_that_a_spreadsheet_would_execute_is_quoted_as_text(string $value): void
    {
        $this->assertSame("'".$value, CsvCell::safe($value));
    }

    public function test_ordinary_text_is_returned_untouched(): void
    {
        // The apostrophe is invisible in a rendered cell but not in a diff or
        // a re-import, so it must not be sprayed over every value.
        $this->assertSame('เกรียงยศ โอหุยหะนภา', CsvCell::safe('เกรียงยศ โอหุยหะนภา'));
        $this->assertSame('user.role_changed', CsvCell::safe('user.role_changed'));
        $this->assertSame('203.0.113.9', CsvCell::safe('203.0.113.9'));
    }

    public function test_a_dangerous_character_later_in_the_string_is_left_alone(): void
    {
        // Only the FIRST character decides whether a spreadsheet treats the
        // cell as a formula. Quoting on a match anywhere would mangle every
        // negative number and every e-mail address.
        $this->assertSame('a=1', CsvCell::safe('a=1'));
        $this->assertSame('somebody@example.com', CsvCell::safe('somebody@example.com'));
    }

    public function test_an_empty_cell_stays_empty(): void
    {
        // A lone apostrophe in an empty cell reads as data that is not there.
        $this->assertSame('', CsvCell::safe(''));
    }
}
