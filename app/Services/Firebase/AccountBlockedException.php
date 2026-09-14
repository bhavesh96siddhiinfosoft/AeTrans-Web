<?php

namespace App\Services\Firebase;

use RuntimeException;

/**
 * The customer's Firestore document carries `blocked: true`.
 *
 * Separate from a disabled Firebase account: an operator sets this in the admin
 * panel, it travels with the customer across the website, the app and the panel, and
 * spec §9 requires the site to honour it. The credentials were correct — the account
 * is simply not allowed to proceed.
 */
class AccountBlockedException extends RuntimeException
{
    public function message(): string
    {
        return __('lang.account_suspended');
    }
}
