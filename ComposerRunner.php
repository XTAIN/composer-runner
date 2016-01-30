<?php
/**
 * This file is part of the XTAIN Composer Runner package.
 *
 * (c) Maximilian Ruta <mr@xtain.net>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace XTAIN\Composer\Runner;

use Symfony\Component\Process\Process;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\PhpExecutableFinder;
use Composer\Script\CommandEvent;

/**
 * Class ComposerRunner
 *
 * @author Maximilian Ruta <mr@xtain.net>
 * @package XTAIN\Composer\Runner
 */
class ComposerRunner
{
    /**
     * @const string
     */
    const COMPOSER_URL = 'https://getcomposer.org/installer';

    /**
     * @const int
     */
    const PROCESS_TIMEOUT = 5000;

    /**
     * @var string
     */
    protected $tempComposerPath;

    /**
     * @var string
     */
    protected $tempComposer;

    /**
     * Executes a Composer Command
     */
    public function execute($command, $arguments = array(), $output = null, $timeout = self::PROCESS_TIMEOUT)
    {
        if ($output === null) {
            $output = function($type, $buffer) {
                echo $buffer;
            };
        }

        $composer = $this->findComposer();

        $argumentString = '';
        foreach ($arguments as $argument) {
            $argumentString .= escapeshellarg($argument) . ' ';
        }

        $this->executeCommand($composer . ' ' . $command . ' ' . $argumentString, $output, $timeout);
    }

    protected function getComposerInstaller()
    {
        return file_get_contents(self::COMPOSER_URL);
    }

    protected function installComposer()
    {
        if (isset($this->tempComposer)) {
            return $this->tempComposer;
        }

        $filesystem = new Filesystem();
        $tmp = tempnam(sys_get_temp_dir(), "composer");
        if ($filesystem->exists($tmp)) {
            $filesystem->remove($tmp);
        }
        $filesystem->mkdir($tmp);
        $this->tempComposerPath = $tmp . DIRECTORY_SEPARATOR;

        $cwd = getcwd();

        try {
            file_put_contents($tmp . DIRECTORY_SEPARATOR . 'installer.php', $this->getComposerInstaller());
            chdir($tmp);
            $this->executeCommand('installer.php', false, self::PROCESS_TIMEOUT);

            if ($filesystem->exists($tmp . DIRECTORY_SEPARATOR . 'composer.phar')) {
                $this->tempComposer = $tmp . DIRECTORY_SEPARATOR . 'composer.phar';
            } else {
                throw new \Exception('Download failed');
            }
        } catch (\Exception $e) {
            $filesystem->remove($tmp);
        } catch (\Throwable $e) {
            $filesystem->remove($tmp);
        }

        chdir($cwd);

        if (!isset($this->tempComposer)) {
            return null;
        }

        return $this->tempComposer;
    }

    /**
     * @return string
     */
    public function findComposer()
    {
        $currentPath = realpath(getcwd());

        $finder = new ExecutableFinder();
        $finder->addSuffix('phar');

        $binary = $finder->find('composer', null, explode(DIRECTORY_SEPARATOR, $currentPath));

        if ($binary === null) {
            $binary = $this->installComposer();
        }

        if ($binary === null) {
            throw new \RuntimeException('Cannot find composer');
        }

        return $binary;
    }

    protected function executeCommand($cmd, $output = false, $timeout = 300)
    {
        $php = escapeshellarg(static::getPhp(false));
        $phpArgs = implode(' ', array_map('escapeshellarg', static::getPhpArguments()));

        $process = new Process($php.($phpArgs ? ' '.$phpArgs : '').' '.$cmd . ' --no-interaction', null, null, null, $timeout);
        if ($output) {
            $process->run($output);
        } else {
            $process->run();
        }
        if (!$process->isSuccessful()) {
            $this->cleanup();
            throw new \RuntimeException(sprintf('An error occurred when executing the "%s" command.', escapeshellarg($cmd)));
        }
        return $process;
    }

    protected static function getPhpArguments()
    {
        $arguments = array();

        $phpFinder = new PhpExecutableFinder();
        if (method_exists($phpFinder, 'findArguments')) {
            $arguments = $phpFinder->findArguments();
        }

        if (false !== $ini = php_ini_loaded_file()) {
            $arguments[] = '--php-ini='.$ini;
        }

        return $arguments;
    }

    protected static function getPhp($includeArgs = true)
    {
        $phpFinder = new PhpExecutableFinder();
        if (!$phpPath = $phpFinder->find($includeArgs)) {
            throw new \RuntimeException('The php executable could not be found, add it to your PATH environment variable and try again');
        }

        return $phpPath;
    }

    public function cleanup()
    {
        if (isset($this->tempComposerPath)) {
            $filesystem = new Filesystem();
            if ($filesystem->exists($this->tempComposerPath)) {
                $filesystem->remove($this->tempComposerPath);
            }
            $this->tempComposerPath = null;
            $this->tempComposer = null;
        }
    }

    public function __destruct()
    {
        $this->cleanup();
    }
}