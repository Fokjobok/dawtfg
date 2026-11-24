<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

final class ProfileUpdateCooldownException extends TooManyRequestsHttpException
{
    public function __construct(public readonly int $retryAfter)
    {
        parent::__construct($retryAfter, 'Profile update cooldown is active.', headers: ['Cache-Control' => 'no-store, private']);
    }
}
