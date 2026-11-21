<?php
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class I18nTest extends TestCase {
    protected function tearDown(): void {
        laSetLang('en');
    }

    public function testRealCatalogLooksUpActiveLanguage(): void {
        laSetLang('en');
        $this->assertSame('English', la_t('common.languageCheck'));
        laSetLang('dk');
        $this->assertSame('Dansk', la_t('common.languageCheck'));
    }

    public function testMissingDanishFallsBackToEnglishThenKey(): void {
        laCatalogInject('en', ['test.onlyEnglish' => 'Only English']);
        laSetLang('dk');
        $this->assertSame('Only English', la_t('test.onlyEnglish'));
        $this->assertSame('no.such.key', la_t('no.such.key'));
    }

    #[DataProvider('languageOverrides')]
    public function testLanguageIsNormalisedAndValidated(string $in, string $expected): void {
        laSetLang($in);
        $this->assertSame($expected, laLang());
    }

    public static function languageOverrides(): array {
        return [
            'upper case' => ['DK', 'dk'],
            'iso code we do not use' => ['da', 'en'],
            'path traversal' => ['../../x', 'en'],
            'empty' => ['', 'en'],
            'core has it, we do not' => ['de', 'en'],
            'english' => ['en', 'en'],
        ];
    }

    public function testPlaceholdersAreSinglePass(): void {
        laCatalogInject('en', ['test.of' => '{n} of {total}']);
        $this->assertSame('1 of 3', la_t('test.of', ['n' => 1, 'total' => 3]));
        $this->assertSame('{total} of 3', la_t('test.of', ['n' => '{total}', 'total' => 3]));
        $this->assertSame('1 of {total}', la_t('test.of', ['n' => 1]));
    }

    public function testPluralPicksOneOrOtherAndFillsCount(): void {
        laCatalogInject('en', ['test.archers.one' => '{count} archer', 'test.archers.other' => '{count} archers']);
        $this->assertSame('1 archer', laPlural('test.archers', 1));
        $this->assertSame('0 archers', laPlural('test.archers', 0));
        $this->assertSame('2 archers', laPlural('test.archers', 2));
    }

    public function testCatalogForFiltersByPrefixAndMergesEnglish(): void {
        laCatalogInject('en', ['testa.x' => 'X', 'testa.y' => 'Y', 'testb.z' => 'Z']);
        laCatalogInject('dk', ['testa.x' => 'XX']);
        laSetLang('dk');
        $this->assertSame(['testa.x' => 'XX', 'testa.y' => 'Y'], laCatalogFor(['testa.']));
    }

    public function testJsCatalogScriptCannotBreakOutOfScriptTag(): void {
        $value = '</script>"\'&æ';
        laCatalogInject('en', ['testjs.y' => $value]);
        $html = laJsCatalogScript(['testjs.']);
        $this->assertStringStartsWith('<script>var LA_I18N = ', $html);
        $this->assertStringEndsWith(';</script>', $html);
        $inner = substr($html, strlen('<script>'), -strlen('</script>'));
        $this->assertStringNotContainsString('</script>', $inner);
        $this->assertStringNotContainsStringIgnoringCase('</', $inner);
        $json = substr($inner, strlen('var LA_I18N = '), -1);
        $this->assertSame(['testjs.y' => $value], json_decode($json, true));
        $this->assertStringContainsString('æ', $inner);
    }

    public function testHtmlVariantEscapesTextAndParams(): void {
        laCatalogInject('en', ['testh.x' => 'A & "{name}"']);
        $this->assertSame('A &amp; &quot;&lt;b&gt;&quot;', la_h('testh.x', ['name' => '<b>']));
    }

    public function testUsesUserLanguageNotPrintLanguage(): void {
        $src = file_get_contents(dirname(__DIR__) . '/Common/i18n.php');
        $this->assertStringContainsString('SelectLanguage(true)', $src);
    }
}
