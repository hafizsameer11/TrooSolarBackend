<?php

namespace App\Services;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class MonoService
{
    private string $baseUrl = 'https://api.withmono.com';

    public function getPublicKey(): string
    {
        return $this->normalizeKey((string) config('services.mono.public_key', ''));
    }

    public function getEnv(): string
    {
        return (string) config('services.mono.env', 'sandbox');
    }

    public function getWebhookSecret(): string
    {
        return (string) config('services.mono.webhook_secret', '');
    }

    public function shouldRunCreditCheck(): bool
    {
        return (bool) config('services.mono.run_credit_check', true);
    }

    /**
     * Normalize secret/public keys from .env (strip quotes and whitespace).
     */
    public function normalizeKey(string $key): string
    {
        $key = trim($key);
        if (
            (str_starts_with($key, '"') && str_ends_with($key, '"'))
            || (str_starts_with($key, "'") && str_ends_with($key, "'"))
        ) {
            $key = substr($key, 1, -1);
        }

        return trim($key);
    }

    /**
     * @return array{configured: bool, prefix: string|null, env: string|null, matches_public: bool}
     */
    public function describeSecretKey(): array
    {
        $secret = $this->getSecretKey();
        $public = $this->normalizeKey($this->getPublicKey());
        $prefix = $this->keyPrefix($secret);

        return [
            'configured' => $secret !== '',
            'prefix' => $prefix,
            'env' => $this->secretEnvFromPrefix($prefix),
            'matches_public' => $this->keysMatchEnvironment($public, $secret),
        ];
    }

    /**
     * Lightweight auth check against Mono (does not use a linked account).
     *
     * @return array{ok: bool, status: int|null, message: string}
     */
    public function verifyApiCredentials(): array
    {
        $secret = $this->getSecretKey();
        if ($secret === '') {
            return [
                'ok' => false,
                'status' => null,
                'message' => 'MONO_SECRET_KEY is not set on the server.',
            ];
        }

        $public = $this->normalizeKey($this->getPublicKey());
        if ($public !== '' && ! $this->keysMatchEnvironment($public, $secret)) {
            return [
                'ok' => false,
                'status' => null,
                'message' => 'MONO_SECRET_KEY does not match MONO_PUBLIC_KEY environment (live vs test mismatch). Use both keys from the same Mono app.',
            ];
        }

        try {
            $response = Http::withHeaders([
                'accept' => 'application/json',
                'mono-sec-key' => $secret,
            ])->timeout(20)->get($this->baseUrl . '/v3/institutions', [
                'scope' => 'financial_data',
            ]);

            if ($response->successful()) {
                return [
                    'ok' => true,
                    'status' => $response->status(),
                    'message' => 'Mono secret key is valid.',
                ];
            }

            $message = $response->json('message') ?? $response->body();

            return [
                'ok' => false,
                'status' => $response->status(),
                'message' => is_string($message) ? $message : 'Mono API rejected the secret key.',
            ];
        } catch (RequestException $e) {
            $message = $e->response?->json('message') ?? $e->getMessage();

            return [
                'ok' => false,
                'status' => $e->response?->status(),
                'message' => is_string($message) ? $message : 'Mono API request failed.',
            ];
        }
    }

    public function getSecretKey(): string
    {
        return $this->normalizeKey((string) config('services.mono.secret_key', ''));
    }

    private function keyPrefix(string $key): ?string
    {
        if (preg_match('/^(live|test)_(pk|sk)_/', $key, $matches)) {
            return $matches[1] . '_' . $matches[2];
        }

        return null;
    }

    private function secretEnvFromPrefix(?string $prefix): ?string
    {
        return match ($prefix) {
            'live_sk' => 'live',
            'test_sk' => 'test',
            default => null,
        };
    }

    private function keysMatchEnvironment(string $publicKey, string $secretKey): bool
    {
        $publicPrefix = $this->keyPrefix($publicKey);
        $secretPrefix = $this->keyPrefix($secretKey);

        if ($publicPrefix === null || $secretPrefix === null) {
            return true;
        }

        return str_starts_with($publicPrefix, 'live') === str_starts_with($secretPrefix, 'live')
            && str_starts_with($publicPrefix, 'test') === str_starts_with($secretPrefix, 'test');
    }

    /**
     * Exchange temporary Connect code for permanent account id.
     *
     * @throws RuntimeException
     */
    public function exchangeCode(string $code): string
    {
        $response = $this->request('POST', '/v2/accounts/auth', ['code' => $code]);

        $accountId = $response['id'] ?? $response['data']['id'] ?? null;
        if (! is_string($accountId) || $accountId === '') {
            throw new RuntimeException('Mono auth response did not include account id.');
        }

        return $accountId;
    }

    /**
     * Initiate async creditworthiness analysis.
     *
     * @param  array{bvn: string, principal: int, interest_rate: float|int, term: int, run_credit_check: bool}  $params
     * @return array<string, mixed>
     *
     * @throws RuntimeException
     */
    public function initiateCreditWorthiness(string $accountId, array $params): array
    {
        $body = [
            'bvn' => $params['bvn'],
            'principal' => (int) $params['principal'],
            'interest_rate' => (float) $params['interest_rate'],
            'term' => (int) $params['term'],
            'run_credit_check' => (bool) ($params['run_credit_check'] ?? true),
        ];

        return $this->request('POST', '/v2/accounts/' . $accountId . '/creditworthiness', $body);
    }

    /**
     * @return array{endpoint: string, body: array<string, mixed>}
     */
    public function buildCreditWorthinessRequestAudit(string $accountId, array $params): array
    {
        return [
            'endpoint' => $this->baseUrl . '/v2/accounts/' . $accountId . '/creditworthiness',
            'method' => 'POST',
            'body' => [
                'bvn' => $params['bvn'],
                'principal' => (int) $params['principal'],
                'interest_rate' => (float) $params['interest_rate'],
                'term' => (int) $params['term'],
                'run_credit_check' => (bool) ($params['run_credit_check'] ?? true),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getAccountDetails(string $accountId): array
    {
        return $this->request('GET', '/v2/accounts/' . $accountId);
    }

    /**
     * @return array<string, mixed>
     */
    public function getAccountIdentity(string $accountId): array
    {
        return $this->request('GET', '/v2/accounts/' . $accountId . '/identity');
    }

    /**
     * @return array<string, mixed>
     */
    public function getAccountBalance(string $accountId): array
    {
        return $this->request('GET', '/v2/accounts/' . $accountId . '/balance');
    }

    /**
     * @return array<string, mixed>
     */
    public function getAccountStatement(string $accountId, string $period = 'last6months', string $output = 'json'): array
    {
        $query = [
            'period' => $period,
            'output' => $output,
        ];

        // format=v2 is for JSON statements only; Mono rejects it on PDF requests.
        if (strtolower($output) !== 'pdf') {
            $query['format'] = 'v2';
        }

        return $this->request('GET', '/v2/accounts/' . $accountId . '/statement', [], $query);
    }

    /**
     * @return array<string, mixed>
     */
    public function pollStatementPdfJob(string $accountId, string $jobId): array
    {
        return $this->request('GET', '/v2/accounts/' . $accountId . '/statement/jobs/' . $jobId);
    }

    /**
     * Request PDF statement and poll until ready (max attempts).
     *
     * @return array{job_id: string|null, status: string, download_url: string|null, raw: array<string, mixed>}
     */
    public function fetchStatementPdfUrl(string $accountId, string $period = 'last6months', int $maxAttempts = 12): array
    {
        $init = $this->getAccountStatement($accountId, $period, 'pdf');
        $data = is_array($init['data'] ?? null) ? $init['data'] : [];
        $pdfMeta = is_array($data['pdf'] ?? null) ? $data['pdf'] : [];

        $jobId = $data['jobId']
            ?? $data['job_id']
            ?? $pdfMeta['jobId']
            ?? $pdfMeta['jobid']
            ?? $pdfMeta['job_id']
            ?? $data['id']
            ?? $init['jobId']
            ?? null;

        $directPath = $data['path']
            ?? $pdfMeta['url']
            ?? $pdfMeta['path']
            ?? null;

        if (is_string($directPath) && $directPath !== '') {
            return [
                'job_id' => is_string($jobId) ? $jobId : null,
                'status' => 'BUILT',
                'download_url' => $directPath,
                'raw' => $init,
            ];
        }

        if (! is_string($jobId) || $jobId === '') {
            return [
                'job_id' => null,
                'status' => 'unknown',
                'download_url' => null,
                'raw' => $init,
            ];
        }

        for ($i = 0; $i < $maxAttempts; $i++) {
            if ($i > 0) {
                usleep(500000);
            }

            $poll = $this->pollStatementPdfJob($accountId, $jobId);
            $pollData = is_array($poll['data'] ?? null) ? $poll['data'] : [];
            $status = strtoupper((string) ($pollData['status'] ?? ''));
            $path = $pollData['path'] ?? $pollData['url'] ?? null;

            if ($status === 'BUILT' && is_string($path) && $path !== '') {
                return [
                    'job_id' => $jobId,
                    'status' => $status,
                    'download_url' => $path,
                    'raw' => $poll,
                ];
            }

            if (in_array($status, ['FAILED', 'ERROR'], true)) {
                return [
                    'job_id' => $jobId,
                    'status' => $status,
                    'download_url' => null,
                    'raw' => $poll,
                ];
            }
        }

        return [
            'job_id' => $jobId,
            'status' => 'processing',
            'download_url' => null,
            'raw' => $init,
        ];
    }

    /**
     * Initiate a one-time DirectPay debit (e.g. BNPL credit check fee).
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function initiateDirectPay(array $body): array
    {
        return $this->request('POST', '/v2/payments/initiate', $body);
    }

    /**
     * Verify a DirectPay transaction by reference.
     *
     * @return array<string, mixed>
     */
    public function verifyDirectPay(string $reference): array
    {
        return $this->request('GET', '/v2/payments/verify/' . rawurlencode($reference));
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function initiateDirectDebitMandate(array $body): array
    {
        return $this->request('POST', '/v2/payments/initiate', $body);
    }

    /**
     * @return array<string, mixed>
     */
    public function retrieveMandate(string $mandateId): array
    {
        return $this->request('GET', '/v3/payments/mandates/' . rawurlencode($mandateId));
    }

    /**
     * @return array<string, mixed>
     */
    public function mandateBalanceInquiry(string $mandateId): array
    {
        return $this->request('GET', '/v3/payments/mandates/' . rawurlencode($mandateId) . '/balance-inquiry');
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function debitMandateAccount(string $mandateId, array $body): array
    {
        return $this->request('POST', '/v3/payments/mandates/' . rawurlencode($mandateId) . '/debit', $body);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function createCustomer(array $body): array
    {
        return $this->request('POST', '/v2/customers', $body);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function updateCustomer(string $customerId, array $body): array
    {
        return $this->request('PATCH', '/v2/customers/' . rawurlencode($customerId), $body);
    }

    /**
     * @return array<string, mixed>
     */
    public function listCustomers(array $query = []): array
    {
        return $this->request('GET', '/v2/customers', [], $query);
    }

    public function findCustomerIdByPhone(string $phone): ?string
    {
        $phone = preg_replace('/\D+/', '', trim($phone));
        if ($phone === '') {
            return null;
        }

        try {
            $response = $this->listCustomers(['phone' => $phone]);
            $rows = $response['data'] ?? null;
            if (! is_array($rows) || $rows === []) {
                return null;
            }

            foreach ($rows as $row) {
                $row = $this->normalizeCustomerRow($row);
                if ($row === null) {
                    continue;
                }
                $id = (string) ($row['id'] ?? $row['_id'] ?? '');

                return $id !== '' ? $id : null;
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }

    public function findCustomerIdByEmail(string $email): ?string
    {
        $email = strtolower(trim($email));
        if ($email === '') {
            return null;
        }

        try {
            for ($page = 1; $page <= 10; $page++) {
                $response = $this->listCustomers(['page' => $page]);
                $rows = $response['data'] ?? null;
                if (! is_array($rows) || $rows === []) {
                    break;
                }

                foreach ($rows as $row) {
                    $row = $this->normalizeCustomerRow($row);
                    if ($row === null) {
                        continue;
                    }
                    if (strtolower(trim((string) ($row['email'] ?? ''))) === $email) {
                        $id = (string) ($row['id'] ?? $row['_id'] ?? '');

                        return $id !== '' ? $id : null;
                    }
                }

                $next = $response['meta']['next'] ?? null;
                if ($next === null || $next === '') {
                    break;
                }
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }

    public function resolveCustomerIdForAccount(string $accountId): ?string
    {
        $accountId = trim($accountId);
        if ($accountId === '') {
            return null;
        }

        try {
            $response = $this->getAccountDetails($accountId);
            $data = is_array($response['data'] ?? null) ? $response['data'] : $response;
            $customer = $data['customer'] ?? null;

            if (is_string($customer) && $customer !== '') {
                return $customer;
            }

            if (is_array($customer)) {
                $id = (string) ($customer['id'] ?? $customer['_id'] ?? '');

                return $id !== '' ? $id : null;
            }
        } catch (\Throwable) {
            // Account lookup is best-effort when resolving an existing Mono customer.
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function normalizeCustomerRow(mixed $row): ?array
    {
        if (is_array($row)) {
            return $row;
        }

        if (is_string($row) && $row !== '') {
            $decoded = json_decode($row, true);

            return is_array($decoded) ? $decoded : null;
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, array $body = [], array $query = []): array
    {
        $secret = $this->getSecretKey();
        if ($secret === '') {
            throw new RuntimeException('Mono secret key is not configured. Set MONO_SECRET_KEY in server .env (live_sk_... for production).');
        }

        $public = $this->normalizeKey($this->getPublicKey());
        if ($public !== '' && ! $this->keysMatchEnvironment($public, $secret)) {
            throw new RuntimeException(
                'MONO_SECRET_KEY and MONO_PUBLIC_KEY are from different environments. Use both live keys or both test keys from the same Mono app.'
            );
        }

        $url = $this->baseUrl . $path;
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }

        try {
            $client = Http::withHeaders([
                'accept' => 'application/json',
                'content-type' => 'application/json',
                'mono-sec-key' => $secret,
            ])->timeout(60);

            $response = match (strtoupper($method)) {
                'POST' => $client->post($url, $body),
                'PATCH' => $client->patch($url, $body),
                'PUT' => $client->put($url, $body),
                'DELETE' => $client->delete($url, $body),
                default => $client->get($url),
            };
        } catch (RequestException $e) {
            throw new RuntimeException(
                $this->formatApiErrorMessage($e->response?->json(), $e->response?->status(), $path),
                0,
                $e
            );
        }

        if (! $response->successful()) {
            throw new RuntimeException(
                $this->formatApiErrorMessage($response->json(), $response->status(), $path)
            );
        }

        return $response->json() ?? [];
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    private function formatApiErrorMessage(?array $payload, ?int $status, string $path = ''): string
    {
        $message = is_array($payload) ? ($payload['message'] ?? null) : null;
        $data = is_array($payload) ? ($payload['data'] ?? null) : null;
        $detail = is_string($data) && $data !== '' ? $data : null;

        $text = is_string($message) ? $message : 'Unknown Mono API error';

        if ($detail && stripos($detail, 'wallet balance') !== false) {
            return 'Mono wallet balance is too low to run this API call. Top up your Mono wallet in the Mono Dashboard (Billing / Wallet), then retry. Credit worthiness costs about ₦500 per check with bureau lookup, or ₦200 without (MONO_RUN_CREDIT_CHECK=false).';
        }

        if ($detail) {
            $text = $text . ' — ' . $detail;
        }

        if ($status === 401 || stripos($text, 'unauthorized') !== false) {
            if (str_contains($path, 'creditworthiness')) {
                return 'Mono Credit Worthiness failed: ' . $text
                    . '. If the detail mentions wallet balance, top up your Mono dashboard wallet. Otherwise confirm Credit Worthiness is enabled and webhook is set to https://api.troosolar.com/api/webhooks/mono';
            }

            return 'Mono API unauthorized: ' . $text
                . '. Verify MONO_SECRET_KEY matches MONO_PUBLIC_KEY on the server .env (live_sk + live_pk, no quotes), then run /api/optimize-app.';
        }

        return 'Mono API error: ' . $text;
    }
}
