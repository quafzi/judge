<?php
namespace MageCompatibility;

class Extension
{
    protected $extensionPath;

    protected $usedClasses;

    public function __construct($extensionPath)
    {
        $this->extensionPath = $extensionPath;
    }

    public function getUsedMagentoClasses()
    {
        $this->usedClasses = new Klasses();

        $extendsToken = 'extends';
        $extendedClassesRegexp = '/^class .* extends ([a-zA-Z0-9_].*)\W/mU';
        $this->addClassesByRegexp($extendsToken, $extendedClassesRegexp);

        $factoryTypes = array(
            'Block'         => 'app/code/*/*/*/Block',
            'Model'         => 'app/code/*/*/*/Model',
            'ResourceModel' => 'app/code/*/*/*/Model/Mysql4'
        );
        foreach ($factoryTypes as $factoryType=>$filePathPattern) {
            $factoryRegexp = '/Mage\W*::\W*get' . $factoryType . '\W*\(\W*["\'](.*)["|\']\"*\)/mU';
            $this->addClassesByRegexp($factoryType, $factoryRegexp, $filePathPattern);
        }
        die(var_dump(__FILE__ . ' on line ' . __LINE__ . ':', $this->usedClasses));

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
                if ($this->isExtensionClass($detailedMatches[1], $filePathPattern)) {
                    continue;
                }
                $this->usedClasses->add($this->getMagentoClassName($detailedMatches[1], $token));
            }
        }
    }

    protected function isUnitTestFile($filePath)
    {
        $filePath = str_replace($this->extensionPath, '', $filePath);
        return (0 < preg_match('~app/code/.*/.*/Test/~u', $filePath));
    }

    protected function isExtensionClass($identifier, $filePathPattern)
    {
        if (0 < preg_match('/^([a-zA-Z0-9]+_)+[a-zA-Z0-9]+$/', $identifier)) {
            /* we got a class name */
            $className = $identifier;
            $token = 'class ' . $className;
            $command = 'grep -rEl "' . $token . '" ' . $this->extensionPath . '/app';
            exec($command, $filesWithThatToken, $return);
        } else {
            list($extensionName, $class) = explode('/', $identifier);
            $classPathItems = explode('_', $class);
            foreach ($classPathItems as $pathItem) {
                $filePathPattern .= '/' . ucfirst($pathItem);
            }
            $filePathPattern .= '.php';
            $files = glob($this->extensionPath . '/' . $filePathPattern);
            return (0 < count($files));
        }
    }

    protected function getMagentoClassName($identifier, $type)
    {
        if (0 < preg_match('/^([a-zA-Z0-9]+_)+[a-zA-Z0-9]+$/', $identifier)) {
            return $identifier;
        }
        list($extensionName, $class) = explode('/', $identifier);
        $className = 'Mage_' . ucfirst($extensionName) . '_' . ucfirst($type);

        $classPathItems = explode('_', $class);
        foreach ($classPathItems as $pathItem) {
            $className .= '_' . ucfirst($pathItem);
        }
        return $className;
    }
}

