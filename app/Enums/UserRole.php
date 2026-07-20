<?php

namespace App\Enums;

enum UserRole: string
{
    case Citizen = 'citizen';
    case Agent = 'agent';
    case Manager = 'manager';
}
