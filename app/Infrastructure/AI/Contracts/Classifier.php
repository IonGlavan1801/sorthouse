<?php

namespace App\Infrastructure\AI\Contracts;

use App\Infrastructure\AI\DTO\CaseInput;
use App\Infrastructure\AI\DTO\ClassificationResult;

interface Classifier
{
    public function classify(CaseInput $input): ClassificationResult;
}
