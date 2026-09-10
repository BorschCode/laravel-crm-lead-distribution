<?php

namespace App\Enums;

enum LeadStatus: string
{
    case NEW = 'NEW';
    case IN_PROGRESS = 'IN_PROGRESS';
    case DONE = 'DONE';
    case CANCELED = 'CANCELED';
}
