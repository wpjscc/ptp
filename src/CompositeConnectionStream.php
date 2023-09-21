<?php

namespace Wpjscc\Penetration;

use Evenement\EventEmitter;
use React\Stream\ReadableStreamInterface;
use React\Stream\WritableStreamInterface;
use React\Stream\Util;
use React\Socket\ConnectionInterface;
use React\Datagram\SocketInterface;

final class CompositeConnectionStream extends EventEmitter implements ConnectionInterface
{
    
    private $connection;
    private $readable;
    private $writable;
    public $protocol;

    private $closed = false;

    protected $remoteAddress;

    // $connection is a SocketInterface or ConnectionInterface
    public function __construct(ReadableStreamInterface $readable, WritableStreamInterface $writable, $connection = null, $protocol = null)
    {
        $this->readable = $readable;
        $this->writable = $writable;
        $this->connection = $connection;
        $this->protocol = $protocol;

        if (!$readable->isReadable() || !$writable->isWritable()) {
            $this->close();
            return;
        }

        Util::forwardEvents($this->readable, $this, array('data', 'end', 'error'));
        Util::forwardEvents($this->writable, $this, array('drain', 'error', 'pipe'));

        $this->readable->on('close', array($this, 'close'));
        $this->writable->on('close', array($this, 'close'));
    }

    public function isReadable()
    {
        return $this->readable->isReadable();
    }

    public function pause()
    {
        $this->readable->pause();
    }

    public function resume()
    {
        if (!$this->writable->isWritable()) {
            return;
        }

        $this->readable->resume();
    }

    public function pipe(WritableStreamInterface $dest, array $options = array())
    {
        return Util::pipe($this, $dest, $options);
    }

    public function isWritable()
    {
        return $this->writable->isWritable();
    }

    public function write($data)
    {
        return $this->writable->write($data);
    }

    public function end($data = null)
    {
        $this->readable->pause();
        $this->writable->end($data);
    }

    public function close()
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        $this->readable->close();
        $this->writable->close();

        $this->emit('close');
        $this->removeAllListeners();
    }

    public function getLocalAddress()
    {
        if ($this->connection)
            return $this->connection->getLocalAddress();
        
        return null;
    }

    public function getRemoteAddress()
    {
        if ($this->remoteAddress)
            return $this->remoteAddress;

        if ($this->connection)
            return $this->remoteAddress = $this->connection->getRemoteAddress();

        return null;
    }
}
