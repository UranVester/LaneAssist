<?php

use PHPUnit\Framework\TestCase;

final class TournamentImportLogicTest extends TestCase
{
    public function testDecodesAndCanonicalizesAnIanseoTournamentExport(): void
    {
        $source = gzcompress(serialize(['Tournament' => ['ToCode' => 'PB2026'], 'Entries' => []]), 9);

        [$payload, $error] = laneAssistDecodeTournamentImportPayload($source);

        $this->assertSame('', $error);
        $this->assertIsString($payload);
        $this->assertSame('PB2026', unserialize(gzuncompress($payload), ['allowed_classes' => false])['Tournament']['ToCode']);
    }

    public function testRejectsMalformedAndIncompletePayloads(): void
    {
        [, $invalidError] = laneAssistDecodeTournamentImportPayload('not an export');
        [, $incompleteError] = laneAssistDecodeTournamentImportPayload(gzcompress(serialize(['Entries' => []]), 9));
        [, $objectError] = laneAssistDecodeTournamentImportPayload(gzcompress(serialize(['Tournament' => ['ToCode' => 'PB2026'], 'unsafe' => new stdClass()]), 9));

        $this->assertSame('The archive entry is not a valid compressed IANSEO export.', $invalidError);
        $this->assertSame('The archive entry does not contain an IANSEO tournament export.', $incompleteError);
        $this->assertSame('The archive entry contains unsupported serialized objects.', $objectError);
    }

    public function testOnlyAcceptsTopLevelIanseoFiles(): void
    {
        $this->assertTrue(laneAssistIsTournamentArchiveEntry('PB2026.ianseo'));
        $this->assertTrue(laneAssistIsTournamentArchiveEntry('PB2026.IANSEO'));
        $this->assertFalse(laneAssistIsTournamentArchiveEntry('nested/PB2026.ianseo'));
        $this->assertFalse(laneAssistIsTournamentArchiveEntry('PB2026.zip'));
    }

    public function testReportsWhenAnExportRequiresANewerDatabase(): void
    {
        $this->assertSame(
            'Requires IANSEO database version 2026-09-01 00:00:00; this installation is 2026-08-09 04:30:00. Update IANSEO before importing this tournament.',
            laneAssistTournamentImportCompatibilityError('2026-09-01 00:00:00', '2026-08-09 04:30:00')
        );
        $this->assertSame('', laneAssistTournamentImportCompatibilityError('2026-08-09 04:30:00', '2026-08-09 04:30:00'));
    }
}