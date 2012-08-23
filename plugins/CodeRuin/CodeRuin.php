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

        $score += ($this->extensionContainsTokens($extensionPath, $settings->criticals))
            ? $settings->critical->bad
            : $settings->critical->good;

        $score += ($this->extensionContainsTokens($extensionPath, $settings->warnings))
            ? $settings->warning->bad
            : $settings->warning->good;

        if ($settings->good === $score) {
            Logger::success('No unfinished code found at ' . $extensionPath);
        }

        Logger::setScore($extensionPath, $this->name, $score);
        return $score;
    }

    protected function extensionContainsTokens($extensionPath, $tokens)
    {
        $found = 0;
        foreach ($tokens as $token) {
            $filesWithThatToken = array();
            $command = 'grep -riEl "' . $token . '" ' . $extensionPath . '/app';
            exec($command, $filesWithThatToken, $return);
            $count = count($filesWithThatToken);
            if (0 < $count) {
                Logger::addComment($extensionPath, $this->name, sprintf(
                    'Found an indicator of unfinished code: "%s" at %s',
                    $token,
                    implode(', ', $filesWithThatToken)
                ));
                $found += $count;
            }
        }
        return (0 < $found);
    }
}

