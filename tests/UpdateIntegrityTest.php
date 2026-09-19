<?php

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/Settings/update-integrity.php';

class UpdateIntegrityTest extends TestCase {
    private $root;

    protected function setUp(): void {
        $this->root = sys_get_temp_dir() . '/laneassist-update-integrity-' . uniqid('', true);
        mkdir($this->root . '/Modules/Custom/LaneAssist/Settings/js', 0775, true);
        mkdir($this->root . '/Modules/Custom/LaneAssist/Common/js', 0775, true);
    }

    protected function tearDown(): void {
        $this->removeDirectory($this->root);
    }

    public function testReportsMissingCriticalFiles(): void {
        file_put_contents($this->root . '/Modules/Custom/LaneAssist/Settings/api.php', '<?php');

        $result = verifyLaneAssistUpdateIntegrity($this->root);

        $this->assertFalse($result['ok']);
        $this->assertSame([
            'Modules/Custom/LaneAssist/Settings/index.php',
            'Modules/Custom/LaneAssist/Settings/js/app.js',
            'Modules/Custom/LaneAssist/Common/js/update-status.js',
        ], $result['missingFiles']);
    }

    public function testPassesWhenAllCriticalFilesAreReadable(): void {
        foreach ([
            'Modules/Custom/LaneAssist/Settings/api.php',
            'Modules/Custom/LaneAssist/Settings/index.php',
            'Modules/Custom/LaneAssist/Settings/js/app.js',
            'Modules/Custom/LaneAssist/Common/js/update-status.js',
        ] as $relativePath) {
            file_put_contents($this->root . '/' . $relativePath, 'test');
        }

        $result = verifyLaneAssistUpdateIntegrity($this->root);

        $this->assertTrue($result['ok']);
        $this->assertSame([], $result['missingFiles']);
    }

    public function testAtomicWriterMakesNewAndExistingFilesWebReadable(): void {
        $newFile = $this->root . '/Modules/Custom/LaneAssist/Common/js/new-file.js';
        $existingFile = $this->root . '/Modules/Custom/LaneAssist/Common/js/existing-file.js';
        file_put_contents($existingFile, 'old');
        chmod($existingFile, 0600);

        $this->assertTrue(writeLaneAssistUpdateFileAtomically($newFile, 'new'));
        $this->assertTrue(writeLaneAssistUpdateFileAtomically($existingFile, 'updated'));
        $this->assertSame('new', file_get_contents($newFile));
        $this->assertSame('updated', file_get_contents($existingFile));
        $this->assertSame(0664, fileperms($newFile) & 0777);
        $this->assertSame(0664, fileperms($existingFile) & 0777);
    }

    public function testPermissionNormalizerRepairsUnchangedFile(): void {
        $file = $this->root . '/Modules/Custom/LaneAssist/Common/js/unchanged-file.js';
        file_put_contents($file, 'unchanged');
        chmod($file, 0600);

        $this->assertTrue(normalizeLaneAssistUpdateFilePermissions($file));
        $this->assertSame('unchanged', file_get_contents($file));
        $this->assertSame(0664, fileperms($file) & 0777);
    }

    private function removeDirectory($path): void {
        if (!is_dir($path)) {
            return;
        }

        $items = scandir($path);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $itemPath = $path . '/' . $item;
            if (is_dir($itemPath)) {
                $this->removeDirectory($itemPath);
            } else {
                unlink($itemPath);
            }
        }
        rmdir($path);
    }
}