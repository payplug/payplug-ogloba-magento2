<?php
/**
 * Payplug - https://www.payplug.com/
 * Copyright © Payplug. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace Payplug\Ogloba\Service;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\ReadInterface;
use Payplug\Ogloba\Logger\Handler;
use Payplug\Ogloba\Logger\Logger;
use Throwable;

class GetLogTail
{
    public const DEFAULT_LINES = 200;
    public const MAX_LINES = 2000;

    /**
     * How much of the end of the file is read, whatever the number of lines asked for
     */
    private const MAX_BYTES = 2097152;

    /**
     * @param Filesystem $filesystem
     * @param Logger $logger
     */
    public function __construct(
        private readonly Filesystem $filesystem,
        private readonly Logger $logger
    ) {
    }

    /**
     * Read the end of the Ogloba log, newest line first
     *
     * @param int $lines
     * @param string $filter
     * @return string[]
     */
    public function execute(int $lines, string $filter = ''): array
    {
        $lines = max(1, min($lines, self::MAX_LINES));
        $directory = $this->filesystem->getDirectoryRead(DirectoryList::ROOT);
        $path = ltrim(Handler::FILE_NAME, '/');

        if ($directory->isExist($path) === false) {
            return [];
        }

        $content = $this->read($directory, $path);

        if ($content === '') {
            return [];
        }

        $rows = array_filter(
            preg_split('/\R/', $content) ?: [],
            static fn (string $row): bool => trim($row) !== ''
        );

        if ($filter !== '') {
            $rows = array_filter(
                $rows,
                static fn (string $row): bool => stripos($row, $filter) !== false
            );
        }

        return array_reverse(array_slice(array_values($rows), -$lines));
    }

    /**
     * Read the tail of the file, dropping the line the offset cut in half
     *
     * @param ReadInterface $directory
     * @param string $path
     * @return string
     */
    private function read(ReadInterface $directory, string $path): string
    {
        try {
            $size = (int) ($directory->stat($path)['size'] ?? 0);

            if ($size === 0) {
                return '';
            }

            $length = min($size, self::MAX_BYTES);
            $file = $directory->openFile($path);
            $file->seek($size - $length);
            $content = '';

            while (strlen($content) < $length && $file->eof() === false) {
                // ReadInterface::read() declares no return type, and the loop below compares against a string
                $chunk = (string) $file->read($length - strlen($content));

                if ($chunk === '') {
                    break;
                }

                $content .= $chunk;
            }

            $file->close();
        } catch (Throwable $e) {
            $this->logger->error(sprintf('Unable to read %s: %s', Handler::FILE_NAME, $e->getMessage()));

            return '';
        }

        return $length < $size ? (string) strstr($content, "\n") : $content;
    }
}
