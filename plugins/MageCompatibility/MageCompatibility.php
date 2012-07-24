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
        $classes = $extension->getUsedMagentoClasses();

        foreach ($this->getTagFileNames() as $tagFileName) {
            $edition = $this->getEdition($tagFileName);
            $version = $this->getVersion($tagFileName);
            Logger::log('Evaluating compatibility to ' . $this->getReadableVersionString($edition, $version));

            $classes->compareToMagentoTags($tagFileName);

            $incompatibilities = $this->getIncompatibilities($tagFileName);

            if (0 < $incompatibilities->count()) {
                Logger::addComment(
                    $extensionPath,
                    $this->name,
                    sprintf(
                        'There are %d incompatibilities to %s.',
                        $incompatibilities->count(),
                        $this->getReadableVersionString($edition, $version)
                    )
                );
            } else {
                $supportedVersions[$this->getReadableVersionString($edition, $version)] = $incompatibilities;
            }
        }
        Logger::success('Supported Magento versions: ' . implode(', ', array_keys($supportedVersions)));
        exit;
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

    protected function getIncompatibilities($tagFileName)
    {
        $incompatibilities = new Incompatibilities();

        $usedClasses = $extension->getUsedMagentoClasses();
        $usedClasses->removeByTagFile($tagFileName);
        $incompatibilities->setClasses($usedClasses);
        /*

        $usedConstants = $this->getUsedMagentoConstants();
        $usedConstants->removeByTagFile($tagFileName);
        $incompatibilities->setConstants($usedConstants);

        $usedMethods = $this->getUsedMagentoMethods();
        $usedMethods->removeByTagFile($tagFileName);
        $incompatibilities->setMethods($usedMethods);
         */

        return $incompatibilities;
    }
}
