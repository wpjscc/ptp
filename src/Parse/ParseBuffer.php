<?php

namespace Wpjscc\PTP\Parse;


use RingCentral\Psr7;
use Evenement\EventEmitter;

class ParseBuffer extends EventEmitter implements \Wpjscc\PTP\Log\LogManagerInterface
{
    use \Wpjscc\PTP\Log\LogManagerTraitDefault;

    protected $buffer = '';
    protected $connection;
    protected $localAddress;
    protected $remoteAddress;

    public function handleBuffer($buffer)
    {
        if ($buffer === '') {
            return;
        }
        
        $this->buffer .= $buffer;
        $this->parseBuffer();
    }

    protected function parseBuffer()
    {
        $pos = strpos($this->buffer, "\r\n\r\n");
        if ($pos !== false) {
            $httpPos = strpos($this->buffer, "HTTP/1.1");
            if ($httpPos === false) {
                $httpPos = 0;
            }
            try {
                $response = Psr7\parse_response(substr($this->buffer, $httpPos, $pos - $httpPos));
            } catch (\Exception $e) {
                static::getLogger()->error($e->getMessage(), [
                    'class' => __CLASS__,
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'all_buffer' => $this->buffer,
                    'buffer' => substr($this->buffer, $httpPos, $pos - $httpPos)
                ]);

                $this->buffer = substr($this->buffer, $pos + 4);

                return;
            }

            $this->buffer = substr($this->buffer, $pos + 4);

            $this->emit('response', [$response, $this]);

            $this->parseBuffer();
        }
    }

    public function setConnection($connection)
    {
        $this->connection = $connection;
    }

    public function getConnection()
    {
        return $this->connection;
    }


    public function setLocalAddress($address)
    {
        $this->localAddress = $address;
    }

    public function getLocalAddress()
    {
        return $this->localAddress;
    }

    public function setRemoteAddress($address)
    {
        $this->remoteAddress = $address;
    }

    public function getRemoteAddress()
    {
        return $this->remoteAddress;
    }

    public function getBuffer()
    {
        return $this->buffer;
    }

    public function pullBuffer()
    {
        $buffer = $this->buffer;

        $this->buffer = '';

        return $buffer;
    }


   
}