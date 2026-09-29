<?php

namespace App\Contracts;

use App\Models\User;

interface OtpSender
{
    /**
     * Deliver a plaintext one-time code to the user.
     *
     * Callers pass the plaintext only here. It is never stored or returned.
     */
    public function send(User $user, string $code): void;
}
