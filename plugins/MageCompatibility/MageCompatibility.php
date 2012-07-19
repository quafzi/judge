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

        Logger::log("checking compatibility starting from $edition version $versionNumber");

        $oldestVersion = $versionNumber;
        $latestVersion = $versionNumber;

        /* check backward compatibility */
        $changesCount = 0;
        while (0 == $changesCount) {
            $result = $this->checkCompatibilityBefore($edition, $oldestVersion, $extensionPath);
            if (empty($result) || false == array_key_exists('changes', $result)) {
                break;
            }
            $changes = $result['changes'];
            $changesCount = count($changes['upstream']);
            $oldestVersion = $result['farestVersion'];
        }
        /* check forward compatibility (up to latest Magento version) */
        $changesCount = 0;
        while (0 == $changesCount) {
            $result = $this->checkCompatibilityAfter($edition, $latestVersion, $extensionPath);
            if (empty($result) || false == array_key_exists('changes', $result)) {
                break;
            }
            $changes = $result['changes'];
            $changesCount = count($changes['downstream']);
            $latestVersion = $result['farestVersion'];
        }

        /* summarize compatibility */
        Logger::addComment(
            $extensionPath,
            $this->name,
            '<info>Extension is compatible to Magento ' . strtoupper($edition) . ' ' . $oldestVersion . ' - ' . $latestVersion . '</info>'
        );

        /* fail if latest version is not supported */
        if ($settings->latest->ce != $latestVersion) {
            Logger::addComment(
                $extensionPath,
                $this->name,
                sprintf('<comment>Latest Magento version %s seems not to be supported (only up to %s)</comment>', $settings->latest->ce, $latestVersion)
            );
            Logger::setScore($extensionPath, $this->name, $settings->bad);
            return $settings->bad;
        }

        /* fail if less than 3 latest major versions are supported */
        $versionAge = $this->getVersionAge($edition, $oldestVersion);
        if ($versionAge < $settings->minBackwardRange) {
            Logger::addComment(
                $extensionPath,
                $this->name,
                sprintf('<comment>Only the latest %s major releases seem to be supported (down to %s)</comment>', $versionAge, $oldestVersion)
            );
            Logger::setScore($extensionPath, $this->name, $settings->bad);
            return $settings->bad;
        }

        Logger::setScore($extensionPath, $this->name, $settings->good);
        return $settings->good;
    }

    protected function getVersionAge($edition, $version)
    {
        /* we only care about the x in 1.x.y */
        $latest = $this->config->plugins->{$this->name}->latest->$edition;
        $latest = explode('.', $latest);

        $version = explode('.', $version);
        
        return (int) $version[1] - (int) $version[1] + 1;
    }

    protected function checkCompatibilityBefore($edition, $version, $extensionPath)
    {
        $result = $this->checkDiff(current(glob(__DIR__."/var/tagdiffs/$edition-*-$version.diff")), $extensionPath);
        if ($result) {
            return $this->logChanges(
                $result,
                $extensionPath,
                'down'
            );
        }
    }

    protected function checkCompatibilityAfter($edition, $version, $extensionPath)
    {
        $result = $this->checkDiff(current(glob(__DIR__."/var/tagdiffs/$edition-$version-*.diff")), $extensionPath);
        if ($result) {
            return $this->logChanges(
                $result,
                $extensionPath,
                'up'
            );
        }
    }

    protected function logChanges($result, $extensionPath, $direction)
    {
        $critical = $direction=='up' ? 'up'     : 'down';
        $previous = $direction=='up' ? 'higher' : 'lower';
        $next     = $direction=='up' ? 'lower'  : 'higher';

        $changes = $result['changes'];
        $edition = $result['edition'];
        $result['farestVersion'] = $result[$previous . 'Version'];
        $changesCount = count($changes[$critical . 'stream']);
        if (!$edition) {
            die(var_dump(__FILE__ . ' on line ' . __LINE__ . ':', $result, $direction));
        }
        if (0 < $changesCount) {
            Logger::addComment(
                $extensionPath,
                $this->name,
                'Extension is compatible ' . $direction . ' to Magento ' . strtoupper($edition) . ' ' . $result['farestVersion']
            );
            Logger::addComment(
                $extensionPath,
                $this->name,
                'Found ' . $changesCount . ' possible incompatibilities to ' . $result[$next . 'Version']
            );
            $changedTags = array();
            foreach ($changes[$critical . 'stream'] as $change) {
                $changedTag = '<comment>' . $change['token'] . '</comment> (changed at ' . $change['path'] . ')';
                if (array_key_exists($changedTag, $changedTags)) {
                    $changedTags[$changedTag]++;
                } else {
                    $changedTags[$changedTag] = 1;
                }
            }
            foreach ($changedTags as $tag=>$count) {
                Logger::addComment($extensionPath, $this->name, $count . '* ' . $tag);
            }
        } else {
            $result['farestVersion'] = $result[$next . 'Version'];
            Logger::addComment(
                $extensionPath,
                $this->name,
                'Extension is compatible to Magento ' . strtoupper($edition) . ' ' . $result['farestVersion']
            );
        }
        return $result;
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
            'edition'       => $edition,
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
                    $this->findDeprecatedFunction($extensionPath, $direction, $token, $codeLine, $type, $path)
                );
            }
        }
        $result['changes'] = $changes;
        return $result;
    }

    protected function findDeprecatedFunction($extensionPath, $direction, $token, $codeLine, $type, $path)
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
        if (count($matches) < 2) {
            /* we assume no change if method call does not end up with a closing parenthesis */
            return $changes;
        }
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
                    'token' => $call,
                    'path'  => $path,
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
