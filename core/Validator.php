<?php

declare(strict_types=1);

namespace FreeSub\Core;

/**
 * Input & Domain/DNS Validation Utility
 *
 * Provides RFC-compliant validation for subdomain labels, DNS record types,
 * IPv4/IPv6 addresses, hostnames, TXT payloads, TTLs, and MX priorities.
 */
final class Validator
{
    /**
     * Reserved subdomain labels blocked from user registration.
     *
     * @var list<string>
     */
    public const RESERVED_SUBDOMAINS = [
        'www',
        'mail',
        'ftp',
        'admin',
        'api',
        'dashboard',
        'auth',
        'cdn',
        'ns1',
        'ns2',
        'status',
        'support',
        'billing',
        'root',
    ];

    /**
     * Supported DNS record types.
     *
     * @var list<string>
     */
    public const ALLOWED_RECORD_TYPES = [
        'A',
        'AAAA',
        'CNAME',
        'MX',
        'TXT',
    ];

    /**
     * Validate a subdomain label.
     *
     * Rules:
     * - Length between 3 and 63 characters
     * - Lowercase alphanumeric characters (`a-z`, `0-9`) and hyphens (`-`) only
     * - Cannot start or end with a hyphen
     * - Cannot match any built-in or admin-configured reserved system label
     *
     * @param list<string> $extraReserved Optional additional reserved names (e.g. from `settings` table)
     * @return array{valid: bool, error: ?string, normalized: string}
     */
    public static function validateSubdomainLabel(string $label, array $extraReserved = []): array
    {
        $trimmed = trim($label);

        if ($trimmed === '') {
            return [
                'valid' => false,
                'error' => 'Subdomain label is required.',
                'normalized' => '',
            ];
        }

        if ($trimmed !== strtolower($trimmed)) {
            return [
                'valid' => false,
                'error' => 'Subdomain label must contain lowercase characters only.',
                'normalized' => strtolower($trimmed),
            ];
        }

        $length = strlen($trimmed);
        if ($length < 3 || $length > 63) {
            return [
                'valid' => false,
                'error' => 'Subdomain label must be between 3 and 63 characters long.',
                'normalized' => $trimmed,
            ];
        }

        if (str_starts_with($trimmed, '-') || str_ends_with($trimmed, '-')) {
            return [
                'valid' => false,
                'error' => 'Subdomain label cannot start or end with a hyphen.',
                'normalized' => $trimmed,
            ];
        }

        if (!preg_match('/^[a-z0-9-]+$/', $trimmed)) {
            return [
                'valid' => false,
                'error' => 'Subdomain label may only contain lowercase letters, numbers, and hyphens.',
                'normalized' => $trimmed,
            ];
        }

        $reservedPool = self::RESERVED_SUBDOMAINS;
        foreach ($extraReserved as $extra) {
            $cleanExtra = strtolower(trim((string) $extra));
            if ($cleanExtra !== '' && !in_array($cleanExtra, $reservedPool, true)) {
                $reservedPool[] = $cleanExtra;
            }
        }

        if (in_array($trimmed, $reservedPool, true)) {
            return [
                'valid' => false,
                'error' => sprintf('The subdomain "%s" is reserved and cannot be registered.', $trimmed),
                'normalized' => $trimmed,
            ];
        }

        return [
            'valid' => true,
            'error' => null,
            'normalized' => $trimmed,
        ];
    }

    /**
     * Boolean shorthand to check whether a subdomain label is valid.
     */
    public static function isValidSubdomainLabel(string $label): bool
    {
        return self::validateSubdomainLabel($label)['valid'];
    }

    /**
     * Check whether a DNS record type is supported.
     */
    public static function isValidRecordType(string $recordType): bool
    {
        return in_array(strtoupper(trim($recordType)), self::ALLOWED_RECORD_TYPES, true);
    }

    /**
     * Validate a complete DNS record input (type, target/content, optional priority, TTL, proxied).
     *
     * @return array{
     *     valid: bool,
     *     error: ?string,
     *     normalized: array{
     *         type: string,
     *         target: string,
     *         priority: ?int,
     *         ttl: int,
     *         proxied: bool
     *     }
     * }
     */
    public static function validateDnsRecord(
        string $recordType,
        string $targetValue,
        ?int $priority = null,
        int $ttl = 1,
        bool $proxied = false
    ): array {
        $type = strtoupper(trim($recordType));
        $target = trim($targetValue);

        $normalized = [
            'type' => $type,
            'target' => $target,
            'priority' => $priority,
            'ttl' => $ttl,
            'proxied' => $proxied,
        ];

        if (!self::isValidRecordType($type)) {
            return [
                'valid' => false,
                'error' => sprintf(
                    'Unsupported DNS record type "%s". Allowed types: %s.',
                    $recordType,
                    implode(', ', self::ALLOWED_RECORD_TYPES)
                ),
                'normalized' => $normalized,
            ];
        }

        if ($target === '') {
            return [
                'valid' => false,
                'error' => 'DNS record target value cannot be empty.',
                'normalized' => $normalized,
            ];
        }

        // Validate TTL: Cloudflare uses 1 for "Automatic", otherwise 60..86400 seconds
        if ($ttl !== 1 && ($ttl < 60 || $ttl > 86400)) {
            return [
                'valid' => false,
                'error' => 'TTL must be 1 (Auto) or between 60 and 86400 seconds.',
                'normalized' => $normalized,
            ];
        }

        // Cloudflare only supports proxying on A, AAAA, and CNAME records
        if ($proxied && !in_array($type, ['A', 'AAAA', 'CNAME'], true)) {
            return [
                'valid' => false,
                'error' => sprintf('%s records cannot be proxied through Cloudflare.', $type),
                'normalized' => $normalized,
            ];
        }

        switch ($type) {
            case 'A':
                if (!self::isValidIpv4($target)) {
                    return [
                        'valid' => false,
                        'error' => 'Invalid IPv4 address for A record.',
                        'normalized' => $normalized,
                    ];
                }
                $normalized['priority'] = null;
                break;

            case 'AAAA':
                if (!self::isValidIpv6($target)) {
                    return [
                        'valid' => false,
                        'error' => 'Invalid IPv6 address for AAAA record.',
                        'normalized' => $normalized,
                    ];
                }
                $normalized['priority'] = null;
                break;

            case 'CNAME':
                $cleanHost = rtrim(strtolower($target), '.');
                if (!self::isValidHostname($cleanHost)) {
                    return [
                        'valid' => false,
                        'error' => 'Invalid target hostname for CNAME record.',
                        'normalized' => $normalized,
                    ];
                }
                $normalized['target'] = $cleanHost;
                $normalized['priority'] = null;
                break;

            case 'MX':
                $cleanHost = rtrim(strtolower($target), '.');
                if (!self::isValidHostname($cleanHost)) {
                    return [
                        'valid' => false,
                        'error' => 'Invalid mail server hostname for MX record.',
                        'normalized' => $normalized,
                    ];
                }
                if ($priority === null || !self::isValidMxPriority($priority)) {
                    return [
                        'valid' => false,
                        'error' => 'MX record requires a valid priority between 0 and 65535.',
                        'normalized' => $normalized,
                    ];
                }
                $normalized['target'] = $cleanHost;
                $normalized['proxied'] = false;
                break;

            case 'TXT':
                if (strlen($target) > 2048) {
                    return [
                        'valid' => false,
                        'error' => 'TXT record content cannot exceed 2048 characters.',
                        'normalized' => $normalized,
                    ];
                }
                if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $target)) {
                    return [
                        'valid' => false,
                        'error' => 'TXT record content contains invalid control characters.',
                        'normalized' => $normalized,
                    ];
                }
                $normalized['priority'] = null;
                $normalized['proxied'] = false;
                break;
        }

        return [
            'valid' => true,
            'error' => null,
            'normalized' => $normalized,
        ];
    }

    /**
     * Validate a relative or fully-qualified DNS record name within a subdomain zone.
     *
     * Accepts `@` (root of the user's subdomain), a single label (`app`), or multi-label (`_acme-challenge.app`).
     *
     * @return array{valid: bool, error: ?string, full_record_name: string}
     */
    public static function validateRecordName(string $recordName, string $subdomainFullDomain): array
    {
        $name = strtolower(trim($recordName));
        $baseDomain = strtolower(rtrim(trim($subdomainFullDomain), '.'));

        if ($name === '' || $name === '@' || $name === $baseDomain) {
            return [
                'valid' => true,
                'error' => null,
                'full_record_name' => $baseDomain,
            ];
        }

        // Strip trailing dot if provided
        $name = rtrim($name, '.');

        // If user passed full FQDN ending with their subdomain, extract prefix
        if (str_ends_with($name, '.' . $baseDomain)) {
            $relative = substr($name, 0, -(strlen($baseDomain) + 1));
        } else {
            $relative = $name;
        }

        $labels = explode('.', $relative);
        foreach ($labels as $part) {
            // Allow leading underscore on DNS verification labels (e.g., _dmarc, _acme-challenge)
            if (
                $part === ''
                || strlen($part) > 63
                || !preg_match('/^_?[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $part)
            ) {
                return [
                    'valid' => false,
                    'error' => sprintf('Invalid DNS record host label "%s".', $part),
                    'full_record_name' => '',
                ];
            }
        }

        $fullRecordName = $relative . '.' . $baseDomain;
        if (strlen($fullRecordName) > 253) {
            return [
                'valid' => false,
                'error' => 'Full DNS record name exceeds maximum length of 253 characters.',
                'full_record_name' => '',
            ];
        }

        return [
            'valid' => true,
            'error' => null,
            'full_record_name' => $fullRecordName,
        ];
    }

    /**
     * Validate an IPv4 address.
     */
    public static function isValidIpv4(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
    }

    /**
     * Validate an IPv6 address.
     */
    public static function isValidIpv6(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
    }

    /**
     * Validate a fully qualified DNS hostname (requires at least two labels, valid TLD).
     */
    public static function isValidHostname(string $hostname): bool
    {
        $clean = rtrim(trim($hostname), '.');
        if ($clean === '' || strlen($clean) > 253 || !str_contains($clean, '.')) {
            return false;
        }

        // Reject raw IP addresses masquerading as hostnames
        if (filter_var($clean, FILTER_VALIDATE_IP) !== false) {
            return false;
        }

        $labels = explode('.', $clean);
        $tld = end($labels);
        if (
            $tld === false
            || (!preg_match('/^[a-zA-Z]{2,63}$/', $tld) && !str_starts_with(strtolower($tld), 'xn--'))
        ) {
            return false;
        }

        foreach ($labels as $label) {
            if (
                $label === ''
                || strlen($label) > 63
                || str_starts_with($label, '-')
                || str_ends_with($label, '-')
                || !preg_match('/^_?[a-zA-Z0-9-]+$/', $label)
            ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Validate an MX priority integer (RFC 5321: 0 to 65535).
     */
    public static function isValidMxPriority(int $priority): bool
    {
        return $priority >= 0 && $priority <= 65535;
    }
}
