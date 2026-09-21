<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$failures = 0;
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
);

foreach ($iterator as $file) {
    if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
        continue;
    }

    if (str_contains($file->getPathname(), DIRECTORY_SEPARATOR . '.agents' . DIRECTORY_SEPARATOR)) {
        continue;
    }

    $command = escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file->getPathname());
    passthru($command, $exitCode);

    if ($exitCode !== 0) {
        $failures++;
    }
}

$nodeLookup = PHP_OS_FAMILY === 'Windows' ? 'where node 2>NUL' : 'command -v node 2>/dev/null';
exec($nodeLookup, $nodePaths, $nodeLookupExit);

if ($nodeLookupExit === 0 && isset($nodePaths[0])) {
    passthru(escapeshellarg($nodePaths[0]) . ' --check ' . escapeshellarg($root . '/src/js/main.js'), $nodeExit);

    if ($nodeExit !== 0) {
        $failures++;
    }
} else {
    fwrite(STDOUT, "Node.js not available; JavaScript syntax check skipped.\n");
}

passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/tests/run.php'), $testExit);

if ($testExit !== 0) {
    $failures++;
}

if ($failures > 0) {
    fwrite(STDERR, sprintf("Verification failed with %d failing step(s).\n", $failures));
    exit(1);
}

fwrite(STDOUT, "Verification completed successfully.\n");
