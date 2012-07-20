<?php
namespace CheckStyle;

use Netresearch\Config;
use Netresearch\Logger;
use Netresearch\PluginInterface as JudgePlugin;

class CheckStyle implements JudgePlugin
{
    protected $config;
    protected $extensionPath;
    protected $settings;
    protected $results;
    protected $uniqueIssues = array(
        'errors'    => array(),
        'warnings'  => array()
    );

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
        $score          = 0;
        $executable     = 'vendor/zerkalica/PHP_CodeSniffer/scripts/phpcs';
        $score          = $this->settings->good;
        $standardToUse  = $this->settings->standardToUse;
        $csResults      = array();
        exec(sprintf($executable . ' --standard="%s" "%s"', $standardToUse, $extensionPath), $csResults);
        $csResults = $this->getClearedResults($csResults);
        // more issues found than allowed -> log them
        if ($this->settings->allowedIssues < sizeof($csResults)) {
            $score = $this->settings->bad;
            foreach ($csResults as $issue) {
                $this->addToUniqueIssues($issue);
                Logger::addComment(
                    $extensionPath,
                    $this->name,
                    '<comment>PHPCS found a violation of coding standard ' . $standardToUse . ':</comment>' . $issue
                );
            }
            //@TODO: log unique issues
        } else {
            Logger::addComment(
                $extensionPath,
                $this->name,
                '<info>PHPCS found ' . count($csResults) . 'only</info>'
            );
        }
        Logger::setScore($extensionPath, $this->name, $score);
        return $score;
    }

    /**
     *
     * removes header and so on from result
     *
     * @param array $results
     * @return array the
     */
    protected function getClearedResults(array $results)
    {
        $newResults = array();
        foreach ($results as $resultLine) {
            if (false !== strpos($resultLine, '|') &&
                (false !== strpos(strtolower($resultLine), 'error') ||
                 false !== strpos(strtolower($resultLine), 'warning'))) {
                $newResults[] = $resultLine;
            }
        }
        $results = $newResults;
        return $results;
    }

    /**
     * adds issue to unique issues
     * @param string $issue
     */
    protected function addToUniqueIssues($issue)
    {
        $issueData = explode('|', $issue);
        if (3 == count($issueData)) {
            $issueClass     = trim($issueData[1]);
            $issueMessage   = substr(trim($issueData[2]), 0, strpos($issueData[2], ';'));
            if (false === array_key_exists($issueClass, $this->uniqueIssues)) {
                $this->uniqueIssues[$issueClass] = array();
            }
            if (false === array_key_exists($issueMessage, $this->uniqueIssues[$issueClass])) {
                $this->uniqueIssues[$issueClass][$issueMessage] = 1;
            }
            if (true === array_key_exists($issueMessage, $this->uniqueIssues[$issueClass])) {
                $this->uniqueIssues[$issueClass][$issueMessage] ++;
            }
        }
    }
}