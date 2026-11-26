<?php

declare(strict_types=1);

if (ini_get("phar.readonly") === "1") {
    fwrite(STDERR, "Run with -d phar.readonly=0\n");
    exit(1);
}
$manifest = file_get_contents(__DIR__ . "/plugin.toml");
if ($manifest === false || preg_match('/^version\s*=\s*"([^"]+)"/m', $manifest, $match) !== 1) {
    fwrite(STDERR, "Version not found in plugin.toml\n");
    exit(1);
}
$buildDir = __DIR__ . "/build";
if (!is_dir($buildDir) && !@mkdir($buildDir) && !is_dir($buildDir)) {
    throw new RuntimeException("Cannot create $buildDir");
}
$output = "$buildDir/Tebex_{$match[1]}.phar";
$building = "$buildDir/.tebex-" . bin2hex(random_bytes(8)) . ".phar";

try {
    $phar = new Phar($building);
    $phar->startBuffering();
    $phar->setSignatureAlgorithm(Phar::SHA256);
    foreach (["plugin.toml", "LICENSE", "README.md"] as $file) {
        $phar->addFile(__DIR__ . "/" . $file, $file);
    }
    foreach (["src", "resources"] as $dir) {
        $root = __DIR__ . "/" . $dir;
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            /** @var SplFileInfo $file */
            $path = $file->getPathname();
            $phar->addFile($path, $dir . "/" . str_replace("\\", "/", substr($path, strlen($root) + 1)));
        }
    }

    $phar->setStub("<?php __HALT_COMPILER();");
    $phar->stopBuffering();
    unset($phar);

    if (!rename($building, $output)) {
        throw new RuntimeException("Cannot replace $output");
    }
} finally {
    unset($phar);
    if (is_file($building)) unlink($building);
}
echo "Built $output (" . filesize($output) . " bytes)\n";
