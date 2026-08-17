<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Tests\Unit;

use Paymenter\Extensions\Others\DynamicPterodactyl\Rules\PublicWebhookUrl;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\WebhookEndpointPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class WebhookEndpointPolicyTest extends TestCase
{
    public function test_public_https_url_is_pinned_and_cannot_redirect_or_use_a_proxy(): void
    {
        $policy = new WebhookEndpointPolicy(function (string $host): array {
            $this->assertSame('hooks.example.com', $host);

            return ['93.184.216.34'];
        });

        $options = $policy->requestOptions(
            'https://hooks.example.com:8443/path?token=secret'
        );

        $this->assertFalse($options['allow_redirects']);
        $this->assertSame(CURLPROTO_HTTPS, $options['curl'][CURLOPT_PROTOCOLS]);
        $this->assertSame('', $options['curl'][CURLOPT_PROXY]);
        $this->assertSame(
            ['hooks.example.com:8443:93.184.216.34'],
            $options['curl'][CURLOPT_RESOLVE]
        );
    }

    #[DataProvider('blockedEndpointProvider')]
    public function test_unsafe_webhook_endpoints_are_rejected(
        string $url,
        array $addresses,
    ): void {
        $policy = new WebhookEndpointPolicy(
            static fn (string $host): array => $addresses
        );

        $this->expectException(\InvalidArgumentException::class);

        $policy->requestOptions($url);
    }

    public static function blockedEndpointProvider(): array
    {
        return [
            'plain HTTP' => ['http://hooks.example.com/path', ['93.184.216.34']],
            'credentials' => ['https://user:pass@hooks.example.com/path', ['93.184.216.34']],
            'fragment' => ['https://hooks.example.com/path#internal', ['93.184.216.34']],
            'trailing-dot hostname' => ['https://hooks.example.com./path', ['93.184.216.34']],
            'loopback IPv4' => ['https://127.0.0.1/path', ['127.0.0.1']],
            'private IPv4' => ['https://10.0.0.1/path', ['10.0.0.1']],
            'carrier-grade NAT IPv4' => ['https://100.64.0.1/path', ['100.64.0.1']],
            'link-local IPv4' => ['https://169.254.169.254/path', ['169.254.169.254']],
            'benchmark IPv4' => ['https://198.18.0.1/path', ['198.18.0.1']],
            'deprecated relay IPv4' => ['https://192.88.99.1/path', ['192.88.99.1']],
            'loopback IPv6' => ['https://[::1]/path', ['::1']],
            'IPv4-compatible IPv6' => ['https://hooks.example.com/path', ['::7f00:1']],
            'reserved IPv4' => ['https://hooks.example.com/path', ['203.0.113.10']],
            'multicast IPv4' => ['https://hooks.example.com/path', ['224.0.0.1']],
            'documentation IPv6' => ['https://hooks.example.com/path', ['2001:db8::1']],
            'AS112 IPv6' => ['https://hooks.example.com/path', ['2620:4f:8000::1']],
            'deprecated site-local IPv6' => ['https://hooks.example.com/path', ['fec0::1']],
            'multicast IPv6' => ['https://hooks.example.com/path', ['ff00::1']],
            'mixed DNS response' => ['https://hooks.example.com/path', ['93.184.216.34', '10.0.0.1']],
            'missing DNS response' => ['https://hooks.example.com/path', []],
        ];
    }

    public function test_validation_rule_reuses_the_public_endpoint_policy(): void
    {
        $rule = new PublicWebhookUrl(new WebhookEndpointPolicy(
            static fn (string $host): array => ['127.0.0.1']
        ));
        $messages = [];

        $rule->validate(
            'webhook_url',
            'https://localhost/hook',
            static function (string $message) use (&$messages): void {
                $messages[] = $message;
            }
        );

        $this->assertSame(
            ['The webhook host must resolve only to public IP addresses.'],
            $messages
        );
    }
}
