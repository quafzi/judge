<?php
namespace Rewrites;

use Netresearch\Config;
use Netresearch\Logger;
use Netresearch\PluginInterface as JudgePlugin;

/**
 * count Magento core rewrites
 */
class Rewrites implements JudgePlugin
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
        $settings = $this->config->plugins->{$this->name};
        $this->extensionPath = $extensionPath;

        exec(sprintf('find "%s" -name config.xml', $extensionPath), $configFiles);

        $rewriteCount = 0;
        $types = array('blocks', 'models');
        foreach ($configFiles as $configFile) {
            foreach ($types as $type) {
                $this->findRewrites($configFile, $type);
            }
        }

        if (count($this->rewrites) <= $settings->allowedRewrites->count) {
            $score = $settings->allowedRewrites->good;
        } elseif ($settings->maxRewrites->count < count($this->rewrites)) {
            $score = $settings->maxRewrites->good;
        } else {
            $score = $settings->maxRewrites->bad;
        }
        foreach ($this->rewrites as $rewrite) {
            list($type, $code) = explode('s:', $rewrite);
            if ($this->isCritical($rewrite)) {
                Logger::addComment(
                    $this->extensionPath,
                    $this->name,
                    '<comment>Critical ' . $type . ' rewrite: ' . $code . '</comment>'
                );
                $score = $settings->bad;
            } else {
                Logger::addComment($this->extensionPath, $this->name, $type . ' rewrite ' . $code);
            }
        }
        if ($settings->bad !== $score) {
            Logger::success('%d rewrites found in %s', array(count($this->rewrites), $extensionPath));
        }

        Logger::setScore($extensionPath, $this->name, $score);
        return $score;
    }

    protected function findRewrites($configFile, $type)
    {
        $xpath = '/config/global/' . $type . '//rewrite/..';
        $config = simplexml_load_file($configFile);
        foreach ($config->xpath($xpath) as $moduleRewrites) {
            $module = $moduleRewrites->getName();
            foreach ($moduleRewrites->rewrite->children() as $path=>$class) {
                $this->rewrites[] = $type . ':' . $module . '/' . $path;
            }
        }
    }

    protected function isCritical($rewrite)
    {
        $critical = $this->config->plugins->{$this->name}->critical->toArray();
        list($type, $code) = explode(':', $rewrite);
        if (false == is_array($critical[$type])) {
            return false;
        }
        return in_array($code, $critical[$type]);
    }
}


