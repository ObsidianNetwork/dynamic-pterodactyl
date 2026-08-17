<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Services;

use Closure;
use Symfony\Component\HttpFoundation\IpUtils;

final class WebhookEndpointPolicy
{
    private const NON_PUBLIC_NETWORKS = [
        '0.0.0.0/8',
        '10.0.0.0/8',
        '100.64.0.0/10',
        '127.0.0.0/8',
        '169.254.0.0/16',
        '172.16.0.0/12',
        '192.0.0.0/24',
        '192.0.2.0/24',
        '192.31.196.0/24',
        '192.52.193.0/24',
        '192.88.99.0/24',
        '192.168.0.0/16',
        '192.175.48.0/24',
        '198.18.0.0/15',
        '198.51.100.0/24',
        '203.0.113.0/24',
        '224.0.0.0/4',
        '240.0.0.0/4',
        '::/128',
        '::1/128',
        '::ffff:0:0/96',
        '64:ff9b::/96',
        '64:ff9b:1::/48',
        '100::/64',
        '2001::/23',
        '2001:db8::/32',
        '2002::/16',
        '2620:4f:8000::/48',
        '3fff::/20',
        '5f00::/16',
        'fc00::/7',
        'fec0::/10',
        'fe80::/10',
        'ff00::/8',
    ];

    private Closure $resolveAddresses;

    public function __construct(?Closure $resolveAddresses = null)
    {
        $this->resolveAddresses = $resolveAddresses
            ?? static fn (string $host): array => self::lookupAddresses($host);
    }

    public function requestOptions(string $url): array
    {
        $parts = $this->parse($url);
        $host = $parts['host'];
        $port = $parts['port'];
        $addresses = ($this->resolveAddresses)($host);

        if (! is_array($addresses) || $addresses === []) {
            throw new \InvalidArgumentException(
                'The webhook host must resolve to a public IP address.'
            );
        }

        $addresses = array_values(array_unique($addresses));
        foreach ($addresses as $address) {
            if (! is_string($address) || ! $this->isPublicAddress($address)) {
                throw new \InvalidArgumentException(
                    'The webhook host must resolve only to public IP addresses.'
                );
            }
        }

        $address = $addresses[0];
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $address = '['.$address.']';
        }

        return [
            'allow_redirects' => false,
            'curl' => [
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_PROXY => '',
                CURLOPT_RESOLVE => ["{$host}:{$port}:{$address}"],
            ],
        ];
    }

    private function parse(string $url): array
    {
        $url = trim($url);
        if ($url === '' || preg_match('/[\x00-\x20\x7f]/', $url) === 1) {
            throw new \InvalidArgumentException(
                'The webhook URL must be an absolute HTTPS URL without whitespace.'
            );
        }

        try {
            $parts = parse_url($url);
        } catch (\ValueError $exception) {
            throw new \InvalidArgumentException(
                'The webhook URL is invalid.',
                previous: $exception
            );
        }

        if (! is_array($parts)) {
            throw new \InvalidArgumentException('The webhook URL is invalid.');
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($scheme !== 'https' || $host === '') {
            throw new \InvalidArgumentException(
                'The webhook URL must use HTTPS and include a host.'
            );
        }

        if (
            array_key_exists('user', $parts)
            || array_key_exists('pass', $parts)
            || array_key_exists('fragment', $parts)
        ) {
            throw new \InvalidArgumentException(
                'The webhook URL cannot contain user information or a fragment.'
            );
        }

        $port = isset($parts['port']) ? (int) $parts['port'] : 443;
        if ($port < 1 || $port > 65535) {
            throw new \InvalidArgumentException(
                'The webhook URL contains an invalid port.'
            );
        }

        if (
            filter_var($host, FILTER_VALIDATE_IP) === false
            && ! $this->isValidHostname($host)
        ) {
            throw new \InvalidArgumentException(
                'The webhook URL contains an invalid host.'
            );
        }

        return [
            'host' => $host,
            'port' => $port,
        ];
    }

    private function isValidHostname(string $host): bool
    {
        if (strlen($host) > 253) {
            return false;
        }

        foreach (explode('.', $host) as $label) {
            if (
                $label === ''
                || strlen($label) > 63
                || preg_match('/^(?!-)[a-z0-9-]+(?<!-)$/i', $label) !== 1
            ) {
                return false;
            }
        }

        return true;
    }

    private function isPublicAddress(string $address): bool
    {
        return filter_var($address, FILTER_VALIDATE_IP) !== false
            && ! IpUtils::checkIp($address, self::NON_PUBLIC_NETWORKS);
    }

    private static function lookupAddresses(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $addresses = [];
        $records = dns_get_record($host, DNS_A | DNS_AAAA);
        if (is_array($records)) {
            foreach ($records as $record) {
                if (isset($record['ip']) && is_string($record['ip'])) {
                    $addresses[] = $record['ip'];
                }
                if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                    $addresses[] = $record['ipv6'];
                }
            }
        }

        if ($addresses === []) {
            $ipv4Addresses = gethostbynamel($host);
            if (is_array($ipv4Addresses)) {
                $addresses = $ipv4Addresses;
            }
        }

        return array_values(array_unique($addresses));
    }
}
