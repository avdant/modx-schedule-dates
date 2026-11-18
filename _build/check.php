<?php
$root = dirname(__DIR__);
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
$failed = false;
foreach ($iterator as $file) {
    if ($file->getExtension() !== 'php') continue;
    passthru(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file->getPathname()), $code);
    $failed = $failed || $code !== 0;
}
if ($failed) exit(1);
require $root . '/tests/run.php';
