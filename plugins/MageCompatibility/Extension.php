<?php
namespace MageCompatibility;

use MageCompatibility\Extension\Config;

class Extension extends Config
{
    protected $extensionPath;

    protected $usedClasses;
    protected $usedMethods;

    protected $databaseChanges;
    protected $tables;

    /** @var mixed $methods Array of methods defined in this extension */
    protected $methods;

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
            $class = current($node->xpath(
                './node:Expr_StaticCall/subNode:class/node:Name/subNode:parts/scalar:array/scalar:string/text()'
            ));
            if ($class && current($class) == 'Mage') {
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
            } elseif ($class && current($class) == 'parent') {
                /* @TODO: get return type of parent method */
            }
        } elseif ($node->xpath('./node:Name')) {
            $type = current(current($node->xpath('./node:Name/subNode:parts/scalar:array/scalar:string/text()')));
            if ('parent' == $type) {
                return $this->getParentClass($node);
            }
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

    protected function getParentClass($node)
    {
        $extends = $node->xpath('./ancestor::node:Stmt_Class/subNode:extends/node:Name/subNode:parts/scalar:array/scalar:string/text()');
        if ($extends && 0 < count($extends)) {
            return current(current($extends));
        }
        throw new \Exception('Extension uses parent without extending another class');
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
        /* if variable is method parameter with type hint */
        $isParamXpath = sprintf(
            './ancestor::node:Stmt_ClassMethod/subNode:params/scalar:array/node:Param[subNode:name/scalar:string/text() = "%s"]/subNode:type/node:Name/subNode:parts/scalar:array/scalar:string/text()',
            $variableName
        );
        $paramTypes = $node->xpath($isParamXpath);
        if ($paramTypes) {
            $type = current($paramTypes);
            if (false !== $type && false == is_string($type)) {
                $type = current($type);
            }
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
            if (false !== $className && false == is_string($className)) {
                $className = current($className);
            }
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
            $methodName = current(current($call->xpath('./subNode:name/scalar:string/text()')));
            $args = $call->xpath('./subNode:args/scalar:array/node:Arg/subNode:value');
            foreach ($args as $pos=>$arg) {
                $args[$pos] = $this->getResultType($arg, true);
            }

            $variable = current($call->xpath('./subNode:var | ./subNode:class'));
            $object = $this->getResultType($variable);

            if (false == $this->isExtensionMethod($object, $methodName)) {
                $method = new Method(
                    $methodName,
                    $args,
                    array('class' => $object)
                );
            } else {
                continue;
            }
            ++$numberOfMethodCalls;
            $this->usedMethods->add($method);
        }
        return $numberOfMethodCalls;
    }

    /**
     * if given method is part of the extension
     *
     * @param string $className
     * @param string $methodName
     * @return boolean
     */
    protected function isExtensionMethod($className, $methodName)
    {
        $classPath = current(glob($this->extensionPath . '/app/code/*/' . str_replace('_', DIRECTORY_SEPARATOR, $className) . '.php'));
        if (file_exists($classPath)) {
            if ($this->isExtensionDatabaseAccessor($className, $methodName)) {
                return true;
            }
            $command = sprintf('grep -i "function %s" %s', $methodName, $classPath);
            exec($command, $matches, $notFound);
            if (0 < count($matches)) {
                return true;
            }
        }
        return false;
    }

    /**
     * if given method is a database field accessor related to a table that is defined by the extension
     *
     * @param string $className
     * @param string $methodName
     * @return boolean
     */
    protected function isExtensionDatabaseAccessor($className, $methodName)
    {
        if (3 < strlen($methodName) && in_array(substr($methodName, 0, 3), array('get', 'set', 'uns', 'has'))) {
            $fieldName = $this->getFieldNameForAccessor($methodName);
            $changes = $this->getDatabaseChanges();
            $additionalProperties = $changes['add'];
            foreach ($additionalProperties as $table=>$fields) {
                if (false == in_array($fieldName, $fields)) {
                    continue;
                }
            }
            if ($this->getTableForClass($className) === $table) {
                return true;
            }
        }
        return false;
    }

    protected function getTableForClass($className)
    {
        if (is_null($this->tables)) {
            $this->tables = $this->getTables($this->extensionPath);
        }
if (false == is_string($className)) die(var_dump(__FILE__ . ' on line ' . __LINE__ . ':', $className));
        if (array_key_exists($className, $this->tables)) {
            return $this->tables[$className];
        }
    }

    protected function getFieldNameForAccessor($methodName)
    {
        return strtolower(implode('_', preg_split('/(?<=\\w)(?=[A-Z])/', substr($methodName, 3))));
    }

    /**
     * get an array of methods associated to the file they are defined in
     *
     * @return array
     */
    public function getMethods()
    {
        if (is_null($this->methods)) {
            $this->methods = array();
            $command = sprintf( 'grep -oriE " function ([a-zA-Z0-9_]*)" %s', $this->extensionPath . '/app/code/');
            exec($command, $output);
            foreach ($output as $line) {
                list($path, $method) = explode(':', $line);
                $this->methods[trim(str_replace('function', '', $method))] = trim(substr_replace($this->extensionPath, '', $path));
            }
        }
        return $this->methods;
    }

    /**
     * if extension has a method with the given name
     *
     * @param string $methodName
     * @return boolean
     */
    public function hasMethod($methodName)
    {
        return array_key_exists($methodName, $this->getMethods());
    }

    /**
     * determine database changes made in sql install and/or update scripts
     */
    protected function getDatabaseChanges()
    {
        if (is_null($this->databaseChanges)) {
            $this->databaseChanges = array();
            $scripts = glob($this->extensionPath . '/app/code/*/*/*/sql/*/mysql*');
            foreach ($scripts as $script) {
                $setup = new Extension\Setup($script);
                $this->databaseChanges = array_merge_recursive($this->databaseChanges, $setup->getChanges());
            }
        }
        return $this->databaseChanges;
    }
}
