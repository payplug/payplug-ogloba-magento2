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
use Payplug\Ogloba\Logger\Logger;
use Throwable;

class OrderLockAcquire
{
    /**
     * @param LockManagerInterface $lockManager
     * @param Logger $logger
     */
    public function __construct(
        private readonly LockManagerInterface $lockManager,
        private readonly Logger $logger
    ) {
    }

    /**
     * Acquire the lock of an operation, blocking up to $timeout seconds
     *
     * @param string $operationId
     * @param int $timeout
     * @return bool
     */
    public function execute(string $operationId, int $timeout = OrderLockInterface::LOCK_TIMEOUT): bool
    {
        if ($operationId === '') {
            return false;
        }

        try {
            return $this->lockManager->lock(OrderLockInterface::LOCK_PREFIX . $operationId, $timeout);
        } catch (Throwable $e) {
            $this->logger->error(sprintf(
                'Could not acquire the Ogloba lock of operation %s: %s',
                $operationId,
                $e->getMessage()
            ));

            return false;
        }
    }
}
