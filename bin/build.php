<?php

// explicitly give VERSION via ENV or ask git for current version
$version = getenv('VERSION');
if ($version === false) {
    $version = ltrim(exec('git describe --always --dirty', $_, $code), 'v');
    if ($code !== 0) {
        fwrite(STDERR, 'Error: Unable to get version info from git. Try passing VERSION via ENV' . PHP_EOL);
        exit(1);
    }
}

// use first argument as output file or use "phar-composer-{version}.phar"
$name = isset($argv[1]) ? $argv[1] : ('ptp-' . $version);
$phar = $name . '.phar';

$linuxBin = $name . '-linux';
$macBin = $name . '-mac';

passthru('
rm -rf build && mkdir build &&
cp -r client.php server.php index.php src/ composer.json build/ && rm-r  bin/ptp*
composer install -d build/ --no-dev &&

vendor/bin/phar-composer build build/ bin/' . escapeshellarg($phar) . ' &&
cat bin/linux-micro.sfx bin/'.escapeshellarg($phar).' > bin/'.escapeshellarg($linuxBin).' &&
cat bin/mac-micro.sfx bin/'.escapeshellarg($phar).' > bin/'.escapeshellarg($macBin).' &&
echo -n "Reported Linux version is: /bin'  . escapeshellarg($linuxBin).'" &&
echo -n "Reported Mac version is: /bin'  . escapeshellarg($macBin) .'"'
, $code);
exit($code);