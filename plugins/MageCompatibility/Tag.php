<?php
namespace MageCompatibility;

use \dibi as dibi;

class Tag
{
    protected $config;

    protected function getFieldsToSelect()
    {
        return array(
            'CONCAT(edition, " ", version) AS magento',
            'ts.signature_id',
            'name',
            's.path',
            'definition'
        );
    }

    public function getMagentoVersions()
    {
        $this->connectTagDatabase();
        $query = 'SELECT ' . implode(', ', $this->getFieldsToSelect()) . '
            FROM [' . $this->table . '] t
            INNER JOIN [' . $this->tagType . '_signature] ts ON (t.id = ts.' . $this->tagType . '_id)
            INNER JOIN [signatures] s ON (ts.signature_id = s.id)
            INNER JOIN [magento_signature] ms ON (s.id = ms.signature_id)
            INNER JOIN [magento] m ON (ms.magento_id = m.id)
            WHERE t.name = %s
            GROUP BY s.id'
        ;
        try {
            $result = dibi::query($query, $this->name);
            $versions = array();
            if (1 < count($result)) {
                die(var_dump(__FILE__ . ' on line ' . __LINE__ . ':', $this->name, $result));
                return $versions;
                $result = $this->getBestMatching($result);
            }
            foreach ($result as $row) {
                $versions[] = $row->magento;
            }
            
            return $versions;
        } catch (\DibiDriverException $e) {
            die(var_dump(__FILE__ . ' on line ' . __LINE__ . ':', $e->getMessage()));
            dibi::test($query, $this->name);
            exit;
        }
    }

    public function setConfig($config)
    {
        $this->config = $config;
    }

    protected function connectTagDatabase()
    {
        $basedir = realpath(dirname(__FILE__) . '/../../');
        require_once $basedir . '/vendor/dg/dibi/dibi/dibi.php';
        if (false == dibi::isConnected()) {
            dibi::connect(array(
                //'driver'   => 'sqlite3',
                //'database' => $basedir . '/plugins/MageCompatibility/var/tags.sqlite'
                'driver'   => 'mysql',
                'username' => 'root',
                'database' => 'judge'
            ));
        }
    }
}
