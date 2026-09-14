<?php
/**
 * Payplug - https://www.payplug.com/
 * Copyright © Payplug. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace Payplug\Ogloba\Service;

use Magento\Framework\Lock\LockManagerInterface;
use Payplug\Ogloba\Api\Data\OrderLockInterface;
use Psr\Log\LoggerInterface;
use Throwable;

class OrderLockRelease
{
    /**
     * @param LockManagerInterface $lockManager
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly LockManagerInterface $lockManager,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Release the lock of an operation
     *
     * @param string $operationId
     * @return void
     */
    public function execute(string $operationId): void
    {
        if ($operationId === '') {
            return;
        }

        try {
            $this->lockManager->unlock(OrderLockInterface::LOCK_PREFIX . $operationId);
        } catch (Throwable $e) {
            $this->logger->error(sprintf(
                'Could not release the Ogloba lock of operation %s: %s',
                $operationId,
                $e->getMessage()
            ));
        }
    }
}
