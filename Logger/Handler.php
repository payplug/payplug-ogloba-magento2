<?php
/**
 * Payplug - https://www.payplug.com/
 * Copyright © Payplug. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace Payplug\Ogloba\Logger;

use Magento\Framework\Filesystem\DriverInterface;
use Magento\Framework\Logger\Handler\Base;
use Monolog\Level;
use Monolog\Logger as MonologLogger;
use Monolog\LogRecord;
use Payplug\Ogloba\Gateway\Config\Ogloba;

class Handler extends Base
{
    /**
     * Logging level
     * @var int
     */
    protected $loggerType = MonologLogger::DEBUG;

    /**
     * Log File
     * @var string
     */
    protected $fileName = '/var/log/payplug_ogloba.log';

    /**
     * @param DriverInterface $filesystem
     * @param Ogloba $config
     * @param string|null $filePath
     * @param string|null $fileName
     */
    public function __construct(
        DriverInterface $filesystem,
        private readonly Ogloba $config,
        ?string $filePath = null,
        ?string $fileName = null
    ) {
        parent::__construct($filesystem, $filePath, $fileName);
    }

    /**
     * Keep the debug records out of the log unless the merchant asked for them
     *
     * Deciding here rather than at every call site keeps the callers free of the configuration, and
     * keeps the raw payloads out of the log by default: they are verbose and they carry the operation
     * secrets that let a transaction be traced.
     *
     * @param LogRecord $record
     * @return bool
     */
    public function isHandling(LogRecord $record): bool
    {
        if ($record->level->value < Level::Info->value && $this->config->isDebugEnabled() === false) {
            return false;
        }

        return parent::isHandling($record);
    }
}
