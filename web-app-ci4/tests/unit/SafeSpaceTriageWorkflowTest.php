<?php

namespace Tests\Unit;

use App\Controllers\VentController;
use CodeIgniter\Test\CIUnitTestCase;

final class SafeSpaceTriageWorkflowTest extends CIUnitTestCase
{
    public function testMergeFollowUpAppendsAdditionalContextOnceAndPreservesPlainMessages(): void
    {
        self::assertSame(
            'Pagod na ako sa lahat ng quizzes.',
            VentController::mergeFollowUp('  Pagod na ako sa lahat ng quizzes.  ', '')
        );

        $merged = VentController::mergeFollowUp(
            'kms sa math exam',
            'Just letting off steam / lighthearted. Joke lang po stressful lang quiz.'
        );
        self::assertSame(
            "kms sa math exam\n\nAdditional context: Just letting off steam / lighthearted. Joke lang po stressful lang quiz.",
            $merged
        );

        // Passing an already-merged message with the same follow-up must not duplicate it.
        self::assertSame(
            $merged,
            VentController::mergeFollowUp($merged, 'Just letting off steam / lighthearted. Joke lang po stressful lang quiz.')
        );
    }

    public function testNormalizeEmotionsTrimsDeduplicatesAndCapsLength(): void
    {
        self::assertSame('Sad,Anxious,Overwhelmed', VentController::normalizeEmotions(' Sad , Anxious, , Sad , Overwhelmed '));
        self::assertSame('', VentController::normalizeEmotions(' , , '));
        self::assertLessThanOrEqual(255, mb_strlen(VentController::normalizeEmotions(str_repeat('FeelingLabel,', 40))));
    }

    public function testVentFormIncludesOptionalFollowUpModalWithAddAndSubmitAsIsActions(): void
    {
        helper(['url', 'form']);
        session()->set(['isLoggedIn' => true, 'role' => 0, 'user_id' => 10]);

        try {
            $html = view('vent_form');
            $dom = new \DOMDocument();
            @$dom->loadHTML($html);
            $xpath = new \DOMXPath($dom);

            self::assertSame(1, $xpath->query('//*[@id="ventFollowUpModal"][@role="dialog"][@aria-modal="true"]')->length);
            self::assertSame(1, $xpath->query('//*[@id="followUpText"]')->length);
            self::assertSame(1, $xpath->query('//*[@id="followUpAddBtn"]')->length);
            self::assertSame(1, $xpath->query('//*[@id="followUpAsIsBtn"]')->length);
            self::assertGreaterThanOrEqual(3, $xpath->query('//*[@data-followup-chip]')->length);
            self::assertStringContainsString('Add to my message', $html);
            self::assertStringContainsString('Submit as is', $html);
            self::assertStringContainsString('Additional context:', $html);
        } finally {
            session()->remove(['isLoggedIn', 'role', 'user_id']);
        }
    }

    public function testCounselorVentsQueueIncludesUrgentSortElapsedTimeAndFeelingsModalHooks(): void
    {
        helper(['url', 'form']);
        session()->set(['isLoggedIn' => true, 'role' => 1, 'user_id' => 1]);

        try {
            $html = view('admin_dashboard', ['dashboard_page' => 'vents', 'pending_meetings_count' => 0]);
            $dom = new \DOMDocument();
            @$dom->loadHTML($html);
            $xpath = new \DOMXPath($dom);

            self::assertSame(1, $xpath->query('//select[@id="sortVents"]')->length);
            self::assertSame(1, $xpath->query('//select[@id="sortVents"]/option[@value="urgent"][@selected]')->length);
            self::assertSame(1, $xpath->query('//select[@id="sortVents"]/option[@value="newest"]')->length);
            self::assertSame(1, $xpath->query('//*[@id="ventsUrgentSummary"]')->length);
            self::assertStringContainsString('Most Urgent First', $html);
            self::assertStringContainsString('function sortVentsList(', $html);
            self::assertStringContainsString('function formatVentElapsedCaption(', $html);
            self::assertStringContainsString("getElementById('modalStudentFeelings')?.remove()", $html);
            self::assertStringContainsString("getElementById('modalFollowUpContext')?.remove()", $html);

            $jsPath = dirname(__DIR__, 2) . '/public/js/screening-explanation.js';
            $js = (string) file_get_contents($jsPath);
            self::assertStringContainsString('function parseStudentEmotions(', $js);
            self::assertStringContainsString('function splitVentFollowUp(', $js);
            self::assertStringContainsString('Student-selected feelings', $js);
            self::assertStringContainsString('Student follow-up context', $js);
            self::assertStringContainsString('modalStudentFeelings', $js);
            self::assertStringContainsString('modalFollowUpContext', $js);
        } finally {
            session()->remove(['isLoggedIn', 'role', 'user_id']);
        }
    }
}
