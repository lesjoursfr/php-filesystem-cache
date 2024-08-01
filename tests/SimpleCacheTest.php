<?php

namespace FileSystemCache\Tests;

use FileSystemCache\Tests\Trait\CreatePoolTrait;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;
use Psr\SimpleCache\InvalidArgumentException;

class SimpleCacheTest extends TestCase
{
    use CreatePoolTrait;

    protected ?CacheInterface $cache;

    /**
     * Advance time perceived by the cache for the purposes of testing TTL.
     *
     * The default implementation sleeps for the specified duration,
     * but subclasses are encouraged to override this,
     * adjusting a mocked time possibly set up in {@link createSimpleCache()},
     * to speed up the tests.
     *
     * @param int $seconds
     */
    public function advanceTime($seconds)
    {
        sleep($seconds);
    }

    #[Before]
    public function setupService()
    {
        $this->cache = $this->createSimpleCache();
    }

    #[After]
    public function tearDownService()
    {
        if (null !== $this->cache) {
            $this->cache->clear();
        }
    }

    /**
     * Data provider for invalid cache & array keys.
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

    /**
     * Data provider for valid keys.
     *
     * @return array
     */
    public static function validKeys()
    {
        return [
            ['AbC19_.'],
            ['1234567890123456789012345678901234567890123456789012345678901234'],
        ];
    }

    /**
     * Data provider for valid data to store.
     *
     * @return array
     */
    public static function validData()
    {
        return [
            ['AbC19_.'],
            [4711],
            [47.11],
            [true],
            [null],
            [['key' => 'value']],
            [new \stdClass()],
        ];
    }

    public function testSet()
    {
        $result = $this->cache->set('key', 'value');
        $this->assertTrue($result, 'set() must return true if success');
        $this->assertEquals('value', $this->cache->get('key'));
    }

    public function testSetTtl()
    {
        $result = $this->cache->set('key1', 'value', 2);
        $this->assertTrue($result, 'set() must return true if success');
        $this->assertEquals('value', $this->cache->get('key1'));

        $this->cache->set('key2', 'value', new \DateInterval('PT2S'));
        $this->assertEquals('value', $this->cache->get('key2'));

        $this->advanceTime(3);

        $this->assertNull($this->cache->get('key1'), 'Value must expire after ttl.');
        $this->assertNull($this->cache->get('key2'), 'Value must expire after ttl.');
    }

    public function testSetExpiredTtl()
    {
        $this->cache->set('key0', 'value');
        $this->cache->set('key0', 'value', 0);
        $this->assertNull($this->cache->get('key0'));
        $this->assertFalse($this->cache->has('key0'));

        $this->cache->set('key1', 'value', -1);
        $this->assertNull($this->cache->get('key1'));
        $this->assertFalse($this->cache->has('key1'));
    }

    public function testGet()
    {
        $this->assertNull($this->cache->get('key'));
        $this->assertEquals('foo', $this->cache->get('key', 'foo'));

        $this->cache->set('key', 'value');
        $this->assertEquals('value', $this->cache->get('key', 'foo'));
    }

    public function testDelete()
    {
        $this->assertTrue($this->cache->delete('key'), 'Deleting a value that does not exist should return true');
        $this->cache->set('key', 'value');
        $this->assertTrue($this->cache->delete('key'), 'Delete must return true on success');
        $this->assertNull($this->cache->get('key'), 'Values must be deleted on delete()');
    }

    public function testClear()
    {
        $this->assertTrue($this->cache->clear(), 'Clearing an empty cache should return true');
        $this->cache->set('key', 'value');
        $this->assertTrue($this->cache->clear(), 'Delete must return true on success');
        $this->assertNull($this->cache->get('key'), 'Values must be deleted on clear()');
    }

    public function testSetMultiple()
    {
        $result = $this->cache->setMultiple(['key0' => 'value0', 'key1' => 'value1']);
        $this->assertTrue($result, 'setMultiple() must return true if success');
        $this->assertEquals('value0', $this->cache->get('key0'));
        $this->assertEquals('value1', $this->cache->get('key1'));
    }

    public function testSetMultipleWithIntegerArrayKey()
    {
        $result = $this->cache->setMultiple(['0' => 'value0']);
        $this->assertTrue($result, 'setMultiple() must return true if success');
        $this->assertEquals('value0', $this->cache->get('0'));
    }

    public function testSetMultipleTtl()
    {
        $this->cache->setMultiple(['key2' => 'value2', 'key3' => 'value3'], 2);
        $this->assertEquals('value2', $this->cache->get('key2'));
        $this->assertEquals('value3', $this->cache->get('key3'));

        $this->cache->setMultiple(['key4' => 'value4'], new \DateInterval('PT2S'));
        $this->assertEquals('value4', $this->cache->get('key4'));

        $this->advanceTime(3);
        $this->assertNull($this->cache->get('key2'), 'Value must expire after ttl.');
        $this->assertNull($this->cache->get('key3'), 'Value must expire after ttl.');
        $this->assertNull($this->cache->get('key4'), 'Value must expire after ttl.');
    }

    public function testSetMultipleExpiredTtl()
    {
        $this->cache->setMultiple(['key0' => 'value0', 'key1' => 'value1'], 0);
        $this->assertNull($this->cache->get('key0'));
        $this->assertNull($this->cache->get('key1'));
    }

    public function testSetMultipleWithGenerator()
    {
        $gen = function () {
            yield 'key0' => 'value0';
            yield 'key1' => 'value1';
        };

        $this->cache->setMultiple($gen());
        $this->assertEquals('value0', $this->cache->get('key0'));
        $this->assertEquals('value1', $this->cache->get('key1'));
    }

    public function testGetMultiple()
    {
        $result = $this->cache->getMultiple(['key0', 'key1']);
        $keys = [];
        foreach ($result as $i => $r) {
            $keys[] = $i;
            $this->assertNull($r);
        }
        sort($keys);
        $this->assertSame(['key0', 'key1'], $keys);

        $this->cache->set('key3', 'value');
        $result = $this->cache->getMultiple(['key2', 'key3', 'key4'], 'foo');
        $keys = [];
        foreach ($result as $key => $r) {
            $keys[] = $key;
            if ('key3' === $key) {
                $this->assertEquals('value', $r);
            } else {
                $this->assertEquals('foo', $r);
            }
        }
        sort($keys);
        $this->assertSame(['key2', 'key3', 'key4'], $keys);
    }

    public function testGetMultipleWithGenerator()
    {
        $gen = function () {
            yield 1 => 'key0';
            yield 1 => 'key1';
        };

        $this->cache->set('key0', 'value0');
        $result = $this->cache->getMultiple($gen());
        $keys = [];
        foreach ($result as $key => $r) {
            $keys[] = $key;
            if ('key0' === $key) {
                $this->assertEquals('value0', $r);
            } elseif ('key1' === $key) {
                $this->assertNull($r);
            } else {
                $this->assertFalse(true, 'This should not happend');
            }
        }
        sort($keys);
        $this->assertSame(['key0', 'key1'], $keys);
        $this->assertEquals('value0', $this->cache->get('key0'));
        $this->assertNull($this->cache->get('key1'));
    }

    public function testDeleteMultiple()
    {
        $this->assertTrue($this->cache->deleteMultiple([]), 'Deleting a empty array should return true');
        $this->assertTrue($this->cache->deleteMultiple(['key']), 'Deleting a value that does not exist should return true');

        $this->cache->set('key0', 'value0');
        $this->cache->set('key1', 'value1');
        $this->assertTrue($this->cache->deleteMultiple(['key0', 'key1']), 'Delete must return true on success');
        $this->assertNull($this->cache->get('key0'), 'Values must be deleted on deleteMultiple()');
        $this->assertNull($this->cache->get('key1'), 'Values must be deleted on deleteMultiple()');
    }

    public function testDeleteMultipleGenerator()
    {
        $gen = function () {
            yield 1 => 'key0';
            yield 1 => 'key1';
        };
        $this->cache->set('key0', 'value0');
        $this->assertTrue($this->cache->deleteMultiple($gen()), 'Deleting a generator should return true');

        $this->assertNull($this->cache->get('key0'), 'Values must be deleted on deleteMultiple()');
        $this->assertNull($this->cache->get('key1'), 'Values must be deleted on deleteMultiple()');
    }

    public function testHas()
    {
        $this->assertFalse($this->cache->has('key0'));
        $this->cache->set('key0', 'value0');
        $this->assertTrue($this->cache->has('key0'));
    }

    #[DataProvider('invalidKeys')]
    public function testGetInvalidKeys($key)
    {
        $this->expectException(InvalidArgumentException::class);
        $this->cache->get($key);
    }

    #[DataProvider('invalidKeys')]
    public function testGetMultipleInvalidKeys($key)
    {
        $this->expectException(InvalidArgumentException::class);
        $result = $this->cache->getMultiple(['key1', $key, 'key2']);
    }

    public function testGetMultipleNoIterable()
    {
        $this->expectException(InvalidArgumentException::class);
        $result = $this->cache->getMultiple('key');
    }

    #[DataProvider('invalidKeys')]
    public function testSetInvalidKeys($key)
    {
        $this->expectException(InvalidArgumentException::class);
        $this->cache->set($key, 'foobar');
    }

    #[DataProvider('invalidKeys')]
    public function testSetMultipleInvalidKeys($key)
    {
        $values = function () use ($key) {
            yield 'key1' => 'foo';
            yield $key => 'bar';
            yield 'key2' => 'baz';
        };
        $this->expectException(InvalidArgumentException::class);
        $this->cache->setMultiple($values());
    }

    #[DataProvider('invalidKeys')]
    public function testHasInvalidKeys($key)
    {
        $this->expectException(InvalidArgumentException::class);
        $this->cache->has($key);
    }

    #[DataProvider('invalidKeys')]
    public function testDeleteInvalidKeys($key)
    {
        $this->expectException(InvalidArgumentException::class);
        $this->cache->delete($key);
    }

    #[DataProvider('invalidKeys')]
    public function testDeleteMultipleInvalidKeys($key)
    {
        $this->expectException(InvalidArgumentException::class);
        $this->cache->deleteMultiple(['key1', $key, 'key2']);
    }

    public function testNullOverwrite()
    {
        $this->cache->set('key', 5);
        $this->cache->set('key', null);

        $this->assertNull($this->cache->get('key'), 'Setting null to a key must overwrite previous value');
    }

    public function testDataTypeString()
    {
        $this->cache->set('key', '5');
        $result = $this->cache->get('key');
        $this->assertTrue('5' === $result, 'Wrong data type. If we store a string we must get an string back.');
        $this->assertTrue(is_string($result), 'Wrong data type. If we store a string we must get an string back.');
    }

    public function testDataTypeInteger()
    {
        $this->cache->set('key', 5);
        $result = $this->cache->get('key');
        $this->assertTrue(5 === $result, 'Wrong data type. If we store an int we must get an int back.');
        $this->assertTrue(is_int($result), 'Wrong data type. If we store an int we must get an int back.');
    }

    public function testDataTypeFloat()
    {
        $float = 1.23456789;
        $this->cache->set('key', $float);
        $result = $this->cache->get('key');
        $this->assertTrue(is_float($result), 'Wrong data type. If we store float we must get an float back.');
        $this->assertEquals($float, $result);
    }

    public function testDataTypeBoolean()
    {
        $this->cache->set('key', false);
        $result = $this->cache->get('key');
        $this->assertTrue(is_bool($result), 'Wrong data type. If we store boolean we must get an boolean back.');
        $this->assertFalse($result);
        $this->assertTrue($this->cache->has('key'), 'has() should return true when true are stored. ');
    }

    public function testDataTypeArray()
    {
        $array = ['a' => 'foo', 2 => 'bar'];
        $this->cache->set('key', $array);
        $result = $this->cache->get('key');
        $this->assertTrue(is_array($result), 'Wrong data type. If we store array we must get an array back.');
        $this->assertEquals($array, $result);
    }

    public function testDataTypeObject()
    {
        $object = new \stdClass();
        $object->a = 'foo';
        $this->cache->set('key', $object);
        $result = $this->cache->get('key');
        $this->assertTrue(is_object($result), 'Wrong data type. If we store object we must get an object back.');
        $this->assertEquals($object, $result);
    }

    public function testBinaryData()
    {
        $data = '';
        for ($i = 0; $i < 256; ++$i) {
            $data .= chr($i);
        }

        $array = ['a' => 'foo', 2 => 'bar'];
        $this->cache->set('key', $data);
        $result = $this->cache->get('key');
        $this->assertTrue($data === $result, 'Binary data must survive a round trip.');
    }

    #[DataProvider('validKeys')]
    public function testSetValidKeys($key)
    {
        $this->cache->set($key, 'foobar');
        $this->assertEquals('foobar', $this->cache->get($key));
    }

    #[DataProvider('validKeys')]
    public function testSetMultipleValidKeys($key)
    {
        $this->cache->setMultiple([$key => 'foobar']);
        $result = $this->cache->getMultiple([$key]);
        $keys = [];
        foreach ($result as $i => $r) {
            $keys[] = $i;
            $this->assertEquals($key, $i);
            $this->assertEquals('foobar', $r);
        }
        $this->assertSame([$key], $keys);
    }

    #[DataProvider('validData')]
    public function testSetValidData($data)
    {
        $this->cache->set('key', $data);
        $this->assertEquals($data, $this->cache->get('key'));
    }

    #[DataProvider('validData')]
    public function testSetMultipleValidData($data)
    {
        $this->cache->setMultiple(['key' => $data]);
        $result = $this->cache->getMultiple(['key']);
        $keys = [];
        foreach ($result as $i => $r) {
            $keys[] = $i;
            $this->assertEquals($data, $r);
        }
        $this->assertSame(['key'], $keys);
    }

    public function testObjectAsDefaultValue()
    {
        $obj = new \stdClass();
        $obj->foo = 'value';
        $this->assertEquals($obj, $this->cache->get('key', $obj));
    }

    public function testObjectDoesNotChangeInCache()
    {
        $obj = new \stdClass();
        $obj->foo = 'value';
        $this->cache->set('key', $obj);
        $obj->foo = 'changed';

        $cacheObject = $this->cache->get('key');
        $this->assertEquals('value', $cacheObject->foo, 'Object in cache should not have their values changed.');
    }
}