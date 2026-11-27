<?php

declare(strict_types=1);

namespace FreeSub\Core;

use InvalidArgumentException;
use OpenSSLAsymmetricKey;
use PDO;
use RuntimeException;

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/Database.php';

/**
 * Clerk Authentication & User Synchronization Service
 *
 * Verifies RS256 Clerk session JWTs against Clerk's JWKS endpoint (cached locally
 * in `/core/cache/jwks.json`), extracts `sub`, email, and name claims, and syncs
 * or fetches the corresponding user record in the MySQL `users` table.
 */
final class ClerkAuth
{
    private const DEFAULT_CACHE_PATH = __DIR__ . '/cache/jwks.json';

    private string $jwksUrl;
    private string $issuer;
    /** @var list<string> */
    private array $authorizedParties;
    private string $cachePath;
    private int $cacheTtl;
    private int $clockLeeway;

    /**
     * @param list<string>|null $authorizedParties
     */
    public function __construct(
        ?string $jwksUrl = null,
        ?string $issuer = null,
        ?array $authorizedParties = null,
        ?string $cachePath = null,
        ?int $cacheTtl = null,
        ?int $clockLeeway = null
    ) {
        $config = Config::all()['clerk'];

        $this->issuer = rtrim($issuer ?? (string) $config['issuer'], '/');
        $this->jwksUrl = $jwksUrl ?? (string) $config['jwks_url'];
        $this->authorizedParties = $authorizedParties ?? $config['authorized_parties'];
        $this->cachePath = $cachePath ?? self::DEFAULT_CACHE_PATH;
        $this->cacheTtl = $cacheTtl ?? (int) $config['jwks_cache_ttl'];
        $this->clockLeeway = $clockLeeway ?? (int) $config['clock_leeway'];
    }

    /**
     * Extract Bearer/Session JWT from current HTTP request headers or `__session` cookie,
     * verify it against Clerk JWKS, and synchronize/return the local MySQL user row.
     *
     * @return array<string, mixed> Authenticated user record from `users` table + `claims`
     * @throws RuntimeException If missing token, invalid JWT, or suspended user
     */
    public function authenticateRequest(?PDO $pdo = null): array
    {
        $token = self::extractTokenFromRequest();
        if ($token === null || $token === '') {
            throw new RuntimeException('Authentication token is missing.');
        }

        return $this->authenticateToken($token, $pdo);
    }

    /**
     * Verify a raw Clerk JWT string and sync/fetch the user record in MySQL.
     *
     * @return array<string, mixed> User database row (with `claims` attached under `_claims`)
     * @throws RuntimeException On verification failure or suspended account
     */
    public function authenticateToken(string $jwt, ?PDO $pdo = null): array
    {
        $claims = $this->verifyJwt($jwt);
        $identity = $this->extractUserIdentity($claims);
        $user = $this->syncUser(
            $identity['clerk_user_id'],
            $identity['email'],
            $identity['full_name'],
            $pdo
        );

        if (($user['status'] ?? 'active') === 'suspended') {
            throw new RuntimeException('Your account has been suspended.');
        }

        $user['_claims'] = $claims;

        return $user;
    }

    /**
     * Verify a Clerk RS256 session JWT using JWKS and return its decoded payload claims.
     *
     * @return array<string, mixed>
     * @throws RuntimeException If the token is malformed, expired, untrusted, or signature fails
     */
    public function verifyJwt(string $jwt): array
    {
        $parts = explode('.', trim($jwt));
        if (count($parts) !== 3) {
            throw new RuntimeException('Malformed JWT structure.');
        }

        [$encodedHeader, $encodedPayload, $encodedSignature] = $parts;

        $headerJson = self::base64UrlDecode($encodedHeader);
        $payloadJson = self::base64UrlDecode($encodedPayload);
        $signature = self::base64UrlDecode($encodedSignature);

        $header = json_decode($headerJson, true);
        $payload = json_decode($payloadJson, true);

        if (!is_array($header) || !is_array($payload)) {
            throw new RuntimeException('Invalid JWT JSON encoding.');
        }

        $alg = $header['alg'] ?? '';
        if ($alg !== 'RS256') {
            throw new RuntimeException(sprintf('Unsupported JWT signing algorithm "%s". Expected RS256.', (string) $alg));
        }

        $kid = isset($header['kid']) && is_string($header['kid']) ? $header['kid'] : null;
        $publicKey = $this->resolvePublicKey($kid);

        $signingInput = $encodedHeader . '.' . $encodedPayload;
        $verified = openssl_verify($signingInput, $signature, $publicKey, OPENSSL_ALGO_SHA256);

        if ($verified !== 1) {
            throw new RuntimeException('JWT cryptographic signature verification failed.');
        }

        $this->validateClaims($payload);

        return $payload;
    }

    /**
     * Extract `sub` (Clerk user ID), `email`, and `full_name` from verified Clerk JWT claims.
     *
     * @param array<string, mixed> $claims
     * @return array{clerk_user_id: string, email: ?string, full_name: ?string}
     */
    public function extractUserIdentity(array $claims): array
    {
        $sub = isset($claims['sub']) && is_string($claims['sub']) ? trim($claims['sub']) : '';
        if ($sub === '') {
            throw new RuntimeException('JWT is missing required "sub" (Clerk user ID) claim.');
        }

        // Email claim variations supported in Clerk session/custom templates
        $email = null;
        foreach (['email', 'email_address', 'primary_email_address'] as $emailKey) {
            if (!empty($claims[$emailKey]) && is_string($claims[$emailKey])) {
                $candidate = trim($claims[$emailKey]);
                if (filter_var($candidate, FILTER_VALIDATE_EMAIL) !== false) {
                    $email = strtolower($candidate);
                    break;
                }
            }
        }

        // Full name claim variations supported in Clerk session/custom templates
        $fullName = null;
        foreach (['name', 'full_name', 'username'] as $nameKey) {
            if (!empty($claims[$nameKey]) && is_string($claims[$nameKey])) {
                $fullName = trim($claims[$nameKey]);
                break;
            }
        }

        if ($fullName === null) {
            $first = isset($claims['first_name']) && is_string($claims['first_name'])
                ? trim($claims['first_name'])
                : (isset($claims['given_name']) && is_string($claims['given_name']) ? trim($claims['given_name']) : '');
            $last = isset($claims['last_name']) && is_string($claims['last_name'])
                ? trim($claims['last_name'])
                : (isset($claims['family_name']) && is_string($claims['family_name']) ? trim($claims['family_name']) : '');
            $combined = trim($first . ' ' . $last);
            if ($combined !== '') {
                $fullName = $combined;
            }
        }

        return [
            'clerk_user_id' => $sub,
            'email' => $email,
            'full_name' => $fullName,
        ];
    }

    /**
     * Sync or fetch a user record in the `users` table by `clerk_user_id`.
     *
     * If the user does not exist yet, inserts a new `free` active user row
     * (optionally pulling missing profile details from Clerk Backend API if configured).
     * If the user exists and new non-empty email/name claims are present, updates them.
     *
     * @return array<string, mixed>
     */
    public function syncUser(
        string $clerkUserId,
        ?string $email = null,
        ?string $fullName = null,
        ?PDO $pdo = null
    ): array {
        $db = $pdo ?? Database::getInstance();

        $stmt = $db->prepare(
            'SELECT id, clerk_user_id, email, full_name, plan, custom_subdomain_limit, status, created_at
             FROM users
             WHERE clerk_user_id = :clerk_user_id
             LIMIT 1'
        );
        $stmt->execute([':clerk_user_id' => $clerkUserId]);
        $existing = $stmt->fetch();

        // If JWT lacks email/name and Clerk Secret Key is configured, attempt optional Clerk Backend API lookup
        if (($email === null || $fullName === null) && ($existing === false || $existing['email'] === null)) {
            $remoteProfile = $this->fetchClerkUserProfile($clerkUserId);
            if ($remoteProfile !== null) {
                $email ??= $remoteProfile['email'];
                $fullName ??= $remoteProfile['full_name'];
            }
        }

        if ($existing !== false) {
            $needsUpdate = ($email !== null && $email !== $existing['email'])
                || ($fullName !== null && $fullName !== $existing['full_name']);

            if ($needsUpdate) {
                $updateStmt = $db->prepare(
                    'UPDATE users
                     SET email = COALESCE(:email, email),
                         full_name = COALESCE(:full_name, full_name)
                     WHERE id = :id'
                );
                $updateStmt->execute([
                    ':email' => $email,
                    ':full_name' => $fullName,
                    ':id' => $existing['id'],
                ]);

                $existing['email'] = $email ?? $existing['email'];
                $existing['full_name'] = $fullName ?? $existing['full_name'];
            }

            return $existing;
        }

        $insertStmt = $db->prepare(
            'INSERT INTO users (clerk_user_id, email, full_name, plan, status)
             VALUES (:clerk_user_id, :email, :full_name, :plan, :status)
             ON DUPLICATE KEY UPDATE
                email = COALESCE(VALUES(email), email),
                full_name = COALESCE(VALUES(full_name), full_name)'
        );
        $insertStmt->execute([
            ':clerk_user_id' => $clerkUserId,
            ':email' => $email,
            ':full_name' => $fullName,
            ':plan' => 'free',
            ':status' => 'active',
        ]);

        $stmt->execute([':clerk_user_id' => $clerkUserId]);
        $created = $stmt->fetch();

        if ($created === false) {
            throw new RuntimeException('Failed to synchronize user record in database.');
        }

        return $created;
    }

    /**
     * Extract JWT token from `Authorization: Bearer <token>` header or Clerk `__session` cookie.
     */
    public static function extractTokenFromRequest(): ?string
    {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null;

        if ($authHeader === null && function_exists('getallheaders')) {
            $headers = getallheaders();
            if (is_array($headers)) {
                foreach ($headers as $name => $value) {
                    if (strcasecmp((string) $name, 'Authorization') === 0 && is_string($value)) {
                        $authHeader = $value;
                        break;
                    }
                }
            }
        }

        if (is_string($authHeader) && preg_match('/^\s*Bearer\s+(.+)\s*$/i', $authHeader, $matches)) {
            return trim($matches[1]);
        }

        if (!empty($_COOKIE['__session']) && is_string($_COOKIE['__session'])) {
            return trim($_COOKIE['__session']);
        }

        return null;
    }

    /**
     * Validate standard JWT claims (`exp`, `nbf`, `iat`, `iss`, `azp`, `sub`).
     *
     * @param array<string, mixed> $claims
     */
    private function validateClaims(array $claims): void
    {
        $now = time();

        if (!isset($claims['sub']) || !is_string($claims['sub']) || trim($claims['sub']) === '') {
            throw new RuntimeException('JWT is missing a valid "sub" claim.');
        }

        if (!isset($claims['exp']) || !is_numeric($claims['exp'])) {
            throw new RuntimeException('JWT is missing required "exp" expiration claim.');
        }

        if (($now - $this->clockLeeway) >= (int) $claims['exp']) {
            throw new RuntimeException('Session token has expired.');
        }

        if (isset($claims['nbf']) && is_numeric($claims['nbf'])) {
            if (($now + $this->clockLeeway) < (int) $claims['nbf']) {
                throw new RuntimeException('Session token is not yet active (nbf).');
            }
        }

        if (isset($claims['iat']) && is_numeric($claims['iat'])) {
            if (($now + $this->clockLeeway) < (int) $claims['iat']) {
                throw new RuntimeException('Session token issued-at timestamp (iat) is in the future.');
            }
        }

        if (
            $this->issuer !== ''
            && $this->issuer !== 'https://clerk.freesub.local'
            && isset($claims['iss'])
        ) {
            $tokenIssuer = rtrim((string) $claims['iss'], '/');
            if (!hash_equals($this->issuer, $tokenIssuer)) {
                throw new RuntimeException('JWT issuer mismatch.');
            }
        }

        if ($this->authorizedParties !== [] && isset($claims['azp']) && is_string($claims['azp'])) {
            $tokenAzp = rtrim(trim($claims['azp']), '/');
            $allowed = false;
            foreach ($this->authorizedParties as $party) {
                $normalizedParty = rtrim(trim($party), '/');
                if ($normalizedParty === $tokenAzp) {
                    $allowed = true;
                    break;
                }
                // Treat localhost:<port> and 127.0.0.1:<port> as equivalent during local development
                $altParty = str_contains($normalizedParty, '://localhost')
                    ? str_replace('://localhost', '://127.0.0.1', $normalizedParty)
                    : (str_contains($normalizedParty, '://127.0.0.1')
                        ? str_replace('://127.0.0.1', '://localhost', $normalizedParty)
                        : '');
                if ($altParty !== '' && $altParty === $tokenAzp) {
                    $allowed = true;
                    break;
                }
            }

            if (!$allowed) {
                throw new RuntimeException(sprintf('JWT authorized party "%s" is not permitted.', $tokenAzp));
            }
        }
    }

    /**
     * Resolve an OpenSSL public key from cached JWKS, refreshing cache once on `kid` miss.
     */
    private function resolvePublicKey(?string $kid): OpenSSLAsymmetricKey
    {
        $jwks = $this->getJwks(false);
        $jwk = $this->findMatchingJwk($jwks, $kid);

        // Key rotation support: force refresh JWKS if `kid` was not found in local cache
        if ($jwk === null) {
            $jwks = $this->getJwks(true);
            $jwk = $this->findMatchingJwk($jwks, $kid);
        }

        if ($jwk === null) {
            throw new RuntimeException('Unable to locate matching JWK public key for token verification.');
        }

        $pem = self::jwkToPem($jwk);
        $publicKey = openssl_pkey_get_public($pem);
        if ($publicKey === false) {
            throw new RuntimeException('Failed to parse RSA public key from JWK.');
        }

        return $publicKey;
    }

    /**
     * Load JWKS from `/core/cache/jwks.json` or fetch fresh keys from Clerk's JWKS endpoint.
     *
     * @return array<string, mixed>
     */
    private function getJwks(bool $forceRefresh = false): array
    {
        if (!$forceRefresh && is_file($this->cachePath) && is_readable($this->cachePath)) {
            $raw = file_get_contents($this->cachePath);
            if ($raw !== false) {
                $cached = json_decode($raw, true);
                if (
                    is_array($cached)
                    && isset($cached['fetched_at'], $cached['jwks']['keys'])
                    && is_array($cached['jwks']['keys'])
                    && (time() - (int) $cached['fetched_at']) < $this->cacheTtl
                ) {
                    return $cached['jwks'];
                }
            }
        }

        if ($this->jwksUrl === '') {
            throw new RuntimeException('CLERK_JWKS_URL is not configured.');
        }

        $fetchedJwks = $this->fetchRemoteJwks($this->jwksUrl);

        $cacheDir = dirname($this->cachePath);
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0755, true);
        }

        $cachePayload = json_encode(
            [
                'fetched_at' => time(),
                'jwks_url' => $this->jwksUrl,
                'jwks' => $fetchedJwks,
            ],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
        );

        if ($cachePayload !== false) {
            @file_put_contents($this->cachePath, $cachePayload, LOCK_EX);
        }

        return $fetchedJwks;
    }

    /**
     * Fetch JWKS JSON document via cURL.
     *
     * @return array<string, mixed>
     */
    private function fetchRemoteJwks(string $url): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Failed to initialize cURL for JWKS request.');
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'User-Agent: FreeSub-Auth/1.0',
            ],
        ]);

        $responseBody = curl_exec($ch);
        $curlErr = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($responseBody === false || !is_string($responseBody)) {
            throw new RuntimeException('Failed to fetch Clerk JWKS: ' . ($curlErr ?: 'Unknown cURL error'));
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            throw new RuntimeException(sprintf('Clerk JWKS endpoint returned HTTP %d.', $httpCode));
        }

        $decoded = json_decode($responseBody, true);
        if (!is_array($decoded) || !isset($decoded['keys']) || !is_array($decoded['keys'])) {
            throw new RuntimeException('Invalid JWKS payload received from Clerk.');
        }

        return $decoded;
    }

    /**
     * Locate a matching RSA JWK entry by `kid`.
     *
     * @param array<string, mixed> $jwks
     * @return array<string, mixed>|null
     */
    private function findMatchingJwk(array $jwks, ?string $kid): ?array
    {
        $keys = $jwks['keys'] ?? [];
        if (!is_array($keys)) {
            return null;
        }

        foreach ($keys as $key) {
            if (!is_array($key) || ($key['kty'] ?? '') !== 'RSA') {
                continue;
            }
            if (isset($key['use']) && $key['use'] !== 'sig') {
                continue;
            }
            if ($kid !== null && isset($key['kid']) && $key['kid'] !== $kid) {
                continue;
            }
            if (!empty($key['n']) && !empty($key['e'])) {
                return $key;
            }
        }

        return null;
    }

    /**
     * Optional fallback to fetch user email/name from Clerk Backend API when `CLERK_SECRET_KEY` is provided.
     *
     * @return array{email: ?string, full_name: ?string}|null
     */
    private function fetchClerkUserProfile(string $clerkUserId): ?array
    {
        $secretKey = Config::get('CLERK_SECRET_KEY', '');
        if ($secretKey === null || trim($secretKey) === '') {
            return null;
        }

        $url = 'https://api.clerk.com/v1/users/' . rawurlencode($clerkUserId);
        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . trim($secretKey),
                'Accept: application/json',
                'User-Agent: FreeSub-Auth/1.0',
            ],
        ]);

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!is_string($body) || $status < 200 || $status >= 300) {
            return null;
        }

        $data = json_decode($body, true);
        if (!is_array($data)) {
            return null;
        }

        $email = null;
        $primaryEmailId = $data['primary_email_address_id'] ?? null;
        if (!empty($data['email_addresses']) && is_array($data['email_addresses'])) {
            foreach ($data['email_addresses'] as $entry) {
                if (!is_array($entry) || empty($entry['email_address'])) {
                    continue;
                }
                if ($primaryEmailId !== null && ($entry['id'] ?? null) === $primaryEmailId) {
                    $email = strtolower(trim((string) $entry['email_address']));
                    break;
                }
                $email ??= strtolower(trim((string) $entry['email_address']));
            }
        }

        $first = isset($data['first_name']) && is_string($data['first_name']) ? trim($data['first_name']) : '';
        $last = isset($data['last_name']) && is_string($data['last_name']) ? trim($data['last_name']) : '';
        $combined = trim($first . ' ' . $last);
        $fullName = $combined !== ''
            ? $combined
            : (isset($data['username']) && is_string($data['username']) ? trim($data['username']) : null);

        return [
            'email' => $email,
            'full_name' => $fullName,
        ];
    }

    /**
     * Convert an RSA JWK (`n`, `e`) into an X.509 SubjectPublicKeyInfo PEM certificate string.
     *
     * @param array<string, mixed> $jwk
     */
    public static function jwkToPem(array $jwk): string
    {
        if (!isset($jwk['n'], $jwk['e']) || !is_string($jwk['n']) || !is_string($jwk['e'])) {
            throw new InvalidArgumentException('JWK must contain string modulus "n" and exponent "e".');
        }

        $modulus = self::base64UrlDecode($jwk['n']);
        $exponent = self::base64UrlDecode($jwk['e']);

        $modulusAsn1 = self::encodeAsn1Integer($modulus);
        $exponentAsn1 = self::encodeAsn1Integer($exponent);

        // RSAPublicKey ::= SEQUENCE { modulus INTEGER, publicExponent INTEGER }
        $rsaPublicKey = self::encodeAsn1Sequence($modulusAsn1 . $exponentAsn1);

        // Wrap in BIT STRING (leading 0x00 unused-bits byte)
        $bitString = "\x03" . self::encodeAsn1Length(strlen($rsaPublicKey) + 1) . "\x00" . $rsaPublicKey;

        // AlgorithmIdentifier for rsaEncryption (OID 1.2.840.113549.1.1.1 + NULL)
        $rsaOidAlgorithmIdentifier = "\x30\x0d\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00";

        // SubjectPublicKeyInfo ::= SEQUENCE { algorithm, subjectPublicKey }
        $spki = self::encodeAsn1Sequence($rsaOidAlgorithmIdentifier . $bitString);

        return "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode($spki), 64, "\n")
            . "-----END PUBLIC KEY-----\n";
    }

    /**
     * Decode Base64URL string according to RFC 7515.
     */
    private static function base64UrlDecode(string $input): string
    {
        $remainder = strlen($input) % 4;
        if ($remainder > 0) {
            $input .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode(strtr($input, '-_', '+/'), true);
        if ($decoded === false) {
            throw new RuntimeException('Failed to decode Base64URL segment.');
        }

        return $decoded;
    }

    /**
     * Encode binary big-endian unsigned integer as DER ASN.1 INTEGER.
     */
    private static function encodeAsn1Integer(string $bytes): string
    {
        $bytes = ltrim($bytes, "\x00");
        if ($bytes === '') {
            $bytes = "\x00";
        }

        // Prepend 0x00 if most significant bit is 1 so integer stays positive
        if ((ord($bytes[0]) & 0x80) !== 0) {
            $bytes = "\x00" . $bytes;
        }

        return "\x02" . self::encodeAsn1Length(strlen($bytes)) . $bytes;
    }

    /**
     * Encode inner bytes as DER ASN.1 SEQUENCE.
     */
    private static function encodeAsn1Sequence(string $content): string
    {
        return "\x30" . self::encodeAsn1Length(strlen($content)) . $content;
    }

    /**
     * Encode DER ASN.1 length octets.
     */
    private static function encodeAsn1Length(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }

        $temp = '';
        $value = $length;
        while ($value > 0) {
            $temp = chr($value & 0xFF) . $temp;
            $value >>= 8;
        }

        return chr(0x80 | strlen($temp)) . $temp;
    }
}
