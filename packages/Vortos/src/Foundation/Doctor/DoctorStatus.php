<?php

declare(strict_types=1);

namespace Vortos\Foundation\Doctor;

enum DoctorStatus: string
{
    case Ok      = 'ok';
    case Warning = 'warning';
    case Error   = 'error';
}
