<?php
namespace MageCompatibility\Extension;

class Config
{
    /**
     * get an array of tables associated to the model they belong to
     * 
     * @return array (class => tableName)
     */
    protected function getTables($path)
    {
        $tables = array();
        $configFiles = $this->getConfigFilesWithTableDefinitions($path);
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
    protected function getConfigFilesWithTableDefinitions($path)
    {
        $command = 'grep -rl -m1 --include "config.xml" "\<table>" ' . $path;
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
}
