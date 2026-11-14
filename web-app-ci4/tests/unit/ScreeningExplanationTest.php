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

            $groqEncoded = ScreeningExplanation::encode(['source' => 'groq', 'reasoning' => '[Groq Fallback] Veiled hopelessness noted.']);
            self::assertStringNotContainsString('Veiled hopelessness noted.', $groqEncoded);
            self::assertSame(
                ['source' => 'groq', 'reasoning' => '[Groq Fallback] Veiled hopelessness noted.'],
                ScreeningExplanation::decode($groqEncoded)
            );

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

    public function testPreservesGroqSourceAndReasoningThroughSafetyFloors(): void
    {
        $groqPrimary = [
            'risk_level' => 'HIGH',
            'risk_score' => 77,
            'reasoning' => '[Groq Fallback] Veiled Taglish hopelessness noted.',
            'source' => 'groq',
        ];
        $result = VentRiskAnalyzer::resolve('pagod na ko sa lahat', $groqPrimary, 200, static function () {
            throw new \LogicException('Local fallback should not run when Groq succeeded.');
        });

        self::assertSame('groq', $result['source']);
        self::assertSame('HIGH', $result['risk_level']);
        self::assertSame(77.0, $result['risk_percent']);
        self::assertSame('[Groq Fallback] Veiled Taglish hopelessness noted.', $result['reasoning']);

        // Even if Groq returns LOW on an explicit self-harm entry, PHP safety rules must raise to CRITICAL.
        $underestimatedGroq = [
            'risk_level' => 'LOW',
            'risk_score' => 15,
            'reasoning' => '[Groq Fallback] Casual phrasing.',
            'source' => 'groq',
        ];
        $enforced = VentRiskAnalyzer::resolve('I will kill myself tonight', $underestimatedGroq, 200, static fn () => []);
        self::assertSame('groq', $enforced['source']);
        self::assertSame('CRITICAL', $enforced['risk_level']);
        self::assertGreaterThanOrEqual(86.0, $enforced['risk_percent']);
        self::assertStringContainsString('PHP safety rules raised the final review priority', $enforced['reasoning']);
    }
}
