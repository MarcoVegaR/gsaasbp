<?php

declare(strict_types=1);

namespace App\Support\Billing;

use App\Support\Billing\Contracts\SubscriptionProvider;
use InvalidArgumentException;

final class BillingProviderRegistry
{
    public function make(string $provider): SubscriptionProvider
    {
        $driver = config("billing.providers.{$provider}.driver");

        if (! is_string($driver) || $driver === '') {
            throw new InvalidArgumentException('Unknown billing provider.');
        }

        $instance = app($driver, ['provider' => $provider]);

        if (! $instance instanceof SubscriptionProvider) {
            throw new InvalidArgumentException('Invalid billing provider driver.');
        }

        return $instance;
    }
}
