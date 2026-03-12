<?php

/*
 * This file is part of php-cache organization.
 *
 * (c) 2015 Aaron Scherer <aequasi@gmail.com>, Tobias Nyholm <tobias.nyholm@gmail.com>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Cache\Adapter\Filesystem;

use Cache\Adapter\Common\AbstractCachePool;
use Cache\Adapter\Common\Exception\InvalidArgumentException;
use Cache\Adapter\Common\PhpCacheItem;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemException;

/**
 * @author Tobias Nyholm <tobias.nyholm@gmail.com>
 */
class FilesystemCachePool extends AbstractCachePool
{
    /**
     * @type Filesystem
     */
    private Filesystem $filesystem;

    /**
     * The folder should not begin nor end with a slash. Example: path/to/cache.
     *
     * @type string
     */
    private string $folder;

    /**
     * @param Filesystem $filesystem
     * @param string $folder
     *
     * @throws \League\Flysystem\FilesystemException
     */
    public function __construct(Filesystem $filesystem, string $folder = 'cache')
    {
        $this->folder = $folder;

        $this->filesystem = $filesystem;
        $this->filesystem->createDirectory($this->folder, []);
    }

    /**
     * @param string $folder
     */
    public function setFolder(string $folder)
    {
        $this->folder = $folder;
    }

    /**
     * {@inheritdoc}
     * @throws \League\Flysystem\FilesystemException
     */
    protected function fetchObjectFromCache($key): array
    {
        $empty = [false, null, [], null];
        $file = $this->getFilePath($key);

        try {
            $data = @unserialize($this->filesystem->read($file));
            if (!is_array($data) || !array_key_exists(0, $data) || !array_key_exists(1, $data) || !array_key_exists(2, $data)) {
                return $empty;
            }
        } catch (FilesystemException $e) {
            return $empty;
        }

        // Determine expirationTimestamp from data, remove items if expired.
        $expirationTimestamp = $data[2] ?: null;
        if ($expirationTimestamp !== null && time() > $expirationTimestamp) {
            foreach ((array) $data[1] as $tag) {
                $this->removeListItem($this->getTagKey($tag), $key);
            }
            $this->forceClear($key);

            return $empty;
        }

        return [true, $data[0], (array) $data[1], $expirationTimestamp];
    }

    /**
     * {@inheritdoc}
     * @throws \League\Flysystem\FilesystemException
     */
    protected function clearAllObjectsFromCache(): bool
    {
        $this->filesystem->deleteDirectory($this->folder);
        $this->filesystem->createDirectory($this->folder);

        return true;
    }

    /**
     * {@inheritdoc}
     */
    protected function clearOneObjectFromCache($key): bool
    {
        return $this->forceClear($key);
    }

    /**
     * {@inheritdoc}
     */
    protected function storeItemInCache(PhpCacheItem $item, $ttl): bool
    {
        $data = serialize(
            [
                $item->get(),
                $item->getTags(),
                $item->getExpirationTimestamp(),
            ]
        );

        $file = $this->getFilePath($item->getKey());
        try {
            $this->filesystem->write($file, $data);

            return true;
        } catch (FilesystemException $e) {
            return false;
        }
    }

    /**
     * @param string $key
     *
     * @return string
     * @throws InvalidArgumentException
     *
     */
    private function getFilePath(string $key): string
    {
        if (! preg_match('|^[a-zA-Z0-9_\.! ]+$|', $key)) {
            throw new InvalidArgumentException(sprintf('Invalid key "%s". Valid filenames must match [a-zA-Z0-9_\.! ].', $key));
        }

        return sprintf('%s/%s', $this->folder, $key);
    }

    /**
     * {@inheritdoc}
     * @throws \League\Flysystem\FilesystemException
     */
    protected function getList($name)
    {
        $file = $this->getFilePath($name);
        try {
            if (!$this->filesystem->has($file)) {
                return [];
            }

            $list = @unserialize($this->filesystem->read($file));
        } catch (FilesystemException $e) {
            return [];
        }

        if (!is_array($list)) {
            return [];
        }

        return $list;
    }

    /**
     * {@inheritdoc}
     * @throws \League\Flysystem\FilesystemException
     */
    protected function removeList($name)
    {
        $file = $this->getFilePath($name);
        $this->filesystem->delete($file);
    }

    /**
     * {@inheritdoc}
     * @throws \League\Flysystem\FilesystemException
     */
    protected function appendListItem($name, $key): bool
    {
        return $this->withListLock($name, function (array $list) use ($key): array {
            if (!in_array($key, $list, true)) {
                $list[] = $key;
            }

            return $list;
        });
    }

    /**
     * {@inheritdoc}
     * @throws \League\Flysystem\FilesystemException
     */
    protected function removeListItem($name, $key): bool
    {
        return $this->withListLock($name, function (array $list) use ($key): array {
            return array_values(array_filter($list, fn ($item): bool => $item !== $key));
        });
    }

    /**
     * Read-lock-modify-write pattern for tag-list files.
     * Uses flock() on a temp-dir lock file to serialize concurrent access.
     * After acquiring the lock the list is re-read so any concurrent write
     * that happened between our original read and the lock acquisition is merged.
     *
     * @param string   $name      List name (tag key)
     * @param callable $transform (array $currentList): array  — pure mutation callback
     *
     * @throws \League\Flysystem\FilesystemException
     */
    private function withListLock(string $name, callable $transform): bool
    {
        $lockPath = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'cache_pool_' . md5($this->folder . '/' . $name) . '.lock';

        $lockHandle = @fopen($lockPath, 'c');

        if ($lockHandle === false) {
            // Fallback: no lock, best-effort write (degraded path)
            try {
                $list = $this->getList($name);
                $this->filesystem->write($this->getFilePath($name), serialize($transform($list)));

                return true;
            } catch (FilesystemException $e) {
                return false;
            }
        }

        flock($lockHandle, LOCK_EX);

        try {
            // Re-read AFTER acquiring the lock to capture any concurrent writes
            $current = $this->getList($name);
            $updated = $transform($current);
            $this->filesystem->write($this->getFilePath($name), serialize(array_values($updated)));

            return true;
        } catch (FilesystemException $e) {
            return false;
        } finally {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }
    }

    /**
     * @param $key
     *
     * @return bool
     */
    private function forceClear($key): bool
    {
        try {
            $this->filesystem->delete($this->getFilePath($key));

            return true;
        } catch (FilesystemException $e) {
            return false;
        }
    }
}
