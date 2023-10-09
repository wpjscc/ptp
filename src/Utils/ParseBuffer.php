<?php

namespace Wpjscc\Penetration\Utils;


use RingCentral\Psr7;
use Evenement\EventEmitter;

class ParseBuffer extends EventEmitter implements \Wpjscc\Penetration\Log\LogManagerInterface
{
    use \Wpjscc\Penetration\Log\LogManagerTraitDefault;

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
                // invalid response message, close connection
                static::getLogger()->error($e->getMessage(), [
                    'class' => __CLASS__,
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
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


   
}