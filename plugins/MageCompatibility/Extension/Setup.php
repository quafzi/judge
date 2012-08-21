<?php
namespace MageCompatibility\Extension;

class Setup
{
    protected $changes=array(
        'add' => array()
    );

    protected $connection;

    public function __construct($file)
    {
        include $file;
    }

    public function startSetup()
    {
    }

    public function endSetup()
    {
    }

    public function run($queryClob)
    {
        $this->evaluateQueries($queryClob);
    }

    public function getChanges()
    {
        return array_merge($this->changes, $this->getConnection()->getChanges());
    }

    /**
     * get table name
     *
     * @param string $tableName Table name
     * @return string
     */
    public function getTable($tableName)
    {
        return $tableName;
    }

    public function getConnection()
    {
        if (is_null($this->connection)) {
            $this->connection = new Setup\Connection();
        }
        return $this->connection;
    }

    protected function evaluateQueries($queryClob)
    {
        $queries = $this->getQueries($queryClob);
        foreach ($queries as $query) {
            $query = trim($query);
            if (0 == strlen($query)) {
                continue;
            }
            preg_match('/CREATE TABLE( IF NOT EXISTS)? ([a-zA-Z0-9_]+) ?\((.+)\)/msi', $query, $matches);
            if (count($matches)) {
                $tableName   = $matches[2];
                $fieldDefinitions = explode(',', $matches[3]);
                $this->addCreateTable($tableName, $fieldDefinitions);
            }
        }
    }

    protected function addCreateTable($tableName, $fieldDefinitions)
    {
        foreach ($fieldDefinitions as $definition) {
            preg_match('/^`?([a-zA-Z0-9_]+)`? ([a-zA-Z]+)/ms', trim($definition), $nameMatches);
            if (0 < count($nameMatches)) {
                $fieldName = $nameMatches[1];
                $type = $nameMatches[2];
                if ('KEY' !== $type) {
                    $this->addField($tableName, $fieldName);
                }
            }
        }
    }

    protected function addField($table, $field)
    {
        if (false == array_key_exists($table, $this->changes['add'])) {
            $this->changes['add'][$table] = array();
        }
        $this->changes['add'][$table][] = $field;
    }

    protected function getQueries($rawQueries)
    {
        /* strip values to avoid wrong splitting if values contain ";" */
        $rawQueries = preg_replace('/".*"/U', '""', substr($rawQueries, 0, strlen($rawQueries)-1));
        $rawQueries = preg_replace('/\'.*\'/U', '\'\'', $rawQueries);

        return explode(';', $rawQueries);
    }
}
