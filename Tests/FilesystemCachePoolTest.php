<?php

/*
 * This file is part of php-cache organization.
 *
 * (c) 2015 Aaron Scherer <aequasi@gmail.com>, Tobias Nyholm <tobias.nyholm@gmail.com>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Cache\Adapter\Filesystem\Tests;

use PHPUnit\Framework\TestCase;
use Psr\Cache\InvalidArgumentException;

/**
 * @author Tobias Nyholm <tobias.nyholm@gmail.com>
 */
class FilesystemCachePoolTest extends TestCase
{
    use CreatePoolTrait;

    public function testInvalidKey()
    {
        $this->expectException(InvalidArgumentException::class);

        $pool = $this->createCachePool();

        $pool->getItem('test%string')->get();
    }

    public function testCleanupOnExpire()
    {
        $pool = $this->createCachePool();

        $item = $pool->getItem('test_ttl_null');
        $item->set('data');
        $item->expiresAt(new \DateTime('now'));
        $pool->save($item);
        $this->assertTrue($this->getFilesystem()->has('cache/test_ttl_null'));

        sleep(1);

        $item = $pool->getItem('test_ttl_null');
        $this->assertFalse($item->isHit());
        $this->assertFalse($this->getFilesystem()->has('cache/test_ttl_null'));
    }

    public function testChangeFolder()
    {
        $pool = $this->createCachePool();
        $pool->setFolder('foobar');

        $pool->save($pool->getItem('test_path'));
        $this->assertTrue($this->getFilesystem()->has('foobar/test_path'));
    }

    public function testCorruptedCacheFileHandledNicely()
    {
        $pool = $this->createCachePool();

        $this->getFilesystem()->write('cache/corrupt', 'corrupt data');

        $item = $pool->getItem('corrupt');
        $this->assertFalse($item->isHit());

        $this->getFilesystem()->delete('cache/corrupt');
    }

    public function testCorruptedTagListDoesNotCrashOnExpiredItemCleanup()
    {
        $pool = $this->createCachePool();

        $item = $pool->getItem('expired_with_tag');
        $item->set('data');
        $item->expiresAt(new \DateTime('now'));
        $item->setTags(['broken_tag']);
        $pool->save($item);

        sleep(1);

        $tagKeyMethod = new \ReflectionMethod($pool, 'getTagKey');
        $tagKeyMethod->setAccessible(true);
        $tagKey = $tagKeyMethod->invoke($pool, 'broken_tag');

        $this->getFilesystem()->write('cache/' . $tagKey, 'broken data');

        $expiredItem = $pool->getItem('expired_with_tag');
        $this->assertFalse($expiredItem->isHit());
        $this->assertFalse($this->getFilesystem()->has('cache/expired_with_tag'));

        $recoveredTagList = @unserialize($this->getFilesystem()->read('cache/' . $tagKey));
        $this->assertIsArray($recoveredTagList);
    }
}
