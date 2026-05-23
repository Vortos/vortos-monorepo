<?php

declare(strict_types=1);

namespace Vortos\Foundation\Doctor\Contract;

use Vortos\Foundation\Doctor\DoctorResult;

interface DoctorCheckInterface
{
    /** Unique identifier for this check — used in output and filtering. */
    public function name(): string;

    /** Run the check and return a result. Must not throw. */
    public function run(): DoctorResult;
}
