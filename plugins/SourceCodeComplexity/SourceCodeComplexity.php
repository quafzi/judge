<?php
namespace SourceCodeComplexity;

use Netresearch\Config;
use Netresearch\Logger;
use Netresearch\PluginInterface as JudgePlugin;

class SourceCodeComplexity implements JudgePlugin
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
     * @return float the sum of scores of all tests
     */
    public function execute($extensionPath)
    {
        $score = 0;
        $score = $score + $this->executePHPDepend($extensionPath);
        $score = $score + $this->executePHPCpd($extensionPath);
        $score = $score + $this->executePHPMessDetector($extensionPath);
        Logger::setScore($extensionPath, $this->name, $score);
        return $score;
    }

    /**
     * checks the extension with phpMessDetector and returns the scoring
     *
     * @param string $extensionPath extension to check
     * @return float the scoring for the extension after php mess detector test
     */
    protected function executePHPMessDetector($extensionPath)
    {
        $executable = 'plugins/' . $this->name . '/lib/PHP/Depend/src/bin/phpmd';
        $score = $this->settings->phpMessDetector->good;
        $mdResults = array();
        exec(sprintf($executable . ' "%s" "%s" "%s"', $extensionPath, 'text', $this->settings->phpMessDetector->useRuleSets), $mdResults);
        if ($this->settings->phpMessDetector->allowedIssues < count($mdResults)) {
            $score = $this->settings->phpMessDetector->bad;
            foreach ($mdResults as $issue) {
                Logger::addComment($this->name, 'failed', $issue);
            }
        }
        return $score;
    }

    /**
     * checks the extensions complexity with phpDepend and returns the scoring
     *
     * @param string $extensionPath extension to check
     * @return float the scoring for the extension after php depend test
     */
    protected function executePHPDepend($extensionPath)
    {
        $executable = 'plugins/' . $this->name . '/lib/PHP/Depend/src/bin/phpdepend';
        $metricViolations = 0;
        $tempXml = $this->settings->phpDepend->tmpXmlFilename;
        $usedMetrics = $this->settings->phpDepend->useMetrics->toArray();
        exec(sprintf($executable . ' --summary-xml="%s" "%s"', $tempXml, $extensionPath));
        $metrics = current(simplexml_load_file($tempXml));
        foreach ($metrics as $metricName => $metricValue) {
            if (in_array($metricName, $usedMetrics)
                && $this->settings->phpDepend->{$metricName} < $metricValue) {
                Logger::addComment(
                    $this->extensionPath,
                    $this->name,
                    '<comment>Critical metric ' . $metricName . ' value: ' . $metricValue . '</comment>');
                ++ $metricViolations;
            }
        }
        $score = $this->settings->phpDepend->metricViolations->good;
        if ($this->settings->phpDepend->metricViolations->allowedMetricViolations < $metricViolations) {
            $score = $score + $this->settings->phpDepend->metricViolations->bad;
        }
        Logger::success('%d metric violations found in %s', array($metricViolations, $extensionPath));
        unlink($tempXml);
        return $score;
    }

    /**
     *  checks the extension with php copy and paste detector
     *
     * @param string $extensionPath extension to check
     * @return float the scoring for the extension after phpcpd test
     */
    protected function executePHPCpd($extensionPath)
    {
        $executable = 'plugins/' . $this->name . '/lib/PHP/Depend/src/bin/phpcpd';
        $line = '';
        $cpdPercentage = 0;
        $scoreForPhpCpd = $this->settings->phpcpd->good;
        $minLines   = $this->settings->phpcpd->minLines;
        $minTokens  = $this->settings->phpcpd->minTokens;
        exec(sprintf($executable . ' --min-lines "%s" --min-tokens "%s" --quiet "%s"', $minLines, $minTokens, $extensionPath), $output);
        foreach ($output as $line) {
            if (false !== strpos($line, '% duplicated')) {
                break;
            }
        }
        $cpdPercentage = substr($line, 0, strpos($line, '%'));
        if ($this->settings->phpcpd->percentageGood < $cpdPercentage) {
            $scoreForPhpCpd = $this->settings->phpcpd->bad;
        }
        return $scoreForPhpCpd;
    }
}
