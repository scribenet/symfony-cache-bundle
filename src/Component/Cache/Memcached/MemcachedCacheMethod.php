<?php

/*
 * This file is part of the Scribe Cache Bundle.
 *
 * (c) Scribe Inc. <source@scribe.software>
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace Scribe\CacheBundle\Component\Cache\Memcached;

use Memcached;
use Scribe\CacheBundle\Component\Cache\AbstractCacheMethod;
use Scribe\Wonka\Exception\InvalidArgumentException;
use Scribe\Wonka\Utility\Extension;
use Scribe\Wonka\Utility\Filter\StringFilter;

/**
 * Class MemcachedCacheMethod.
 */
class MemcachedCacheMethod extends AbstractCacheMethod implements MemcachedCacheMethodInterface
{
    /**
     * Options defined by DI; normalized and loaded upon lazy initialization, {@see setUp()}.
     *
     * @var string[]
     */
    protected $optionCollection = [];

    /**
     * Servers defined by DI; normalized and loaded upon lazy initialization, {@see setUp()}.
     *
     * @var array[]
     */
    protected $serverCollection = [];

    /**
     * Memcached api object instance.
     *
     * @var Memcached
     */
    protected $m;

    /**
     * Set server options.
     *
     * @param string[] $options
     *
     * @return $this
     */
    public function setOptions(array $options = [])
    {
        $this->optionCollection = (array) $options;

        return $this;
    }

    /**
     * Get server options.
     *
     * @return string[]
     */
    public function getOptions()
    {
        return $this->optionCollection;
    }

    /**
     * @param array[] $servers
     *
     * @return $this
     */
    public function setServers(array $servers = [])
    {
        $this->serverCollection = (array) $servers;

        return $this;
    }

    /**
     * @return array[]
     */
    public function getServers()
    {
        return $this->serverCollection;
    }

    /**
     * {@inheritdoc}
     */
    public function isSupported(...$by)
    {
        return (bool) (Extension::isEnabled('memcached'));
    }

    /**
     * {@inheritdoc}
     */
    protected function setUp()
    {
        $this
            ->setUpInstance()
            ->setUpOptions()
            ->setUpServers();
    }

    /**
     * Setup memcached api object instance.
     *
     * @return $this
     */
    protected function setUpInstance()
    {
        $this->m = new Memcached();

        return $this;
    }

    /**
     * Normalize passed option list and apply.
     *
     * @return $this
     */
    protected function setUpOptions()
    {
        $normalized = [];

        foreach ($this->optionCollection as $type => $state) {
            $this->normalizeOption($normalized, $type, $state);
        }

        $this->m->setOptions($normalized);

        return $this;
    }

    /**
     * Normalize human-readable options to correct Memcache constants for type and state.
     *
     * @param string[] $optionCollection
     * @param string   $type
     * @param mixed    $state
     */
    protected function normalizeOption(array &$optionCollection, $type, $state)
    {
        $optionCollection[ $this->normalizeOptionType($type) ] = $this->normalizeOptionState($state);
    }

    /**
     * Normalize option type.
     *
     * @param string $type
     *
     * @throws InvalidArgumentException
     *
     * @return mixed
     */
    protected function normalizeOptionType($type)
    {
        return $this->resolveConstant($type, 'OPT_');
    }

    /**
     * Normalize option type state.
     *
     * @param mixed $state
     *
     * @return mixed
     */
    protected function normalizeOptionState($state)
    {
        if (is_bool($state)) {
            return $state;
        }

        return $this->resolveConstant($state);
    }

    /**
     * Resolve value of Memcached server constant.
     *
     * @param string $name
     * @param string $prefix
     *
     * @throws InvalidArgumentException If constant cannot be resolved.
     *
     * @return mixed
     */
    protected function resolveConstant($name, $prefix = '')
    {
        $constant = 'Memcached::'.strtoupper($prefix.$name);

        if (!defined($constant)) {
            throw new InvalidArgumentException('Provided name "%s" unresolvable to Memcached constant "%s".', null, null, $name, $constant);
        }

        return constant($constant);
    }

    /**
     * Normalize passed server list and apply.
     *
     * @return $this
     */
    protected function setUpServers()
    {
        $normalized = [];

        foreach ($this->serverCollection as $name => $options) {
            $this->normalizeServer($normalized, $name, $options);
        }

        $this->m->addServers($normalized);

        return $this;
    }

    /**
     * Perform server normalization.
     *
     * @param array[] $serverCollection
     * @param string  $name
     * @param mixed[] $options
     */
    protected function normalizeServer(array &$serverCollection, $name, array $options)
    {
        $serverCollection[ $this->normalizeServerName($name) ] = $this->normalizeServerOptions($name, $options);
    }

    /**
     * Normalize server name.
     *
     * @param string $name
     *
     * @return string
     */
    protected function normalizeServerName($name)
    {
        return (string) StringFilter::alphanumericAndDashesOnly($name);
    }

    /**
     * Normalize server options.
     *
     * @param string  $name
     * @param mixed[] $options
     *
     * @return array
     */
    protected function normalizeServerOptions($name, array $options)
    {
        $options['port'] = (int) (array_key_exists('port',   $options) ? $options['port']   : self::DEFAULT_PORT);
        $options['weight'] = (int) (array_key_exists('weight', $options) ? $options['weight'] : self::DEFAULT_WEIGHT);

        if (array_keys($options) !== ['host', 'port', 'weight']) {
            throw new InvalidArgumentException('Memcached server "%s" must declare ordered options "host, port, weight" (has "%s").', null, null, $name, implode(', ', array_keys($options)));
        }

        return array_values($options);
    }

    /**
     * Checks for successful operation using server return code.
     *
     * @param int $expected
     *
     * @return bool
     */
    protected function isSuccessful($expected = Memcached::RES_SUCCESS)
    {
        return (bool) ($this->m->getResultCode() === $expected);
    }

    /**
     * Get cache entry.
     *
     * @param string $key
     *
     * @return null|string
     */
    protected function getCacheEntry($key)
    {
        $data = $this->m->get($key);

        if (!$this->isSuccessful()) {
            return;
        }

        return $data;
    }

    /**
     * Set cache entry.
     *
     * @param string $key
     * @param mixed  $data
     *
     * @return bool
     */
    protected function setCacheEntry($key, $data)
    {
        $this->m->set($key, $data, $this->ttl);

        return (bool) $this->isSuccessful();
    }

    /**
     * Check for cache entry.
     *
     * @param string $key
     *
     * @return bool
     */
    protected function hasCacheEntry($key)
    {
        return (bool) (null !== $this->getCacheEntry($key));
    }

    /**
     * Delete cache entry.
     *
     * @param string $key
     *
     * @return bool
     */
    protected function delCacheEntry($key)
    {
        $this->m->delete($key);

        return (bool) $this->isSuccessful();
    }

    /**
     * Flush all cache entries.
     *
     * @return bool
     */
    protected function flushCacheEntries()
    {
        $this->m->flush();

        return (bool) $this->isSuccessful();
    }
}

/* EOF */
