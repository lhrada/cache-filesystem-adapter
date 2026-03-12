<?php

declare(strict_types=1);

namespace Cache\Adapter\Filesystem\Tests;

use Cache\Adapter\Filesystem\FilesystemCachePool;
use League\Flysystem\Config;
use League\Flysystem\FileAttributes;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\FilesystemException;
use League\Flysystem\UnableToCheckExistence;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToCreateDirectory;
use League\Flysystem\UnableToDeleteDirectory;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToSetVisibility;
use League\Flysystem\UnableToWriteFile;
use PHPUnit\Framework\TestCase;

final class FilesystemCachePoolTest extends TestCase
{
    public function testCorruptedTagListRecovery(): void
    {
        $adapter = new InMemoryFilesystemAdapter();
        $filesystem = new Filesystem($adapter);
        $pool = new TestableFilesystemCachePool($filesystem);

        $adapter->seed('cache/tag!broken', serialize('not-an-array'));

        self::assertSame([], $pool->readList('tag!broken'));
        self::assertTrue($pool->removeFromList('tag!broken', 'item-a'));
        self::assertSame([], $pool->readList('tag!broken'));
    }

    public function testConcurrentWriteLostUpdateInAppendListItem(): void
    {
        $adapter = new InMemoryFilesystemAdapter();
        $filesystem = new Filesystem($adapter);
        $pool = new TestableFilesystemCachePool($filesystem);

        $path = 'cache/tag!race';
        $adapter->seed($path, serialize(['base']));
        $adapter->setCorruptOnDirectRewrite($path);

        self::assertTrue($pool->appendToList('tag!race', 'local'));

        $finalList = $pool->readList('tag!race');
        sort($finalList);

        self::assertSame(['base', 'local'], $finalList);
    }

    public function testWriteFailureGracefulHandling(): void
    {
        $adapter = new InMemoryFilesystemAdapter();
        $adapter->setFailWrites(true);

        $filesystem = new Filesystem($adapter);
        $pool = new TestableFilesystemCachePool($filesystem);

        self::assertFalse($pool->appendToList('tag!failure', 'item-a'));
    }

    public function testAtomicWriteIntegrityAcrossSequentialAppends(): void
    {
        $adapter = new InMemoryFilesystemAdapter();
        $filesystem = new Filesystem($adapter);
        $pool = new TestableFilesystemCachePool($filesystem);

        $items = ['item-1', 'item-2', 'item-3', 'item-4', 'item-5'];
        foreach ($items as $item) {
            self::assertTrue($pool->appendToList('tag!integrity', $item));
        }

        $storedItems = $pool->readList('tag!integrity');
        sort($storedItems);
        sort($items);

        self::assertSame($items, $storedItems);
    }
}

final class TestableFilesystemCachePool extends FilesystemCachePool
{
    public function appendToList(string $name, string $key): bool
    {
        return $this->appendListItem($name, $key);
    }

    public function removeFromList(string $name, string $key): bool
    {
        return $this->removeListItem($name, $key);
    }

    public function readList(string $name): array
    {
        $list = $this->getList($name);

        return is_array($list) ? $list : [];
    }
}

final class InMemoryFilesystemAdapter implements FilesystemAdapter
{
    private array $files = [];

    private array $corruptOnDirectRewrite = [];

    private bool $failWrites = false;

    public function seed(string $path, string $content): void
    {
        $this->files[$path] = $content;
    }

    public function setCorruptOnDirectRewrite(string $path): void
    {
        $this->corruptOnDirectRewrite[$path] = true;
    }

    public function setFailWrites(bool $failWrites): void
    {
        $this->failWrites = $failWrites;
    }

    public function fileExists(string $path): bool
    {
        return array_key_exists($path, $this->files);
    }

    public function directoryExists(string $path): bool
    {
        foreach (array_keys($this->files) as $filePath) {
            if (str_starts_with($filePath, rtrim($path, '/') . '/')) {
                return true;
            }
        }

        return false;
    }

    public function write(string $path, string $contents, Config $config): void
    {
        if ($this->failWrites) {
            throw UnableToWriteFile::atLocation($path, 'Injected write failure');
        }

        if (isset($this->corruptOnDirectRewrite[$path]) && array_key_exists($path, $this->files)) {
            $this->files[$path] = 'incomplete-serialized-payload';

            return;
        }

        $this->files[$path] = $contents;
    }

    public function writeStream(string $path, $contents, Config $config): void
    {
        if (!is_resource($contents)) {
            throw UnableToWriteFile::atLocation($path, 'Expected stream resource');
        }

        $data = stream_get_contents($contents);
        if ($data === false) {
            throw UnableToWriteFile::atLocation($path, 'Unable to read stream');
        }

        $this->write($path, $data, $config);
    }

    public function read(string $path): string
    {
        if (!array_key_exists($path, $this->files)) {
            throw UnableToReadFile::fromLocation($path);
        }

        $content = $this->files[$path];

        return $content;
    }

    public function readStream(string $path)
    {
        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            throw UnableToReadFile::fromLocation($path, 'Unable to open temporary stream');
        }

        fwrite($stream, $this->read($path));
        rewind($stream);

        return $stream;
    }

    public function delete(string $path): void
    {
        unset($this->files[$path]);
    }

    public function deleteDirectory(string $path): void
    {
        $prefix = rtrim($path, '/') . '/';
        foreach (array_keys($this->files) as $filePath) {
            if (str_starts_with($filePath, $prefix)) {
                unset($this->files[$filePath]);
            }
        }
    }

    public function createDirectory(string $path, Config $config): void
    {
    }

    public function setVisibility(string $path, string $visibility): void
    {
    }

    public function visibility(string $path): FileAttributes
    {
        return new FileAttributes($path);
    }

    public function mimeType(string $path): FileAttributes
    {
        return new FileAttributes($path, null, null, null, 'application/octet-stream');
    }

    public function lastModified(string $path): FileAttributes
    {
        return new FileAttributes($path, null, null, time());
    }

    public function fileSize(string $path): FileAttributes
    {
        $size = array_key_exists($path, $this->files) ? strlen($this->files[$path]) : 0;

        return new FileAttributes($path, $size);
    }

    public function listContents(string $path, bool $deep): iterable
    {
        return [];
    }

    public function move(string $source, string $destination, Config $config): void
    {
        if (!array_key_exists($source, $this->files)) {
            throw UnableToMoveFile::fromLocationTo($source, $destination);
        }

        $this->files[$destination] = $this->files[$source];
        unset($this->files[$source]);
    }

    public function copy(string $source, string $destination, Config $config): void
    {
        if (!array_key_exists($source, $this->files)) {
            throw UnableToCopyFile::fromLocationTo($source, $destination);
        }

        $this->files[$destination] = $this->files[$source];
    }
}
