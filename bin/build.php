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
$phar = isset($argv[1]) ? $argv[1].'.phar' : ('ptp-' . $version).'.phar';
$linuxBin = isset($argv[1]) ? 'release-ptp-linux-'.$argv[1] : ('ptp-linux-' . $version);
$macBin = isset($argv[1]) ? 'release-ptp-mac-'.$argv[1] : ('ptp-mac-' . $version);


passthru('
rm -rf build && mkdir build &&
cp -r client.php server.php index.php src/ composer.json build/ && rm -rf bin/ptp*
composer install -d build/ --no-dev -vvv &&

vendor/bin/phar-composer build build/ bin/' . escapeshellarg($phar) . ' &&
cat bin/linux-micro.sfx bin/myiniheader.bin bin/'.escapeshellarg($phar).' > bin/'.escapeshellarg($linuxBin).' &&
cat bin/mac-micro.sfx bin/myiniheader.bin bin/'.escapeshellarg($phar).' > bin/'.escapeshellarg($macBin).' &&
echo -n "Reported Linux version is: /bin/'  . escapeshellarg($linuxBin).'\n" &&
echo -n "Reported Mac version is: /bin/'  . escapeshellarg($macBin) .'\n"'
, $code);
exit($code);