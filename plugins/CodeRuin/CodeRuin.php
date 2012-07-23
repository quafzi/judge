<?php
namespace CodeRuin;

use Netresearch\Config;
use Netresearch\Logger;
use Netresearch\PluginInterface as JudgePlugin;

class CodeRuin implements JudgePlugin
{
    protected $config;
    protected $extensionPath;
    protected $settings;
    protected $results;

    public function __construct(Config $config)
    {
        $this->config = $config;
        $this->name   = current(explode('\\', __CLASS__));
        $this->settings = $this->config->plugins->{$this->name};
    }

    /**
     *
     * @param string $extensionPath the path to the extension to check
     * @return float the score for the extension for this test
     */
    public function execute($extensionPath)
    {
        $this->extensionPath = $extensionPath;
        $settings = $this->config->plugins->{$this->name};
        $score = $settings->good;
        $foundTokens = array();
        foreach ($settings->criticals as $token) {
            $filesWithThatToken = array();
            $command = 'grep -rEl "' . $token . '" ' . $extensionPath . '/app';
            exec($command, $filesWithThatToken, $return);
            if (0 < count($filesWithThatToken)) {
                $score = $settings->bad;
                Logger::addComment($extensionPath, $this->name, sprintf(
                    'Found an indicator of unfinished code: "%s" at %s',
                    $token,
                    implode(';', $filesWithThatToken)
                ));
                $foundTokens[$token] = $filesWithThatToken;
            }
            Logger::setResultValue($extensionPath, $this->name, $token, count($filesWithThatToken));
        }
        if (0 < count($foundTokens)) {
            foreach ($foundTokens as $token=>$files) {
                Logger::warning(sprintf(
                    'Found %d unfinished code part' . (1 < count($foundTokens[$token]) ? 's' : '') . ' marked with "%s" at %s',
                    count($foundTokens[$token]),
                    $token,
                    $extensionPath
                ));
            }
        } else {
            Logger::success('No unfinished code found at ' . $extensionPath);
        }
        Logger::setScore($extensionPath, $this->name, $score);
        return $score;
    }
}

