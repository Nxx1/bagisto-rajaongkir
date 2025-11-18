<?php

return [
    [
        'key' => 'sales.carriers.rajaongkir',
        'name' => 'RajaOngkir Shipping',
        'info' => 'RajaOngkir Shipping API',
        'fields' => [
            ['name' => 'active', 'title' => 'Enabled', 'type' => 'boolean', 'default_value' => true],
            ['name' => 'api_key', 'title' => 'RajaOngkir API Key', 'type' => 'text', 'validation' => 'required'],
            ['name' => 'account_type', 'title' => 'Account Type', 'type' => 'select', 'options' => [['title' => 'Starter', 'value' => 'starter'], ['title' => 'Basic', 'value' => 'basic'], ['title' => 'Pro', 'value' => 'pro']], 'default_value' => 'starter'],
            ['name' => 'origin_zipcode', 'title' => 'Origin City ZIP Code', 'type' => 'text', 'validation' => 'required|numeric', 'default_value' => 20152],
            ['name' => 'couriers', 'title' => 'Couriers ( : separated, example: jne:sicepat:ide:sap:jnt:ninja:tiki:lion:anteraja:pos:ncs:rex:rpx:sentral:star:wahana:dse)', 'type' => 'text', 'default_value' => 'jne:sicepat:ide:sap:ninja:jnt:tiki:wahana'],
            ['name' => 'cache_ttl', 'title' => 'Cache TTL (seconds)', 'type' => 'text', 'default_value' => '300'],
        ],
    ],
];
