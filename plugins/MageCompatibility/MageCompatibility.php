<?php
namespace MageCompatibility;

use Netresearch\Config;
use Netresearch\Logger;
use Netresearch\PluginInterface as JudgePlugin;

class MageCompatibility implements JudgePlugin
{
    protected $config   = null;
    protected $name     = null;

    public function __construct(Config $config)
    {
        $this->config = $config;
        $this->name   = current(explode('\\', __CLASS__));
    }

    public function execute($extensionPath)
    {
        $this->settings = $this->config->plugins->{$this->name};
        $this->extensionPath = $extensionPath;

        $supportedVersions = array();

        $extension = new Extension($this->extensionPath);
        $methods = $extension->getUsedMagentoMethods();
        $classes = $extension->getUsedMagentoClasses();

        Logger::addComment(
            $extensionPath,
            $this->name,
            sprintf(
                'Extension uses %d classes and %d methods of Magento core',
                $classes->count(),
                $methods->count()
            )
        );
        Logger::setScore($extensionPath, current(explode('\\', __CLASS__)), $this->settings->bad);
        return $this->settings->bad;
    }

    protected function getTagFileNames()
    {
        return glob(__DIR__ . '/var/tags/*');
    }

    protected function getEdition($tagFileName)
    {
        list($edition, $version) = explode('-', baseName($tagFileName));
        return ucfirst(substr($edition, 0, 1)) . 'E';
    }

    protected function getVersion($tagFileName)
    {
        $basename = strstr(basename($tagFileName), '.tags', $beforeNeedle=true);
        list($edition, $version) = explode('-', $basename);
        return $version;
    }

    protected function getReadableVersionString($edition, $version)
    {
        return $edition . ' ' . $version;
    }
}
