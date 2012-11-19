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
        $score = 0;

        $score += ($this->extensionContainsTokens($extensionPath, $this->settings->criticals))
            ? $this->settings->critical->bad
            : $this->settings->critical->good;

        $score += ($this->extensionContainsTokens($extensionPath, $this->settings->warnings))
            ? (int) $this->settings->warning->bad
            : $this->settings->warning->good;

        if ($this->settings->good <= $score) {
            Logger::success('No unfinished code found at ' . $extensionPath);
        } else {
            Logger::warning('Unfinished code found at ' . $extensionPath);
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

