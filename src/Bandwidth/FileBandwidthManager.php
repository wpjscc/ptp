<?php

namespace Wpjscc\PTP\Bandwidth;


use React\Filesystem\Factory;
use Wpjscc\React\Limiter\TokenBucket;
use function React\Async\async;
use function React\Async\await;

class FileBandwidthManager
{
    use \Wpjscc\PTP\Traits\Singleton;

    protected $filesystem;

    protected $maxBandwidth;
    protected $bandwidth;
    protected $kb;
    protected TokenBucket $bucket;

    protected function init()
    {
        $this->filesystem = Factory::create();
    }

    public function setBandwidth(int $maxBandwidth, int $bandwidth, int $interval)
    {
        $this->maxBandwidth = $maxBandwidth;
        $this->bandwidth = $bandwidth;

        $this->kb = $bandwidth / 1024 / 1024;
        $this->bucket = new TokenBucket($maxBandwidth, $bandwidth, $interval);

    }

    public function addStream($stream, $filepath)
    {

        if (!$this->bucket) {
            throw new \Error("Bandwidth not set");
        }

        \React\EventLoop\Loop::addTimer(0.001, function () use ($stream, $filepath) {
            $this->runStream([
                'stream' => $stream,
                'filepath' => $filepath,
                'filesize' => filesize($filepath),
                'position' => 0,
            ]);
        });

       
        return $this;
    }

    protected function runStream($stream)
    {
        $sendStream = function ($stream) use (&$sendStream) {
            return async(function () use ($stream, $sendStream) {
                $path = $stream['filepath'];
                $p = $stream['position'];
                $size = $stream['filesize'];
                $writeable = $stream['stream'];
                if ($writeable->isWritable() === false) {
                    return;
                }
                $bucket = $this->bucket;
                if ($size/1024 < $this->kb) {
                    await($bucket->removeTokens(1024 * 1024 * ceil($size/1024)));
                    $content = await($this->filesystem->file($path)->getContents(0, $size));
                    $writeable->end($content);
                } else {
                    if (($size-$p)/1024 < $this->kb) {
                        await($bucket->removeTokens(1024 * 1024 * ceil(($size-$p)/1024)));
                        $content = await($this->filesystem->file($path)->getContents($p, 1024 * $this->kb));
                        $p += strlen($content);
                        $writeable->end($content);
                    } else {
                        await($bucket->removeTokens(1024 * 1024 * $this->kb));
                        $content = await($this->filesystem->file($path)->getContents($p, 1024 * $this->kb));
                        $p += strlen($content);
                        if ($p >= $size) {
                            $writeable->end($content);
                        } else {
                            $writeable->write($content);
                            $stream['position'] = $p;
                            await($sendStream($stream));
                        }
                    }
                }
            })();
        };
        $sendStream($stream);
    }
   
}