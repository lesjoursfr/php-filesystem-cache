<?php

namespace FileSystemCache\Tests;

use FileSystemCache\Tests\Trait\CreatePoolTrait;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\InvalidArgumentException;

class CachePoolTest extends TestCase
{
    use CreatePoolTrait;

    protected ?CacheItemPoolInterface $cache;

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

    /**
     * Data provider for invalid keys.
     *
     * @return array
     */
    public static function invalidKeys()
    {
        return [
            [''],
            ['{str'],
            ['rand{'],
            ['rand{str'],
            ['rand}str'],
            ['rand(str'],
            ['rand)str'],
            ['rand/str'],
            ['rand\\str'],
            ['rand@str'],
            ['rand:str'],
        ];
    }

    public function testBasicUsage()
    {
        $item = $this->cache->getItem('key');
        $item->set('4711');
        $this->cache->save($item);

        $item = $this->cache->getItem('key2');
        $item->set('4712');
        $this->cache->save($item);

        $fooItem = $this->cache->getItem('key');
        $this->assertTrue($fooItem->isHit());
        $this->assertEquals('4711', $fooItem->get());

        $barItem = $this->cache->getItem('key2');
        $this->assertTrue($barItem->isHit());
        $this->assertEquals('4712', $barItem->get());

        // Remove 'key' and make sure 'key2' is still there
        $this->cache->deleteItem('key');
        $this->assertFalse($this->cache->getItem('key')->isHit());
        $this->assertTrue($this->cache->getItem('key2')->isHit());

        // Remove everything
        $this->cache->clear();
        $this->assertFalse($this->cache->getItem('key')->isHit());
        $this->assertFalse($this->cache->getItem('key2')->isHit());
    }

    public function testItemModifiersReturnsStatic()
    {
        $item = $this->cache->getItem('key');
        $this->assertSame($item, $item->set('4711'));
        $this->assertSame($item, $item->expiresAfter(2));
        $this->assertSame($item, $item->expiresAt(new \DateTime('+2hours')));
    }

    public function testGetItem()
    {
        $item = $this->cache->getItem('key');
        $item->set('value');
        $this->cache->save($item);

        // get existing item
        $item = $this->cache->getItem('key');
        $this->assertEquals('value', $item->get(), 'A stored item must be returned from cached.');
        $this->assertEquals('key', $item->getKey(), 'Cache key can not change.');

        // get non-existent item
        $item = $this->cache->getItem('key2');
        $this->assertFalse($item->isHit());
        $this->assertNull($item->get(), "Item's value must be null when isHit is false.");
    }

    public function testGetItems()
    {
        $keys = ['foo', 'bar', 'baz'];
        $items = $this->cache->getItems($keys);

        $count = 0;

        /** @var CacheItemInterface $item */
        foreach ($items as $i => $item) {
            $item->set($i);
            $this->cache->save($item);

            ++$count;
        }

        $this->assertSame(3, $count);

        $keys[] = 'biz';
        /** @var CacheItemInterface[] $items */
        $items = $this->cache->getItems($keys);
        $count = 0;
        foreach ($items as $key => $item) {
            $itemKey = $item->getKey();
            $this->assertEquals($itemKey, $key, 'Keys must be preserved when fetching multiple items');
            $this->assertEquals('biz' !== $key, $item->isHit());
            $this->assertTrue(in_array($key, $keys), 'Cache key can not change.');

            // Remove $key for $keys
            foreach ($keys as $k => $v) {
                if ($v === $key) {
                    unset($keys[$k]);
                }
            }

            ++$count;
        }

        $this->assertSame(4, $count);
    }

    public function testGetItemsEmpty()
    {
        $items = $this->cache->getItems([]);
        $this->assertTrue(
            is_array($items) || $items instanceof \Traversable,
            'A call to getItems with an empty array must always return an array or \Traversable.'
        );

        $count = 0;
        foreach ($items as $item) {
            ++$count;
        }

        $this->assertSame(0, $count);
    }

    public function testHasItem()
    {
        $item = $this->cache->getItem('key');
        $item->set('value');
        $this->cache->save($item);

        // has existing item
        $this->assertTrue($this->cache->hasItem('key'));

        // has non-existent item
        $this->assertFalse($this->cache->hasItem('key2'));
    }

    public function testClear()
    {
        $item = $this->cache->getItem('key');
        $item->set('value');
        $this->cache->save($item);

        $return = $this->cache->clear();

        $this->assertTrue($return, 'clear() must return true if cache was cleared. ');
        $this->assertFalse($this->cache->getItem('key')->isHit(), 'No item should be a hit after the cache is cleared. ');
        $this->assertFalse($this->cache->hasItem('key2'), 'The cache pool should be empty after it is cleared.');
    }

    public function testClearWithDeferredItems()
    {
        $item = $this->cache->getItem('key');
        $item->set('value');
        $this->cache->saveDeferred($item);

        $this->cache->clear();
        $this->cache->commit();

        $this->assertFalse($this->cache->getItem('key')->isHit(), 'Deferred items must be cleared on clear(). ');
    }

    public function testDeleteItem()
    {
        $item = $this->cache->getItem('key');
        $item->set('value');
        $this->cache->save($item);

        $this->assertTrue($this->cache->deleteItem('key'));
        $this->assertFalse($this->cache->getItem('key')->isHit(), 'A deleted item should not be a hit.');
        $this->assertFalse($this->cache->hasItem('key'), 'A deleted item should not be a in cache.');

        $this->assertTrue($this->cache->deleteItem('key2'), 'Deleting an item that does not exist should return true.');
    }

    public function testDeleteItems()
    {
        $items = $this->cache->getItems(['foo', 'bar', 'baz']);

        /** @var CacheItemInterface $item */
        foreach ($items as $idx => $item) {
            $item->set($idx);
            $this->cache->save($item);
        }

        // All should be a hit but 'biz'
        $this->assertTrue($this->cache->getItem('foo')->isHit());
        $this->assertTrue($this->cache->getItem('bar')->isHit());
        $this->assertTrue($this->cache->getItem('baz')->isHit());
        $this->assertFalse($this->cache->getItem('biz')->isHit());

        $return = $this->cache->deleteItems(['foo', 'bar', 'biz']);
        $this->assertTrue($return);

        $this->assertFalse($this->cache->getItem('foo')->isHit());
        $this->assertFalse($this->cache->getItem('bar')->isHit());
        $this->assertTrue($this->cache->getItem('baz')->isHit());
        $this->assertFalse($this->cache->getItem('biz')->isHit());
    }

    public function testSave()
    {
        $item = $this->cache->getItem('key');
        $item->set('value');
        $return = $this->cache->save($item);

        $this->assertTrue($return, 'save() should return true when items are saved.');
        $this->assertEquals('value', $this->cache->getItem('key')->get());
    }

    public function testSaveExpired()
    {
        $item = $this->cache->getItem('key');
        $item->set('value');
        $item->expiresAt(\DateTime::createFromFormat('U', time() + 10));
        $this->cache->save($item);
        $item->expiresAt(\DateTime::createFromFormat('U', time() - 1));
        $this->cache->save($item);
        $item = $this->cache->getItem('key');
        $this->assertFalse($item->isHit(), 'Cache should not save expired items');
    }

    public function testSaveWithoutExpire()
    {
        $item = $this->cache->getItem('test_ttl_null');
        $item->set('data');
        $this->cache->save($item);

        // Use a new pool instance to ensure that we don't hit any caches
        $pool = $this->createCachePool();
        $item = $pool->getItem('test_ttl_null');

        $this->assertTrue($item->isHit(), 'Cache should have retrieved the items');
        $this->assertEquals('data', $item->get());
    }

    public function testDeferredSave()
    {
        $item = $this->cache->getItem('key');
        $item->set('4711');
        $return = $this->cache->saveDeferred($item);
        $this->assertTrue($return, 'save() should return true when items are saved.');

        $item = $this->cache->getItem('key2');
        $item->set('4712');
        $this->cache->saveDeferred($item);

        // They are not saved yet but should be a hit
        $this->assertTrue($this->cache->hasItem('key'), 'Deferred items should be considered as a part of the cache even before they are committed');
        $this->assertTrue($this->cache->getItem('key')->isHit(), 'Deferred items should be a hit even before they are committed');
        $this->assertTrue($this->cache->getItem('key2')->isHit());

        $this->cache->commit();

        // They should be a hit after the commit as well
        $this->assertTrue($this->cache->getItem('key')->isHit());
        $this->assertTrue($this->cache->getItem('key2')->isHit());
    }

    public function testDeferredExpired()
    {
        $item = $this->cache->getItem('key');
        $item->set('4711');
        $item->expiresAt(\DateTime::createFromFormat('U', time() - 1));
        $this->cache->saveDeferred($item);

        $this->assertFalse($this->cache->hasItem('key'), 'Cache should not have expired deferred item');
        $this->cache->commit();
        $item = $this->cache->getItem('key');
        $this->assertFalse($item->isHit(), 'Cache should not save expired items');
    }

    public function testDeleteDeferredItem()
    {
        $item = $this->cache->getItem('key');
        $item->set('4711');
        $this->cache->saveDeferred($item);
        $this->assertTrue($this->cache->getItem('key')->isHit());

        $this->cache->deleteItem('key');
        $this->assertFalse($this->cache->hasItem('key'), 'You must be able to delete a deferred item before committed. ');
        $this->assertFalse($this->cache->getItem('key')->isHit(), 'You must be able to delete a deferred item before committed. ');

        $this->cache->commit();
        $this->assertFalse($this->cache->hasItem('key'), 'A deleted item should not reappear after commit. ');
        $this->assertFalse($this->cache->getItem('key')->isHit(), 'A deleted item should not reappear after commit. ');
    }

    public function testDeferredSaveWithoutCommit()
    {
        $this->prepareDeferredSaveWithoutCommit();
        gc_collect_cycles();

        $cache = $this->createCachePool();
        $this->assertTrue($cache->getItem('key')->isHit(), 'A deferred item should automatically be committed on CachePool::__destruct().');
    }

    public function testCommit()
    {
        $item = $this->cache->getItem('key');
        $item->set('value');
        $this->cache->saveDeferred($item);
        $return = $this->cache->commit();

        $this->assertTrue($return, 'commit() should return true on successful commit. ');
        $this->assertEquals('value', $this->cache->getItem('key')->get());

        $return = $this->cache->commit();
        $this->assertTrue($return, 'commit() should return true even if no items were deferred. ');
    }

    public function testExpiration()
    {
        $item = $this->cache->getItem('key');
        $item->set('value');
        $item->expiresAfter(2);
        $this->cache->save($item);

        sleep(3);
        $item = $this->cache->getItem('key');
        $this->assertFalse($item->isHit());
        $this->assertNull($item->get(), "Item's value must be null when isHit() is false.");
    }

    public function testExpiresAt()
    {
        $item = $this->cache->getItem('key');
        $item->set('value');
        $item->expiresAt(new \DateTime('+2hours'));
        $this->cache->save($item);

        $item = $this->cache->getItem('key');
        $this->assertTrue($item->isHit());
    }

    public function testExpiresAtWithNull()
    {
        $item = $this->cache->getItem('key');
        $item->set('value');
        $item->expiresAt(null);
        $this->cache->save($item);

        $item = $this->cache->getItem('key');
        $this->assertTrue($item->isHit());
    }

    public function testExpiresAfterWithNull()
    {
        $item = $this->cache->getItem('key');
        $item->set('value');
        $item->expiresAfter(null);
        $this->cache->save($item);

        $item = $this->cache->getItem('key');
        $this->assertTrue($item->isHit());
    }

    public function testKeyLength()
    {
        $key = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_.';
        $item = $this->cache->getItem($key);
        $item->set('value');
        $this->assertTrue($this->cache->save($item), 'The implementation does not support a valid cache key');

        $this->assertTrue($this->cache->hasItem($key));
    }

    #[DataProvider('invalidKeys')]
    public function testGetItemInvalidKeys($key)
    {
        $this->expectException(InvalidArgumentException::class);
        $this->cache->getItem($key);
    }

    #[DataProvider('invalidKeys')]
    public function testGetItemsInvalidKeys($key)
    {
        $this->expectException(InvalidArgumentException::class);
        $this->cache->getItems(['key1', $key, 'key2']);
    }

    #[DataProvider('invalidKeys')]
    public function testHasItemInvalidKeys($key)
    {
        $this->expectException(InvalidArgumentException::class);
        $this->cache->hasItem($key);
    }

    #[DataProvider('invalidKeys')]
    public function testDeleteItemInvalidKeys($key)
    {
        $this->expectException(InvalidArgumentException::class);
        $this->cache->deleteItem($key);
    }

    #[DataProvider('invalidKeys')]
    public function testDeleteItemsInvalidKeys($key)
    {
        $this->expectException(InvalidArgumentException::class);
        $this->cache->deleteItems(['key1', $key, 'key2']);
    }

    public function testDataTypeString()
    {
        $item = $this->cache->getItem('key');
        $item->set('5');
        $this->cache->save($item);

        $item = $this->cache->getItem('key');
        $this->assertTrue('5' === $item->get(), 'Wrong data type. If we store a string we must get an string back.');
        $this->assertTrue(is_string($item->get()), 'Wrong data type. If we store a string we must get an string back.');
    }

    public function testDataTypeInteger()
    {
        $item = $this->cache->getItem('key');
        $item->set(5);
        $this->cache->save($item);

        $item = $this->cache->getItem('key');
        $this->assertTrue(5 === $item->get(), 'Wrong data type. If we store an int we must get an int back.');
        $this->assertTrue(is_int($item->get()), 'Wrong data type. If we store an int we must get an int back.');
    }

    public function testDataTypeNull()
    {
        $item = $this->cache->getItem('key');
        $item->set(null);
        $this->cache->save($item);

        $this->assertTrue($this->cache->hasItem('key'), 'Null is a perfectly fine cache value. hasItem() should return true when null are stored. ');
        $item = $this->cache->getItem('key');
        $this->assertTrue(null === $item->get(), 'Wrong data type. If we store null we must get an null back.');
        $this->assertTrue(is_null($item->get()), 'Wrong data type. If we store null we must get an null back.');
        $this->assertTrue($item->isHit(), 'isHit() should return true when null are stored. ');
    }

    public function testDataTypeFloat()
    {
        $float = 1.23456789;
        $item = $this->cache->getItem('key');
        $item->set($float);
        $this->cache->save($item);

        $item = $this->cache->getItem('key');
        $this->assertTrue(is_float($item->get()), 'Wrong data type. If we store float we must get an float back.');
        $this->assertEquals($float, $item->get());
        $this->assertTrue($item->isHit(), 'isHit() should return true when float are stored. ');
    }

    public function testDataTypeBoolean()
    {
        $item = $this->cache->getItem('key');
        $item->set(true);
        $this->cache->save($item);

        $item = $this->cache->getItem('key');
        $this->assertTrue(is_bool($item->get()), 'Wrong data type. If we store boolean we must get an boolean back.');
        $this->assertTrue($item->get());
        $this->assertTrue($item->isHit(), 'isHit() should return true when true are stored. ');
    }

    public function testDataTypeArray()
    {
        $array = ['a' => 'foo', 2 => 'bar'];
        $item = $this->cache->getItem('key');
        $item->set($array);
        $this->cache->save($item);

        $item = $this->cache->getItem('key');
        $this->assertTrue(is_array($item->get()), 'Wrong data type. If we store array we must get an array back.');
        $this->assertEquals($array, $item->get());
        $this->assertTrue($item->isHit(), 'isHit() should return true when array are stored. ');
    }

    public function testDataTypeObject()
    {
        $object = new \stdClass();
        $object->a = 'foo';
        $item = $this->cache->getItem('key');
        $item->set($object);
        $this->cache->save($item);

        $item = $this->cache->getItem('key');
        $this->assertTrue(is_object($item->get()), 'Wrong data type. If we store object we must get an object back.');
        $this->assertEquals($object, $item->get());
        $this->assertTrue($item->isHit(), 'isHit() should return true when object are stored. ');
    }

    public function testBinaryData()
    {
        $data = '';
        for ($i = 0; $i < 256; ++$i) {
            $data .= chr($i);
        }

        $item = $this->cache->getItem('key');
        $item->set($data);
        $this->cache->save($item);

        $item = $this->cache->getItem('key');
        $this->assertTrue($data === $item->get(), 'Binary data must survive a round trip.');
    }

    public function testIsHit()
    {
        $item = $this->cache->getItem('key');
        $item->set('value');
        $this->cache->save($item);

        $item = $this->cache->getItem('key');
        $this->assertTrue($item->isHit());
    }

    public function testIsHitDeferred()
    {
        $item = $this->cache->getItem('key');
        $item->set('value');
        $this->cache->saveDeferred($item);

        // Test accessing the value before it is committed
        $item = $this->cache->getItem('key');
        $this->assertTrue($item->isHit());

        $this->cache->commit();
        $item = $this->cache->getItem('key');
        $this->assertTrue($item->isHit());
    }

    public function testSaveDeferredWhenChangingValues()
    {
        $item = $this->cache->getItem('key');
        $item->set('value');
        $this->cache->saveDeferred($item);

        $item = $this->cache->getItem('key');
        $item->set('new value');

        $item = $this->cache->getItem('key');
        $this->assertEquals('value', $item->get(), 'Items that is put in the deferred queue should not get their values changed');

        $this->cache->commit();
        $item = $this->cache->getItem('key');
        $this->assertEquals('value', $item->get(), 'Items that is put in the deferred queue should not get their values changed');
    }

    public function testSaveDeferredOverwrite()
    {
        $item = $this->cache->getItem('key');
        $item->set('value');
        $this->cache->saveDeferred($item);

        $item = $this->cache->getItem('key');
        $item->set('new value');
        $this->cache->saveDeferred($item);

        $item = $this->cache->getItem('key');
        $this->assertEquals('new value', $item->get());

        $this->cache->commit();
        $item = $this->cache->getItem('key');
        $this->assertEquals('new value', $item->get());
    }

    public function testSavingObject()
    {
        $item = $this->cache->getItem('key');
        $item->set(new \DateTime());
        $this->cache->save($item);

        $item = $this->cache->getItem('key');
        $value = $item->get();
        $this->assertInstanceOf('DateTime', $value, 'You must be able to store objects in cache.');
    }

    public function testHasItemReturnsFalseWhenDeferredItemIsExpired()
    {
        $item = $this->cache->getItem('key');
        $item->set('value');
        $item->expiresAfter(2);
        $this->cache->saveDeferred($item);

        sleep(3);
        $this->assertFalse($this->cache->hasItem('key'));
    }

    private function prepareDeferredSaveWithoutCommit()
    {
        $cache = $this->cache;
        $this->cache = null;

        $item = $cache->getItem('key');
        $item->set('4711');
        $cache->saveDeferred($item);
    }
}
