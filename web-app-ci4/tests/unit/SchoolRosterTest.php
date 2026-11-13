<?php

namespace Tests\Unit;

use App\Libraries\SchoolRoster;
use CodeIgniter\Test\CIUnitTestCase;

final class SchoolRosterTest extends CIUnitTestCase
{
    public function testGradeSectionPairMustExistAndLegacyGradesAreNormalized(): void
    {
        $sections = SchoolRoster::fromRows([
            ['grade_level' => 'Grade 7', 'section' => 'Rizal'],
            ['grade_level' => '7', 'section' => 'Rizal'],
            ['grade_level' => '8', 'section' => 'Sampaguita'],
            ['grade_level' => 'BSIT', 'section' => 'Legacy'],
            ['grade_level' => '10', 'section' => ''],
        ]);
        self::assertSame(['Rizal'], $sections[7]);
        self::assertSame([], $sections[10]);
        self::assertTrue(SchoolRoster::validPlacement('7', 'Rizal', $sections));
        self::assertFalse(SchoolRoster::validPlacement('8', 'Rizal', $sections));
        self::assertFalse(SchoolRoster::validPlacement('Grade 7', 'Rizal', $sections));
        self::assertFalse(SchoolRoster::validPlacement(['7'], 'Rizal', $sections));
        self::assertFalse(SchoolRoster::validPlacement('7', ['Rizal'], $sections));
    }
}
