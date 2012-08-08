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
            $result = dibi::query($query, $this->getName());
            $versions = array();
            if (1 < count($result)) {
                $result = $this->getBestMatching($result->fetchAll());
            }
if ('helper' == $this->name) die(var_dump(__FILE__ . ' on line ' . __LINE__ . ':', $result));
            if (is_null($result)) {
                return null;
            }
            $signatureId = $result->fetchSingle();
            if (false == $signatureId) {
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
            die(var_dump(__FILE__ . ' on line ' . __LINE__ . ':', $this));
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
if ('helper' == $this->name) var_dump(__FILE__ . ' on line ' . __LINE__ . ':', $candidates);
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
if ('helper' == $this->name) var_dump(__FILE__ . ' on line ' . __LINE__ . ':', $candidates, $this->context);
        return $candidates;
    }

    protected function filterByContext($candidates)
    {
        if (0 == count($this->context)) {
            return $candidates;
        }
        $query = 'SELECT s.path
            FROM [classes] c
            INNER JOIN [class_signature] cs ON (c.id = cs.class_id)
            INNER JOIN [signatures] s ON (s.id = cs.signature_id)
            WHERE c.name = %s';
        try {
            $result = dibi::query($query, $this->context);
            $path = $result->fetchSingle();
            if (false == $path) {
                return null;
            }
            die(var_dump(__FILE__ . ' on line ' . __LINE__ . ':', $path));
            die(var_dump(__FILE__ . ' on line ' . __LINE__ . ':', $candidates, $this->name, $this->params, $this->context));
            return $candidates;
        } catch (\DibiDriverException $e) {
            dibi::test($query, $this->context);
            die(var_dump(__FILE__ . ' on line ' . __LINE__ . ':', $this->context, $this->name));
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
