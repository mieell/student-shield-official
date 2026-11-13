<?php

namespace Tests\Unit;

use App\Libraries\ScreeningExplanation;
use App\Libraries\VentRiskAnalyzer;
use CodeIgniter\Test\CIUnitTestCase;

final class ScreeningExplanationTest extends CIUnitTestCase
{
    public function testExplanationIsEncryptedAndCanBeReadBack(): void
    {
        $config = new \Config\Encryption();
        $config->key = random_bytes(32);
        \CodeIgniter\Config\Factories::injectMock('config', 'Encryption', $config);
        try {
            $encoded = ScreeningExplanation::encode(['source' => 'model', 'reasoning' => 'A recorded explanation.']);
            self::assertStringNotContainsString('A recorded explanation.', $encoded);
            self::assertSame(['source' => 'model', 'reasoning' => 'A recorded explanation.'], ScreeningExplanation::decode($encoded));
            self::assertNull(ScreeningExplanation::decode(substr($encoded, 1)));
        } finally {
            \CodeIgniter\Config\Factories::reset('config');
        }
    }

    public function testPreservesActualModelReasoning(): void
    {
        $result = VentRiskAnalyzer::resolve('A school concern', ['risk_level' => 'MEDIUM', 'risk_score' => 45, 'reasoning' => 'Recorded service explanation.'], 200,
            static function () { throw new \LogicException('Unexpected fallback'); });
        self::assertSame('Recorded service explanation.', $result['reasoning']);
        self::assertNull(ScreeningExplanation::decode(null));
        self::assertNull(ScreeningExplanation::decode('old unencrypted data'));
    }

    public function testDoesNotInventReasoningForModelWithoutDetails(): void
    {
        $result = VentRiskAnalyzer::resolve('A school concern', ['risk_level' => 'MEDIUM', 'risk_score' => 45], 200, static fn () => []);
        self::assertSame('The ML service did not return a detailed explanation for this entry.', $result['reasoning']);
    }
}
