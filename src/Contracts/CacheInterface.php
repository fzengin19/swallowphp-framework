<?php

namespace SwallowPHP\Framework\Contracts;

use Psr\SimpleCache\CacheInterface as Psr16CacheInterface;

/**
 * Defines the interface for cache drivers within the framework,
 * extending the PSR-16 Simple Cache interface.
 */
interface CacheInterface extends Psr16CacheInterface
{
   
}