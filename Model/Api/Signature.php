<?php
/**
 * Payplug - https://www.payplug.com/
 * Copyright © Payplug. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace Payplug\Ogloba\Model\Api;

use Payplug\Ogloba\Gateway\Config\Ogloba;
use Payplug\Ogloba\Logger\Logger;

class Signature
{
    private const ALGORITHM = 'sha256';
    private const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    /**
     * @param Ogloba $config
     * @param Logger $logger
     */
    public function __construct(
        private readonly Ogloba $config,
        private readonly Logger $logger
    ) {
    }

    /**
     * Serialize a payload the exact same way it is signed and sent
     *
     * @param array $payload
     * @return string
     */
    public function encode(array $payload): string
    {
        return (string) json_encode($payload, self::JSON_FLAGS);
    }

    /**
     * Is a shared secret the scope can actually sign and verify with
     *
     * @param int|null $storeId
     * @return bool
     */
    public function hasKey(?int $storeId = null): bool
    {
        return $this->config->getHmacKey($storeId) !== '';
    }

    /**
     * Sign an already serialized payload
     *
     * @param string $encodedPayload
     * @param int|null $storeId
     * @return string
     */
    public function sign(string $encodedPayload, ?int $storeId = null): string
    {
        return hash_hmac(self::ALGORITHM, $encodedPayload, $this->config->getHmacKey($storeId));
    }

    /**
     * Verify the signature received on an incoming Ogloba notification
     *
     * @param array $payload
     * @param string $receivedSignature
     * @param int|null $storeId
     * @return bool
     */
    public function verify(array $payload, string $receivedSignature, ?int $storeId = null): bool
    {
        $encodedPayload = $this->encode($payload);

        if ($this->verifyEncoded($encodedPayload, $receivedSignature, $storeId)) {
            return true;
        }

        if ($receivedSignature !== '' && $this->hasKey($storeId)) {
            $this->logger->error(sprintf(
                'The Ogloba payload was re-encoded as %s to be verified, and the signature does not match it. '
                . 'Compare it with the payload as received to spot what the re-encoding changed.',
                $encodedPayload
            ));
        }

        return false;
    }

    /**
     * Verify a signature against the payload exactly as it was received, without any re-encoding
     *
     * @param string $encodedPayload
     * @param string $receivedSignature
     * @param int|null $storeId
     * @return bool
     */
    public function verifyEncoded(string $encodedPayload, string $receivedSignature, ?int $storeId = null): bool
    {
        if ($encodedPayload === '' || $receivedSignature === '') {
            return false;
        }

        if ($this->hasKey($storeId) === false) {
            $this->logger->error(sprintf(
                'No usable Ogloba HMAC key for store %s: the payload is refused without being verified. Check that '
                . 'the key is configured for this scope and that it can still be decrypted.',
                $storeId ?? 'default'
            ));

            return false;
        }

        return hash_equals($this->sign($encodedPayload, $storeId), $receivedSignature);
    }
}
