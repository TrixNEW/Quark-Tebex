<?php

declare(strict_types=1);

if(ini_get("phar.readonly") === "1"){
	fwrite(STDERR, "Run with -d phar.readonly=0\n");
	exit(1);
}
$manifest = file_get_contents(__DIR__ . "/plugin.toml");
if($manifest === false || preg_match('/^version\s*=\s*"([^"]+)"/m', $manifest, $match) !== 1){
	fwrite(STDERR, "Version not found in plugin.toml\n");
	exit(1);
}
@mkdir(__DIR__ . "/build");
$output = __DIR__ . "/build/Tebex_{$match[1]}.phar";
// Built beside the release and moved over it once complete, so a failed build keeps the old one.
$building = $output . ".tmp.phar";
@unlink($building);

$phar = new Phar($building);
$phar->startBuffering();
$phar->setSignatureAlgorithm(Phar::SHA256);
foreach(["plugin.toml", "LICENSE", "README.md"] as $file){
	$phar->addFile(__DIR__ . "/" . $file, $file);
}
foreach(["src", "resources"] as $dir){
	$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . "/" . $dir, FilesystemIterator::SKIP_DOTS));
	foreach($files as $file){
		/** @var SplFileInfo $file */
		$phar->addFile($file->getPathname(), $dir . "/" . str_replace("\\", "/", substr($file->getPathname(), strlen(__DIR__ . "/" . $dir) + 1)));
	}
}
$phar->setStub("<?php __HALT_COMPILER();");
$phar->stopBuffering();
unset($phar);

if(!rename($building, $output)){
	fwrite(STDERR, "Cannot replace $output\n");
	exit(1);
}
echo "Built $output (" . filesize($output) . " bytes)\n";
