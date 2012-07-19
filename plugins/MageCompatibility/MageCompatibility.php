<?php
namespace MageCompatibility;

use Netresearch\Config;
use Netresearch\Logger;
use Netresearch\PluginInterface as JudgePlugin;

class MageCompatibility implements JudgePlugin
{
    const CRITICAL_DIRECTION_UP   = '<';
    const CRITICAL_DIRECTION_DOWN = '>';

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
            $changesCount = count($changes);
            if ($result['farestVersion'] == $oldestVersion) {
                break;
            }
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
            $changesCount = count($changes);
            if ($result['farestVersion'] == $latestVersion) {
                break;
            }
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
        
        return (int) $latest[1] - (int) $version[1] + 1;
    }

    protected function checkCompatibilityBefore($edition, $version, $extensionPath)
    {
        $result = $this->checkDiff(current(glob(__DIR__."/var/tagdiffs/$edition-*-$version.diff")), $extensionPath, self::CRITICAL_DIRECTION_DOWN);
        if ($result) {
            return $this->logChanges($result, $extensionPath, 'down');
        }
    }

    protected function checkCompatibilityAfter($edition, $version, $extensionPath)
    {
        $result = $this->checkDiff(current(glob(__DIR__."/var/tagdiffs/$edition-$version-*.diff")), $extensionPath, self::CRITICAL_DIRECTION_UP);
        if ($result) {
            return $this->logChanges($result, $extensionPath, 'up');
        }
    }

    protected function logChanges($result, $extensionPath, $direction)
    {
        $critical = $direction=='up' ? 'up'     : 'down';
        $previous = $direction=='up' ? 'lower'  : 'higher';
        $next     = $direction=='up' ? 'higher' : 'lower';

        $changes = $result['changes'];
        $edition = $result['edition'];
        $result['farestVersion'] = $result[$previous . 'Version'];
        $changesCount = count($changes);
        if (0 < $changesCount) {
            Logger::addComment(
                $extensionPath,
                $this->name,
                'Extension is compatible ' . $direction . ' to Magento ' . strtoupper($edition) . ' ' . $result['farestVersion']
            );
            Logger::addComment(
                $extensionPath,
                $this->name,
                'Found ' . $changesCount . ' possible incompatibilities ' . $direction . ' to ' . $result[$next . 'Version']
            );
            $changedTags = array();
            foreach ($changes as $change) {
                $tokenComment = '<comment>' . $change['token'] . '</comment> (changed at ' . $change['path'] . ')';
                $context = array();
                foreach ($change['files'] as $file=>$matchDetails) {
                    $context = array_unique(array_merge($context, $matchDetails['contextTokens']));
                }
                $contextComment = implode('", "', $context);
                Logger::addComment($extensionPath, $this->name, count($change['files']) . ' files calling ' . $tokenComment . ', with context "' . $contextComment . '"');
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

    protected function checkDiff($pathToDiff, $extensionPath, $criticalDirection)
    {
        if (false == $pathToDiff) {
            return null;
        }
        $filename = basename($pathToDiff);
        list($edition, $lower, $higher) = explode('-', str_replace('.diff', '', $filename));
        $result = array(
            'edition'       => $edition,
            'lowerVersion'  => $lower,
            'higherVersion' => $higher,
        );
        $versionToCheck = self::CRITICAL_DIRECTION_UP ? $lower : $higher;
        if ($this->versionIsSupported($edition, $versionToCheck)) {
            Logger::log("extension is known to be compatible to " . strtoupper($edition) . " version $versionToCheck");
            $result['changes'] = array();
            return $result;
        }
        Logger::log("checking compatibility change between " . strtoupper($edition) . " version $lower and $higher");
        $changes = array();
        $changesCount = 0;
        $fileHandle = fopen($pathToDiff, 'r');
        while ($line = trim(fgets($fileHandle))) {
            $direction = substr($line, 0, 1);
            if ($criticalDirection !== $direction) {
                continue;
            }
            list ($token, $path, $codeLine, $type) = explode("\t", substr($line, 2));
            if ('f' == $type) {
                $changes = array_merge(
                    $changes,
                    $this->findIncompatibleFunction($extensionPath, $direction, $token, $codeLine, $type, $path)
                );
            }
        }
        $result['changes'] = $changes;
        return $result;
    }

    protected function versionIsSupported($edition, $version)
    {
        foreach ($this->config->plugins->{$this->name}->supportedVersions as $supportedVersion) {
            if ($supportedVersion == $edition . '-' . $version) {
                return true;
            }
        }
        return false;
    }

    protected function findIncompatibleFunction($extensionPath, $direction, $token, $codeLine, $type, $path)
    {
        $changes = array();

        /* find extension files including a call of a method with that name */
        $command = 'grep -rEl "' . $token . '" ' . $extensionPath . '/app';
        exec($command, $filesWithThatToken, $return);

        if (0 == count($filesWithThatToken)) {
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
        $paramRegexp = "\->(\n|\W)*$token(\n|\W)*\(";
        $paramRegexp .= $this->getRegexpForParams($paramsCount['required'], $paramsCount['optional']);
        $paramRegexp .= "\)";

        $contextTokens = $this->getContextTokens($path, $token, $codeLine);

        $filesTouchedByChange = array();

        /* lets have a detailed look at the files we found */
        foreach ($filesWithThatToken as $filePath) {
            $content = file_get_contents($filePath);
            /* check if parameters count matches the changed call */
            preg_match('/' . $paramRegexp . '/mU', $content, $detailedMatches);

            if (count($detailedMatches)) {
                /* check if the context matches */
                $contextResult = $this->codeMatchesContext(
                    $this->getDefaultHeaderCleanedContent($content),
                    $contextTokens
                );
                if (false == $contextResult) {
                    continue;
                }
                $relativeFilePath = str_replace($extensionPath, '', $filePath);
                $filesTouchedByChange[$relativeFilePath] = array(
                    'count'               => count($contextResult['matches']),
                    'contextTokens'       => $contextResult['matches'],
                    'contextMatchDetails' => $contextResult['details']
                );
            }
        }
        if (0 < count($filesTouchedByChange)) {
            $changes[] = array(
                'type'          => 'f',
                'files'         => $filesTouchedByChange,
                'token'         => $call,
                'path'          => $path,
            );
        }
        return $changes;
    }

    protected function getDefaultHeaderCleanedContent($content)
    {
        $classPos = strpos($content, "\nclass ");
        return $classPos ? substr($content, $classPos) : $content;
    }

    protected function getContextTokens($path, $token, $codeLine)
    {
        $irrelevantParts = array(
            'abstract',
            'adminhtml',
            'app',
            'block',
            'class',
            'code',
            'collection',
            'config',
            'core',
            'eav',
            'helper',
            'lib',
            'mage',
            'model',
            'mysql4',
            'resource',
            'set',
            'source',
            'store',
            'system',
            'type',
            'varien',
            'zend'
        );
        $pathParts = explode('/', str_replace('.php' , '', strtolower($path)));
        return array_diff($pathParts, $irrelevantParts);
    }

    protected function codeMatchesContext($content, $contextTokens)
    {
        $contextMatches = array();
        $contextMatchDetails = array();
        foreach ($contextTokens as $contextToken) {
            if (strlen($contextToken) < 4) {
                continue;
            }
            $contextRegexp = '/[^a-z]' . $contextToken . '[^a-z]/i';
            preg_match($contextRegexp, $content, $tokenMatches);
            if (0 < count($tokenMatches)) {
                $contextMatchDetails[] = $tokenMatches;
                $contextMatches[] = $contextToken;
            }
        }
        if (0 < count($contextMatches)) {
            return array(
                'matches' => $contextMatches,
                'details' => $contextMatchDetails
            );
        }
        return false;
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
