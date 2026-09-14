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
use Monolog\LogRecord;
use Payplug\Ogloba\Gateway\Config\Ogloba;

class Handler extends Base
{
    public const FILE_NAME = '/var/log/payplug_ogloba.log';

    /**
     * Logging level
     * @var int
     */
    protected $loggerType = Level::Debug->value;

    /**
     * Log File
     * @var string
     */
    protected $fileName = self::FILE_NAME;

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
