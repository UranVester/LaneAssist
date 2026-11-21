<?php
use PHPUnit\Framework\TestCase;

/** Keeps the Lang/ catalogs complete: every language has every English key. */
final class CatalogTest extends TestCase {
    private static function load(string $file): array {
        return require $file;
    }

    private static function placeholders(string $text): array {
        preg_match_all('/\{([a-zA-Z0-9_]+)\}/', $text, $m);
        $names = array_unique($m[1]);
        sort($names);
        return $names;
    }

    public function testEveryCatalogFileReturnsAnArray(): void {
        $files = glob(dirname(__DIR__) . '/Lang/*.php');
        $this->assertNotEmpty($files);
        foreach ($files as $file) {
            $this->assertIsArray(self::load($file), basename($file));
        }
    }

    public function testDanishHasExactlyTheEnglishKeys(): void {
        $en = self::load(dirname(__DIR__) . '/Lang/en.php');
        $dk = self::load(dirname(__DIR__) . '/Lang/dk.php');
        $this->assertSame([], array_values(array_diff(array_keys($en), array_keys($dk))), 'missing in dk');
        $this->assertSame([], array_values(array_diff(array_keys($dk), array_keys($en))), 'only in dk');
    }

    public function testNoEmptyTextsAndPlaceholdersMatch(): void {
        $en = self::load(dirname(__DIR__) . '/Lang/en.php');
        foreach (glob(dirname(__DIR__) . '/Lang/*.php') as $file) {
            foreach (self::load($file) as $key => $text) {
                $this->assertIsString($text, basename($file) . " $key");
                $this->assertNotSame('', trim($text), basename($file) . " $key is empty");
                if (isset($en[$key])) {
                    $this->assertSame(self::placeholders($en[$key]), self::placeholders($text),
                        basename($file) . " $key placeholders differ from en");
                }
            }
        }
    }

    /** Common/js/i18n.js englishTranslator() reads en.php line by line; every entry must be readable that way. */
    public function testEnglishCatalogIsOneSingleQuotedEntryPerLine(): void {
        $file = dirname(__DIR__) . '/Lang/en.php';
        $count = preg_match_all("/^\\s*'([a-zA-Z0-9.]+)'\\s*=>\\s*'((?:[^'\\\\]|\\\\.)*)',?\\s*$/m", file_get_contents($file));
        $this->assertSame(count(self::load($file)), $count);
    }
}
