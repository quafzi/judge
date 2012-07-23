<?php
namespace Netresearch;

use Symfony\Component\Console\Output\OutputInterface;
use \Exception as Exception;

/**
 * Simple logger
 */
class Logger
{
    const TYPE_COMMENT = 'comment';

    const TYPE_NOTICE = 'info';

    const TYPE_ERROR = 'error';

    const VERBOSITY_NONE   = 0;

    const VERBOSITY_MIN    = 1;

    const VERBOSITY_MEDIUM = 5;

    const VERBOSITY_MAX    = 10;

    protected static $verbosity = self::VERBOSITY_MEDIUM;

    protected static $output;

    protected static $results = array();

    public static function setOutputInterface(OutputInterface $output)
    {
        self::$output = $output;
    }

    public static function setVerbosity($verbosity)
    {
        self::$verbosity = $verbosity;
    }

    protected static function writeln($message, array $args = array(), $type = null)
    {
        if (self::VERBOSITY_NONE === self::$verbosity) {
            return;
        }
        if (self::VERBOSITY_MIN == self::$verbosity
            && self::TYPE_ERROR !== $type
        ) {
            return;
        }
        if (self::VERBOSITY_MEDIUM == self::$verbosity
            && self::TYPE_ERROR !== $type
            && self::TYPE_NOTICE !== $type
        ) {
            return;
        }

        if (!self::$output) {
            throw new Exception('No output interface given');
        }

        self::$output->writeln(
            is_null($type)
            ? vsprintf("$message", $args)
            : vsprintf("<$type>$message</$type>", $args)
        );
    }

    public static function log($message, array $args = array(), $type=null)
    {
        self::writeln($message, $args, $type);
    }

    public static function comment($message, array $args = array())
    {
        self::writeln($message, $args, self::TYPE_COMMENT);
    }

    public static function notice($message, array $args = array())
    {
        self::writeln($message, $args, self::TYPE_NOTICE);
    }

    public static function error($message, array $args = array(), $stopExecution = true)
    {
        self::writeln($message, $args, self::TYPE_ERROR);
        if ($stopExecution) {
            exit;
        }
    }

    public static function success($message, array $args = array())
    {
        self::notice($message, $args);
    }

    public static function warning($message, array $args = array())
    {
        self::comment($message, $args);
    }

    public static function addCheck($extension, $check, $range)
    {
        if (false == array_key_exists($extension, self::$results)) {
            self::$results[$extension] = array();
        }
        self::$results[$extension][$check] = array('range' => $range);
    }

    public static function setComments($extension, $check, $comments)
    {
        if (false == array_key_exists($extension, self::$results)) {
            self::$results[$extension] = array();
        }
        if (false == array_key_exists($check, self::$results[$extension])) {
            self::$results[$extension][$check] = array();
        }
        self::$results[$extension][$check]['comments'] = $comments;
    }
    public static function addComment($extension, $check, $comment)

    {
        if (false == array_key_exists($extension, self::$results)
            || false == array_key_exists($check, self::$results[$extension])
            || false == array_key_exists('comments', self::$results[$extension][$check])
        ) {
            self::$results[$extension][$check]['comments'] = array();
        }
        self::$results[$extension][$check]['comments'][] = $comment;
    }

    public static function setScore($extension, $check, $score)
    {
        if (false == array_key_exists($extension, self::$results)) {
            self::$results[$extension] = array();
        }
        if (false == array_key_exists($check, self::$results[$extension])) {
            self::$results[$extension][$check] = array();
        }
        $result = self::$results[$extension][$check];
        self::$results[$extension][$check]['result'] = $score;
        self::$results[$extension][$check]['failed'] = $score < array_sum($result['range'])/2;
    }

    /**
     * set a result value
     *
     * @param string $extension
     * @param string $check
     * @param string $name
     * @param mixed $value
     * @return void
     */
    public static function setResultValue($extension, $check, $name, $value)
    {
        if (false == array_key_exists($extension, self::$results)) {
            self::$results[$extension] = array();
        }
        if (false == array_key_exists($check, self::$results[$extension])) {
            self::$results[$extension][$check] = array();
        }
        if (false == array_key_exists('resultValue', self::$results[$extension][$check])) {
            self::$results[$extension][$check]['resultValue'] = array();
        }
        self::$results[$extension][$check]['resultValue'][$name] = $value;
    }

    public static function getScore($extension)
    {
        $score = 0;
        foreach (self::$results[$extension] as $result) {
            $score += $result['result'];
        }
        return $score;
    }

    public static function getFailedChecks($extension)
    {
        $failedChecks = array();
        foreach (self::$results[$extension] as $check=>$result) {
            if ($result['failed']) {
                $failedChecks[] = $check;
            }
        }
        return $failedChecks;
    }

    public static function getPassedChecks($extension)
    {
        $passedChecks = array();
        foreach (self::$results[$extension] as $check=>$result) {
            if (false == $result['failed']) {
                $passedChecks[] = $check;
            }
        }
        return $passedChecks;
    }

    public static function printResults($extension)
    {
        foreach (self::getFailedChecks($extension) as $failedCheck) {
            self::error('<comment>"%s" failed check "%s"</comment>', array($extension, $failedCheck), false);
            if (array_key_exists('comments', self::$results[$extension][$failedCheck])) {
                foreach (self::$results[$extension][$failedCheck]['comments'] as $comment) {
                    self::$output->writeln('* ' . $comment);
                }
            }
        }
        foreach (self::getPassedChecks($extension) as $passedCheck) {
            self::log('"%s" passed check "%s" with score %s', array($extension, $passedCheck, self::$results[$extension][$passedCheck]['result']));
            if (array_key_exists('comments', self::$results[$extension][$passedCheck])) {
                foreach (self::$results[$extension][$passedCheck]['comments'] as $comment) {
                    self::log('* ' . $comment);
                }
            }
        }
        $score = self::getScore($extension);
        if (0 < $score) {
            $message = sprintf('<info>Extension "%s" succeeded in evaluation: %d</info>', $extension, $score);
        } elseif (0 == $score) {
            $message = sprintf('<comment>Result of "%s" evaluation: %d</comment>', $extension, $score);
        } else {
            $message = sprintf('<error>Extension "%s" failed evaluation: %d</error>', $extension, $score);
        }
        self::$output->writeln($message);
    }

    /**
     * get results array
     *
     * @param string $extension 
     * @param string $check
     * @return array
     */
    public static function getResults($extension, $check=null)
    {
        if (is_null($check)) {
            return self::$results[$extension];
        }
        if (array_key_exists($check, self::$results[$extension])) {
            return self::$results[$extension][$check];
        }
    }
}
