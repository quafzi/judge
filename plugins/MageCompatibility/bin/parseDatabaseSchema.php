<?php
if (count($argv) < 3) {
    die('Call with ' . __FILE__ . ' {path to magento} {database name}' . PHP_EOL);
}
$pathToMagentoBaseDir = $argv[1];
$databaseName = $argv[2];
if (substr($pathToMagentoBaseDir, -1) != '/') {
    $pathToMagentoBaseDir .= '/';
}
if (false === file_exists($pathToMagentoBaseDir . '/app/Mage.php')) {
    die('Are you sure, there is a Magento? Couldn\'t find Mage.php!' . PHP_EOL);
}

$parser = new DatabaseParser($pathToMagentoBaseDir, $databaseName);
$parser->run();

class DatabaseParser
{
    protected $pathToMagentoBaseDir;
    protected $databaseName;
    protected $edition;
    protected $version;
    protected $basedir;
    protected $tagFile;
    protected $resourceModelNames;
    protected $classIdentifiers;

    public function __construct($pathToMagentoBaseDir, $databaseName)
    {
        $this->basedir = realpath(dirname(__FILE__) . '/../../../');
        require_once $this->basedir . '/vendor/dg/dibi/dibi/dibi.php';

        $this->databaseName = $databaseName;
        $this->pathToMagentoBaseDir = $pathToMagentoBaseDir;

        $this->verifyMagento($pathToMagentoBaseDir);
        $this->createJumpstormIni();
        $this->setUpEnv();

        dibi::connect(array(
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

        $this->version = Mage::getVersion();
        if (method_exists('Mage', 'getEdition')) {
            $this->edition = Mage::getEdition();
        } else {
            preg_match('/^1\.(\d+)\./', $this->version, $matches);
            $majorRelease = $matches[1];
            $this->edition = ($majorRelease < 7) ? 'Community' : 'Enterprise';
        }
        echo 'Analyzing Magento ' . $this->version . ' (' . $this->edition . ' Edition)...' . PHP_EOL;
    }

    protected function createJumpstormIni()
    {
        $config = file_get_contents($this->basedir . '/plugins/MageCompatibility/var/base.jumpstorm.ini');
        $branch = strtolower($this->edition) . '-' . $this->version;
        $branch = str_replace('community', 'magento', $branch);
        $this->jumpstormConfigFile = $this->basedir . '/plugins/MageCompatibility/var/tmp.jumpstorm.ini';
        $config = str_replace('###branch###', $branch, $config);
        $config = str_replace('###target###', $this->basedir . '/tmp/' . $branch, $config);
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
        $tables = $this->getTables();
        foreach ($tables as $class=>$tableName) {
            $this->writeMethodsForFlatTable($class, $tableName);
        }

        $eavEntities = $this->getEavEntities(array_keys($tables));
        foreach ($eavEntities as $class) {
            $this->addMethodsForEavAttributes($class, $tables[$class]);
        }
    }

    protected function writeMethodsForFlatTable($class, $tableName)
    {
        try {
            $fields = dibi::query('DESCRIBE [' . $tableName . ']');
            $this->writeMethodsForFields($class, $tableName, $fields);
        } catch (Exception $e) {
            // skip non-existing tables
        }
    }

    protected function writeMethodsForFields($class, $tableName, $fields)
    {
        $lines = array();
        foreach ($fields as $row) {
            $lines[] = $this->getTaglineForField($class, $tableName, $row->Field, 'get', '$value=null');
            $lines[] = $this->getTaglineForField($class, $tableName, $row->Field, 'set', '$value=null');
            $lines[] = $this->getTaglineForField($class, $tableName, $row->Field, 'uns', '$value=null');
            $lines[] = $this->getTaglineForField($class, $tableName, $row->Field, 'has', '$value=null');
        }
        file_put_contents($this->getTagFileName(), $lines, FILE_APPEND);
    }

    protected function getTaglineForField($class, $tableName, $fieldName, $prefix, $params='')
    {
        $camelCaseFieldName = str_replace(' ', '', ucwords(str_replace('_', ' ', $fieldName)));
        $methodName = $prefix . $camelCaseFieldName;
        $method = array(
            'name'     => $methodName,
            'path'     => "database[$class]",
            'codeLine' => "/^public function $methodName($params)$/;\"",
            'type'     => 'm',
            'line'     => 'line:[magic]'
        );
        return implode("\t", $method) . PHP_EOL;
    }

    /**
     * get an array of tables associated to the model they belong to
     * 
     * @return array (class => tableName)
     */
    protected function getTables()
    {
        $tables = array();
        $configFiles = $this->getConfigFilesWithTableDefinitions();
        foreach ($configFiles as $configFile) {
            $tables = array_merge($tables, $this->getTablesForConfig($configFile));
        }
        return $tables;
    }

    /**
     * get list of config.xml files containing a table definition
     * 
     * @return array
     */
    protected function getConfigFilesWithTableDefinitions()
    {
        $command = 'grep -rl -m1 --include "config.xml" "\<table>" ' . $this->pathToMagentoBaseDir;
        exec($command, $configFiles);
        return $configFiles;
    }

    /**
     * get an array of tables defined in a config.xml, associated to the model they belong to
     * 
     * @return array (class => tableName)
     */
    protected function getTablesForConfig($configFile)
    {
        $tables = array();
        $config = simplexml_load_file($configFile);
        $resourceModelNodes = $config->xpath('./global/models//resourceModel');
        foreach ($resourceModelNodes as $resourceModelNode) {
            $resourceModelNodeName = current($resourceModelNode->xpath('./text()'));
            $classPrefix = current($resourceModelNode->xpath('../class/text()'));
            $entityNodes = $config->xpath('./global/models/' . $resourceModelNodeName . '/entities/*');
            foreach ($entityNodes as $entityNode) {
                $classNameWithoutPrefix = str_replace(' ', '_', ucwords(str_replace('_', ' ', $entityNode->getName())));
                $className = $classPrefix . '_' . $classNameWithoutPrefix;
                $tableName = current($entityNode->xpath('./table/text()'));
                $tables[$className] = (string) $tableName;
                $resourcePrefix = current($entityNode->xpath('../../class/text()'));
                $this->resourceModelNames[$className] = $resourcePrefix . '_' . $classNameWithoutPrefix;
                $identifierPrefix = current($resourceModelNode->xpath('..'))->getName();
                $this->classIdentifiers[$className] = $identifierPrefix . '/' . $entityNode->getName();
            }
        }
        return $tables;
    }

    protected function setUpEnv()
    {
        echo 'Setting Up Magento environment via jumpström';
        $iniFile = $this->jumpstormConfigFile;
        $installMagentoCommand = 'magento -v -c ' . $iniFile;
        $executable = $this->basedir . '/vendor/netresearch/jumpstorm/jumpstorm';
        passthru(sprintf('%s %s', $executable, $installMagentoCommand));
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

    protected function addMethodsForEavAttributes($model, $table)
    {
        $query = 'SELECT attribute_code as Field
            FROM eav_attribute a
            JOIN eav_entity_type t ON t.entity_type_id = a.entity_type_id
            WHERE entity_model = %s';
        $this->writeMethodsForFields($model, $table, dibi::query($query, $this->classIdentifiers[$model]));
    }
}
