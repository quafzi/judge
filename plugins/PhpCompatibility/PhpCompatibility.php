<?php
namespace PhpCompatibility;

use Netresearch\Config;
use Netresearch\Logger;
use Netresearch\PluginInterface as JudgePlugin;

/**
 * check PHP compatibility
 */
class PhpCompatibility implements JudgePlugin
{
    protected $config;
    protected $extensionPath;
    protected $rewrites=array();

    public function __construct(Config $config)
    {
        $this->config = $config;
        $this->name   = current(explode('\\', __CLASS__));
    }

    public function execute($extensionPath)
    {
        require_once 'plugins/' . $this->name . '/lib/PHP/CompatInfo/PHP/CompatInfo/Autoload.php';

        $settings = $this->config->plugins->{$this->name};
        $this->extensionPath = $extensionPath;

        $options = array(
            'recursive' => true,
            'report'    => 'summary'
        );

        $min         = 0;
        $minReadable = 0;
        $max         = INF;
        $maxReadable = 'latest';

        try {
            $phpci = new \PHP_CompatInfo($options);
            $phpci->parse($extensionPath);

            $allResultsAtOnce = $phpci->toArray();
            foreach ($phpci->toArray() as $file=>$result) {
                $currentMin = $this->getVersionInt($result['versions'][0]);
                $currentMax = $this->getVersionInt($result['versions'][1]);

                if (false == is_null($currentMin) && $min < $currentMin) {
                    $min = $currentMin;
                    $minReadable = $result['versions'][0];
                }
                if (false == is_null($currentMax) && $currentMax < $max) {
                    $max = $currentMax;
                    $maxReadable = $result['versions'][1];
                }
            }

        } catch (\PHP_CompatInfo_Exception $e) {
            die ('PHP_CompatInfo Exception : ' . $e->getMessage() . PHP_EOL);
        }

        if ($min <= $this->getVersionInt($settings->min) && $maxReadable=='latest') {
            Logger::success(vsprintf(
                'Extension is compatible to PHP from version %s up to latest versions',
                array($minReadable)
            ));
            Logger::setScore($extensionPath, $this->name, $settings->good);
            return $settings->good;
        }
        Logger::warning(vsprintf(
            'Extension is compatible to PHP from version %s (instead of required %s) up to %s',
            array($minReadable, $settings->min, $maxReadable)
        ));
        Logger::setScore($extensionPath, $this->name, $settings->bad);
        return $settings->bad;
    }

    protected function getVersionInt($version)
    {
        if (strlen($version)) {
            list($major, $minor, $revision) = explode('.', $version);
            return 10000*$major + 100*$minor + $revision;
        }
        return null;
    }
}
