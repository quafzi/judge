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

    /**
     * determine which Magento versions seem to be compatible to this call
     * 
     * @return array Array of Magento versions (e.g. [ 'CE 1.6.2.0', 'CE 1.7.0.2' ])
     */
    public function getMagentoVersions()
    {
        $query = 'SELECT ' . implode(', ', $this->getFieldsToSelect()) . '
            FROM [' . $this->table . '] t
            INNER JOIN [' . $this->tagType . '_signature] ts ON (t.id = ts.' . $this->tagType . '_id)
            INNER JOIN [signatures] s ON (ts.signature_id = s.id)
            WHERE t.name = %s
            GROUP BY s.id'
            ;
        try {
            /* find all signatures matching that call */
            $result = dibi::query($query, $this->getName());
        } catch (\DibiDriverException $e) {
            dibi::test($query, $this->getName());
            //die(var_dump(__FILE__ . ' on line ' . __LINE__ . ':', $this, $e->getMessage(), $query));
            exit;
        }
        $versions = array();
        if (is_null($result)) {
            return $versions;
        }

        /* get best matching signature id */
        if (1 < count($result)) {
            $signatureIds = $this->getBestMatching($result->fetchAll());
        } else {
            try {
                $signatureIds = array_keys($result->fetchPairs());
            } catch (\DibiDriverException $e) {
                dibi::test($query, $signatureId);
                exit;
            }
        }
        if (false == is_array($signatureIds) || 0 == count($signatureIds)) {
            Logger::warning('Could not find any matching definition of ' . $this->name);
            return array();
        }

        /* fetch Magento versions */
        $query = 'SELECT CONCAT(edition, " ", version) AS magento
            FROM [magento_signature] ms
            INNER JOIN [magento] m ON (ms.magento_id = m.id)
            WHERE ms.signature_id IN (%s)
            GROUP BY magento'
            ;
        try {
            return dibi::fetchPairs($query, $signatureIds);
        } catch (\DibiDriverException $e) {
            dibi::test($query, $signatureId);
            //die(var_dump(__FILE__ . ' on line ' . __LINE__ . ':', $this, $e->getMessage(), $query));
            exit;
        }
    }

    /**
     * determine best matching signature
     * 
     * @param array $candidates Array of DibiRows
     * @return array
     */
    protected function getBestMatching($candidates)
    {
        $candidates = $this->filterByParamCount($candidates);
        $candidates = $this->filterByContext($candidates);
        $signatureIds = array();
        foreach ($candidates as $candidate) {
            $signatureIds[] = $candidate->signature_id;
        }
        return $signatureIds;
    }

    /**
     * determine signatures matching the given param count
     * 
     * @param array $candidates Array of DibiRows
     * @return array
     */
    protected function filterByParamCount($candidates)
    {
        $infiniteParamsMethods = array(
            'Mage_Core_Helper_Abstract' => array(
                '__'
            )
        );
        foreach ($infiniteParamsMethods as $class=>$methods) {
            if (in_array($this->getName(), $methods)) {
                return $candidates;
            }
        }
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

    /**
     * determine signatures with the same context
     * currently we only check if the class we detected matches the signature
     * 
     * @param array $candidates Array of DibiRows
     * @return array
     */
    protected function filterByContext($candidates)
    {
        if (false === array_key_exists('class', $this->context)
            && 0 < strlen($this->context['class']
            && Method::TYPE_MIXED !== $this->context['class']
            && 0 < count($candidates)
            && current($candidates)->class_id)
        ) {
            $classIds = array();
            $query = 'SELECT name, id FROM [classes] WHERE id IN (%s) AND name=%s';
            foreach ($candidates as $key=>$candidate) {
                $classIds[$key] = $candidate->class_id;
            }
            try {
                $result = dibi::fetchPairs(
                    $query,
                    $classIds,
                    $this->context['class']
                );
            } catch (\DibiDriverException $e) {
                dibi::test(
                    $query,
                    $classIds,
                    $this->context['class']
                );
                throw $e;
            }
            $contextMatchingCandidates = $candidates;
            foreach ($candidates as $key=>$candidate) {
                if (false == in_array($candidate->class_id, $classIds)) {
                    unset($contextMatchingCandidates[$key]);
                }
            }
            return count($contextMatchingCandidates) ? $contextMatchingCandidates : $candidates;
        }
        return $candidates;
    }

    public function setConfig($config)
    {
        $this->config = $config;
    }
}
