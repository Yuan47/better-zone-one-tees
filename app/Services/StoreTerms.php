<?php

namespace App\Services;

class StoreTerms
{
    public const VERSION = '2026-11-12';

    public const PAYMONGO_TERMS = 'https://www.paymongo.com/terms';

    public const PAYMONGO_REFUNDS = 'https://docs.paymongo.com/docs/payment-acceptance-refunds';

    public const LALAMOVE_TERMS = 'https://www.lalamove.com/en-ph/terms-and-conditions';

    public static function acceptance(): array
    {
        return ['terms_accepted_at' => now(), 'terms_version' => self::VERSION];
    }
}
