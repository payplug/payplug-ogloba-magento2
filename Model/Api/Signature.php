<?php
/**
 * Payplug - https://www.payplug.com/
 * Copyright © Payplug. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace Payplug\Ogloba\Model\Api;

use Payplug\Ogloba\Gateway\Config\Ogloba;

class Signature
{
    private const ALGORITHM = 'sha256';
    private const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    /**
     * @param Ogloba $config
     */
    public function __construct(
        private readonly Ogloba $config
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
        return $this->verifyEncoded($this->encode($payload), $receivedSignature, $storeId);
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

        return hash_equals($this->sign($encodedPayload, $storeId), $receivedSignature);
    }
}
