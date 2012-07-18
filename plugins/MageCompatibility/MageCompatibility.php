<?php
namespace MageCompatibility;

use Netresearch\Config;
use Netresearch\Logger;
use Netresearch\PluginInterface as JudgePlugin;

class MageCompatibility implements JudgePlugin
{
    protected $config   = null;
    protected $name     = null;

    public function __construct(Config $config)
    {
        $this->config = $config;
        $this->name   = current(explode('\\', __CLASS__));
    }

    public function execute($extensionPath)
    {
        $settings = $this->config->plugins->{$this->name};

        list($edition, $versionNumber) = explode('-', $settings->start);

        $oldestVersion = $settings->start;
        $latestVersion = $settings->start;

        $changesCount = 0;
        while (0 == $changesCount) {
            $result = $this->checkCompatibilityBefore($edition, $versionNumber, $extensionPath);
            if (is_null($result)) {
                break;
            }
            $changes = $result['changes'];
            $changesCount = count($changes['upstream']);
            if (0 < $changesCount) {
                Logger::addComment(
                    $extensionPath,
                    $this->name,
                    'Extension is compatible down to Magento ' . strtoupper($edition) . ' ' . $result['higherVersion']
                );
            } else {
                $versionNumber = $result['lowerVersion'];
            }
        }
        $changesCount = 0;
        while (0 == $changesCount) {
            $result = $this->checkCompatibilityAfter($edition, $versionNumber, $extensionPath);
            if (is_null($result)) {
                break;
            }
            $changes = $result['changes'];
            $changesCount = count($changes['downstream']);
            if (0 < $changesCount) {
                Logger::addComment(
                    $extensionPath,
                    $this->name,
                    'Extension is compatible up to Magento ' . strtoupper($edition) . ' ' . $result['lowerVersion']
                );
            } else {
                $versionNumber = $result['higherVersion'];
            }
        }
        //TODO: FAIL if there are less than 3 latest versions supported
        //TODO: FAIL if the current version is not supported
        if (0 < $changesCount) {
            Logger::addComment(
                $extensionPath,
                $this->name,
                'Found ' . $changesCount . ' possible incompatibilities '
            );
            Logger::setScore($extensionPath, $this->name, $settings->bad);
            return $settings->bad;
        }
        Logger::setScore($extensionPath, $this->name, $settings->good);
        return $settings->good;
    }

    protected function checkCompatibilityBefore($edition, $version, $extensionPath)
    {
        return $this->checkDiff(current(glob(__DIR__."/var/tagdiffs/$edition-*-$version.diff")), $extensionPath);
    }

    protected function checkCompatibilityAfter($edition, $version, $extensionPath)
    {
        return $this->checkDiff(current(glob(__DIR__."/var/tagdiffs/$edition-$version-*.diff")), $extensionPath);
    }


    protected function checkDiff($pathToDiff, $extensionPath)
    {
        if (false == $pathToDiff) {
            return null;
        }
        $settings = $this->config->plugins->{$this->name};
        $filename = end(explode('/', $pathToDiff));
        list($edition, $lower, $higher) = explode('-', str_replace('.diff', '', $filename));
        $result = array(
            'lowerVersion'  => $lower,
            'higherVersion' => $higher,
        );
        Logger::log("checking compatibility change between $edition version $lower and $higher");
        $changes = array(
            'upstream'   => array(),
            'downstream' => array()
        );
        $changesCount = 0;
        $fileHandle = fopen($pathToDiff, 'r');
        while ($line = trim(fgets($fileHandle))) {
            $direction = substr($line, 0, 1);
            if (is_numeric($direction)) {
                continue;
            }
            if ('---' == $line) {
                continue;
            }
            $direction = $direction=='>' ? 'upstream' : 'downstream';
            list ($token, $path, $codeLine, $type) = explode("\t", substr($line, 2));
            if ('f' == $type) {
                $changes = array_merge_recursive(
                    $changes,
                    $this->findDeprecatedFunction($extensionPath, $direction, $token, $codeLine, $type)
                );
            }
        }
        $result['changes'] = $changes;
        return $result;
    }

    protected function findDeprecatedFunction($extensionPath, $direction, $token, $codeLine, $type)
    {
        $changes = array(
            'upstream'   => array(),
            'downstream' => array()
        );

        /* find extension files including a call of a method with that name */
        $command = 'grep -rEl "' . $token . '" ' . $extensionPath . '/app';
        exec($command, $usedInFile, $return);

        if (0 == count($usedInFile)) {
            return $changes;
        }

        /* analyze method params */
        preg_match('/function\W+(.*\))/', $codeLine, $matches);
        $call = $matches[1];
        $paramsCount = $this->getCountOfParams($call);

        /* look for changed function calls, regarding its params */
        $regexp = "\->(\n|\W)*$token(\n|\W)*\(";
        $regexp .= $this->getRegexpForParams($paramsCount['required'], $paramsCount['optional']);
        $regexp .= "\)";
        foreach ($usedInFile as $filePath) {
            $content = file_get_contents($filePath);
            preg_match('/' . $regexp . '/mU', $content, $detailedMatches);
            if (count($detailedMatches)) {
                $filePath = str_replace($extensionPath, '', $filePath);
                $changes[$direction][] = array(
                    'type'  => 'f',
                    'file'  => $filePath,
                    'count' => count($detailedMatches)
                );
            }
        }
        return $changes;
    }

    protected function getRegexpForParams($required, $optional)
    {
        $requiredParam = '[^,(]+(\(.*\))?';
        $optionalParam = '(,(\n|\W)*' . $requiredParam . ')?';

        $regexpRequiredParams = '';
        if (0 < $required) {
            $regexpRequiredParams = implode(',(\n|\W)*', array_fill(0, $required, $requiredParam));
        }
        $regexpOptionalParams = str_repeat($optionalParam, $optional);

        if (0 == $required && 0 < $optional) {
            /* strip first "," if there were no parameters before first optional parameter */
            $regexpOptionalParams = '(' . substr($regexpOptionalParams, 2);
        }

        $regexp = $regexpRequiredParams . $regexpOptionalParams;
    }

    protected function getCountOfParams($call)
    {
        $count = array(
            'required' => 0,
            'optional' => 0
        );
        preg_match('/(.*)\((.*)\)/', $call, $matches);
        list ($call, $method, $params) = $matches;

        if (strlen($params)) {
            $params = explode(', ', $params);
            $countOfRequiredParams = 0;
            $countOfOptionalParams = 0;
            foreach ($params as $param) {
                if (false !== strpos($param, '=')) {
                    $count['required']++;
                } else {
                    $count['optional']++;
                }
            }
        }
        return $count;
    }
}
