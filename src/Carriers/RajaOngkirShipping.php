<?php

namespace Akara\RajaOngkir\Carriers;

use Log;
use Webkul\Shipping\Carriers\AbstractShipping;
use Webkul\Checkout\Facades\Cart;
use Webkul\Checkout\Models\CartShippingRate;
use Akara\RajaOngkir\Clients\RajaOngkirClient;
use Akara\RajaOngkir\Services\RajaOngkirDestinationResolver;

class RajaongkirShipping extends AbstractShipping
{
    protected $code = 'rajaongkir';

    public function calculate()
    {
        try {
            if (!$this->isAvailable()) {
                return false;
            }

            return $this->getRate();

        } catch (\Throwable $e) {
            Log::error('RO-CALCULATE-FAILED', [
                'carrier' => $this->code,
                'cart_id' => optional(Cart::getCart())->id,
                'message' => $e->getMessage(),
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [];
        }
    }


    protected function getRate()
    {
        $cart = Cart::getCart();
        $shippingAddress = $cart->shipping_address;

        $apiKey = $this->getConfigData('api_key');
        $rawCouriers = $this->getConfigData('couriers');

        // -- Courier parsing ----------------------------------------------------
        if (!is_string($rawCouriers) || trim($rawCouriers) === '') {
            Log::error("RO-BAD-COURIER-CONFIG", [
                'context' => 'shipping_rate_calculation',
                'received' => $rawCouriers,
            ]);
            return [];
        }

        $courierArray = array_values(array_filter(
            explode(':', $rawCouriers),
            fn($c) => trim($c) !== ''
        ));

        if (count($courierArray) === 0) {
            Log::error("RO-EMPTY-COURIERS-AFTER-FILTER", [
                'context' => 'shipping_rate_calculation',
                'raw_config' => $rawCouriers,
            ]);
            return [];
        }

        $courierString = implode(':', $courierArray);

        $client = new RajaOngkirClient($apiKey);
        $resolver = new RajaOngkirDestinationResolver($client);

        // -- Origin resolution --------------------------------------------------
        $origin = $this->resolveOrigin($cart, $resolver);

        if (!$origin) {
            Log::error("RO-MISSING-ORIGIN", [
                'context' => 'shipping_rate_calculation',
            ]);
            return [];
        }

        // -- Destination resolution --------------------------------------------
        $searchKey = $shippingAddress->postcode
            ?: $shippingAddress->city
            ?: $shippingAddress->address1;

        $destinationId = $resolver->resolve($searchKey);

        if (!$destinationId) {
            Log::error("RO-DESTINATION-RESOLVE-FAILED", [
                'context' => 'shipping_rate_calculation',
                'address' => $shippingAddress,
            ]);
            return [];
        }

        // -- Weight in grams ----------------------------------------------------
        $weight = $cart->items->sum(function ($item) {
            $grams = ((float) $item->weight) * 1000;
            return $item->quantity * max(1, (int) round($grams));
        });

        $weight = max(1, (int) $weight);

        if ($weight <= 0) {
            Log::error("RO-INVALID-WEIGHT", [
                'context' => 'shipping_rate_calculation',
                'cart_weight' => $weight,
            ]);
            return [];
        }

        // -- Payload ------------------------------------------------------------
        $payload = [
            'origin' => $origin,
            'destination' => $destinationId,
            'weight' => $weight,
            'courier' => $courierString,
            'price' => 'lowest',
        ];

        $resp = $client->domesticCost($payload);

        // --- Optimization Layer -----------------------------------------------------
        $optimizedServices = $this->optimizeRates($resp);

        $rates = [];

        foreach ($optimizedServices as $service) {
            $price = (float) $service['cost'];

            $rate = new CartShippingRate();
            $rate->carrier = $service['code'];
            $rate->carrier_title = $service['name'] . " " . $service['service'];
            $rate->method = $service['name'] . " " . $service['service'] . $service['description'];
            $rate->method_title = $service['name'];

            $etaText = 'Est. ' . ($service['etd'] ?: '-');
            $rate->method_description =
                e(trim($service['description'])) . ' | ' . e($etaText);

            $rate->price = core()->convertPrice($price);
            $rate->base_price = $price;

            $rates[] = $rate;
        }

        return $rates;
    }

    /**
     * Resolve origin city ID:
     * 1) Try inventory source
     * 2) Fallback: ZIP code → resolved via RajaOngkir
     */
    protected function resolveOrigin($cart, RajaOngkirDestinationResolver $resolver)
    {
        $sourceIds = $cart->items->pluck('inventory_source_id')->filter()->unique();

        // Single inventory source
        if ($sourceIds->count() === 1) {
            $source = \Webkul\Inventory\Models\InventorySource::find($sourceIds->first());

            if ($source && $source->city_id_rajaongkir) {
                return (int) $source->city_id_rajaongkir;
            }

            Log::error("RO-INVALID-INVENTORY-SOURCE-CITY-ID", [
                'context' => 'shipping_rate_calculation',
                'source' => $source,
            ]);
        }

        // Multiple sources
        if ($sourceIds->count() > 1) {
            Log::warning("RO-MULTI-INVENTORY-SOURCE", [
                'context' => 'shipping_rate_calculation',
                'sources' => $sourceIds,
            ]);
        }

        // ZIP fallback
        $originZip = trim((string) $this->getConfigData('origin_zipcode'));

        if ($originZip === '') {
            Log::error("RO-ORIGIN-ZIP-MISSING");
            return null;
        }

        $originId = $resolver->resolve($originZip);

        if (!$originId) {
            Log::error("RO-ORIGIN-ZIP-RESOLUTION-FAILED", [
                'zip' => $originZip,
            ]);
            return null;
        }

        return (int) $originId;
    }

    protected function canonicalServiceCode(array $service): string
    {
        // Priority order: service code from API → parsed from strings → fallback
        $title = strtoupper($service['service'] ?? '');
        $full = strtoupper(($service['name'] ?? '') . ' ' . ($service['service'] ?? ''));

        // Extract well-known service tokens
        $patterns = [
            'CTCYES',
            'CTCSPS',
            'CTC',
            'YES',
            'REG',
            'BEST',
            'GOKIL',
            'JTR>200',
            'JTR>130',
            'JTR<130',
            'JTR',
            'STD',
            'IDLITE',
            'IDTRUCK',
            'EZ',
            'STANDARD',
            'UDRREG',
            'UDRONS',
            'DRGREG'
        ];

        foreach ($patterns as $p) {
            if (str_contains($full, $p)) {
                return $p;
            }
        }

        // Fallback → normalize the "service" field
        return preg_replace('/[^A-Z0-9]+/', '', $title) ?: 'DEFAULT';
    }

    protected function normalizeEta(string $raw): array
    {
        $raw = trim(strtolower($raw));

        // Format: "1-2"
        if (preg_match('/(\d+)\s*-\s*(\d+)/', $raw, $m)) {
            return [(int) $m[1], (int) $m[2]];
        }

        // Format: "2"
        if (preg_match('/(\d+)/', $raw, $m)) {
            $v = (int) $m[1];
            return [$v, $v];
        }

        // "-" or invalid → fallback slowest
        return [9999, 9999];
    }

    protected function optimizeRates(array $resp): array
    {
        $group = [];

        foreach ($resp['data'] as $svc) {

            $carrier = strtolower($svc['code']);

            // Correct ETA normalization (use etd only)
            $etaNorm = $this->normalizeEta((string) ($svc['etd'] ?? '-'));
            $etaKey = implode('-', $etaNorm);

            $price = (float) $svc['cost'];

            if (!isset($group[$carrier][$etaKey]) || $price < $group[$carrier][$etaKey]['cost']) {
                $group[$carrier][$etaKey] = $svc;
            }
        }

        // Flatten
        $optimized = [];
        foreach ($group as $carrier => $etaGroup) {
            foreach ($etaGroup as $svc) {
                $optimized[] = $svc;
            }
        }

        // --- Per-courier limit: keep only best 2 services per courier --------------
        $byCourier = [];

        foreach ($optimized as $svc) {
            $carrier = strtolower($svc['code']);
            $byCourier[$carrier][] = $svc;
        }

        $limited = [];

        foreach ($byCourier as $carrier => $list) {
            // Already sorted: lowest price → best ETA → deterministic code
            $limited = array_merge($limited, array_slice($list, 0, 2));
        }

        $optimized = $limited;

        // Global sort: price → eta → code
        usort($optimized, function ($a, $b) {
            $pA = (float) $a['cost'];
            $pB = (float) $b['cost'];
            if ($pA !== $pB) {
                return $pA <=> $pB;
            }

            // ETA
            $etaA = $this->normalizeEta((string) ($a['etd'] ?? '-'));
            $etaB = $this->normalizeEta((string) ($b['etd'] ?? '-'));
            $minA = min($etaA);
            $minB = min($etaB);
            if ($minA !== $minB) {
                return $minA <=> $minB;
            }

            // Deterministic fallback: carrier + service
            return strcmp($a['code'] . $a['service'], $b['code'] . $b['service']);
        });

        return $optimized;
    }


    /**
     * Convert normalized ETA → sortable integer
     */
    protected function etaSortValue(array $eta): int
    {
        return ($eta[0] * 10000) + $eta[1];
    }
}
