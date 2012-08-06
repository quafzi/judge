<?php
namespace PerformanceCheck;

use Netresearch\Config;
use Netresearch\Logger;
use Netresearch\PluginInterface as JudgePlugin;

class PerformanceCheck implements JudgePlugin
{
    protected $config;
    protected $extensionPath;
    protected $settings;
    protected $results;

    /**
     *
     * @param Config $config
     */
    public function __construct(Config $config)
    {
        $this->config = $config;
        $this->name   = current(explode('\\', __CLASS__));
        $this->settings = $this->config->plugins->{$this->name};
    }

    /**
     *
     * @param string $extensionPath path to the extension to evaluate
     * @return float the score for this test
     */
    public function execute($extensionPath)
    {
        $this->extensionPath = $extensionPath;
        $settings = $this->config->plugins->{$this->name};
        $score = $settings->good;
        foreach ($this->settings->requestParamsPattern as $requestPattern) {
            $filesWithThatToken = array();
            $command = 'grep -riEl "' . $requestPattern . '" ' . $extensionPath . '/app';
            exec($command, $filesWithThatToken, $return);
            if (0 < count($filesWithThatToken)) {
                $score = $this->settings->bad;
                Logger::addComment($extensionPath, $this->name, sprintf(
                    'Found an indicator of using direct request params: "%s" at %s',
                    $requestPattern,
                    implode(';' . PHP_EOL, $filesWithThatToken)
                ));
                $foundTokens = $foundTokens + count($filesWithThatToken);
            }
            Logger::setResultValue($extensionPath, $this->name, $requestPattern, count($filesWithThatToken));
        }
        if ($score == $this->settings->good) {
            Logger::success('No potential performance issues found ' . $extensionPath);
        }

        return $score;
    }
}