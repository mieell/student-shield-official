<?php

use CodeIgniter\Test\CIUnitTestCase;

final class StudentVentLayoutTest extends CIUnitTestCase
{
    public function testCompactFeelingsKeepTheExistingSubmissionAndSupportContracts(): void
    {
        helper(['url', 'form']);
        $dom = new DOMDocument();
        @$dom->loadHTML(view('vent_form'));
        $xpath = new DOMXPath($dom);

        $this->assertSame(1, $xpath->query('//form[@id="ventForm"][@method="POST"][contains(@action,"student/vent/submit")]')->length);
        $this->assertSame(1, $xpath->query('//form[@id="ventForm"]//input[@name="' . csrf_token() . '"]')->length);
        $this->assertSame(1, $xpath->query('//textarea[@name="vent_text"][@maxlength="2000"][@required]')->length);
        $this->assertSame(1, $xpath->query('//input[@name="emotions"][@type="hidden"][@value=""]')->length);
        $this->assertSame(1, $xpath->query('//details[@id="moodDisclosure"][not(@open)]/summary')->length);
        $moods = $xpath->query('//*[@id="moodSelect"]/button[@type="button"][@aria-pressed="false"]');
        $this->assertCount(12, $moods);
        $values = [];
        foreach ($moods as $mood) {
            $values[] = $mood->getAttribute('data-mood');
        }
        $this->assertSame(['Okay', 'Calm', 'Happy', 'Hopeful', 'Sad', 'Angry', 'Anxious', 'Lonely', 'Overwhelmed', 'Hurt', 'Numb', 'Scared'], $values);
        $this->assertSame(1, $xpath->query('//*[@id="moodSelections"][@hidden]')->length);
        $this->assertSame(0, $xpath->query('//input[@name="hide_from_parents"]')->length);
        $this->assertStringContainsString('registered parent or guardian', $dom->textContent);
        $this->assertSame(1, $xpath->query('//form[contains(@action,"/logout")]')->length);
        $this->assertSame(1, $xpath->query('//*[@data-request-url][contains(@data-request-url,"student/meeting/request")]')->length);
        $this->assertSame(1, $xpath->query('//*[@id="empathyModal"]')->length);
    }
}
