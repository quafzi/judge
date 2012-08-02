<?php
namespace SecurityCheck;

use Netresearch\Config;
use Netresearch\Logger;
use Netresearch\PluginInterface as JudgePlugin;

class SecurityCheck implements JudgePlugin
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
     * @param type $extensionPath
     * @return float the score for this test
     */
    public function execute($extensionPath)
    {
        $this->extensionPath = $extensionPath;
        $settings = $this->config->plugins->{$this->name};
        $score = $settings->good;
//        if ($settings->allowedRequestParams <= $this->checkForRequestParams($extensionPath)) {
//            $score = $settings->bad;
//        }
//        if ($settings->allowedMissingEscaping <= $this->checkForEscaping($extensionPath)) {
//            $score = $settings->bad;
//        }
        if ($settings->allowedSQLQueries <= $this->checkForSQLQueries($extensionPath)) {
            $score = $settings->bad;
        }
        if ($score == $settings->good) {
            Logger::success('No direct usage of request params, sql queries or unescaped output ' . $extensionPath);
        }
        Logger::setScore($extensionPath, $this->name, $score);
        return $score;
    }


    /**
     *
     * @param string $extensionPath
     * @return int number of files containing direct usage of request params
     */
    protected function checkForRequestParams($extensionPath)
    {
        $foundTokens = 0;
        foreach ($this->settings->requestParamsPattern as $requestPattern) {
            $filesWithThatToken = array();
            $command = 'grep -ri "' . $requestPattern . '" ' . $extensionPath . '/app';
            exec($command, $filesWithThatToken, $return);
            if (0 < count($filesWithThatToken)) {
                Logger::addComment($extensionPath, $this->name, sprintf(
                    'Found an indicator of using direct request params: "%s" at %s',
                    $requestPattern,
                    implode(';' . PHP_EOL, $filesWithThatToken)
                ));
                $foundTokens = $foundTokens + count($filesWithThatToken);
            }
            Logger::setResultValue($extensionPath, $this->name, $requestPattern, count($filesWithThatToken));
        }
        return $foundTokens;
    }


    /**
     *
     * @param string $extensionPath
     * @return int number of files containing unescaped output
     */
    protected function checkForEscaping($extensionPath)
    {
        $foundTokens = 0;
        foreach ($this->settings->unescapedOutputPattern as $unescapedOutputPattern) {
            $filesWithThatToken = array();
            $command = 'grep -ri "' . $unescapedOutputPattern . '" ' . $extensionPath . '/app';
            exec($command, $filesWithThatToken, $return);
            if (0 < count($filesWithThatToken)) {
                Logger::addComment($extensionPath, $this->name, sprintf(
                    'Found an indicator of not escaping output: "%s" at %s',
                    $unescapedOutputPattern,
                    implode(';' . PHP_EOL, $filesWithThatToken)
                ));
                $foundTokens = $foundTokens + count($filesWithThatToken);
            }
            Logger::setResultValue($extensionPath, $this->name, $unescapedOutputPattern, count($filesWithThatToken));
        }
        return $foundTokens;
    }

    /**
     *
     * @param type $extensionPath
     * @return int number of files containing direct usage of sql queries
     */
    protected function checkForSQLQueries($extensionPath)
    {
        $foundTokens = 0;
        foreach ($this->settings->sqlQueryPattern as $sqlQueryPattern) {
            $filesWithThatToken = array();
            $command = 'grep -ri "' . $sqlQueryPattern . '" ' . $extensionPath . '/app';
            echo $command . PHP_EOL;
            exec($command, $filesWithThatToken, $return);
            if (0 < count($filesWithThatToken)) {
                Logger::addComment($extensionPath, $this->name, sprintf(
                    'Found an indicator of using direct sql queries: "%s" at %s',
                    $sqlQueryPattern,
                    implode(';' . PHP_EOL, $filesWithThatToken)
                ));
                $foundTokens = $foundTokens + count($filesWithThatToken);
            }
            Logger::setResultValue($extensionPath, $this->name, $sqlQueryPattern, count($filesWithThatToken));
        }
        return $foundTokens;
    }
}