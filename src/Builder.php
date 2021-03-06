<?php declare(strict_types=1);
/**
 * @author   Fung Wing Kit <wengee@gmail.com>
 * @version  2020-03-30 11:42:07 +0800
 */

namespace fwkit\PharBuilder;

use ArrayIterator;
use Phar;

class Builder
{
    protected $phar;

    protected $basePath;

    protected $options;

    public function __construct(string $basePath, array $options = [])
    {
        $this->basePath = $basePath;
        $this->options = $options + [
            'dist'          => $this->joinPaths($basePath, 'dist'),
            'main'          => 'index.php',
            'output'        => 'app.phar',
            'directories'   => [],
            'files'         => [],
            'rules'         => [],
            'ignore'        => [],
            'stub'          => null,
            'copy'          => [],
            'compress'      => 'none',
            'extensions'    => [],
            'clear'         => false,
        ];

        if (isset($this->options['exclude'])) {
            $this->options['ignore'] = $this->options['exclude'];
        }
    }

    public static function build(string $basePath, array $options = [])
    {
        return (new static($basePath, $options))->run();
    }

    public function run()
    {
        if (!$this->options['directories'] && !$this->options['files']) {
            return false;
        }

        if (!file_exists($this->options['dist'])) {
            mkdir($this->options['dist'], 0755);
        }

        if ($this->options['clear']) {
            Utils::clearDir($this->options['dist']);
        }

        $s = microtime(true);
        $files = $this->addFiles();
        if ($this->options['output']) {
            $this->makePhar($files);
        } else {
            $this->makeCopy($files);
        }
        
        if ($this->options['copy']) {
            $this->copyFiles($this->options['copy']);
        }
        $elapsed = sprintf('%.3f', microtime(true) - $s);
        echo "Elapsed time: {$elapsed}s" . PHP_EOL;
    }

    protected function makeCopy(ArrayIterator $files): void
    {
        foreach ($files as $key => $source) {
            $dest = $this->joinPaths($this->options['dist'], $key);
            Utils::xcopy($source, $dest);
        }

        $filesTotal = $files->count();
        echo "Copy finished, Total files: {$filesTotal}, ";
    }

    protected function makePhar(ArrayIterator $files): void
    {
        $pharFile = $this->joinPaths($this->options['dist'], $this->options['output']);
        if (file_exists($pharFile)) {
            Phar::unlinkArchive($pharFile);
        }

        $phar = new Phar($pharFile, 0, $this->options['output']);
        $phar->startBuffering();
        $phar->buildFromIterator($files);

        $this->setStub($phar);
        $this->compressFiles($phar);
        $phar->stopBuffering();

        file_put_contents($pharFile . '.md5sum', md5_file($pharFile));
        $filesize = Utils::humanFilesize(filesize($pharFile));
        $filesTotal = $files->count();
        chmod($pharFile, 0755);
        echo "Finished {$pharFile}, Size: {$filesize}, Total files: {$filesTotal}, ";
    }

    protected function addFiles(?ArrayIterator $files = null): ArrayIterator
    {
        $files = $files ?: new ArrayIterator;
        foreach ($this->options['directories'] as $dir) {
            $this->addFile($dir, $files);
        }

        foreach ($this->options['files'] as $file) {
            $this->addFile($file, $files);
        }

        return $files;
    }

    protected function addFile(string $path, ArrayIterator $files)
    {
        $path = ltrim($path, '\\/');
        if (!$this->checkPath($path)) {
            return false;
        }

        $realpath = $this->joinPaths($this->basePath, $path);
        if (is_file($realpath)) {
            $pos = strrpos($realpath, '.');
            $ext = ($pos === false) ? false : substr($realpath, $pos + 1);
            if ($ext === 'php' || $ext === 'stub' || in_array($ext, $this->options['extensions'])) {
                $files[$path] = $realpath;
            }
        } elseif (is_dir($realpath)) {
            if ($dh = opendir($realpath)) {
                while (($file = readdir($dh)) !== false) {
                    if ($file === '.' || $file === '..') {
                        continue;
                    }

                    $subPath = $this->joinPaths($path, $file);
                    $this->addFile($subPath, $files);
                }

                closedir($dh);
            }
        }
    }

    protected function compressFiles(Phar $phar): void
    {
        switch ($this->options['compress']) {
            case 'gz':
            case 'gzip':
                $phar->compressFiles(Phar::GZ);
                break;

            case 'bz2':
            case 'bzip2':
                $phar->compressFiles(Phar::BZ2);
                break;

            default:
                break;
        }
    }

    protected function setStub(Phar $phar): void
    {
        $stubFile = $this->options['stub'];
        if ($stubFile) {
            $stubFile = $this->joinPaths($this->basePath, $stubFile);
            $stub = file_get_contents($stubFile);
        } else {
            $stub = <<<EOD
                #!/usr/bin/env php
                <?php

                Phar::mapPhar();
                include 'phar://' . __FILE__ . '/{$this->options['main']}';

                __HALT_COMPILER();
                EOD;
        }

        $phar->setStub($stub);
    }

    protected function checkPath(string $path)
    {
        if ($this->checkIgnore($path)) {
            return false;
        }

        return $this->checkRule($path);
    }

    protected function checkRule(string $path)
    {
        if (empty($this->options['rules'])) {
            return true;
        }

        $rules = (array) $this->options['rules'];
        foreach ($rules as $rule) {
            if (preg_match('#' . $rule . '#i', $path)) {
                return true;
            }
        }

        return false;
    }

    protected function checkIgnore(string $path)
    {
        if (empty($this->options['ignore'])) {
            return false;
        }

        $rules = (array) $this->options['ignore'];
        foreach ($rules as $rule) {
            if (preg_match('#^' . $rule . '#i', $path)) {
                return true;
            }
        }

        return false;
    }

    protected function joinPaths(...$args): string
    {
        $firstArg = '';
        if (isset($args[0])) {
            $firstArg = rtrim($args[0], '\\/') . DIRECTORY_SEPARATOR;
            unset($args[0]);
        }

        $paths = array_map(function ($p) {
            return trim($p, '\\/');
        }, $args);
        $paths = array_filter($paths);
        return $firstArg . implode(DIRECTORY_SEPARATOR, $paths);
    }

    protected function copyFiles($files): void
    {
        $files = (array) $files;
        foreach ($files as $key => $value) {
            $dest = $this->joinPaths($this->options['dist'], $value);
            if (is_int($key)) {
                $source = $this->joinPaths($this->basePath, $value);
            } else {
                $source = $this->joinPaths($this->basePath, $key);
            }

            Utils::xcopy($source, $dest);
        }
    }

    protected function setDist(string $value): void
    {
        if (!is_dir($value)) {
            mkdir($value, 0755);
        }

        $this->options['dist'] = $value;
    }
}
