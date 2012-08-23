<?php
namespace CheckComments;

use Netresearch\Config;
use Netresearch\Logger;
use Netresearch\PluginInterface as JudgePlugin;

class CheckComments implements JudgePlugin
{
    protected $config;
    protected $settings;
    protected $ncloc = 0;
    protected $cloc = 0;




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
        $score = $this->settings->good;
        $lowerBoundary = $this->settings->lowerBoundary;
        $upperBoundary = $this->settings->upperBoundary;
        $clocToNclocRatio = $this->getClocToNclocRatio($extensionPath);
        Logger::addComment($extensionPath, $this->name, '<comment>calculated cloc to ncloc ratio of</comment> ' . $clocToNclocRatio);
        if ($clocToNclocRatio <= $lowerBoundary || $clocToNclocRatio >= $upperBoundary) {
            $score = $this->settings->bad;
        }
        $unfinishedCodeToNclocRatio = $this->getUnfinishedCodeToNclocRatio($extensionPath);
        Logger::addComment($extensionPath, $this->name, '<comment>calculated unfinished code to ncloc ratio</comment> ' . $unfinishedCodeToNclocRatio);
        if ($this->settings->allowedUnfinishedCodeToNclocRatio < $unfinishedCodeToNclocRatio) {
            $score = $this->settings->bad;
        }
        Logger::success('Registered good comment count in ' . $extensionPath);
        Logger::setScore($extensionPath, $this->name, $score);
        return $score;
    }

    /**
     *
     * calculates the ratio between 'number logical lines of code' and 'number comment lines of code'
     *
     * @param string $extensionPath
     * @return float
     * @throws Exception if the ratio cannot be calculated
     */
    protected function getClocToNclocRatio($extensionPath)
    {
        $ncloc = 0;
        $cloc = 0;
        $metrics = $this->getMetrics($extensionPath);
        $this->ncloc = $metrics['ncloc'];
        $this->cloc = $metrics['cloc'];
        if ((!is_numeric($ncloc) || !is_numeric($cloc)) && $ncloc <= 0) {
            throw new Exception('Number of code lines is not numeric or 0? Please check extension path!');
        }
        return $this->cloc / $this->ncloc;
    }


    protected function getUnfinishedCodeToNclocRatio($extensionPath)
    {
        $unfinishedCode = 0;
        $precalculatedResults = Logger::getResults($extensionPath, 'CodeRuin');
        if (!is_null($precalculatedResults)
            && array_key_exists('resultValue', $precalculatedResults)) {
            foreach ($precalculatedResults['resultValue'] as $key => $value) {
                $unfinishedCode += $value;
            }
        }
        return $unfinishedCode / $this->ncloc;
    }


    /**
     * getting the metrics which are used for calculation
     * either the metrics came from a previous check or the metrics were calculated
     *
     * @param $extensionPath the extension path
     * @return array an array containning the *locs
     */
    protected function getMetrics($extensionPath)
    {
        $metrics = array();
        $precalculatedResults = Logger::getResults($extensionPath, 'SourceCodeComplexity');
        if (!is_null($precalculatedResults)
            && array_key_exists('resultValue', $precalculatedResults)
            && array_key_exists('metrics', $precalculatedResults['resultValue'])
            && array_key_exists('ncloc', $precalculatedResults['resultValue']['metrics'])
            && array_key_exists('cloc', $precalculatedResults['resultValue']['metrics'])
        ) {
            $metrics = $precalculatedResults['resultValue']['metrics'];
        }
        if (0 == count($metrics)) {
            $executable = 'vendor/pdepend/pdepend/src/bin/pdepend';
            $tempXml = $this->settings->tmpXmlFilename;
            $command = sprintf($executable . ' --summary-xml="%s" "%s"', $tempXml, $extensionPath);
            exec($command);
            $metrics = current(simplexml_load_file($tempXml));
            unlink($tempXml);
        }
        return $metrics;
    }
}
