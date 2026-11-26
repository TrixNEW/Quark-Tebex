<?php

declare(strict_types=1);

$quark = getenv("QUARK_DIR") ?: dirname(__DIR__, 3) . "/Quark";
if (!is_file($quark . "/vendor/autoload.php")) {
    throw new RuntimeException("Quark checkout with installed dependencies not found at $quark, set QUARK_DIR");
}
$stubs = $quark . "/tests/phpstan/stubs/";

return [
    "parameters" => [
        "bootstrapFiles" => [$quark . "/vendor/autoload.php", $quark . "/tests/phpstan/bootstrap.php"],
        "scanFiles" => [$stubs . "bedwire.stub", $stubs . "chunkutils2-light.stub", $stubs . "qmath.stub", $stubs . "toml.stub"],
        "stubFiles" => [$stubs . "chunkutils2.stub", $stubs . "leveldb.stub", $stubs . "pmmpthread.stub", $stubs . "qmath.stub"],
    ],
];
