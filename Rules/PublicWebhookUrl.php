<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\WebhookEndpointPolicy;

final class PublicWebhookUrl implements ValidationRule
{
    public function __construct(
        private readonly ?WebhookEndpointPolicy $policy = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('The webhook URL must be a string.');

            return;
        }

        try {
            ($this->policy ?? new WebhookEndpointPolicy)->requestOptions($value);
        } catch (\InvalidArgumentException $exception) {
            $fail($exception->getMessage());
        }
    }
}
