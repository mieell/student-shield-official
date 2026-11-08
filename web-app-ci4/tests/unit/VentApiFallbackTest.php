<?php
namespace Tests\Unit;

use App\Controllers\VentController;
use App\Libraries\VentRiskAnalyzer;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;

final class VentApiFallbackTest extends CIUnitTestCase
{
    private function local(): callable
    {
        $method = new ReflectionMethod(VentController::class, 'localKeywordFallback');
        return static fn (string $text) => $method->invoke(new VentController(), $text);
    }

    public function testValidPrimaryIsUsed(): void
    {
        $result = VentRiskAnalyzer::resolve('ordinary entry', ['risk_level' => 'MEDIUM', 'risk_score' => 45], 200,
            static function () { throw new \LogicException('Fallback should not run'); });
        $this->assertSame('model', $result['source']);
        $this->assertSame(45.0, $result['risk_percent']);
    }

    public static function failures(): iterable
    {
        yield 'transport' => [null, 0];
        yield 'unauthorized' => [null, 401];
        yield 'server error' => [null, 503];
        yield 'invalid result' => [['risk_level' => 'LOW', 'risk_score' => 99], 200];
    }

    #[DataProvider('failures')]
    public function testFailedPrimaryUsesLocalRules($result, int $status): void
    {
        $assessment = VentRiskAnalyzer::resolve('naglalaslas ako', $result, $status, $this->local());
        $this->assertSame('local', $assessment['source']);
        $this->assertSame('CRITICAL', $assessment['risk_level']);
    }

    public function testPrimaryStillUsesCrisisGuard(): void
    {
        $result = VentRiskAnalyzer::resolve('naglalaslas ako', ['risk_level' => 'LOW', 'risk_score' => 10], 200, $this->local());
        $this->assertSame('CRITICAL', $result['risk_level']);
    }
}
