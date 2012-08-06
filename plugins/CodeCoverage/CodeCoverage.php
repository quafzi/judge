<?php
namespace CodeCoverage;

use Netresearch\Config;
use Netresearch\Logger;
use Netresearch\PluginInterface as JudgePlugin;

class CodeCoverage implements JudgePlugin
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
        $this->setUpEnv();
        $score = $this->settings->good;
        $score = $this->evaluateTestCoverage($extensionPath . DIRECTORY_SEPARATOR);
        Logger::setScore($extensionPath, $this->name, $score);
        return $score;
    }

    /**
     *
     * calculates test coverage and find classes which are not covered
     * by any test
     *
     * @param string $extensionPath
     * @return float the score for test coverage
     */
    protected function evaluateTestCoverage($extensionPath)
    {
        $score = 0;
        $executable = 'vendor/EHER/PHPUnit/bin/phpunit';
        $codeCoverages = array();
        $phpUnitOutput = array();
        if (0 < count($this->settings->PHPUnitParams->toArray())) {
            $paramsArray = $this->settings->PHPUnitParams->toArray();
            $params = implode(' ', $paramsArray);
            $execString = $executable . ' ' . $params . ' ' .  $this->settings->pathToUnitTests;
            exec($execString, $phpUnitOutput);
            $phpUnitCoverageFile = str_replace('--coverage-clover ', '', $paramsArray['log']);
            $pdependSummaryFile = $this->settings->pdependReportFile;
            $execString = sprintf('vendor/pdepend/pdepend/src/bin/pdepend --summary-xml="%s" "%s"', $this->settings->pdependReportFile, $extensionPath);
            exec($execString);
            $phpUnitXpath = "//class[starts-with(@name, '" . $this->settings->modulePrefix . "')]/../metrics";
            $codeCoverages = $this->evaluateCodeCoverage($phpUnitCoverageFile, $phpUnitXpath);
            $codeCoverageSettings = $this->settings->phpUnitCodeCoverages->toArray();
            foreach (array_keys($codeCoverageSettings) as $codeCoverageType) {
                if (array_key_exists($codeCoverageType, $codeCoverages)
                    && $codeCoverages[$codeCoverageType] < $codeCoverageSettings[$codeCoverageType]) {
                    Logger::addComment(
                        $extensionPath,
                        $this->name,
                        sprintf('<comment>Extension has a code coverage of "%d" for type "%s"</comment>', $codeCoverages[$codeCoverageType], $codeCoverageType)
                    );
                    Logger::notice(sprintf('<comment>Extension has a code coverage of "%d" for type "%s"</comment>', $codeCoverages[$codeCoverageType], $codeCoverageType));
                    $score = $this->settings->bad;
                }
            }
            // compare phpunit test results with pdepend
            $phpUnitXpath = "//class[starts-with(@name, '" . $this->settings->modulePrefix . "')]";
            $phpUnitClasses = $this->getClasses($phpUnitCoverageFile, $phpUnitXpath);
            $pdependClasses = $this->getClasses($pdependSummaryFile, "//class[starts-with(@name, '" . $this->settings->modulePrefix . "')  and not(starts-with(@name, '" . $this->settings->moduleTestPrefix . "'))]");
            $notCoveredClasses = array_diff($pdependClasses, $phpUnitClasses);

            if (0 < sizeof($notCoveredClasses)) {
                if ($this->settings->allowedNotCoveredClasses < sizeof($notCoveredClasses)) {
                    $score = $this->settings->bad;
                }
                foreach ($notCoveredClasses as $notCoveredClass) {

                    Logger::notice(
                        '<comment>Following class is not covered by any test: ' . $notCoveredClass . ' </comment>'
                    );
                }
            }
        }
        return $score;
    }

    /**
     * gets the classes which are contained in a xml report file
     *
     * @param string $pathToXmlFile - the path to the report file
     * @param string $xpathExpression - the xpath for retrieving the class names
     * @return type
     */
    protected function getClasses($pathToXmlFile, $xpathExpression)
    {
        $classes = array();
        $classNodes = $this->getNodes($pathToXmlFile, $xpathExpression);
        if (!is_null($classNodes)) {
            foreach ($classNodes as $classNode) {
                // collect class names for determinig those which weren't covered by a test
                if (!in_array($classNode['name'], $classes)) {
                    $classes[] = current($classNode[0]['name']);
                }
            }
        }
        return $classes;
    }


    /**
     *
     * evaluates the code coverage by PHPUnit tests
     *
     * @param string $pathToXmlReport - the xml containing the results for the classes
     * @param string $xpathExpression - the xpath for retrievibng the results for the classes
     * @return array - the array containing the code coverage results
     */
    protected function evaluateCodeCoverage($pathToXmlReport, $xpathExpression)
    {
        $valuesForClasses = array(
            'coveredmethods'        => 0,
            'methods'               => 0,
            'coveredstatements'     => 0,
            'statements'            => 0,
            'coveredconditionals'   => 0,
            'conditionals'          => 0,
            'coveredelements'       => 0,
            'elements'              => 0
        );
        $codeCoverage = array(
            'methodCoverage'        => 0,
            'statementCoverage'     => 0,
            'conditionalsCoverage'  => 0,
            'elementsCoverage'      => 0
        );
        $classNodes = $this->getNodes($pathToXmlReport, $xpathExpression);
        if (!is_null($classNodes)) {
            foreach ($classNodes as $classNode) {
                foreach (array_keys($valuesForClasses) as $key) {
                    $valuesForClasses[$key] += $this->getValueForNodeAttr($classNode, $key);
                }
            }
        }
        $codeCoverage['methodCoverage']         = $this->getCoverageRatio($valuesForClasses['coveredmethods'], $valuesForClasses['methods']);
        $codeCoverage['statementCoverage']      = $this->getCoverageRatio($valuesForClasses['coveredstatements'], $valuesForClasses['statements']);
        $codeCoverage['conditionalsCoverage']   = $this->getCoverageRatio($valuesForClasses['coveredconditionals'], $valuesForClasses['conditionals']);
        $codeCoverage['elementsCoverage']       = $this->getCoverageRatio($valuesForClasses['coveredelements'], $valuesForClasses['elements']);
        return $codeCoverage;
    }

    /**
     * retrieves the nodes of an xml document for given xpath
     *
     * @param string $pathToXmlReport - the path to xml document
     * @param string $xpathExpression - the xpath for retrieving the nodes
     * @return array - the nodes
     */
    protected function getNodes($pathToXmlReport, $xpathExpression)
    {
        $xmlElement = simplexml_load_file($pathToXmlReport);
        $classNodes = null;
        if ($xmlElement instanceof \SimpleXMLElement) {
            $classNodes = $xmlElement->xpath($xpathExpression);
        }
        return $classNodes;
    }

    /**
     * gets the attribute value from a given node by the attributes name
     * @param \SimpleXMLElement $node
     * @param string $attrName
     * @return mixed - the value
     */
    protected function getValueForNodeAttr(\SimpleXMLElement $node, $attrName)
    {
        $value = 0;
        if (!is_null($node[$attrName])) {
            $value = current($node[$attrName]);
        }
        return $value;
    }

    /**
     *
     * calculates the ratio between covered code and total amount of code
     *
     * @param float $covered
     * @param float $total
     * @return float -the ratio between covered and total
     */
    protected function getCoverageRatio($covered, $total)
    {
        $ratio = 0;
        if (is_numeric($covered) && is_numeric($total) && $total > 0) {
            $ratio = $covered / $total;
        }
        return $ratio;
    }


    protected function setUpEnv()
    {
        if ($this->settings->useJumpstorm == true) {
            Logger::notice('Setting Up Magento environment via jumpström');
            $iniFile = $this->settings->jumpstormIni;
            $installMagentoCommand      = 'magento -c ' . $iniFile;
            $installUnitTestingCommand  = 'unittesting -c ' . $iniFile;
            $installExtensionCommand    = 'extensions -c ' . $iniFile;
            $executable = 'vendor/netresearch/jumpstorm/jumpstorm';
            exec(sprintf('%s %s', $executable, $installMagentoCommand), $output);
            Logger::notice(implode(PHP_EOL, $output));
            exec(sprintf('%s %s', $executable, $installUnitTestingCommand), $output);
            Logger::notice(implode(PHP_EOL, $output));
            exec(sprintf('%s %s', $executable, $installExtensionCommand), $output);
            Logger::notice(implode(PHP_EOL, $output));
        }
    }

}
