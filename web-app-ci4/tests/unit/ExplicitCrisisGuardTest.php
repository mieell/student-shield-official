<?php

namespace Tests\Unit;

use App\Controllers\VentController;
use App\Libraries\ExplicitCrisisGuard;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;

final class ExplicitCrisisGuardTest extends CIUnitTestCase
{
    public static function selfHarmTexts(): array
    {
        return array_map(static fn ($text) => [$text], [
            'sinasaksak ko sarili ko sa sobrang lungkot. like naglalaslas',
            'naglalaslas ako',
            'maglalaslas ako haha charot',
            'naglaslas ako',
            'maglaslas',
            'nilalaslas ko ang braso ko',
            'nilaslas ko ang pulso ko',
            'laslasin ko ang pulso ko',
            'sinasaktan ko sarili ko',
            'sinaktan ko ang sarili ko',
            'sasaktan ang sarili ko',
            'saktan ko ang sarili',
            'sinasaksak ko sarili ko',
            'sinaksak ang sarili ko',
            'sasaksakin ko ang sarili ko',
            'saksakin ko ang sarili',
            "SINASAKTAN KO\nANG SARILI KO lol",
            'kill myself',
            'magpakamatay',
            'pakamatay na ako tangina',
            'papakamatay na ako',
            'nagpakamatay',
            'magpapakamatay',
            'nagbigti',
            'magpatiwakal',
            'kayat kon matayen',
            'i surely do want to kill my self wow amazing lupettt i love it!!',
            'kill  my  self',
            'cut my self',
        ]);
    }

    #[DataProvider('selfHarmTexts')]
    public function testExplicitSelfHarmOverridesSuccessfulLowRiskMLResult(string $text): void
    {
        foreach (['LOW', 'MEDIUM', 'HIGH'] as $level) {
            $result = ExplicitCrisisGuard::enforce($text, ['risk_level' => $level, 'risk_percent' => 50.0]);
            $this->assertSame('CRITICAL', $result['risk_level']);
            $this->assertSame(99.0, $result['risk_percent']);
        }
    }

    #[DataProvider('selfHarmTexts')]
    public function testOfflineFallbackTreatsExplicitSelfHarmAsCritical(string $text): void
    {
        $fallback = new ReflectionMethod(VentController::class, 'localKeywordFallback');
        $result = $fallback->invoke(new VentController(), $text);
        $this->assertSame('CRITICAL', $result['risk_level']);
        $this->assertSame(99, $result['risk_percent']);
    }

    public function testOtherDirectedHarmAndOrdinarySadnessKeepTheMLClassification(): void
    {
        $classification = ['risk_level' => 'MEDIUM', 'risk_percent' => 50.0, 'trajectory' => 'STABLE'];
        foreach ([
            'sinasaktan ako ng kaklase ko',
            'sinaksak ang kaklase ko',
            'sinasaktan ang damdamin ko',
            'inaalagaan ko ang sarili ko',
            'nalulungkot ako',
            'Naglalaslaser ang laruan',
        ] as $text) {
            $this->assertSame($classification, ExplicitCrisisGuard::enforce($text, $classification));
        }
    }

    public function testExistingCriticalScoreAndMetadataArePreserved(): void
    {
        $classification = ['risk_level' => 'CRITICAL', 'risk_percent' => 100.0, 'trajectory' => 'ESCALATING'];
        $this->assertSame($classification, ExplicitCrisisGuard::enforce('naglalaslas ako', $classification));
    }

    public function testExistingPassiveFallbackRulesArePreserved(): void
    {
        $fallback = new ReflectionMethod(VentController::class, 'localKeywordFallback');
        $result = $fallback->invoke(new VentController(), 'i wanna die haha charot');
        $this->assertSame(['risk_level' => 'MEDIUM', 'risk_percent' => 60], $result);
    }

    public function testTagalogHopelessnessAndDisappearanceFallbackIsHigh(): void
    {
        $fallback = new ReflectionMethod(VentController::class, 'localKeywordFallback');
        $text = 'Pagod na pagod na akong magpanggap na kaya ko pa, matagal na akong sumuko at pabigat na lang ako, mas mabuti pang maglaho na lang.';
        $result = $fallback->invoke(new VentController(), $text);
        $this->assertSame('HIGH', $result['risk_level']);
        $this->assertSame(79, $result['risk_percent']);
    }
}
