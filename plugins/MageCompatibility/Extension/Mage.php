<?php
namespace MageCompatibility\Extension;

use Netresearch\Logger;

/**
 * fake Mage
 */
class Mage
{
    public static $logger;

    public static function __callStatic($method, $args)
    {
        Logger::warning("Called static Magento method \"$method\" in installer script");
        return new Mage;
    }

    public function __call($method, $args)
    {
        Logger::warning("Called Magento method \"$method\" in installer script");
        return $this;
    }
}
