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
            if (is_null($result)) {
                return null;
            }
            if (is_array($result)) {
                if (0 < count($result)) {
                    $firstResult = current($result);
                    $signatureId = is_object($result) ? current($result)->signature_id : $firstResult;
                } else {
                    $signatureId = false;
                }
            } else {
                $signatureId = $result->fetchSingle();
            }
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
        if (false === array_key_exists('class', $this->context)) {
            return $candidates;
        }
        if (0 < count($candidates) && current($candidates)->class_id) {
            $classIds = array();
            foreach ($candidates as $key=>$candidate) {
                $classIds[$key] = $candidate->class_id;
            }
            $result = dibi::fetchPairs(
                'SELECT name, id FROM [classes] WHERE id IN (%s) AND name=%s',
                $classIds,
                $this->context['class']
            );
            return $result;
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
