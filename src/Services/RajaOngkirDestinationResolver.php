<?php

namespace Akara\RajaOngkir\Services;

use Akara\RajaOngkir\Clients\RajaOngkirClient;

class RajaOngkirDestinationResolver
{
    protected RajaOngkirClient $client;

    public function __construct(RajaOngkirClient $client)
    {
        $this->client = $client;
    }

    public function resolve(string $search): ?int
    {
        $resp = $this->client->searchDomesticDestination($search, 1, 0);

        if (!isset($resp['data'][0])) {
            return null;
        }

        return (int) $resp['data'][0]['id']; // Required by domestic-cost API
    }
}
