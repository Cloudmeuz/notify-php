<?php

use CloudMe\Notify\Auth\SignatureSigner;
use CloudMe\Notify\Exceptions\ConfigurationException;

test('the signing string matches the exact format the Notify API verifies against', function () {
    [$privateKey] = generateTestRsaKeyPair();
    $signer = new SignatureSigner($privateKey);

    $string = $signer->signingString('client-1', 1700000000, 'nonce-abc', 'secret-key');

    expect($string)->toBe("client-1\n1700000000\nnonce-abc\n".hash('sha256', 'secret-key'));
});

test('a signature produced by sign() verifies against the matching public key', function () {
    [$privateKey, $publicKey] = generateTestRsaKeyPair();
    $signer = new SignatureSigner($privateKey);

    $signature = $signer->sign('client-1', 1700000000, 'nonce-abc', 'secret-key');
    $signingString = $signer->signingString('client-1', 1700000000, 'nonce-abc', 'secret-key');

    $verified = openssl_verify($signingString, base64_decode($signature, true), $publicKey, OPENSSL_ALGO_SHA256);

    expect($verified)->toBe(1);
});

test('accepts a private key given as a file path, not just raw PEM content', function () {
    [$privateKey, $publicKey] = generateTestRsaKeyPair();
    $path = tempnam(sys_get_temp_dir(), 'notify-sdk-test-key-');
    file_put_contents($path, $privateKey);

    try {
        $signer = new SignatureSigner($path);
        $signature = $signer->sign('client-1', 1700000000, 'nonce-abc', 'secret-key');
        $signingString = $signer->signingString('client-1', 1700000000, 'nonce-abc', 'secret-key');

        expect(openssl_verify($signingString, base64_decode($signature, true), $publicKey, OPENSSL_ALGO_SHA256))->toBe(1);
    } finally {
        unlink($path);
    }
});

test('an invalid private key is rejected at construction time', function () {
    new SignatureSigner('not a real PEM key');
})->throws(ConfigurationException::class);

test('two nonces generated in a row are never the same', function () {
    expect(SignatureSigner::generateNonce())->not->toBe(SignatureSigner::generateNonce());
});
