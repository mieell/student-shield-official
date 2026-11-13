<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

class SchoolRoster extends BaseConfig
{
    /** School-approved section names, keyed by grade (7–10). Existing school records also supply options. */
    public array $sectionsByGrade = [];
}
