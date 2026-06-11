<?php

namespace App\Exceptions;

use Exception;

class SkillCategoryLimitExceededException extends Exception
{
    public function __construct(int $limit)
    {
        parent::__construct("Maximum of {$limit} skill categories allowed.");
    }
}
