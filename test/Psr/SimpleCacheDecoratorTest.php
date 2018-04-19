<?php
/**
 * @see       https://github.com/zendframework/zend-cache for the canonical source repository
 * @copyright Copyright (c) 2018 Zend Technologies USA Inc. (https://www.zend.com)
 * @license   https://github.com/zendframework/zend-cache/blob/master/LICENSE.md New BSD License
 */

namespace ZendTest\Cache\Psr;

use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\Prophecy\ObjectProphecy;
use Psr\SimpleCache\CacheInterface as SimpleCacheInterface;
use ReflectionProperty;
use Zend\Cache\Exception;
use Zend\Cache\Psr\SimpleCacheDecorator;
use Zend\Cache\Psr\SimpleCacheInvalidArgumentException;
use Zend\Cache\Psr\SimpleCacheException;
use Zend\Cache\Storage\Adapter\AdapterOptions;
use Zend\Cache\Storage\Capabilities;
use Zend\Cache\Storage\FlushableInterface;
use Zend\Cache\Storage\StorageInterface;

/**
 * Test the PSR-16 decorator.
 *
 * Note to maintainers: the try/catch blocks are done on purpose within this
 * class, instead of expectException*(). This is due to the fact that the
 * decorator is expected to re-throw any caught exceptions as PSR-16 exception
 * types. The class passes the original exception as the previous exception
 * when doing so, and the only way to test that this has happened is to use
 * try/catch blocks and assert identity against the result of getPrevious().
 */
class SimpleCacheDecoratorTest extends TestCase
{
    private $requiredTypes = [
        'string'  => true,
        'integer' => true,
        'double'  => true,
        'boolean' => true,
        'NULL'    => true,
        'array'   => true,
        'object'  => true,
    ];

    public function setUp()
    {
        $this->options = $this->prophesize(AdapterOptions::class);
        $this->storage = $this->prophesize(StorageInterface::class);
        $this->mockCapabilities($this->storage);
        $this->cache = new SimpleCacheDecorator($this->storage->reveal());
    }

    /**
     * @param bool $staticTtl
     * @param int $minTtl
     */
    public function mockCapabilities(
        ObjectProphecy $storage,
        array $supportedDataTypes = null,
        $staticTtl = true,
        $minTtl = 60
    ) {
        $supportedDataTypes = $supportedDataTypes ?: $this->requiredTypes;
        $capabilities = $this->prophesize(Capabilities::class);
        $capabilities->getSupportedDatatypes()->willReturn($supportedDataTypes);
        $capabilities->getStaticTtl()->willReturn($staticTtl);
        $capabilities->getMinTtl()->willReturn($minTtl);
        $storage->getCapabilities()->will([$capabilities, 'reveal']);
    }

    public function setSuccessReference(SimpleCacheDecorator $cache, $success)
    {
        $r = new ReflectionProperty($cache, 'success');
        $r->setAccessible(true);
        $r->setValue($cache, $success);
    }

    /**
     * Set of string key names that should be considered invalid for operations
     * that create cache entries.
     *
     * @return array
     */
    public function invalidKeyProvider()
    {
        return [
            'brace-start'   => ['key{', 'cannot contain'],
            'brace-end'     => ['key}', 'cannot contain'],
            'paren-start'   => ['key(', 'cannot contain'],
            'paren-end'     => ['key)', 'cannot contain'],
            'forward-slash' => ['ns/key', 'cannot contain'],
            'back-slash'    => ['ns\key', 'cannot contain'],
            'at'            => ['ns@key', 'cannot contain'],
            'too-long'      => [str_repeat('abcd', 17), 'too long'],
        ];
    }

    /**
     * TTL values less than 1 should result in immediate cache removal.
     *
     * @return array
     */
    public function invalidatingTtls()
    {
        return [
            'zero'         => [0],
            'negative-1'   => [-1],
            'negative-100' => [-100],
        ];
    }

    public function testItIsASimpleCacheImplementation()
    {
        $this->assertInstanceOf(SimpleCacheInterface::class, $this->cache);
    }

    public function testGetReturnsDefaultValueWhenUnderlyingStorageDoesNotContainItem()
    {
        $testCase = $this;
        $cache = $this->cache;
        $this->storage
            ->getItem('key', Argument::any())
            ->will(function () use ($testCase, $cache) {
                // Indicating lookup succeeded, but...
                $testCase->setSuccessReference($cache, true);
                // null === not found
                return null;
            });

        $this->assertSame('default', $this->cache->get('key', 'default'));
    }

    public function testGetReturnsDefaultValueWhenStorageIndicatesFailure()
    {
        $testCase = $this;
        $cache = $this->cache;
        $this->storage
            ->getItem('key', Argument::any())
            ->will(function () use ($testCase, $cache) {
                // Indicating failure to lookup
                $testCase->setSuccessReference($cache, false);
                return false;
            });

        $this->assertSame('default', $this->cache->get('key', 'default'));
    }

    public function testGetReturnsValueReturnedByStorage()
    {
        $testCase = $this;
        $cache = $this->cache;
        $expected = 'returned value';

        $this->storage
            ->getItem('key', Argument::any())
            ->will(function () use ($testCase, $cache, $expected) {
                // Indicating lookup success
                $testCase->setSuccessReference($cache, true);
                return $expected;
            });

        $this->assertSame($expected, $this->cache->get('key', 'default'));
    }

    public function testGetUnserializesValueBeforeReturningItIfStorageAdapterRequiresSerialization()
    {
        $value = ['tags' => true];
        $serializedValue = serialize($value);

        $storage = $this->prophesize(StorageInterface::class);
        $this->mockCapabilities($storage, [
            'string' => false,
        ]);

        $cache = new SimpleCacheDecorator($storage->reveal());

        $testCase = $this;
        $storage
            ->getItem('key', Argument::any())
            ->will(function () use ($testCase, $cache, $serializedValue) {
                // Indicating lookup success
                $testCase->setSuccessReference($cache, true);
                return $serializedValue;
            });

        $this->assertSame($value, $cache->get('key', 'default'));
    }

    public function testGetShouldReRaiseExceptionThrownByStorage()
    {
        $exception = new Exception\ExtensionNotLoadedException('failure', 500);
        $this->storage
            ->getItem('key', Argument::any())
            ->willThrow($exception);

        try {
            $this->cache->get('key', 'default');
            $this->fail('Exception should have been raised');
        } catch (SimpleCacheException $e) {
            $this->assertSame($exception->getMessage(), $e->getMessage());
            $this->assertSame($exception->getCode(), $e->getCode());
            $this->assertSame($exception, $e->getPrevious());
        }
    }

    public function testSetProxiesToStorageAndModifiesAndResetsOptions()
    {
        $originalTtl = 600;
        $ttl = 86400;

        $this->options
            ->getTtl()
            ->will(function () use ($ttl, $originalTtl) {
                $this
                    ->setTtl($ttl)
                    ->will(function () use ($originalTtl) {
                        $this->setTtl($originalTtl)->shouldBeCalled();
                    });
                return $originalTtl;
            });

        $this->storage->getOptions()->will([$this->options, 'reveal']);
        $this->storage->setItem('key', 'value')->willReturn(true);

        $this->assertTrue($this->cache->set('key', 'value', $ttl));
    }

    public function testSetSerializesValuesPriorToPassingThemToStorageIfAdapterRequiresSerialization()
    {
        $originalTtl = 600;
        $ttl = 86400;
        $value = 'value';
        $serializedValue = serialize($value);

        $options = $this->prophesize(AdapterOptions::class);
        $options
            ->getTtl()
            ->will(function () use ($ttl, $originalTtl) {
                $this
                    ->setTtl($ttl)
                    ->will(function () use ($originalTtl) {
                        $this->setTtl($originalTtl)->shouldBeCalled();
                    });
                return $originalTtl;
            });

        $storage = $this->prophesize(StorageInterface::class);
        $this->mockCapabilities($storage, [
            'string' => false,
        ]);
        $storage->getOptions()->will([$options, 'reveal']);
        $storage->setItem('key', $serializedValue)->willReturn(true);

        $cache = new SimpleCacheDecorator($storage->reveal());

        $this->assertTrue($cache->set('key', $value, $ttl));
    }

    /**
     * @dataProvider invalidatingTtls
     * @param int $ttl
     */
    public function testSetShouldRemoveItemFromCacheIfTtlIsBelow1($ttl)
    {
        $this->storage->getOptions()->shouldNotBeCalled();
        $this->storage->setItem('key', 'value')->shouldNotBeCalled();
        $this->storage->removeItem('key')->willReturn(true);

        $this->assertTrue($this->cache->set('key', 'value', $ttl));
    }

    public function testSetShouldReturnFalseWhenProvidedWithPositiveTtlAndStorageDoesNotSupportPerItemTtl()
    {
        $storage = $this->prophesize(StorageInterface::class);
        $this->mockCapabilities($storage, null, false);
        $storage->getOptions()->shouldNotBeCalled();
        $storage->setItem('key', 'value')->shouldNotBeCalled();

        $cache = new SimpleCacheDecorator($storage->reveal());

        $this->assertFalse($cache->set('key', 'value', 3600));
    }

    /**
     * @dataProvider invalidatingTtls
     * @param int $ttl
     */
    public function testSetShouldRemoveItemFromCacheIfTtlIsBelow1AndStorageDoesNotSupportPerItemTtl($ttl)
    {
        $storage = $this->prophesize(StorageInterface::class);
        $this->mockCapabilities($storage, null, false);
        $storage->getOptions()->shouldNotBeCalled();
        $storage->setItem('key', 'value')->shouldNotBeCalled();
        $storage->removeItem('key')->willReturn(true);

        $cache = new SimpleCacheDecorator($storage->reveal());

        $this->assertTrue($cache->set('key', 'value', $ttl));
    }

    /**
     * @dataProvider invalidKeyProvider
     * @param string $key
     * @param string $expectedMessage
     */
    public function testSetShouldRaisePsrInvalidArgumentExceptionForInvalidKeys($key, $expectedMessage)
    {
        $this->storage->getOptions()->shouldNotBeCalled();
        $this->expectException(SimpleCacheInvalidArgumentException::class);
        $this->expectExceptionMessage($expectedMessage);
        $this->cache->set($key, 'value');
    }

    public function testSetShouldReRaiseExceptionThrownByStorage()
    {
        $originalTtl = 600;
        $ttl = 86400;

        $this->options
            ->getTtl()
            ->will(function () use ($ttl, $originalTtl) {
                $this
                    ->setTtl($ttl)
                    ->will(function () use ($originalTtl) {
                        $this->setTtl($originalTtl)->shouldNotBeCalled();
                    });
                return $originalTtl;
            });

        $this->storage->getOptions()->will([$this->options, 'reveal']);

        $exception = new Exception\ExtensionNotLoadedException('failure', 500);
        $this->storage->setItem('key', 'value')->willThrow($exception);

        try {
            $this->cache->set('key', 'value', $ttl);
            $this->fail('Exception should have been raised');
        } catch (SimpleCacheException $e) {
            $this->assertSame($exception->getMessage(), $e->getMessage());
            $this->assertSame($exception->getCode(), $e->getCode());
            $this->assertSame($exception, $e->getPrevious());
        }
    }

    public function testDeleteShouldProxyToStorage()
    {
        $this->storage->removeItem('key')->willReturn(true);
        $this->assertTrue($this->cache->delete('key'));
    }

    public function testDeleteShouldReRaiseExceptionThrownByStorage()
    {
        $exception = new Exception\ExtensionNotLoadedException('failure', 500);
        $this->storage->removeItem('key')->willThrow($exception);

        try {
            $this->cache->delete('key');
            $this->fail('Exception should have been raised');
        } catch (SimpleCacheException $e) {
            $this->assertSame($exception->getMessage(), $e->getMessage());
            $this->assertSame($exception->getCode(), $e->getCode());
            $this->assertSame($exception, $e->getPrevious());
        }
    }

    public function testClearReturnsFalseIfStorageIsNotFlushable()
    {
        $this->assertFalse($this->cache->clear());
    }

    public function testClearProxiesToStorageIfStorageIsFlushable()
    {
        $storage = $this->prophesize(StorageInterface::class);
        $storage->willImplement(FlushableInterface::class);
        $this->mockCapabilities($storage);
        $storage->flush()->willReturn(true);

        $cache = new SimpleCacheDecorator($storage->reveal());
        $this->assertTrue($cache->clear());
    }

    public function testGetMultipleProxiesToStorageAndProvidesDefaultsForUnfoundKeysWhenNonNullDefaultPresent()
    {
        $keys = ['one', 'two', 'three'];
        $expected = [
            'one' => 1,
            'two' => 'default',
            'three' => 3,
        ];

        $this->storage
            ->getItems($keys)
            ->willReturn([
                'one' => 1,
                'three' => 3,
            ]);

        $this->assertEquals($expected, $this->cache->getMultiple($keys, 'default'));
    }

    public function getMultipleProxiesToStorageAndUnserializesValuesBeforeReturningValues()
    {
        $values = [
            'one' => 1,
            'two' => 'string',
            'three' => ['tags' => true],
        ];
        $serializedValues = [
            'one' => serialize(1),
            'two' => serialize('string'),
            'three' => serialize(['tags' => true]),
        ];

        $storage = $this->prophesize(StorageInterface::class);
        $this->mockCapabilities($storage, [
            'string' => false,
        ]);

        $cache = new SimpleCacheDecorator($storage->reveal());

        $storage
            ->getItems(array_keys($values))
            ->willReturn($serializedValues);

        $this->assertEquals(
            $values,
            $cache->getMultiple(array_keys($values), 'default')
        );
    }

    public function testGetMultipleProxiesToStorageAndOmitsValuesForUnfoundKeysWhenNullDefaultPresent()
    {
        $keys = ['one', 'two', 'three'];
        $expected = [
            'one' => 1,
            'three' => 3,
        ];

        $this->storage
            ->getItems($keys)
            ->willReturn([
                'one' => 1,
                'three' => 3,
            ]);

        $this->assertEquals($expected, $this->cache->getMultiple($keys));
    }

    public function testGetMultipleReRaisesExceptionFromStorage()
    {
        $keys = ['one', 'two', 'three'];
        $exception = new Exception\ExtensionNotLoadedException('failure', 500);

        $this->storage
            ->getItems($keys)
            ->willThrow($exception);

        try {
            $this->cache->getMultiple($keys);
            $this->fail('Exception should have been raised');
        } catch (SimpleCacheException $e) {
            $this->assertSame($exception->getMessage(), $e->getMessage());
            $this->assertSame($exception->getCode(), $e->getCode());
            $this->assertSame($exception, $e->getPrevious());
        }
    }

    public function testSetMultipleProxiesToStorageAndModifiesAndResetsOptions()
    {
        $originalTtl = 600;
        $ttl = 86400;

        $this->options
            ->getTtl()
            ->will(function () use ($ttl, $originalTtl) {
                $this
                    ->setTtl($ttl)
                    ->will(function () use ($originalTtl) {
                        $this->setTtl($originalTtl)->shouldBeCalled();
                    });
                return $originalTtl;
            });

        $this->storage->getOptions()->will([$this->options, 'reveal']);

        $values = ['one' => 1, 'three' => 3];

        $this->storage->setItems($values)->willReturn(true);

        $this->assertTrue($this->cache->setMultiple($values, $ttl));
    }

    public function testSetMultipleSerializesValuesPriorToProxyingToStorageIfAdapterRequiresSerialization()
    {
        $values = [
            'one' => 1,
            'two' => 'true',
            'three' => ['tags' => true],
        ];
        $serializedValues = [
            'one' => serialize(1),
            'two' => serialize('true'),
            'three' => serialize(['tags' => true]),
        ];
        $originalTtl = 600;
        $ttl = 86400;

        $options = $this->prophesize(AdapterOptions::class);
        $options
            ->getTtl()
            ->will(function () use ($ttl, $originalTtl) {
                $this
                    ->setTtl($ttl)
                    ->will(function () use ($originalTtl) {
                        $this->setTtl($originalTtl)->shouldBeCalled();
                    });
                return $originalTtl;
            });


        $storage = $this->prophesize(StorageInterface::class);
        $this->mockCapabilities($storage, [
            'string' => false,
        ]);
        $storage->getOptions()->will([$options, 'reveal']);
        $storage->setItems($serializedValues)->willReturn(true);

        $cache = new SimpleCacheDecorator($storage->reveal());

        $this->assertTrue($cache->setMultiple($values, $ttl));
    }

    /**
     * @dataProvider invalidatingTtls
     * @param int $ttl
     */
    public function testSetMultipleShouldRemoveItemsFromCacheIfTtlIsBelow1($ttl)
    {
        $values = [
            'one' => 1,
            'two' => 'true',
            'three' => ['tags' => true],
        ];

        $this->storage->getOptions()->shouldNotBeCalled();
        $this->storage->setItems(Argument::any())->shouldNotBeCalled();
        $this->storage->removeItems(array_keys($values))->willReturn([]);

        $this->assertTrue($this->cache->setMultiple($values, $ttl));
    }

    public function testSetMultipleShouldReturnFalseWhenProvidedWithPositiveTtlAndStorageDoesNotSupportPerItemTtl()
    {
        $values = [
            'one' => 1,
            'two' => 'true',
            'three' => ['tags' => true],
        ];

        $storage = $this->prophesize(StorageInterface::class);
        $this->mockCapabilities($storage, null, false);
        $storage->getOptions()->shouldNotBeCalled();
        $storage->setItems(Argument::any())->shouldNotBeCalled();

        $cache = new SimpleCacheDecorator($storage->reveal());

        $this->assertFalse($cache->setMultiple($values, 60));
    }

    /**
     * @dataProvider invalidatingTtls
     * @param int $ttl
     */
    public function testSetMultipleShouldRemoveItemsFromCacheIfTtlIsBelow1AndStorageDoesNotSupportPerItemTtl($ttl)
    {
        $values = [
            'one' => 1,
            'two' => 'true',
            'three' => ['tags' => true],
        ];

        $storage = $this->prophesize(StorageInterface::class);
        $this->mockCapabilities($storage, null, false);
        $storage->getOptions()->shouldNotBeCalled();
        $storage->setItems(Argument::any())->shouldNotBeCalled();
        $storage->removeItems(array_keys($values))->willReturn([]);

        $cache = new SimpleCacheDecorator($storage->reveal());

        $this->assertTrue($cache->setMultiple($values, $ttl));
    }

    /**
     * @dataProvider invalidKeyProvider
     * @param string $key
     * @param string $expectedMessage
     */
    public function testSetMultipleShouldRaisePsrInvalidArgumentExceptionForInvalidKeys($key, $expectedMessage)
    {
        $this->storage->getOptions()->shouldNotBeCalled();
        $this->expectException(SimpleCacheInvalidArgumentException::class);
        $this->expectExceptionMessage($expectedMessage);
        $this->cache->setMultiple([$key => 'value']);
    }

    public function testSetMultipleReRaisesExceptionFromStorage()
    {
        $originalTtl = 600;
        $ttl = 86400;

        $this->options
            ->getTtl()
            ->will(function () use ($ttl, $originalTtl) {
                $this
                    ->setTtl($ttl)
                    ->will(function () use ($originalTtl) {
                        $this->setTtl($originalTtl)->shouldNotBeCalled();
                    });
                return $originalTtl;
            });

        $this->storage->getOptions()->will([$this->options, 'reveal']);

        $exception = new Exception\ExtensionNotLoadedException('failure', 500);
        $values = ['one' => 1, 'three' => 3];

        $this->storage->setItems($values)->willThrow($exception);

        try {
            $this->cache->setMultiple($values, $ttl);
            $this->fail('Exception should have been raised');
        } catch (SimpleCacheException $e) {
            $this->assertSame($exception->getMessage(), $e->getMessage());
            $this->assertSame($exception->getCode(), $e->getCode());
            $this->assertSame($exception, $e->getPrevious());
        }
    }

    public function testDeleteMultipleProxiesToStorageAndReturnsTrueWhenStorageReturnsEmptyArray()
    {
        $keys = ['one', 'two', 'three'];
        $this->storage->removeItems($keys)->willReturn([]);
        $this->assertTrue($this->cache->deleteMultiple($keys));
    }

    public function testDeleteMultipleProxiesToStorageAndReturnsFalseIfStorageReturnsNonEmptyArray()
    {
        $keys = ['one', 'two', 'three'];
        $this->storage->removeItems($keys)->willReturn(['two']);
        $this->assertFalse($this->cache->deleteMultiple($keys));
    }

    public function testDeleteMultipleReRaisesExceptionThrownByStorage()
    {
        $keys = [1, 2, 3];
        $exception = new Exception\InvalidArgumentException('bad key', 500);
        $this->storage->removeItems($keys)->willThrow($exception);

        try {
            $this->cache->deleteMultiple($keys);
            $this->fail('Exception should have been raised');
        } catch (SimpleCacheInvalidArgumentException $e) {
            $this->assertSame($exception->getMessage(), $e->getMessage());
            $this->assertSame($exception->getCode(), $e->getCode());
            $this->assertSame($exception, $e->getPrevious());
        }
    }

    public function hasResultProvider()
    {
        return [
            'true' => [true],
            'false' => [false],
        ];
    }

    /**
     * @dataProvider hasResultProvider
     */
    public function testHasProxiesToStorage($result)
    {
        $this->storage->hasItem('key')->willReturn($result);
        $this->assertSame($result, $this->cache->has('key'));
    }

    public function testHasReRaisesExceptionThrownByStorage()
    {
        $exception = new Exception\ExtensionNotLoadedException('failure', 500);
        $this->storage->hasItem('key')->willThrow($exception);

        try {
            $this->cache->has('key');
            $this->fail('Exception should have been raised');
        } catch (SimpleCacheException $e) {
            $this->assertSame($exception->getMessage(), $e->getMessage());
            $this->assertSame($exception->getCode(), $e->getCode());
            $this->assertSame($exception, $e->getPrevious());
        }
    }
}
