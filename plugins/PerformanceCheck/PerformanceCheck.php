<?php
namespace PerformanceCheck;

use Netresearch\Config;
use Netresearch\Logger;
use Netresearch\PluginInterface as JudgePlugin;

class PerformanceCheck implements JudgePlugin
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
     * @param string $extensionPath path to the extension to evaluate
     * @return float the score for this test
     */
    public function execute($extensionPath)
    {
        require 'vendor/nikic/php-parser/lib/bootstrap.php';
        $this->extensionPath = $extensionPath;
        $settings = $this->config->plugins->{$this->name};
        $score = $settings->good;
        $possiblePerformanceKillers = $this->scanForPerformanceLeaks($extensionPath);

        if (0 < sizeof($possiblePerformanceKillers)) {
            foreach ($possiblePerformanceKillers as $possiblePerformanceKiller) {
               Logger::addComment($extensionPath, $this->name, '<comment>Found an indicator of a performance leak</comment>: ' . $possiblePerformanceKiller);
               Logger::setResultValue($extensionPath, $this->name, $possiblePerformanceKiller, count($possiblePerformanceKillers));
            }
        }
        if ($this->settings->allowedPerformanceIssues < sizeof($possiblePerformanceKillers)) {
            $score = $this->settings->bad;
        }
        if ($score == $this->settings->good) {
            Logger::success('No potential performance issues found ' . $extensionPath);
        }
        Logger::setScore($extensionPath, $this->name, $score);
        return $score;
    }


    /**
     * @TODO refactor (the same as \MageCompability\Extension::isUnitTestFile)
     */
    protected function isUnitTestFile($filePath)
    {
        $filePath = str_replace($this->extensionPath, '', $filePath);
        return (0 < preg_match('~app/code/.*/.*/Test/~u', $filePath));
    }

    /**
     *
     * @TODO: refactor (nearly the same as \MageCompability\Extension::addMethods)
     *
     * @param string $path
     * @return array array of potential performance issues
     */
    protected function scanForPerformanceLeaks($path)
    {
        $possiblePerformanceLeaks = array();
        $parser = new \PHPParser_Parser(new \PHPParser_Lexer);
        foreach (glob($path . '/*') as $item) {
            if (is_dir($item)) {
                $possiblePerformanceLeaks = array_merge($possiblePerformanceLeaks,$this->scanForPerformanceLeaks($item));
            }
            if (is_file($item) && is_readable($item)) {
                if ($this->isUnitTestFile($item)) {
                    continue;
                }
                /* we assume that there are only php files */
                if (substr($item, -6) == '.stmts.xml') {
                    unlink($item); continue;
                }
                $fileNameParts = explode('.', basename($item));
                $extension = end($fileNameParts);
                if (false === in_array($extension, array('php', 'phtml'))) {
                    continue;
                }
                try {
                    $stmts = $parser->parse(file_get_contents($item));
                    $serializer = new \PHPParser_Serializer_XML;
                    $xml = $serializer->serialize($stmts);
//                    file_put_contents($item . '.stmts.xml', var_export($xml, true));
                    $leaks = $this->collectPerformanceKillers(simplexml_load_string($xml), $item);
                    $possiblePerformanceLeaks = array_merge($possiblePerformanceLeaks, $leaks);
                } catch (\PHPParser_Error $e) {
                    // no valid php
                    continue;
                }
            }
        }
        return $possiblePerformanceLeaks;
    }


    protected function collectPerformanceKillers($xmlTree, $fileName)
    {
        $possiblePerformanceLeaks = array();
        $stmts = array('Stmt_Foreach', 'Stmt_For', 'Stmt_While', 'Stmt_Do');
        foreach ($stmts as $stmt) {
            $saveStmtXpath  = "//node:$stmt//node:Expr_MethodCall[subNode:name/scalar:string/text()='save']";
            $saveCalls      = $xmlTree->xpath($saveStmtXpath);
            foreach ($saveCalls as $saveCall) {
                $saveCallLineNumber = current($saveCall->xpath('./attribute:endLine/scalar:int/text()'));
                $encirclingForeach  = $xmlTree->xpath("//node:" . $stmt . "[./attribute:startLine/scalar:int/text() < $saveCallLineNumber and ./attribute:endLine/scalar:int/text() > $saveCallLineNumber]");
                if (0 < count($encirclingForeach)) {
                    $loopStartLine  = current(current($encirclingForeach)->xpath('./attribute:startLine/scalar:int/text()'));
                    $loopEndLine    = current(current($encirclingForeach)->xpath('./attribute:endLine/scalar:int/text()'));
                    $possiblePerformanceLeak = 'save called in a loop in file '
                    . $fileName . ' in line ' . $saveCallLineNumber . ' (loop ' . $stmt . ' starts in line ' . $loopStartLine . ' and ends in line ' . $loopEndLine . ')' ;
                    if (!in_array($possiblePerformanceLeak, $possiblePerformanceLeaks)) {
                        $possiblePerformanceLeaks[] = $possiblePerformanceLeak;
                    }
                }
            }
        }
        $xpathForPerformanceLeaks = "//node:Expr_MethodCall[./subNode:name/scalar:string/text() = 'getItemById']//node:Expr_MethodCall[./subNode:name/scalar:string/text() = 'getCollection']";
        $collectionCalls = $xmlTree->xpath($xpathForPerformanceLeaks);
        if (0 < count($collectionCalls)) {
            $startLine  = current(current($collectionCalls)->xpath('./attribute:startLine/scalar:int/text()'));
            $endLine    = current(current($collectionCalls)->xpath('./attribute:endLine/scalar:int/text()'));
            $possiblePerformanceLeak = 'getCollection()->getItemById() called in file '
                    . $fileName . ' in lines ' . $startLine . '-' . $endLine;
            if (!in_array($possiblePerformanceLeak, $possiblePerformanceLeaks)) {
                        $possiblePerformanceLeaks[] = $possiblePerformanceLeak;
            }
        }
        return $possiblePerformanceLeaks;
    }
}