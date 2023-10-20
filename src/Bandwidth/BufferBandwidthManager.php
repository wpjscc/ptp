<?php

namespace Wpjscc\PTP\Bandwidth;

use Wpjscc\React\Limiter\TokenBucket;
use function React\Async\async;
use function React\Async\await;

class BufferBandwidthManager implements \Wpjscc\PTP\Log\LogManagerInterface
{
    use \Wpjscc\PTP\Traits\Singleton;
    use \Wpjscc\PTP\Log\LogManagerTraitDefault;

    protected $filesystem;

    protected $maxBandwidth;
    protected $bandwidth;
    protected $kb;
    protected TokenBucket $bucket;

    protected $size = 0;

    protected $queues = [];

    protected $isSet = false;

    protected function init()
    {
    }

    public function setBandwidth(int $maxBandwidth, int $bandwidth, int|string $interval = 10000)
    {
        if ($this->isSet) {
            return;
        }
        $this->isSet = true;
        $this->maxBandwidth = $maxBandwidth;
        $this->bandwidth = $bandwidth;

        $this->kb = $bandwidth / 1024 / 1024;

        $this->bucket = new TokenBucket($maxBandwidth, $bandwidth, $interval);

    }

    public function addBuffer($stream, $buffer)
    {
        if (!$this->bucket) {
            throw new \Error("Bandwidth not set");
        }

        $this->size += strlen($buffer); 
        $streamId = spl_object_id($stream);
        $deferred = new \React\Promise\Deferred();
        // $stream->on('close', function () use ($deferred, $streamId) {
        //     static::getLogger()->error('stream close', [
        //         'class' => __CLASS__,
        //         'streamId' => $streamId,
        //         'size' => $this->size,
        //     ]);
        //     $deferred->reject(new \Error('stream close'));
        //     $this->removeStream($streamId);
        // });
   
        \React\EventLoop\Loop::addTimer(0.001, function () use ($deferred, $stream, $buffer, $streamId) {
            $this->queues[$streamId]['queues'][] = [
                'deferred' => $deferred,
                'stream' => $stream,
                'buffer' => $buffer,
                'size' => strlen($buffer),
                'position' => 0,
            ];
    
            if (!isset($this->queues[$streamId]['running'])){
                $this->queues[$streamId]['running'] = false;
            }
            static::getLogger()->debug('addBuffer', [
                'class' => __CLASS__,
                'streamId' => $streamId,
                'size' => $this->size,
                'count' => count($this->queues[$streamId]['queues']),
            ]);
            $this->startConsume($streamId);
        });

        return  $deferred->promise();
    }


    protected function startConsume($streamId)
    {
        if (isset($this->queues[$streamId]['running']) && $this->queues[$streamId]['running'] === false) {
            static::getLogger()->debug('startConsume:running', [
                'class' => __CLASS__,
                'streamId' => $streamId,
                'size' => $this->size,
                'count' => count($this->queues[$streamId]['queues'] ?? []),
            ]);
            $this->queues[$streamId]['running'] = true;
            if (isset($this->queues[$streamId]['queues']) && !empty($this->queues[$streamId]['queues'])) {
                $stream = array_shift($this->queues[$streamId]['queues']);
                static::getLogger()->debug('startConsume', [
                    'class' => __CLASS__,
                    'streamId' => $streamId,
                    'size' => $this->size,
                    'count' => count($this->queues[$streamId]['queues'] ?? []),
                ]);
                $this->runStream($streamId, $stream);
            } else {
                static::getLogger()->notice('startConsume:empty', [
                    'class' => __CLASS__,
                    'streamId' => $streamId,
                    'size' => $this->size,
                    'count' => count($this->queues[$streamId]['queues'] ?? []),
                ]);
                $this->removeStream($streamId);
            }
        } else {
            static::getLogger()->notice('startConsume:no:data', [
                'class' => __CLASS__,
                'streamId' => $streamId,
                'size' => $this->size,
            ]);
        }
    }

    protected function removeStream($streamId)
    {
        unset($this->queues[$streamId]);
    }

    protected function continueStream($streamId)
    {
        $this->queues[$streamId]['running'] = false;
        $this->startConsume($streamId);
    }

    protected function runStream($streamId, $stream)
    {
        $sendStream = function ($stream) use (&$sendStream, $streamId) {
            return async(function () use ($stream, $sendStream, $streamId) {
                static::getLogger()->debug('runStream', [
                    'class' => __CLASS__,
                    'streamId' => $streamId,
                    'size' => $this->size,
                    'count' => count($this->queues[$streamId]['queues'] ?? []),
                ]);
                $deferred = $stream['deferred'];
                $p = $stream['position'];
                $buffer = $stream['buffer'];
                $size = $stream['size'];
                $currentSize = $size - $p;
                $writeable = $stream['stream'];

                if (!$writeable->isReadable()) {
                    static::getLogger()->error('runStream:isReadable:not', [
                        'class' => __CLASS__,
                        'streamId' => $streamId,
                        'size' => $this->size,
                        'current_size' => $currentSize,
                        'count' => count($this->queues[$streamId]['queues'] ?? []),
                    ]);
                    $data = substr($buffer, 0, $currentSize);
                    $writeable->emit('data', [$data]);

                    $deferred->reject(new \Error('stream not readable'));

                    $this->removeStream($streamId);
                    return;
                }

                $bucket = $this->bucket;
                if ($currentSize/1024 < $this->kb) {
                    await($bucket->removeTokens(1024 * 1024 * ceil($currentSize/1024)));
                   
                    $writeable->emit('data', [substr($buffer, 0, $currentSize)]);
                    $p += $currentSize;
                    $this->size -= $currentSize;
                    
                    $deferred->resolve($p);
                    static::getLogger()->debug('runStream:currentSize', [
                        'class' => __CLASS__,
                        'streamId' => $streamId,
                        'size' => $this->size,
                        'buffer_length' => strlen($buffer),
                        'currentSize' => $currentSize,
                        'kb' => $this->kb,
                        'p' => $p,
                        'count' => count($this->queues[$streamId]['queues'] ?? []),
                        
                    ]);
                    // 当前发送完了
                    $this->continueStream($streamId);
                } else {
                    await($bucket->removeTokens(1024 * 1024 * $this->kb));
                    $content = substr($buffer, 0, 1024 * $this->kb);
                    $this->size -= strlen($content);
                    $p += strlen($content);
                    $stream['position'] = $p;
                    $writeable->emit('data', [$content]);
                    if ($p < $size) {
                        static::getLogger()->debug('runStream:continue', [
                            'class' => __CLASS__,
                            'streamId' => $streamId,
                            'size' => $this->size,
                            'buffer' => $buffer,
                            'content' => $content,
                            'p' => $p,
                            'count' => count($this->queues[$streamId]['queues'] ?? []),
                        ]);
                        $stream['buffer'] = substr($buffer, 1024 * $this->kb);
                        await($sendStream($stream));
                    } else {
                        $deferred->resolve($p);
                        // 当前发送完了
                        $this->continueStream($streamId);
                    }
                }
                
            })();
        };
        $sendStream($stream);
    }
   
}