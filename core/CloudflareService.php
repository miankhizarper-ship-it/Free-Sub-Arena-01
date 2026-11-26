<?php

declare(strict_types=1);

namespace FreeSub\Core;

use InvalidArgumentException;

require_once __DIR__ . '/config.php';

/**
 * Cloudflare REST API v4 Client Service
 *
 * Manages DNS records for a specific root domain's Cloudflare Zone ID and API Token
 * via PHP cURL, returning consistent structured result envelopes:
 * `['success' => bool, 'data' => mixed, 'error' => ?string]`
 */
final class CloudflareService
{
    private string $zoneId;
    private string $apiToken;
    private string $apiBaseUrl;
    private int $timeout;

    public function __construct(
        string $zoneId,
        string $apiToken,
        ?string $apiBaseUrl = null,
        ?int $timeout = null
    ) {
        $zoneId = trim($zoneId);
        $apiToken = trim($apiToken);

        if ($zoneId === '') {
            throw new InvalidArgumentException('Cloudflare Zone ID cannot be empty.');
        }

        if ($apiToken === '') {
            throw new InvalidArgumentException('Cloudflare API Token cannot be empty.');
        }

        $cfConfig = Config::all()['cloudflare'];

        $this->zoneId = $zoneId;
        $this->apiToken = $apiToken;
        $this->apiBaseUrl = rtrim($apiBaseUrl ?? (string) $cfConfig['api_base_url'], '/');
        $this->timeout = $timeout ?? (int) $cfConfig['timeout'];
    }

    /**
     * Instantiate CloudflareService directly from a `root_domains` database row.
     *
     * @param array<string, mixed> $rootDomainRow
     */
    public static function fromRootDomain(array $rootDomainRow): self
    {
        return new self(
            (string) ($rootDomainRow['cloudflare_zone_id'] ?? ''),
            (string) ($rootDomainRow['cloudflare_api_token'] ?? '')
        );
    }

    /**
     * Create a new DNS record in the Cloudflare zone.
     *
     * Expected `$payload` keys: `type`, `name`, `content` (or `target_value`),
     * optional `ttl` (default 1 = Auto), `proxied` (bool), `priority` (int for MX), `comment`.
     *
     * @param array<string, mixed> $payload
     * @return array{success: bool, data: mixed, error: ?string}
     */
    public function createDnsRecord(array $payload): array
    {
        $normalized = $this->normalizeDnsPayload($payload);
        if ($normalized['error'] !== null) {
            return [
                'success' => false,
                'data' => null,
                'error' => $normalized['error'],
            ];
        }

        $endpoint = sprintf('/zones/%s/dns_records', rawurlencode($this->zoneId));

        return $this->request('POST', $endpoint, $normalized['body']);
    }

    /**
     * Update an existing DNS record in the Cloudflare zone.
     *
     * @param array<string, mixed> $payload
     * @return array{success: bool, data: mixed, error: ?string}
     */
    public function updateDnsRecord(string $recordId, array $payload): array
    {
        $recordId = trim($recordId);
        if ($recordId === '') {
            return [
                'success' => false,
                'data' => null,
                'error' => 'Cloudflare DNS record ID is required for update.',
            ];
        }

        $normalized = $this->normalizeDnsPayload($payload);
        if ($normalized['error'] !== null) {
            return [
                'success' => false,
                'data' => null,
                'error' => $normalized['error'],
            ];
        }

        $endpoint = sprintf(
            '/zones/%s/dns_records/%s',
            rawurlencode($this->zoneId),
            rawurlencode($recordId)
        );

        return $this->request('PUT', $endpoint, $normalized['body']);
    }

    /**
     * Delete a DNS record from the Cloudflare zone.
     *
     * @return array{success: bool, data: mixed, error: ?string}
     */
    public function deleteDnsRecord(string $recordId): array
    {
        $recordId = trim($recordId);
        if ($recordId === '') {
            return [
                'success' => false,
                'data' => null,
                'error' => 'Cloudflare DNS record ID is required for deletion.',
            ];
        }

        $endpoint = sprintf(
            '/zones/%s/dns_records/%s',
            rawurlencode($this->zoneId),
            rawurlencode($recordId)
        );

        return $this->request('DELETE', $endpoint);
    }

    /**
     * List DNS records in the Cloudflare zone with optional query filters
     * (e.g., `['name' => 'app.example.com', 'type' => 'A', 'per_page' => 100]`).
     *
     * @param array<string, scalar|null> $filters
     * @return array{success: bool, data: mixed, error: ?string}
     */
    public function listDnsRecords(array $filters = []): array
    {
        $cleanFilters = array_filter(
            $filters,
            static fn(mixed $val): bool => $val !== null && $val !== ''
        );

        $endpoint = sprintf('/zones/%s/dns_records', rawurlencode($this->zoneId));
        if ($cleanFilters !== []) {
            $endpoint .= '?' . http_build_query($cleanFilters, '', '&', PHP_QUERY_RFC3986);
        }

        return $this->request('GET', $endpoint);
    }

    /**
     * Verify that the configured Cloudflare API token can access this zone and list DNS records.
     *
     * @return array{success: bool, data: mixed, error: ?string}
     */
    public function verifyZoneCredentials(): array
    {
        return $this->listDnsRecords(['per_page' => 1]);
    }

    /**
     * Normalize incoming DNS record payload to Cloudflare REST API v4 specification.
     *
     * @param array<string, mixed> $payload
     * @return array{body: array<string, mixed>, error: ?string}
     */
    private function normalizeDnsPayload(array $payload): array
    {
        $type = strtoupper(trim((string) ($payload['type'] ?? $payload['record_type'] ?? '')));
        $name = trim((string) ($payload['name'] ?? $payload['record_name'] ?? ''));
        $content = trim((string) ($payload['content'] ?? $payload['target_value'] ?? ''));

        if ($type === '' || $name === '' || $content === '') {
            return [
                'body' => [],
                'error' => 'DNS record payload requires non-empty "type", "name", and "content" fields.',
            ];
        }

        $ttl = isset($payload['ttl']) ? (int) $payload['ttl'] : 1;
        if ($ttl <= 0) {
            $ttl = 1;
        }

        $body = [
            'type' => $type,
            'name' => $name,
            'content' => $content,
            'ttl' => $ttl,
        ];

        if (in_array($type, ['A', 'AAAA', 'CNAME'], true)) {
            $body['proxied'] = isset($payload['proxied']) ? (bool) $payload['proxied'] : false;
        }

        if ($type === 'MX') {
            if (!isset($payload['priority']) || !is_numeric($payload['priority'])) {
                return [
                    'body' => [],
                    'error' => 'MX DNS records require a numeric "priority" field.',
                ];
            }
            $body['priority'] = (int) $payload['priority'];
        }

        if (isset($payload['comment']) && is_string($payload['comment']) && trim($payload['comment']) !== '') {
            $body['comment'] = substr(trim($payload['comment']), 0, 100);
        }

        return [
            'body' => $body,
            'error' => null,
        ];
    }

    /**
     * Execute an authenticated HTTP request against Cloudflare API v4.
     *
     * @param array<string, mixed>|null $jsonBody
     * @return array{success: bool, data: mixed, error: ?string}
     */
    private function request(string $method, string $endpoint, ?array $jsonBody = null): array
    {
        $url = $this->apiBaseUrl . $endpoint;
        $ch = curl_init($url);

        if ($ch === false) {
            return [
                'success' => false,
                'data' => null,
                'error' => 'Failed to initialize cURL handle for Cloudflare API.',
            ];
        }

        $headers = [
            'Authorization: Bearer ' . $this->apiToken,
            'Content-Type: application/json',
            'Accept: application/json',
            'User-Agent: FreeSub-CloudflareClient/1.0',
        ];

        $curlOptions = [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => $headers,
        ];

        if ($jsonBody !== null) {
            $encoded = json_encode($jsonBody, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($encoded === false) {
                curl_close($ch);
                return [
                    'success' => false,
                    'data' => null,
                    'error' => 'Failed to encode Cloudflare request payload as JSON.',
                ];
            }
            $curlOptions[CURLOPT_POSTFIELDS] = $encoded;
        }

        curl_setopt_array($ch, $curlOptions);

        $rawResponse = curl_exec($ch);
        $curlErrno = curl_errno($ch);
        $curlError = curl_error($ch);
        $httpStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($rawResponse === false || !is_string($rawResponse)) {
            return [
                'success' => false,
                'data' => null,
                'error' => sprintf('Cloudflare network error (cURL #%d): %s', $curlErrno, $curlError ?: 'Unknown error'),
            ];
        }

        $decoded = json_decode($rawResponse, true);
        if (!is_array($decoded)) {
            return [
                'success' => false,
                'data' => null,
                'error' => sprintf('Malformed JSON response from Cloudflare (HTTP %d).', $httpStatus),
            ];
        }

        $apiSuccess = (bool) ($decoded['success'] ?? false);
        if (!$apiSuccess || $httpStatus < 200 || $httpStatus >= 300) {
            $errorMessage = $this->formatCloudflareErrors($decoded['errors'] ?? [], $httpStatus);
            return [
                'success' => false,
                'data' => $decoded['result'] ?? null,
                'error' => $errorMessage,
            ];
        }

        $resultData = $decoded['result'] ?? null;

        // Include pagination metadata if present on list queries
        if (isset($decoded['result_info']) && is_array($decoded['result_info']) && is_array($resultData)) {
            return [
                'success' => true,
                'data' => [
                    'records' => $resultData,
                    'result_info' => $decoded['result_info'],
                ],
                'error' => null,
            ];
        }

        return [
            'success' => true,
            'data' => $resultData,
            'error' => null,
        ];
    }

    /**
     * Format Cloudflare API error objects into a concise human-readable error string.
     *
     * @param mixed $errors
     */
    private function formatCloudflareErrors(mixed $errors, int $httpStatus): string
    {
        if (!is_array($errors) || $errors === []) {
            return sprintf('Cloudflare API request failed with HTTP status %d.', $httpStatus);
        }

        $messages = [];
        foreach ($errors as $err) {
            if (!is_array($err)) {
                continue;
            }
            $code = isset($err['code']) ? '[' . $err['code'] . '] ' : '';
            $msg = isset($err['message']) ? (string) $err['message'] : 'Unknown Cloudflare error';

            // Include nested chain messages when Cloudflare provides detailed validation errors
            if (!empty($err['error_chain']) && is_array($err['error_chain'])) {
                $chainParts = [];
                foreach ($err['error_chain'] as $chainErr) {
                    if (is_array($chainErr) && !empty($chainErr['message'])) {
                        $chainParts[] = (string) $chainErr['message'];
                    }
                }
                if ($chainParts !== []) {
                    $msg .= ' (' . implode('; ', $chainParts) . ')';
                }
            }

            $messages[] = $code . $msg;
        }

        return $messages !== []
            ? implode(' | ', $messages)
            : sprintf('Cloudflare API request failed with HTTP status %d.', $httpStatus);
    }
}
