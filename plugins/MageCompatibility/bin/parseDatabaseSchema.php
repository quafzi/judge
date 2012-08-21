<?php
namespace MageCompatibility;

use MageCompatibility\Extension\Config;
use MageCompatibility\DatabaseParser;

include realpath(dirname(__FILE__) . '/../Extension/Config.php');

if (count($argv) < 3) {
    die('Call with ' . __FILE__ . ' {path to magento} {database name}' . PHP_EOL);
}
$branch = $argv[1];
$databaseName = $argv[2];

class DatabaseParser extends Config
{
    protected $pathToMagentoBaseDir;
    protected $databaseName;
    protected $edition;
    protected $version;
    protected $basedir;
    protected $tagFile;
    protected $resourceModelNames;
    protected $classIdentifiers;

    public function __construct($branch, $databaseName)
    {
        $this->basedir = realpath(dirname(__FILE__) . '/../../../');
        require_once $this->basedir . '/vendor/dg/dibi/dibi/dibi.php';

        $this->databaseName = $databaseName;

        $this->createJumpstormIni($branch);
        $this->setUpEnv();
        $this->verifyMagento($this->pathToMagentoBaseDir);

        \dibi::connect(array(
            //'driver'   => 'sqlite3',
            //'database' => $basedir . '/plugins/MageCompatibility/var/tags.sqlite'
            'driver'   => 'mysql',
            'username' => 'root',
            'database' => $this->databaseName
        ));
        file_put_contents($this->getTagFileName(), '');
    }

    protected function verifyMagento($pathToMagentoBaseDir)
    {
        include $pathToMagentoBaseDir . 'app/Mage.php';

        $this->version = \Mage::getVersion();
        if (method_exists('Mage', 'getEdition')) {
            $this->edition = \Mage::getEdition();
        } else {
            preg_match('/^1\.(\d+)\./', $this->version, $matches);
            $majorRelease = $matches[1];
            $this->edition = ($majorRelease < 7) ? 'Community' : 'Enterprise';
        }
        echo 'Analyzing Magento ' . $this->version . ' (' . $this->edition . ' Edition)...' . PHP_EOL;
    }

    protected function createJumpstormIni($branch)
    {
        $config = file_get_contents($this->basedir . '/plugins/MageCompatibility/var/base.jumpstorm.ini');
        $this->jumpstormConfigFile = $this->basedir . '/plugins/MageCompatibility/var/tmp.jumpstorm.ini';
        $config = str_replace('###branch###', $branch, $config);
        $config = str_replace('###target###', $this->basedir . '/tmp/' . $branch, $config);
        $this->pathToMagentoBaseDir = $this->basedir . '/tmp/' . $branch . '/';
        $config = str_replace('###database###', $this->databaseName, $config);
        file_put_contents($this->jumpstormConfigFile, $config);
    }

    protected function getTagFileName()
    {
        return $this->basedir . '/plugins/MageCompatibility/var/tags/'
            . strtolower($this->edition) . 'Database-' . $this->version . '.tags';
    }

    public function run()
    {
        $tables = $this->getTables($this->pathToMagentoBaseDir);
        foreach ($tables as $class=>$tableName) {
            $this->writeMethodsForFlatTable($class, $tableName);
        }

        $eavEntities = $this->getEavEntities(array_keys($tables));
        foreach ($eavEntities as $class) {
            $this->writeMethodsForEavAttributes($class, $tables[$class]);
        }
    }

    protected function writeMethodsForFlatTable($class, $tableName)
    {
        try {
            $fields = \dibi::query('DESCRIBE [' . $tableName . ']');
            $this->writeMethodsForFields($class, $tableName, $fields, 'flat');
        } catch (\Exception $e) {
            // skip non-existing tables
        }
    }

    protected function writeMethodsForEavAttributes($model, $table)
    {
        $query = 'SELECT attribute_code as Field
            FROM eav_attribute a
            JOIN eav_entity_type t ON t.entity_type_id = a.entity_type_id
            WHERE entity_model = %s';
        $this->writeMethodsForFields($model, $table, \dibi::query($query, $this->classIdentifiers[$model]), 'eav');
    }

    protected function writeMethodsForFields($class, $tableName, $fields, $type)
    {
        $lines = array();
        foreach ($fields as $row) {
            $lines[] = $this->getTaglineForField($class, $tableName, $row->Field, $type, 'get', '$value=null');
            $lines[] = $this->getTaglineForField($class, $tableName, $row->Field, $type, 'set', '$value=null');
            $lines[] = $this->getTaglineForField($class, $tableName, $row->Field, $type, 'uns', '$value=null');
            $lines[] = $this->getTaglineForField($class, $tableName, $row->Field, $type, 'has', '$value=null');
        }
        file_put_contents($this->getTagFileName(), $lines, FILE_APPEND);
    }

    protected function getTaglineForField($class, $tableName, $fieldName, $type, $prefix, $params='')
    {
        $camelCaseFieldName = str_replace(' ', '', ucwords(str_replace('_', ' ', $fieldName)));
        $methodName = $prefix . $camelCaseFieldName;
        $method = array(
            'name'     => $methodName,
            'path'     => "database[$class/$fieldName/$type/$tableName]",
            'codeLine' => "/^public function $methodName($params)$/;\"",
            'type'     => 'f',
            'line'     => 'line:[magic]'
        );
        return implode("\t", $method) . PHP_EOL;
    }

    protected function setUpEnv()
    {
        echo 'Setting Up Magento environment via jumpström' . PHP_EOL;
        $iniFile = $this->jumpstormConfigFile;
        $installMagentoCommand = 'magento -v -c ' . $iniFile;
        $executable = $this->basedir . '/vendor/netresearch/jumpstorm/jumpstorm';
        passthru(sprintf('%s %s', $executable, $installMagentoCommand), $error);
        if ($error) {
            die('Installation failed!');
        }
    }

    protected function getEavEntities($models)
    {
        $eavModels = array();
        foreach ($models as $model) {
            if (false === array_key_exists($model, $this->resourceModelNames)) {
                continue;
            }
            $resourceModel = $this->resourceModelNames[$model];
            if ($this->isEavModel($resourceModel)) {
                echo "* EAV: $model\n";
                $eavModels[] = $model;
            }
        }
        return $eavModels;
    }

    protected function isEavModel($model) {
        $command = sprintf('grep -rEzoh "%s extends \w+" %s/app/code/core/', $model, $this->pathToMagentoBaseDir);
        exec($command, $output, $noMatch);
        if ($noMatch) {
            return false;
        }
        list($class, $parentClass) = explode('extends', current($output));
        $parentClass = trim($parentClass);
        if ('Mage_Core_Model_Mysql4_Abstract' == $parentClass) {
            return false;
        }
        if ('Mage_Eav_Model_Entity_Abstract' == $parentClass) {
            return true;
        }
        return $this->isEavModel($parentClass);
    }
}

$parser = new DatabaseParser($branch, $databaseName);
$parser->run();
