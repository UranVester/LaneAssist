<?php
use PHPUnit\Framework\TestCase;

final class NormalizeTest extends TestCase
{
    public function testDateTimeEmptyDateReturnsEmpty(): void
    {
        $this->assertSame('', normalizeDateTimeValue('', '10:00'));
        $this->assertSame('', normalizeDateTimeValue('0000-00-00', '10:00'));
    }

    public function testDateTimePadsShortTime(): void
    {
        $this->assertSame('2026-08-16 10:00:00', normalizeDateTimeValue('2026-08-16', '10:00'));
    }

    public function testDateTimeEmptyTimeBecomesMidnight(): void
    {
        $this->assertSame('2026-08-16 00:00:00', normalizeDateTimeValue('2026-08-16', ''));
    }

    public function testScheduledDateForUiEmpties(): void
    {
        $this->assertSame('', normalizeScheduledDateForUi('0000-00-00'));
        $this->assertSame('2026-08-16', normalizeScheduledDateForUi('2026-08-16'));
    }

    public function testScheduledTimeForUiRequiresDate(): void
    {
        $this->assertSame('', normalizeScheduledTimeForUi('10:00', ''));
        $this->assertSame('10:00', normalizeScheduledTimeForUi('10:00', '2026-08-16'));
        $this->assertSame('', normalizeScheduledTimeForUi('', '2026-08-16'));
    }
}
