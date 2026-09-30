<?php

namespace App\Enums;

enum StripeHealthStatus: string
{
    case Healthy = 'healthy';
    case NeedsAttention = 'needs_attention';
    case Restricted = 'restricted';
    case Unreachable = 'unreachable';
}
