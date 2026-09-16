<?php

use CloudMe\Notify\Exceptions\ConfigurationException;
use CloudMe\Notify\NotifyClient;

test('an empty clientId or apiKey is rejected', function (string $clientId, string $apiKey) {
    [$privateKey] = generateTestRsaKeyPair();

    new NotifyClient($clientId, $apiKey, $privateKey, 'https://notify.test/api/v1');
})->with([
    ['', 'api-key'],
    ['client-id', ''],
    ['   ', 'api-key'],
])->throws(ConfigurationException::class);

test('an empty baseUrl is rejected', function () {
    [$privateKey] = generateTestRsaKeyPair();

    new NotifyClient('client-id', 'api-key', $privateKey, '   ');
})->throws(ConfigurationException::class);
