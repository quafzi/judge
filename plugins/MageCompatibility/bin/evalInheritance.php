<?php
$basedir = realpath(dirname(__FILE__) . '/../../../');
require_once $basedir . '/vendor/dg/dibi/dibi/dibi.php';
dibi::connect(array(
    //'driver'   => 'sqlite3',
    //'database' => $basedir . '/plugins/MageCompatibility/var/tags.sqlite'
    'driver'   => 'mysql',
    'username' => 'root',
    'database' => 'judge'
));

$evaluator = new InheritanceEvaluator();
$evaluator->setBaseDir($basedir);
$evaluator->run();

class InheritanceEvaluator
{
    protected $classes = array();
    protected $parents = array();
    protected $magento = array();
    protected $baseDir;

    public function setBaseDir($baseDir)
    {
        $this->baseDir = $baseDir;
    }

    public function run()
    {
        if (false == file_exists($this->baseDir . '/graphs')) {
            mkdir($this->baseDir . '/graphs');
        }
        $dotfiles = array();
        $query = '
            SELECT
                t.id as classId,
                t.name as className,
                m.id as magentoId,
                IF(LOCATE("extends", definition), TRIM(REPLACE(SUBSTR(definition, LOCATE("extends", definition)+8), "{", "")), NULL) as parentClassName,
                s.definition,
                CONCAT_WS("-", m.edition, m.version) as mage
            FROM [classes] t
                JOIN [class_signature] ts ON ( t.id = ts.class_id )
                JOIN [signatures] s ON ( ts.signature_id = s.id)
                JOIN [magento_signature] ms ON ( s.id = ms.signature_id)
                JOIN [magento] m ON ( m.id = ms.magento_id)
            ';
        $classInheritance = dibi::fetchAll($query);

        foreach ($classInheritance as $inheritance) {
            $childName  = $inheritance->className;
            $parentName = $inheritance->parentClassName;
            $mage       = $inheritance->mage;

            if (false === array_key_exists($inheritance->magentoId, $this->magento)) {
                $this->classes[$inheritance->magentoId] = $mage;
            }
            if (false === array_key_exists($mage, $this->classes)) {
                $this->classes[$mage] = array();
            }
            if (false === array_key_exists($mage, $this->parents)) {
                $this->parents[$mage] = array();
            }
            if (false === array_key_exists($childName, $this->classes)) {
                $this->classes[$mage][$childName] = new Klass($childName);
            }
            $this->classes[$mage][$childName]->setId($inheritance->classId);
            $this->classes[$mage][$childName]->setMagentoId($inheritance->magentoId);
            $this->classes[$mage][$childName]->setDefinition($inheritance->definition);

            /* remove child from main parent array, if a class inherits from another one */
            if (array_key_exists($childName, $this->parents)) {
                unset($this->parents[$childName]);
            }

            $parentName = trim(preg_replace('/implements.*$/s', '', $parentName));
            $parentName = trim(preg_replace('/[^A-Za-z0-9_]/', '', $parentName));
            if (0 < strlen($parentName)) {

                $dotfile = $this->baseDir . '/graphs/inheritance_' . $inheritance->mage . '.dot';
                if (false == file_exists($dotfile)) {
                    file_put_contents($dotfile, 'digraph G {' . PHP_EOL);
                    $dotfiles[] = $dotfile;
                }
                if (false === array_key_exists($parentName, $this->classes[$mage])) {
                    $this->classes[$mage][$parentName] = new Klass($parentName);
                    $this->classes[$mage][$parentName]->setDefinition($inheritance->parentClassName);
                    $this->parents[$mage][$parentName] = $this->classes[$mage][$parentName];
                }
                file_put_contents($dotfile, $parentName . ' -> ' . $childName . ';' . PHP_EOL, FILE_APPEND);
                $parentClass = $this->classes[$mage][$parentName];
                $childClass  = $this->classes[$mage][$childName];
                $parentClass->addChild($childClass);
            }
        }
        foreach ($dotfiles as $dotfile) {
            file_put_contents($dotfile, '}', FILE_APPEND);
        }
        foreach($this->parents as $mage=>$parents) {
            foreach($parents as $parent) {
                $this->saveInheritedMethods($parent);
            }
        }
    }

    protected function saveInheritedMethods($class, $parentMethods=array())
    {
        $methods = array();
        if (false == $this->isBuiltinClass($class->getName())) {
            if (is_null($class->getId())) {
                echo "Found {$class->getName()} to be parent class for {$class->getChildrenCount()} classes, but it does not exist at all!" . php_eol;
                foreach ($class->getchildren() as $child) {
                    echo "* {$child->getName()} in magento {$this->magento[$class->getmagentoid()]}" . PHP_EOL;
                }
                return;
            }

            $methods = dibi::query('
                SELECT s.id as signature_id, t.name as method
                FROM [methods] t
                JOIN [method_signature] ts ON (t.id = ts.method_id)
                JOIN [signatures] s ON (s.id = ts.signature_id)
                JOIN [magento_signature] ms ON (s.id = ms.signature_id)
                WHERE ms.magento_id = ? AND t.class_id = ? AND s.definition NOT LIKE "%private function%"
                ',
                $class->getMagentoId(),
                $class->getId()
            )->fetchPairs();
        } else {
            echo "Skip builtin class {$class->getName()}" . PHP_EOL;
        }

        foreach ($class->getChildren() as $child) {
            foreach ($methods as $signatureId=>$methodName) {
                dibi::query(
                    'INSERT INTO [flat_method_inheritance] SET class_id = ?, signature_id = ?, magento_id = ?',
                    $child->getId(),
                    $signatureId,
                    $class->getMagentoId()
                );
            }
            $this->saveInheritedMethods($child, array_merge($methods, $parentMethods));
        }
    }

    protected function isBuiltinClass($name)
    {
        $builtinClasses = array(
            'ArrayObject',
            'Countable',
            'Exception',
            'FilterIterator',
            'IteratorCountable',
            'LimitIterator',
            'RecursiveFilterIterator',
            'RecursiveIterator',
            'ReflectionClass',
            'ReflectionExtension',
            'ReflectionMethod',
            'ReflectionFunction',
            'ReflectionParameter',
            'ReflectionProperty',
            'Serializable',
            'SimpleXMLElement',
            'SoapClient',
            'SplFileObject',
        );
        if (in_array($name, $builtinClasses)) {
            return true;
        }
        if (0 === strpos($name, 'PHPUnit_')) {
            return true;
        }
        return false;
    }
}

class Klass
{
    protected $name;
    protected $definition;
    protected $id;
    protected $magentoId;
    protected $children=array();

    public function __construct($name)
    {
        $this->name = $name;
    }

    public function getName()
    {
        return $this->name;
    }

    public function addChild(Klass $child)
    {
        $this->children[$child->getName()] = $child;
    }

    public function setId($id)
    {
        $this->id = $id;
    }

    public function setDefinition($definition)
    {
        $this->definition = $definition;
    }

    public function getId()
    {
        return $this->id;
    }

    public function setMagentoId($magentoId)
    {
        $this->magentoId = $magentoId;
    }

    public function getMagentoId()
    {
        return $this->magentoId;
    }

    public function getChildren()
    {
        return $this->children;
    }

    public function getChildrenCount()
    {
        $count = count($this->children);
        foreach ($this->children as $child) {
            $count += $child->getChildrenCount();
        }
        return $count;
    }
}
