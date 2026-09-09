<?php

namespace App\Enums;

enum ProvisionFailureClass: string
{
    case Config = 'config';
    case Transient = 'transient';
    case Capacity = 'capacity';
}
