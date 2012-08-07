<?php
namespace MageCompatibility;

class Extension
{
    protected $extensionPath;

    protected $usedClasses;
    protected $usedMethods;

    public function __construct($extensionPath)
    {
        $this->extensionPath = $extensionPath;
    }

    public function getUsedMagentoMethods()
    {
        require 'vendor/nikic/php-parser/lib/bootstrap.php';
        $this->usedMethods = new Methods();
        $this->addMethods($this->extensionPath);
        return $this->usedMethods;
    }

    public function getUsedMagentoClasses()
    {
        $this->usedClasses = new Klasses();

        $extendsToken = 'extends';
        $extendedClassesRegexp = '/^class .* extends ([a-zA-Z0-9_].*)\W/mU';
        $this->addClassesByRegexp($extendsToken, $extendedClassesRegexp);

        $factoryTypes = array(
            'Block'         => 'Block',
            'Model'         => 'Model',
            'ResourceModel' => 'Model/Mysql4'
        );
        foreach ($factoryTypes as $factoryType=>$filePathPattern) {
            $factoryRegexp = '/Mage\W*::\W*get' . $factoryType . '\W*\(\W*["\'](.*)["|\']\"*\)/mU';
            $this->addClassesByRegexp($factoryType, $factoryRegexp, $filePathPattern);
        }

        return $this->usedClasses;
    }

    protected function addClassesByRegexp($token, $regexp, $filePathPattern=null)
    {
        $command = 'grep -rEl "' . $token . '" ' . $this->extensionPath . '/app';
        exec($command, $filesWithThatToken, $return);

        if (0 == count($filesWithThatToken)) {
            return;
        }

        foreach ($filesWithThatToken as $filePath) {
            if ($this->isUnitTestFile($filePath)) {
                continue;
            }
            $content = file_get_contents($filePath);
            preg_match($regexp, $content, $detailedMatches);
            if (1 < count($detailedMatches)) {
                $class = new Klass($detailedMatches[1], str_replace('/', '_', $filePathPattern));
                if ($class->isExtensionClass($detailedMatches[1], $filePathPattern, $this->extensionPath)) {
                    continue;
                }
                $this->usedClasses->add($class);
            }
        }
    }

    protected function isUnitTestFile($filePath)
    {
        $filePath = str_replace($this->extensionPath, '', $filePath);
        return (0 < preg_match('~app/code/.*/.*/Test/~u', $filePath));
    }

    protected function addMethods($path)
    {
        $parser = new \PHPParser_Parser(new \PHPParser_Lexer);
        foreach (glob($path . '/*') as $item) {
            if (is_dir($item)) {
                $this->addMethods($item);
            }
            if (is_file($item) && is_readable($item)) {
                if ($this->isUnitTestFile($item)) {
                    continue;
                }
                /* we assume that there are only php files */
                if (substr($item, -6) == '.stmts') {
                    unlink($item); continue;
                }
                try {
                    $stmts = $parser->parse(file_get_contents($item));
                    $serializer = new \PHPParser_Serializer_XML;
                    $xmlTree = $serializer->serialize($stmts);
                    //file_put_contents($item . '.stmts', var_export($stmts, true));
                    $numberOfMethodCalls = $this->collectMethodCalls(
                        $stmts,
                        simplexml_load_string($xmlTree)
                    );
                } catch (\PHPParser_Error $e) {
                    // no valid php
                    continue;
                }
            }
        }
    }

    /**
     * collect method calls 
     * 
     * @param PHPParser_Node_Stmt $stmt 
     * @return int Number of called methods
     */
    protected function collectMethodCalls($stmt, $xmlTree)
    {
        $methods = $xmlTree->xpath('//node:Expr_MethodCall');
        foreach ($methods as $method) {
            die(var_dump(__FILE__ . ' on line ' . __LINE__ . ':', $method));
        }
        die(var_dump(__FILE__ . ' on line ' . __LINE__ . ':', $methods));
        $numberOfMethodCalls = 0;
        if (is_array($stmt)) {
            foreach ($stmt as $subNode) {
                $numberOfMethodCalls += $this->collectMethodCalls($subNode, $xmlTree);
            }
        }
        if (is_object($stmt)) {
            foreach ($stmt->getSubNodeNames() as $name) {
                $numberOfMethodCalls += $this->collectMethodCalls($stmt->$name, $xmlTree);
            }
        }
        if ($stmt instanceof \PHPParser_Node_Expr_Assign) {
            if (isset($stmt->var)) {
                $variable = $stmt->var;
                $type = $this->determineExpressionType($var, $xmlTree);
            }
        }
        if ($stmt instanceof \PHPParser_Node_Expr_MethodCall
            || $stmt instanceof \PHPParser_Node_Expr_StaticCall
        ) {
            $method = new Method(
                $stmt->name,
                $stmt->args,
                null
            );
            $this->usedMethods->add($method);
            ++$numberOfMethodCalls;
        }
        return $numberOfMethodCalls;
    }

    protected function determineExpressionType($var, $xmlTree)
    {
        $xmlTree->xpath('//');
    }
}
