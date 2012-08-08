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
                    //echo $item . PHP_EOL;
                    $serializer = new \PHPParser_Serializer_XML;
                    $xml = $serializer->serialize($stmts);

                    //file_put_contents($item . '.stmts.xml', var_export($xml, true));
                    $numberOfMethodCalls = $this->collectMethodCalls(
                        $stmts,
                        simplexml_load_string($xml)
                    );
                    //echo PHP_EOL;
                } catch (\PHPParser_Error $e) {
                    // no valid php
                    continue;
                }
            }
        }
    }

    protected function getResultType(\SimpleXMLElement $node, $debug=false)
    {
        $type = Method::TYPE_MIXED;
        if ($node->xpath('./node:Expr_StaticCall')) {
            $staticCall = current($node->xpath(
                './node:Expr_StaticCall/subNode:class/node:Name/subNode:parts/scalar:array/scalar:string/text()'
            ));
            if ($staticCall && current($staticCall) == 'Mage') {
                $node = current($node->xpath('./node:Expr_StaticCall'));
                $method = current($node->xpath('./subNode:name/scalar:string/text()'));
                $firstArgument = current($node->xpath('./subNode:args/scalar:array/node:Arg/subNode:value'));
                if (false === $firstArgument || false == $firstArgument->xpath('./node:Scalar_String')) {
                    return $type;
                }
                $firstArgument = current($firstArgument->xpath('./node:Scalar_String/subNode:value/scalar:string/text()'));
                if (in_array($method, array('getModel', 'getSingleton'))) {
                    $type = $this->getClassName('model', $firstArgument);
                } elseif ('getBlock' == $method) {
                    $type = $this->getClassName('block', $firstArgument);
                } elseif ('helper' == $method) {
                    $type = $this->getClassName('helper', $firstArgument);
                }
            } elseif ($staticCall && current($staticCall) == 'parent') {
                /* @TODO: get return type of parent method */
            }
        } elseif ($node->xpath('./node:Name')) {
            $type = current(current($node->xpath('./node:Name/subNode:parts/scalar:array/scalar:string/text()')));
        } elseif ($node->xpath('./node:Expr_Variable')) {
            $type = $this->getTypeOfVariable($node);
        } elseif ($node->xpath('./node:Scalar_String')) {
            $type = Method::TYPE_STRING;
        } elseif ($node->xpath('./node:Expr_MethodCall')) {
            $methodName = current($node->xpath('./node:Expr_MethodCall/subNode:name/scalar:string/text()'));
            if ('load' == $methodName) {
                $type = $this->getResultType(current($node->xpath('./node:Expr_MethodCall/subNode:var')));
            } elseif ('get' === substr($methodName, 0, 3) && 'Id' === substr($methodName, -2)) {
                $type = Method::TYPE_INT;
            }
        }
        
        return $type;
    }

    protected function getTypeOfVariable($node)
    {
        $type = Method::TYPE_MIXED;
        $variableName = current($node->xpath('./node:Expr_Variable/subNode:name/scalar:string/text()'));
        if ('this' == $variableName) {
            /* @TODO: $this may refer to parent or child class if method is not defined here */
            $className = current($node->xpath('./ancestor::node:Stmt_Class/subNode:name/scalar:string/text()'));
            return (false == $className) ? $type : current($className);
        }
        $usedInLine = (int) current($node->xpath('./node:Expr_Variable/attribute:endLine/scalar:int/text()'));
        $methodXpath = './ancestor::node:Stmt_ClassMethod';
        $currentMethod = current($node->xpath($methodXpath));
        if (false === $currentMethod) {
            return $type;
        }
        $variableDefinitionXpath = sprintf(
            './descendant::node:Expr_Assign[subNode:var/node:Expr_Variable/subNode:name/scalar:string/text() = "%s"]',
            $variableName
        );
        $variableDefinitions = $currentMethod->xpath($variableDefinitionXpath);
        $lastAssignmentLine = 0;
        $lastAssignment = null;
        foreach ($variableDefinitions as $key=>$assignment) {
            $assignmentLine = (int) current($assignment->xpath('./attribute:endLine/scalar:int/text()'));
            if ($usedInLine < $assignmentLine) {
                continue;
            }
            if ($lastAssignmentLine <= $assignmentLine) {
                $lastAssignmentLine = $assignmentLine;
                $lastAssignment = $assignment;
            }
        }
        if (false == is_null($lastAssignment)) {
            return $this->getResultType(current($lastAssignment->xpath('./subNode:expr')));
        }
        return $type;
    }

    protected function getClassName($type, $identifier)
    {
        $className = Method::TYPE_MIXED;
        $configFiles = glob($this->extensionPath . '/app/code/*/*/*/etc/config.xml');
        foreach ($configFiles as $configFile) {
            $extensionConfig = simplexml_load_file($configFile);
            if (false !== strpos($identifier, '/')) {
                list($module, $path) = explode('/', $identifier);
            } else {
                $module = $identifier;
                $path = 'data';
            }
            $xpath = '/config/*/' . $type . 's/' . $module . '/class/text()';
            $identifierPathParts = explode('_', $path);
            $className = current($extensionConfig->xpath($xpath));
            if (false == $className) {
                $className = 'Mage_' . ucfirst($module) . '_' . ucfirst($type);
            }
            foreach ($identifierPathParts as $part) {
                $className .= '_' . ucfirst($part);
            }
        }
        return $className;
    }

    /**
     * collect method calls 
     * 
     * @param PHPParser_Node_Stmt $stmt 
     * @return int Number of called methods
     */
    protected function collectMethodCalls($stmt, $xmlTree)
    {
        $numberOfMethodCalls = 0;
        $methodCallXPath = '//node:Expr_MethodCall | //node:Expr_StaticCall';
        $methodCalls = $xmlTree->xpath($methodCallXPath);
        foreach ($methodCalls as $call) {
            $methodName = current($call->xpath('./subNode:name/scalar:string/text()'));
            $args = $call->xpath('./subNode:args/scalar:array/node:Arg/subNode:value');
            foreach ($args as $pos=>$arg) {
                $args[$pos] = $this->getResultType($arg, true);
            }

            //echo 'Called method ' . $methodName . ' ';
            $object = $this->getResultType(current($call->xpath('./subNode:var | ./subNode:class')));
            //echo 'on ' . $object . PHP_EOL;
            $method = new Method(
                current($methodName),
                $args,
                array($object)
            );
            ++$numberOfMethodCalls;
            $this->usedMethods->add($method);
        }
        return $numberOfMethodCalls;
        /*
        die(var_dump(__FILE__ . ' on line ' . __LINE__ . ':', $xmlTree->xpath($methodCallXPath)));
        $methods = $xmlTree->xpath('//*[local-name() = "Expr_MethodCall"]');
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
            ++$numberOfMethodCalls;
        }
         */
        return $numberOfMethodCalls;
    }

    protected function determineExpressionType($var, $xmlTree)
    {
        $xmlTree->xpath('//');
    }
}
