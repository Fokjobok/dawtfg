<?php

namespace App\Enums;

enum UserRole: string
{
    case Administrator = 'Administrator';
    case Coach = 'Coach';
    case Student = 'Student';
}
