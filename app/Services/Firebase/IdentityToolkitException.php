<?php

namespace App\Services\Firebase;

use RuntimeException;

/**
 * A refusal from Firebase Auth, carrying the code Google returned.
 *
 * The code is kept RAW (`EMAIL_EXISTS`, `INVALID_LOGIN_CREDENTIALS`, …) and the
 * translation to a sentence happens at the edge, in `message()`. Two reasons: the
 * raw code is what goes in the log and what a search matches, and Google adds new
 * codes without warning — an unmapped one must still surface as *something*, not as
 * a blank page.
 *
 * `TRANSPORT` is ours, not Google's: it means the request never got an answer.
 */
class IdentityToolkitException extends RuntimeException
{
    public const TRANSPORT = 'TRANSPORT';

    public function __construct(
        public readonly string $errorCode,
        public readonly ?string $rawMessage = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($rawMessage ?: $errorCode, 0, $previous);
    }

    /**
     * True when the account exists but its password did not match.
     *
     * Firebase used to answer `EMAIL_NOT_FOUND` and `INVALID_PASSWORD` separately;
     * newer projects answer `INVALID_LOGIN_CREDENTIALS` for both, deliberately, so an
     * attacker cannot use the login form to enumerate which emails have accounts. We
     * treat all three the same and print one message for them — reintroducing the
     * distinction in our own wording would hand back exactly what Google withheld.
     */
    public function isBadCredentials(): bool
    {
        return in_array($this->errorCode, [
            'INVALID_LOGIN_CREDENTIALS',
            'INVALID_PASSWORD',
            'EMAIL_NOT_FOUND',
            'MISSING_PASSWORD',
        ], true);
    }

    public function isEmailTaken(): bool
    {
        return $this->errorCode === 'EMAIL_EXISTS';
    }

    public function isDisabled(): bool
    {
        return $this->errorCode === 'USER_DISABLED';
    }

    /** A sentence for the customer. Never leaks the raw code into the UI. */
    public function message(): string
    {
        if ($this->isBadCredentials()) {
            return (string) trans('auth.failed');
        }

        return match (true) {
            $this->isEmailTaken() => __('lang.account_already_uses_email_address'),
            $this->isDisabled() => __('lang.account_suspended'),
            $this->errorCode === 'TOO_MANY_ATTEMPTS_TRY_LATER' => __('lang.too_many_attempts'),
            $this->errorCode === 'OPERATION_NOT_ALLOWED' => __('lang.provider_disabled'),
            str_starts_with($this->errorCode, 'WEAK_PASSWORD') => __('lang.please_choose_longer_password'),
            str_contains($this->errorCode, 'ID_TOKEN') => __('lang.sign_expired_please_try_again'),
            $this->errorCode === self::TRANSPORT => __('lang.sign_in_unreachable'),
            default => __('lang.could_complete_please_try_again'),
        };
    }
}
