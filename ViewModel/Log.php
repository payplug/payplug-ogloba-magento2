<?php
/**
 * Payplug - https://www.payplug.com/
 * Copyright © Payplug. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace Payplug\Ogloba\ViewModel;

use Magento\Backend\Model\UrlInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Store\Model\StoreManagerInterface;
use Payplug\Ogloba\Gateway\Config\Ogloba;
use Payplug\Ogloba\Logger\Handler;
use Payplug\Ogloba\Service\GetLogTail;

class Log implements ArgumentInterface
{
    public const PARAM_LINES = 'lines';
    public const PARAM_FILTER = 'filter';
    private const LINE_COUNTS = [100, 200, 500, 1000, 2000];
    private const ROUTE = 'payplug_ogloba/log/index';
    private const CONFIG_ROUTE = 'adminhtml/system_config/edit';
    private const PAYMENT_SECTION = 'payment';

    /**
     * @param RequestInterface $request
     * @param UrlInterface $url
     * @param GetLogTail $getLogTail
     * @param Ogloba $config
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        private readonly RequestInterface $request,
        private readonly UrlInterface $url,
        private readonly GetLogTail $getLogTail,
        private readonly Ogloba $config,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * Get the lines to display, the last one written first
     *
     * @return string[]
     */
    public function getLines(): array
    {
        return $this->getLogTail->execute($this->getLineCount(), $this->getFilter());
    }

    /**
     * Get how many lines the user asked for
     *
     * @return int
     */
    public function getLineCount(): int
    {
        $lines = (int) $this->request->getParam(self::PARAM_LINES);

        return in_array($lines, self::LINE_COUNTS, true) ? $lines : GetLogTail::DEFAULT_LINES;
    }

    /**
     * Get the line counts the user can choose from
     *
     * @return int[]
     */
    public function getLineCounts(): array
    {
        return self::LINE_COUNTS;
    }

    /**
     * Get the text the lines are filtered on, an order number more often than not
     *
     * @return string
     */
    public function getFilter(): string
    {
        return trim((string) $this->request->getParam(self::PARAM_FILTER));
    }

    /**
     * Get the log file the page reads
     *
     * @return string
     */
    public function getLogPath(): string
    {
        return ltrim(Handler::FILE_NAME, '/');
    }

    /**
     * Get where the form posts, which is the page itself
     *
     * @return string
     */
    public function getFormUrl(): string
    {
        return $this->url->getUrl(self::ROUTE);
    }

    /**
     * Get the payment configuration, where Debug Mode is turned on
     *
     * @return string
     */
    public function getConfigUrl(): string
    {
        return $this->url->getUrl(self::CONFIG_ROUTE, ['section' => self::PAYMENT_SECTION]);
    }

    /**
     * Tell an error and a warning apart from the rest, so they are seen at a glance
     *
     * @param string $line
     * @return string
     */
    public function getLineModifier(string $line): string
    {
        return match (true) {
            str_contains($line, '.ERROR:') => '_error',
            str_contains($line, '.WARNING:') => '_warning',
            default => '',
        };
    }

    /**
     * Is Debug Mode on somewhere, which is what puts the API payloads in the log
     *
     * @return bool
     */
    public function isDebugEnabled(): bool
    {
        foreach ($this->storeManager->getStores() as $store) {
            if ($this->config->isDebugEnabled((int) $store->getId())) {
                return true;
            }
        }

        return false;
    }
}
