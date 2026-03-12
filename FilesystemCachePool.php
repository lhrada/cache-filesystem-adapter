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
        $list = $this->getList($name);
        if (in_array($key, $list, true)) {
            return true;
        }

        return $this->withListLock(
            (string) $name,
            static function (array $currentList) use ($key): array {
                if (!in_array($key, $currentList, true)) {
                    $currentList[] = $key;
                }

                return $currentList;
            }
        );
    }

    /**
     * {@inheritdoc}
     * @throws \League\Flysystem\FilesystemException
     */
    protected function removeListItem($name, $key): bool
    {
        $list = $this->getList($name);
        $filteredList = array_values(array_filter($list, fn($item) => $item !== $key));
        if ($filteredList === $list) {
            return true;
        }

        return $this->withListLock(
            (string) $name,
            static fn(array $currentList): array => array_values(array_filter($currentList, fn($item) => $item !== $key))
        );
    }

    private function withListLock(string $name, callable $listTransformer): bool
    {
        $lockFile = sys_get_temp_dir().'/cache_pool_'.md5($this->folder.'/'.$name).'.lock';
        $lockHandle = @fopen($lockFile, 'c');

        if ($lockHandle === false) {
            return $this->applyListTransform($name, $listTransformer);
        }

        if (!flock($lockHandle, LOCK_EX)) {
            fclose($lockHandle);

            return $this->applyListTransform($name, $listTransformer);
        }

        try {
            return $this->applyListTransform($name, $listTransformer);
        } finally {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }
    }

    private function applyListTransform(string $name, callable $listTransformer): bool
    {
        $currentList = $this->getList($name);
        $newList = $listTransformer($currentList);
        if (!is_array($newList)) {
            return false;
        }

        return $this->writeListAtomic($name, array_values($newList));
    }

    private function writeListAtomic(string $name, array $list): bool
    {
        $filePath = $this->getFilePath($name);
        $tmpName = $filePath.'.tmp.'.uniqid('', true);

        try {
            $this->filesystem->write($tmpName, serialize($list));
            $this->filesystem->move($tmpName, $filePath);

            return true;
        } catch (FilesystemException $e) {
            try {
                $this->filesystem->delete($tmpName);
            } catch (\Throwable $ignored) {
            }

            return false;
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
