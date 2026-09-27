<?php

namespace App\Services\Run;

/**
 * The two directory operations a run needs. Written out rather than pulled from
 * symfony/filesystem, which this service does not otherwise depend on.
 */
class Directory
{
    public function copy(string $source, string $target): void
    {
        @mkdir($target, 0775, true);

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($items as $item) {
            /** @var \SplFileInfo $item */
            $destination = rtrim($target, '/').'/'.substr($item->getPathname(), strlen(rtrim($source, '/')) + 1);

            $item->isDir() ? @mkdir($destination, 0775, true) : copy($item->getPathname(), $destination);
        }
    }

    public function remove(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($path);
    }
}
