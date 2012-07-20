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

    public function execute($extensionPath)
    {
        $metricViolations = 0;
        $score = 0;
        $results = $this->executePHPDepend($extensionPath);
        foreach ($results as $metric => $value) {
            if ($this->isCriticalMetricValue($metric, $value)) {
                Logger::addComment(
                    $this->extensionPath,
                    $this->name,
                    '<comment>Critical metric ' . $metric . ' value: ' . $value . '</comment>');
                ++ $metricViolations;
            }
        }
        $score = $this->settings->metricViolations->good;
        if ($this->settings->metricViolations->allowedMetricViolations < $metricViolations) {
            $score = $score + $this->settings->metricViolations->bad;
        }
        Logger::success('%d metric violations found in %s', array($metricViolations, $extensionPath));


        $score = $score + $this->executePHPCpd($extensionPath);

        Logger::setScore($extensionPath, $this->name, $score);
        return $score;

//        $results = $this->executePHPMessDetection($extensionPath);
//        var_dump($results);
//        $score = 0;
//        Logger::setScore($extensionPath, $this->name, $score);
//        return $score;
    }

    protected function executePHPMessDetection($extensionPath)
    {
        $mdResults = array();
        exec(sprintf('phpmd "%s" "%s" "%s"', $extensionPath, 'text', 'codesize'), $mdResults);
        return $mdResults;
    }


    protected function executePHPDepend($extensionPath)
    {
        $pdResults = array();
        exec(sprintf('pdepend --"%s" "%s"', 'summary-xml=sum.xml', $extensionPath));
        $xml = current(simplexml_load_file('sum.xml'));
        return $xml;
    }

    protected function executePHPCpd($extensionPath)
    {
        $line = '';
        $cpdPercentage = 0;
        $scoreForPhpCpd = $this->settings->phpcpd->good;
        $minLines   = $this->settings->phpcpd->minLines;
        $minTokens  = $this->settings->phpcpd->minTokens;
        exec(sprintf('phpcpd --min-lines "%s" --min-tokens "%s" --quiet "%s"', $minLines, $minTokens, $extensionPath), $output);
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

    protected function isCriticalMetricValue($metricName, $value)
    {
        $result = false;
        if (in_array($metricName, $this->settings->phpdepend->useMetrics->toArray())
            && $this->settings->phpdepend->{$metricName} < $value) {
            $result = true;
        }
        return $result;
    }

}


