<?php
namespace MageCompatibility;

use Netresearch\Logger;

use \dibi as dibi;

class Tag
{
    protected $config;

    protected function getFieldsToSelect()
    {
        return array(
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
            WHERE t.name = %s
            GROUP BY s.id'
        ;
        try {
            $result = dibi::query($query, $this->name);
            $versions = array();
            if (1 < count($result)) {
                $result = $this->getBestMatching($result->fetchAll());
                die(var_dump(__FILE__ . ' on line ' . __LINE__ . ':', $result));
            }
            $signatureId = $result->fetchSingle();
            if ($signatureId) {
                Logger::warning('Could not find any matching definition of ' . $this->name);
                return array();
            }

            $query = 'SELECT CONCAT(edition, " ", version) AS magento
                FROM [magento_signature] ms
                INNER JOIN [magento] m ON (ms.magento_id = m.id)
                WHERE ms.signature_id = %s'
            ;
            return dibi::fetchPairs($query, $signatureId);
        } catch (\DibiDriverException $e) {
            die(var_dump(__FILE__ . ' on line ' . __LINE__ . ':', $e->getMessage()));
            dibi::test($query, $this->name);
            exit;
        }
    }

    protected function getBestMatching($candidates)
    {
        $candidates = $this->filterByParamCount($candidates);
        $candidates = $this->filterByContext($candidates);
        return $candidates;
    }

    protected function filterByParamCount($candidates)
    {
        foreach ($candidates as $key => $candidate) {
            $givenParamsCount = count($this->params);
            $minParamsCount = $candidate->required_params_count;
            $maxParamsCount = $candidate->required_params_count + $candidate->optional_params_count;
            if ($givenParamsCount < $minParamsCount
                || $maxParamsCount < $givenParamsCount
            ) {
                unset($candidates[$key]);
            }
        }
        return $candidates;
    }

    protected function filterByContext($candidates)
    {
        return $candidates;
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
