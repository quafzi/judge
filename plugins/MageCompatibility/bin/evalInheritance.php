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
            SELECT t.id as classId, t.name as className, m.id as magentoId, TRIM(REPLACE(SUBSTR(definition, LOCATE("extends", definition)+8), "{", "")) as parentClassName, CONCAT_WS("-", m.edition, m.version) as mage
            FROM [classes] t
                JOIN [class_signature] ts ON ( t.id = ts.class_id )
                JOIN [signatures] s ON ( ts.signature_id = s.id)
                JOIN [magento_signature] ms ON ( s.id = ms.signature_id)
                JOIN [magento] m ON ( m.id = ms.magento_id)
            WHERE s.definition LIKE "% extends %"
            ';
        $classInheritance = dibi::fetchAll($query);

        foreach ($classInheritance as $inheritance) {
            $parent = $inheritance->parentClassName;
            $child  = $inheritance->className;
            $parent = preg_replace('/[^A-Za-z0-9_]/', '', $parent);

            $dotfile = $this->baseDir . '/graphs/inheritance_' . $inheritance->mage . '.dot';
            if (false == file_exists($dotfile)) {
                file_put_contents($dotfile, 'digraph G {' . PHP_EOL);
                $dotfiles[] = $dotfile;
            }
            if (false === array_key_exists($parent, $this->classes)) {
                $this->classes[$parent] = new Klass($parent);
                $this->parents[$parent] = $this->classes[$parent];
            }
            if (false === array_key_exists($child, $this->classes)) {
                $this->classes[$child] = new Klass($child);
            }
            $this->classes[$child]->setClassId($inheritance->classId);
            $this->classes[$child]->setMagentoId($inheritance->magentoId);

            /* remove child from main parent array, if a class inherits from another one */
            if (array_key_exists($child, $this->parents)) {
                unset($this->parents[$child]);
            }
            file_put_contents($dotfile, $parent . ' -> ' . $child . ';' . PHP_EOL, FILE_APPEND);
            $parentClass = $this->classes[$parent];
            $childClass  = $this->classes[$child];
            $parentClass->addChild($childClass);
        }
        foreach ($dotfiles as $dotfile) {
            file_put_contents($dotfile, '}', FILE_APPEND);
        }
        foreach($this->parents as $parent) {
            $this->saveInheritedMethods($parent);
        }
    }

    protected function saveInheritedMethods($class, $parentMethods=array())
    {
        $methods = dibi::query('
            SELECT s.id as signature_id, t.name as method
            FROM [methods] t
            JOIN [method_signature] ts ON (t.id = ts.method_id)
            JOIN [signatures] s ON (s.id = ts.signature_id)
            JOIN [magento_signature] ms ON (s.id = ms.signature_id)
            WHERE ms.magento_id = %d AND t.class_id = %d AND s.definition NOT LIKE "%private function%"
            ',
            $class->getMagentoId(),
            $class->getId()
        )->fetchPairs();
        foreach ($class->getChildren() as $child) {
            foreach ($methods as $method) {
                dibi::query(
                    'INSERT INTO [flat_method_inheritance] SET class_id = %d, signature_id = %d, magento_id = %d',
                    $child->getId(),
                    $method['signature_id'],
                    $class->getMagentoId()
                );
                $this->saveInheritedMethods($child, array_merge($methods, $parentMethods));
            }
        }
    }
}

class Klass
{
    protected $name;
    protected $classId;
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
        $this->children[] = $child;
    }

    public function setClassId($classId)
    {
        $this->classId = $classId;
    }

    public function getId()
    {
        return $this->classId;
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
