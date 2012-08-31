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
    protected $modulePrefixes = array();

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
        $config = new \MageCompatibility\Extension\Config();
        $this->modulePrefixes = $config->getUnitTestPrefixes($extensionPath);
        $this->setUpEnv($extensionPath);
        $score = $this->settings->good;
        $score = $this->evaluateTestCoverage($extensionPath);
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
        $score = $this->settings->good;
        $executable = 'vendor/EHER/PHPUnit/bin/phpunit';
        $codeCoverages  = array();
        $phpUnitOutput  = array();
        $paramsArray    = array();
        if (isset($this->settings->PHPUnitParams)) {
            $paramsArray = $this->settings->PHPUnitParams->toArray();
        }
        if (0 < count($this->modulePrefixes)) {
            $modulePrefixString = '"' . implode('|', $this->modulePrefixes) . '"';
            $phpUnitCoverageFile = 'codecoverage.xml';
            $params = '--coverage-clover ' . $phpUnitCoverageFile .' --filter ' . $modulePrefixString;
            if (0 < count($paramsArray)) {
                $params .= implode(' ', $paramsArray);
            }
            $execString = $executable . ' ' . $params . ' ' .  $this->config->common->magento->target . DIRECTORY_SEPARATOR . 'UnitTests.php';
            exec($execString, $phpUnitOutput);
            $pdependSummaryFile = 'summary.xml';
            $execString = sprintf('vendor/pdepend/pdepend/src/bin/pdepend --summary-xml="%s" "%s"', $pdependSummaryFile, $extensionPath);
            exec($execString);
            $phpUnitXpaths = array();
            foreach ($this->modulePrefixes as $modulePrefixString) {
                $phpUnitXpaths[] = "//class[starts-with(@name, '" . $modulePrefixString . "')]/../metrics";
            }
            $codeCoverages = $this->evaluateCodeCoverage($phpUnitCoverageFile, $phpUnitXpaths);
            $codeCoverageSettings = $this->settings->phpUnitCodeCoverages->toArray();
            foreach (array_keys($codeCoverageSettings) as $codeCoverageType) {
                if (array_key_exists($codeCoverageType, $codeCoverages)) {
                    Logger::addComment(
                        $extensionPath,
                        $this->name,
                        sprintf('<comment>Extension has a code coverage of "%f" for type "%s"</comment>', $codeCoverages[$codeCoverageType], $codeCoverageType)
                    );
                    Logger::notice(sprintf('<comment>Extension has a code coverage of "%f" for type "%s"</comment>', $codeCoverages[$codeCoverageType], $codeCoverageType));
                    if ($codeCoverages[$codeCoverageType] < $codeCoverageSettings[$codeCoverageType]) {
                        $score = $this->settings->bad;
                    }
                }
            }
            // compare phpunit test results with pdepend
            $phpUnitXpaths = array();
            $pdependXpaths = array();
            foreach ($this->modulePrefixes as $modulePrefixString) {
                $phpUnitXpaths[] = "//class[starts-with(@name, '" . $modulePrefixString . "')]";
                $pdependXpaths[] = "//class[starts-with(@name, '" . $modulePrefixString . "')  and not(starts-with(@name, '" . $modulePrefixString . '_Test' . "'))]";
            }
            $phpUnitClasses = $this->getClasses($phpUnitCoverageFile, $phpUnitXpaths);
            $pdependClasses = $this->getClasses($pdependSummaryFile, $pdependXpaths);
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
            unlink($pdependSummaryFile);
            unlink($phpUnitCoverageFile);
        } else {
            $score = $this->settings->bad;
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
    protected function getClasses($pathToXmlFile, $xpathExpressions)
    {
        $classes = array();
        foreach ($xpathExpressions as $xpathExpression) {
            $classNodes = $this->getNodes($pathToXmlFile, $xpathExpression);
            if (!is_null($classNodes)) {
                foreach ($classNodes as $classNode) {
                    // collect class names for determinig those which weren't covered by a test
                    if (!in_array($classNode['name'], $classes)) {
                        $classes[] = current($classNode[0]['name']);
                    }
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
    protected function evaluateCodeCoverage($pathToXmlReport, $xpathExpressions)
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
        foreach ($xpathExpressions as $xpathExpression) {
            $classNodes = $this->getNodes($pathToXmlReport, $xpathExpression);
            if (!is_null($classNodes)) {
                foreach ($classNodes as $classNode) {
                    foreach (array_keys($valuesForClasses) as $key) {
                        $valuesForClasses[$key] += $this->getValueForNodeAttr($classNode, $key);
                    }
                }
            }
            $codeCoverage['methodCoverage']         += $this->getCoverageRatio($valuesForClasses['coveredmethods'], $valuesForClasses['methods']);
            $codeCoverage['statementCoverage']      += $this->getCoverageRatio($valuesForClasses['coveredstatements'], $valuesForClasses['statements']);
            $codeCoverage['conditionalsCoverage']   += $this->getCoverageRatio($valuesForClasses['coveredconditionals'], $valuesForClasses['conditionals']);
            $codeCoverage['elementsCoverage']       += $this->getCoverageRatio($valuesForClasses['coveredelements'], $valuesForClasses['elements']);
        }
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


    protected function setUpEnv($extensionPath)
    {
        $executable = 'vendor/netresearch/jumpstorm/jumpstorm';

        if ($this->settings->useJumpstorm == true) {
            $iniFile = $this->generateJumpstormConfig($extensionPath);
            $installMagentoCommand      = 'magento -c ' . $iniFile;
            $installUnitTestingCommand  = 'unittesting -c ' . $iniFile;
            $installExtensionCommand    = 'extensions -c ' . $iniFile;
            Logger::notice('Setting Up Magento environment via jumpström');
            exec(sprintf('%s %s', $executable, $installMagentoCommand), $output);
            exec(sprintf('%s %s', $executable, $installUnitTestingCommand), $output);
            exec(sprintf('%s %s', $executable, $installExtensionCommand), $output);
            Logger::notice(implode(PHP_EOL, $output));
        }
        if (!is_file($this->config->common->magento->target . DIRECTORY_SEPARATOR . 'UnitTests.php')) {
            $iniFile = $this->generateJumpstormConfig($extensionPath);
            Logger::notice('Installing Unit Testing environment via jumpström');
            $installUnitTestingCommand  = 'unittesting -c ' . $iniFile;
            exec(sprintf('%s %s', $executable, $installUnitTestingCommand), $output);
        }
    }



    /**
     * @throws Zend_Config_Exception if config could not be written
     * @param string $extensionPath
     * @return string path to jumpstorm config file
     */
    protected function generateJumpstormConfig($extensionPath)
    {
        $this->config->extensions = array('ext' => array('source' => $extensionPath));
        $filename = dirname(__FILE__) . DIRECTORY_SEPARATOR .'config.ini';
        $writer = new \Zend_Config_Writer_Ini();
        $writer->write($filename, $this->config);
        return $filename;
    }



}
