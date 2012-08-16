<?php
namespace MageCompatibility;

use Netresearch\Config;
use Netresearch\Logger;
use Netresearch\PluginInterface as JudgePlugin;

use \dibi as dibi;

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
        $this->connectTagDatabase();

        $availableVersions = dibi::query('SELECT concat( m.edition, " ", m.version ) as Magento FROM magento m ORDER BY Magento')->fetchPairs();
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

        $incompatibleVersions = array();
        foreach ($availableVersions as $version) {
            $incompatibleVersions[$version] = array(
                'classes'   => array(),
                'methods'   => array(),
                'constants' => array()
            );
        }
        foreach ($classes as $class) {
            $class->setConfig($this->settings);
            $supportedVersions = $class->getMagentoVersions();
            if (is_array($supportedVersions)) {
                $tagIncompatibleVersions = array_diff($availableVersions, $supportedVersions);
                foreach ($tagIncompatibleVersions as $version) {
                    $incompatibleVersions[$version]['classes'][] = $class->getName();
                }
            }
        }
        foreach ($methods as $method) {
            $isExtensionMethod = false;
            $context = current($method->getContext());
            $method->setConfig($this->settings);
            $supportedVersions = $method->getMagentoVersions();
            //echo $context['class'] . '->' . $method->getName() . ' ';
            if (false == is_array($supportedVersions)) {
                /* method is not known for any Magento version, so it is either a database getter or part of the extension itself */
                $isExtensionMethod = $method->isExtensionMethod($method->getName(), $extensionPath);
                if (false === $isExtensionMethod && substr($method->getName(), 0, 3) == 'get') {
                    echo "possible database getter found in Method ". $method->getName() . PHP_EOL;
                } elseif (true === $isExtensionMethod) {
                    echo "possible extension method found: " . $context . '->' . $method->getName() . PHP_EOL;
                }
                continue;
            }
            $tagIncompatibleVersions = array_diff($availableVersions, $supportedVersions);
            foreach ($tagIncompatibleVersions as $version) {
                $methodName = $method->getContext('class')
                    . '->' . $method->getName()
                    . '(' . implode(', ', $method->getParams()) . ')';
                if ($extension->hasMethod($method->getName())) {
                    $methodName .= ' [maybe part of the extension]';
                    continue;
                }
                $incompatibleVersions[$version]['methods'][] = $methodName;
            }
            //echo implode(', ', $supportedVersions);
            //echo PHP_EOL;
        }

        $compatibleVersions = array();

        foreach ($incompatibleVersions as $version=>$incompatibilities) {
            $message = '';
            $incompatibleClasses   = array_unique($incompatibilities['classes']);
            $incompatibleMethods   = array_unique($incompatibilities['methods']);
            $incompatibleConstants = array_unique($incompatibilities['constants']);
            if (0 < count($incompatibleClasses)) {
                $message .= sprintf(
                    "<comment>The following classes are not compatible to Magento %s:</comment>\n  * %s\n",
                    $version,
                    implode("\n  * ", $incompatibleClasses)
                );
            }
            if (0 < count($incompatibleMethods)) {
                $message .= sprintf(
                    "<comment>The following methods are not compatible to Magento %s:</comment>\n  * %s\n",
                    $version,
                    implode("\n  * ", $incompatibleMethods)
                );
            }
            if (0 < count($incompatibleConstants)) {
                $message .= sprintf(
                    "<comment>The following constants are not compatible to Magento %s:</comment>\n  * %s\n",
                    $version,
                    implode("\n  * ", $incompatibleConstants)
                );
            }
            if (0 < strlen($message)) {
                Logger::addComment(
                    $extensionPath,
                    $this->name,
                    sprintf("<error>Extension is not compatible to Magento %s</error>\n%s", $version, $message)
                );
            } else {
                $compatibleVersions[] = $version;
            }
        }

        Logger::addComment(
            $extensionPath,
            $this->name,
            'Checked Magento versions: ' . implode(', ', $availableVersions) . "\n"
            . '* Extension seems to support following Magento versions: ' . implode(', ', $compatibleVersions)
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

    /**
     * connect to tag database
     *
     * @return void
     */
    protected function connectTagDatabase()
    {
        $basedir = realpath(dirname(__FILE__) . '/../../');
        require_once $basedir . '/vendor/dg/dibi/dibi/dibi.php';
        if (false == dibi::isConnected()) {
            $databaseConfig = $this->settings->database;
            if (0 == strlen($databaseConfig->password)) {
                unset($databaseConfig->password);
            }
            dibi::connect($databaseConfig);
            /*
             array(
                //'driver'   => 'sqlite3',
                //'database' => $basedir . '/plugins/MageCompatibility/var/tags.sqlite'
                'driver'   => 'mysql',
                'username' => 'root',
                'database' => 'judge'
            ));
         */
        }
    }
}
