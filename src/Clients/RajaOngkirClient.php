<?php

namespace Akara\RajaOngkir\Clients;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Throwable;

class RajaOngkirClient
{
    protected string $apiKey;

    protected int $timeout = 10;
    protected int $maxRetries = 2;

    // Cache TTL
    protected int $ttlDestination = 86400; // 24h
    protected int $ttlCost = 900;          // 15m
    protected int $cooldownSec = 60;       // cooldown for upstream instability

    // lifecycle dedupe storage
    protected static array $dedupeMemory = [];

    public function __construct(string $apiKey)
    {
        $this->apiKey = $apiKey;
    }

    protected function baseUrl(): string
    {
        return 'https://rajaongkir.komerce.id/api/v1/';
    }

    /* ============================================================
     * Helpers
     * ============================================================ */

    protected function cacheKey(string $prefix, array $data): string
    {
        return 'rajaongkir:' . $prefix . ':' . md5(json_encode($data));
    }

    protected function dedupe(string $key, callable $fn)
    {
        if (array_key_exists($key, self::$dedupeMemory)) {
            return self::$dedupeMemory[$key];
        }
        return self::$dedupeMemory[$key] = $fn();
    }

    protected function validateRequest(
        string $method,
        array $params,
        array $payload
    ): void {
        if (!in_array($method, ['GET', 'POST'], true)) {
            throw new \InvalidArgumentException("Unsupported HTTP method: {$method}");
        }

        if (!is_array($params) || !is_array($payload)) {
            throw new \InvalidArgumentException("Params/Payload must be arrays");
        }
    }

    /* ============================================================
     * Request Wrapper with retry + cooldown + logging
     * ============================================================ */
    protected function request(string $method, string $endpoint, array $params = [], array $payload = [])
    {
        $this->validateRequest($method, $params, $payload);

        if (Cache::get('rajaongkir:cooldown')) {
            Log::warning("RO-COOLDOWN-ACTIVE", [
                'endpoint' => $endpoint,
            ]);
            return []; // fail-soft rather than throw
        }

        $traceId = uniqid('ro_', true);
        $url = rtrim($this->baseUrl(), '/') . '/' . ltrim($endpoint, '/');

        $attempt = 0;
        $lastEx = null;

        Log::info("RO-REQUEST", [
            'trace_id' => $traceId,
            'method' => $method,
            'endpoint' => $endpoint,
            'url' => $url,
            'params' => $params,
            'payload' => $payload,
        ]);

        while ($attempt <= $this->maxRetries) {
            try {
                $attempt++;

                $client = Http::withHeaders([
                    'key' => $this->apiKey,
                    'Accept' => 'application/json',
                ])->timeout($this->timeout);

                $resp = ($method === 'GET')
                    ? $client->get($url, $params)
                    : $client->asForm()->post($url, $payload);

                if ($resp->successful()) {
                    $json = $resp->json();

                    Log::info("RO-SUCCESS", [
                        'trace_id' => $traceId,
                        'status' => $resp->status(),
                    ]);

                    return $json;
                }

                // Apply cooldown only for upstream issues
                if ($resp->status() === 429 || $resp->status() >= 500) {
                    Cache::put('rajaongkir:cooldown', true, $this->cooldownSec);
                }

                Log::warning("RO-NON-SUCCESS", [
                    'trace_id' => $traceId,
                    'attempt' => $attempt,
                    'status' => $resp->status(),
                    'body' => $resp->body(),
                ]);

            } catch (Throwable $ex) {
                $lastEx = $ex;

                Cache::put('rajaongkir:cooldown', true, $this->cooldownSec);

                Log::error("RO-EXCEPTION", [
                    'trace_id' => $traceId,
                    'attempt' => $attempt,
                    'error' => $ex->getMessage(),
                    'file' => $ex->getFile(),
                    'line' => $ex->getLine(),
                ]);

                usleep(200000 * $attempt);
            }
        }

        Log::error("RO-FAILED-AFTER-RETRIES", [
            'trace_id' => $traceId,
            'endpoint' => $endpoint,
            'method' => $method,
        ]);

        throw $lastEx ?: new \RuntimeException("RajaOngkir request failed after retries");
    }

    /* ============================================================
     * DESTINATION LOOKUP (cached + deduped)
     * ============================================================ */
    public function searchDomesticDestination(string $keyword, int $limit = 30, int $offset = 0): array
    {
        $query = compact('keyword', 'limit', 'offset');
        $cacheKey = $this->cacheKey('dest', $query);
        $dedupeKey = 'dest:' . $cacheKey;

        return $this->dedupe($dedupeKey, function () use ($query, $cacheKey) {
            return Cache::remember($cacheKey, $this->ttlDestination, function () use ($query) {
                Log::info("RO-CACHE-MISS:DEST", $query);

                return $this->request(
                    'GET',
                    'destination/domestic-destination',
                    [
                        'search' => $query['keyword'],
                        'limit' => $query['limit'],
                        'offset' => $query['offset'],
                    ]
                );
            });
        });
    }

    /* ============================================================
     * DOMESTIC COST (cached + normalized + deduped)
     * ============================================================ */
    public function domesticCost(array $payload): array
    {
        $norm = [
            'origin' => $payload['origin'] ?? null,
            'destination' => $payload['destination'] ?? null,
            'weight' => (int) ($payload['weight'] ?? 0),
            'courier' => (string) ($payload['courier'] ?? ''),
        ];

        $cacheKey = $this->cacheKey('cost', $norm);
        $dedupeKey = 'cost:' . $cacheKey;

        return $this->dedupe($dedupeKey, function () use ($cacheKey, $payload, $norm) {
            return Cache::remember($cacheKey, $this->ttlCost, function () use ($payload, $norm) {
                Log::info("RO-CACHE-MISS:COST", [
                    'hash' => md5(json_encode($norm)),
                ]);

                return $this->request(
                    'POST',
                    'calculate/domestic-cost',
                    [],
                    $payload
                );
            });
        });
    }
}
