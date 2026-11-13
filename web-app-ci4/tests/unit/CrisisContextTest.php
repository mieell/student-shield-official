<?php
namespace Tests\Unit;

use App\Controllers\VentController;
use App\Libraries\ExplicitCrisisGuard;
use App\Libraries\VentRiskAnalyzer;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;

final class CrisisContextTest extends CIUnitTestCase
{
    public static function cases(): iterable
    {
        $path = dirname(__DIR__, 3) . '/ml_service/tests/fixtures/crisis_context.json';
        foreach (json_decode(file_get_contents($path), true) as $i => $case) yield "case $i" => [$case['text'], $case['critical']];
    }

    #[DataProvider('cases')]
    public function testContextInGuardsAndOutagePath(string $text, bool $critical): void
    {
        $method = new ReflectionMethod(VentController::class, 'localKeywordFallback');
        $local = static fn ($entry) => $method->invoke(new VentController(), $entry);
        $result = VentRiskAnalyzer::resolve($text, null, 503, $local);
        $this->assertSame($critical, $result['risk_level'] === 'CRITICAL');
        $primary = VentRiskAnalyzer::resolve($text, ['risk_level' => 'LOW', 'risk_score' => 10], 200, $local);
        $this->assertSame($critical, $primary['risk_level'] === 'CRITICAL');
    }

    public function testPassiveDisappearanceAndCoexistingIntent(): void
    {
        $method = new ReflectionMethod(VentController::class, 'localKeywordFallback');
        $passive = $method->invoke(new VentController(), 'I wish I could disappear. I do not have a plan.');
        $this->assertSame(['risk_level' => 'HIGH', 'risk_percent' => 79], $passive);
        $active = $method->invoke(new VentController(), 'I wish I could disappear. I will kill myself tonight.');
        $this->assertSame('CRITICAL', $active['risk_level']);
        $spaced = $method->invoke(new VentController(), 'Sometimes I wish I could just disappear from everything.');
        $this->assertSame(['risk_level' => 'HIGH', 'risk_percent' => 79], $spaced);
        $postPivot = $method->invoke(new VentController(), 'I thought we would fail earlier but we passed with flying colors! Still, gusto ko na talagang maglaho na lang.');
        $this->assertSame(['risk_level' => 'HIGH', 'risk_percent' => 79], $postPivot);
    }

    public function testRegressionCasesForResolutionSustainedDistressAndMixedSignals(): void
    {
        $method = new ReflectionMethod(VentController::class, 'localKeywordFallback');
        $ctrl = new VentController();

        // Case 1: Resolved thesis panic + tears of joy -> LOW
        $case1 = "Gusto ko nang sumuko at maglaho kanina habang naghihintay ng deliberation ng panel, kasi akala ko bumagsak na kami at nasayang lahat ng puyat namin. Sobrang bigat sa dibdib at parang hihimatayin na ako sa sobrang kaba, halos maiyak na ako sa takot na uulit kami next semester. Plot twist, pagpasok namin sa room, sinabi nilang pasado kami with flying colors at best thesis pa! Napahagulgol talaga ako sa harap ng prof namin na parang baliw, pero tears of joy pala dahil tapos na ang kalbaryo namin\u{2014}sobrang slay ng ending na ito, dasurv!";
        $this->assertSame('LOW', $method->invoke($ctrl, $case1)['risk_level']);

        // Dog emergency surgery resolved with pure relief -> LOW
        $dog = "I was literally sobbing on the floor and ready to give up all hope this morning because we thought our family dog was not going to survive his emergency surgery. My chest hurt so badly from crying that I could barely breathe, and I honestly felt like my world was ending\u{2014}until the vet walked out smiling and told us he pulled through completely fine! I collapsed into my chair ugly-crying and shaking uncontrollably from pure relief. I practically lost my voice screaming with joy in the waiting room, lowkey the greatest miracle of my entire life, no cap!";
        $this->assertSame('LOW', $method->invoke($ctrl, $dog)['risk_level']);

        // Case 2: Sustained daily distress + worthless + ambiguous endurance -> HIGH (not CRITICAL)
        $case2 = "Sobrang sakit na sa pakiramdam na kahit ibigay mo na ang lahat sa mga taong mahalaga sa'yo, sa dulo ay iiwan ka pa rin nilang mag-isa. Araw-araw akong gumigising na parang durog na durog ang pagkatao ko at wala nang natitirang rason para bumangon pa. Pagod na pagod na akong magkunwaring malakas habang unti-unti na akong nilalamon ng lungkot at dilim sa loob ng kwarto ko. Pakiramdam ko ay wala akong silbi at hindi ako karapat-dapat mahalin ng kahit sino. Gusto ko na lang matapos itong walang katapusang paghihirap dahil hindi ko na talaga kayang dalhin pa ang bigat sa dibdib ko.";
        $resCase2 = $method->invoke($ctrl, $case2);
        $this->assertSame('HIGH', $resCase2['risk_level']);
        $this->assertGreaterThanOrEqual(61, $resCase2['risk_percent']);
        $this->assertLessThanOrEqual(85, $resCase2['risk_percent']);

        // Mixed positive + active crisis -> CRITICAL
        $mixed = 'Passed the exam today with flying colors, pero gusto ko na talagang mamatay and end my life tonight.';
        $this->assertSame('CRITICAL', $method->invoke($ctrl, $mixed)['risk_level']);

        // Tagalog inflection & spaced sarcasm -> CRITICAL even if ML returns LOW
        foreach ([
            'papakamatay na ako at hindi ko na kaya mabuhay ngayon',
            'i surely do want to kill my self wow amazing lupettt i love ittt!!',
        ] as $criticalText) {
            $resolved = VentRiskAnalyzer::resolve(
                $criticalText,
                ['risk_level' => 'LOW', 'risk_score' => 5],
                200,
                static fn ($entry) => $method->invoke($ctrl, $entry)
            );
            $this->assertSame('CRITICAL', $resolved['risk_level']);
        }
    }
}
