<?php

namespace FileSystemCache\Tests;

use FileSystemCache\FileSystemCachePool;
use FileSystemCache\Tests\Trait\CreatePoolTrait;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Cache\InvalidArgumentException;

class IntegrationTagTest extends TestCase
{
    use CreatePoolTrait;

    protected ?FileSystemCachePool $cache;

    #[Before]
    public function setupService()
    {
        $this->cache = $this->createCachePool();
    }

    #[After]
    public function tearDownService()
    {
        if (null !== $this->cache) {
            $this->cache->clear();
        }
    }

    public static function invalidKeys()
    {
        return CachePoolTest::invalidKeys();
    }

    public function testMultipleTags()
    {
        $this->cache->save($this->cache->getItem('key1')->set('value')->setTags(['tag1', 'tag2']));
        $this->cache->save($this->cache->getItem('key2')->set('value')->setTags(['tag1', 'tag3']));
        $this->cache->save($this->cache->getItem('key3')->set('value')->setTags(['tag2', 'tag3']));
        $this->cache->save($this->cache->getItem('key4')->set('value')->setTags(['tag4', 'tag3']));

        $this->cache->invalidateTags(['tag1']);
        $this->assertFalse($this->cache->hasItem('key1'));
        $this->assertFalse($this->cache->hasItem('key2'));
        $this->assertTrue($this->cache->hasItem('key3'));
        $this->assertTrue($this->cache->hasItem('key4'));

        $this->cache->invalidateTags(['tag2']);
        $this->assertFalse($this->cache->hasItem('key1'));
        $this->assertFalse($this->cache->hasItem('key2'));
        $this->assertFalse($this->cache->hasItem('key3'));
        $this->assertTrue($this->cache->hasItem('key4'));
    }

    public function testPreviousTag()
    {
        $item = $this->cache->getItem('key')->set('value');
        $tags = $item->getPreviousTags();
        $this->assertTrue(is_array($tags));
        $this->assertCount(0, $tags);

        $item->setTags(['tag0']);
        $this->assertCount(0, $item->getPreviousTags());

        $this->cache->save($item);
        $this->assertCount(0, $item->getPreviousTags());

        $item = $this->cache->getItem('key');
        $this->assertCount(1, $item->getPreviousTags());
    }

    public function testPreviousTagDeferred()
    {
        $item = $this->cache->getItem('key')->set('value');
        $item->setTags(['tag0']);
        $this->assertCount(0, $item->getPreviousTags());

        $this->cache->saveDeferred($item);
        $this->assertCount(0, $item->getPreviousTags());

        $item = $this->cache->getItem('key');
        $this->assertCount(1, $item->getPreviousTags());
    }

    public function testTagAccessorWithEmptyTag()
    {
        $item = $this->cache->getItem('key')->set('value');
        $this->expectException(InvalidArgumentException::class);
        $item->setTags(['']);
    }

    #[DataProvider('invalidKeys')]
    public function testTagAccessorWithInvalidTag($tag)
    {
        $item = $this->cache->getItem('key')->set('value');
        $this->expectException(InvalidArgumentException::class);
        $item->setTags([$tag]);
    }

    public function testTagAccessorDuplicateTags()
    {
        $item = $this->cache->getItem('key')->set('value');
        $item->setTags(['tag', 'tag', 'tag']);
        $this->cache->save($item);
        $item = $this->cache->getItem('key');

        $this->assertCount(1, $item->getPreviousTags());
    }

    /**
     * The tag must be removed whenever we remove an item. If not, when creating a new item
     * with the same key will get the same tags.
     */
    public function testRemoveTagWhenItemIsRemoved()
    {
        $item = $this->cache->getItem('key')->set('value');
        $item->setTags(['tag1']);

        // Save the item and then delete it
        $this->cache->save($item);
        $this->cache->deleteItem('key');

        // Create a new item (same key) (no tags)
        $item = $this->cache->getItem('key')->set('value');
        $this->cache->save($item);

        // Clear the tag, The new item should not be cleared
        $this->cache->invalidateTags(['tag1']);
        $this->assertTrue($this->cache->hasItem('key'), 'Item key should be removed from the tag list when the item is removed');
    }

    public function testClearPool()
    {
        $item = $this->cache->getItem('key')->set('value');
        $item->setTags(['tag1']);
        $this->cache->save($item);

        // Clear the pool
        $this->cache->clear();

        // Create a new item (no tags)
        $item = $this->cache->getItem('key')->set('value');
        $this->cache->save($item);
        $this->cache->invalidateTags(['tag1']);

        $this->assertTrue($this->cache->hasItem('key'), 'Tags should be removed when the pool was cleared.');
    }

    public function testInvalidateTag()
    {
        $item = $this->cache->getItem('key')->set('value');
        $item->setTags(['tag1', 'tag2']);
        $this->cache->save($item);
        $item = $this->cache->getItem('key2')->set('value');
        $item->setTags(['tag1']);
        $this->cache->save($item);

        $this->cache->invalidateTag('tag2');
        $this->assertFalse($this->cache->hasItem('key'), 'Item should be cleared when tag is invalidated');
        $this->assertTrue($this->cache->hasItem('key2'), 'Item should be cleared when tag is invalidated');

        // Create a new item (no tags)
        $item = $this->cache->getItem('key')->set('value');
        $this->cache->save($item);
        $this->cache->invalidateTags(['tag2']);
        $this->assertTrue($this->cache->hasItem('key'), 'Item key list should be removed when clearing the tags');

        $this->cache->invalidateTags(['tag1']);
        $this->assertTrue($this->cache->hasItem('key'), 'Item key list should be removed when clearing the tags');
    }

    public function testInvalidateTags()
    {
        $item = $this->cache->getItem('key')->set('value');
        $item->setTags(['tag1', 'tag2']);
        $this->cache->save($item);
        $item = $this->cache->getItem('key2')->set('value');
        $item->setTags(['tag1']);
        $this->cache->save($item);

        $this->cache->invalidateTags(['tag1', 'tag2']);
        $this->assertFalse($this->cache->hasItem('key'), 'Item should be cleared when tag is invalidated');
        $this->assertFalse($this->cache->hasItem('key2'), 'Item should be cleared when tag is invalidated');

        // Create a new item (no tags)
        $item = $this->cache->getItem('key')->set('value');
        $this->cache->save($item);
        $this->cache->invalidateTags(['tag1']);

        $this->assertTrue($this->cache->hasItem('key'), 'Item k list should be removed when clearing the tags');
    }

    /**
     * When an item is overwritten we need to clear tags for original item.
     */
    public function testTagsAreCleanedOnSave()
    {
        $pool = $this->cache;
        $i = $pool->getItem('key')->set('value');
        $pool->save($i->setTags(['foo']));
        $i = $pool->getItem('key');
        $pool->save($i->setTags(['bar']));
        $pool->invalidateTags(['foo']);
        $this->assertTrue($pool->getItem('key')->isHit());
    }
}
