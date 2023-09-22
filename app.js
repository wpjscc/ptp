var EventEmitter = require('events')
const { Transform, pipeline } = require('stream');
const { HTTPParser } = require('http-parser-js');
const { Buffer } = require('buffer');
const http = require('http');

class ThroughStream extends Transform {
    constructor() {
        super();
    }
    // 重写_transform方法来处理输入数据
    _transform(chunk, encoding, callback) {
        // 对输入数据进行处理
        // const transformedChunk = chunk.toString().toUpperCase();
        const transformedChunk = chunk;
        // 将处理后的数据传递给可写流
        this.push(transformedChunk);
        // 回调函数通知流处理完毕
        callback();
    }
}



class Util {
    static parseRequest(input) {
        input = Buffer.from(input);
        const parser = new HTTPParser(HTTPParser.REQUEST);
        let complete = false;
        let shouldKeepAlive;
        let upgrade;
        let host;
        let port;
        let method;
        let url;
        let versionMajor;
        let versionMinor;
        let headers = {};
        let trailers = [];
        let bodyChunks = [];

        parser[HTTPParser.kOnHeadersComplete] = function (req) {
            shouldKeepAlive = req.shouldKeepAlive;
            upgrade = req.upgrade;
            method = HTTPParser.methods[req.method];
            url = req.url;
            versionMajor = req.versionMajor;
            versionMinor = req.versionMinor;

            for (let $i = 0; $i < req.headers.length; $i += 2) {
                if (req.headers[$i] === 'Host') {
                    let hostAndPort = req.headers[$i + 1].split(':');
                    host = hostAndPort[0];
                    if (hostAndPort.length > 1) {
                        port = hostAndPort[1];
                    }
                }
                headers[req.headers[$i]] = req.headers[$i + 1]
            }
        };

        parser[HTTPParser.kOnBody] = function (chunk, offset, length) {
            bodyChunks.push(chunk.slice(offset, offset + length));
        };

        // This is actually the event for trailers, go figure.
        parser[HTTPParser.kOnHeaders] = function (t) {
            trailers = t;
        };

        parser[HTTPParser.kOnMessageComplete] = function () {
            complete = true;
        };

        // Since we are sending the entire Buffer at once here all callbacks above happen synchronously.
        // The parser does not do _anything_ asynchronous.
        // However, you can of course call execute() multiple times with multiple chunks, e.g. from a stream.
        // But then you have to refactor the entire logic to be async (e.g. resolve a Promise in kOnMessageComplete and add timeout logic).
        parser.execute(input);
        parser.finish();

        if (!complete) {
            throw new Error('Could not parse request');
        }

        let body = Buffer.concat(bodyChunks);

        return {
            host,
            port,
            shouldKeepAlive,
            upgrade,
            method,
            path: url,
            versionMajor,
            versionMinor,
            headers,
            body,
            trailers,
        };
    }
    static parseResponse(input) {
        console.log(input)
        input = Buffer.from(`${input}\r\n`);
        const parser = new HTTPParser(HTTPParser.RESPONSE);
        let complete = false;
        let shouldKeepAlive;
        let upgrade;
        let statusCode;
        let statusMessage;
        let versionMajor;
        let versionMinor;
        let headers = {};
        let trailers = [];
        let bodyChunks = [];

        parser[HTTPParser.kOnHeadersComplete] = function (res) {
            shouldKeepAlive = res.shouldKeepAlive;
            upgrade = res.upgrade;
            statusCode = res.statusCode;
            statusMessage = res.statusMessage;
            versionMajor = res.versionMajor;
            versionMinor = res.versionMinor;

            for (let $i = 0; $i < res.headers.length; $i += 2) {
                headers[res.headers[$i]] = res.headers[$i + 1]
            }
        };

        parser[HTTPParser.kOnBody] = function (chunk, offset, length) {
            bodyChunks.push(chunk.slice(offset, offset + length));
        };

        // This is actually the event for trailers, go figure.
        parser[HTTPParser.kOnHeaders] = function (t) {
            trailers = t;
        };

        parser[HTTPParser.kOnMessageComplete] = function () {
            complete = true;
        };

        // Since we are sending the entire Buffer at once here all callbacks above happen synchronously.
        // The parser does not do _anything_ asynchronous.
        // However, you can of course call execute() multiple times with multiple chunks, e.g. from a stream.
        // But then you have to refactor the entire logic to be async (e.g. resolve a Promise in kOnMessageComplete and add timeout logic).
        parser.execute(input);
        parser.finish();

        if (!complete) {
            throw new Error('Could not parse');
        }

        let body = Buffer.concat(bodyChunks);

        return {
            shouldKeepAlive,
            upgrade,
            statusCode,
            statusMessage,
            versionMajor,
            versionMinor,
            headers,
            body,
            trailers,
        };
    }
    static array(value) {
        if (typeof Array.isArray === 'function') {
            return Array.isArray(value)
        }
        return Object.prototype.toString.call(value) === '[object Array]'
    }

    /**
     * 是否对象
     */
    static object(value) {
        return Object.prototype.toString.call(value) === '[object Object]'
    }
    static pipe(source, dest, options = {}) {
        // source not readable => NO-OP
        if (!source.isReadable()) {
            return dest;
        }

        // destination not writable => just pause() source
        if (!dest.isWritable()) {
            source.pause();

            return dest;
        }

        dest.emit('pipe', [source]);

        // forward all source data events as dest.write()
        const dataer = (data) => {
            const feedMore = dest.write(data);

            if (feedMore === false) {
                source.pause();
            }
        };
        source.on('data', dataer);
        dest.on('close', () => {
            source.removeListener('data', dataer);
            source.pause();
        });

        // forward destination drain as source.resume()
        const drainer = () => {
            source.resume();
        };
        dest.on('drain', drainer);
        source.on('close', () => {
            dest.removeListener('drain', drainer);
        });

        // forward end event from source as dest.end()
        const end = options['end'] !== undefined ? options['end'] : true;
        if (end) {
            const ender = () => {
                dest.end();
            };
            source.on('end', ender);
            dest.on('close', () => {
                source.removeListener('end', ender);
            });
        }

        return dest;
    }

    static forwardEvents(source, target, events) {

        if (Util.object(events)) {
            Object.keys(events).forEach((sourceEvent) => {
                const targetEvent = events[sourceEvent];
                source.on(sourceEvent, (...args) => {
                    target.emit(targetEvent, args);
                });
            });
        } else {
            events.forEach((event) => {
                source.on(event, (...args) => {
                    target.emit(event, args);
                });
            });
        }


    }
}

class ReadableStreamWraper extends EventEmitter {

    constructor($read) {
        super();
        this._read = $read;
        this.closed = false;
        Util.forwardEvents($read, this, {
            data: 'data',
            end: 'end',
            error: 'error',
            readable: 'data',
            close: 'close',
        });

    }

    isReadable() {
        return this._read.readable;
    }

    pause() {
        if (!this.closed) {
            this._read.pause();
        }
    }

    resume() {
        if (!this.closed) {
            return this._read.resume();
        }
    }

    pipe(dest, options = []) {
        return Util.pipe(this, dest, options);
    }

    close() {
        if (this.closed) {
            return;
        }

        this.closed = true;
        this.emit('close');
        this._read.destroy();
        this.removeAllListeners()

    }
}

class WritableStreamWraper extends EventEmitter {

    constructor($write) {
        super();
        this._write = $write;
        this.closed = false;
        Util.forwardEvents($write, this, {
            drain: 'drain',
            error: 'error',
            pipe: 'pipe',
            close: 'close',
        });
    }

    isWritable() {
        return this._write.writable;
    }

    write($data) {

        if (this.closed) {
            return;
        }
        if ($data !== null && $data !== undefined) {
            this._write.write($data);
        }
    }

    end($data) {
        this._write.end($data);
        this.close()
    }

    close() {
        if (this.closed) {
            return;
        }
        this.closed = true;
        this.emit('close');
        this._write.destroy();
        this.removeAllListeners()
    }
}


class CompositeConnectionStream extends EventEmitter {
    constructor($read, $write, $connection, $protocol) {
        super();
        this.closed = false;
        // $read ThroughStream
        this.readable = new ReadableStreamWraper($read);
        // $write ThroughStream
        this.writable = new WritableStreamWraper($write);
        this.connection = $connection;
        this.protocol = $protocol;

        if (!this.readable.isReadable() || !this.writable.isWritable()) {
            this.close();
            return;
        }

        Util.forwardEvents(this.readable, this, {
            data: 'data',
            end: 'end',
            error: 'error',
        });

        Util.forwardEvents(this.writable, this, {
            drain: 'drain',
            error: 'error',
            pipe: 'pipe',
        });

        this.readable.on('close', () => {
            this.close();
        });

        this.writable.on('close', () => {
            this.close();
        });



    }

    isWritable() {
        return this.writable.isWritable();
    }

    pause() {
        this.readable.pause();
    }

    resume() {

        if (!this.writable.isWritable()) {
            return;
        }

        this.readable.resume();
    }

    pipe($dest, $options = []) {
        return Util.pipe(this, $dest, $options);
    }

    isWritable() {
        return this.writable.isWritable();
    }

    write($data) {
        this.writable.write($data);
    }

    end($data) {
        this.readable.pause();
        this.writable.end($data);
    }

    close() {
        if (this.closed) {
            return;
        }
        this.closed = true;

        this.readable.close();
        this.writable.close();
        this.emit('close');
        this.removeAllListeners();
    }

    getLocalAddress() {

    }

    getRemoteAddress() {

    }
}



class WebSocketTunnel {
    connect($uri) {
        return new Promise((resolve, reject) => {

            let socket = new WebSocket($uri);

            let $read = new ThroughStream;
            let $write = new ThroughStream;

            let $compositeConnectionStream = new CompositeConnectionStream($read, $write, '', 'ws');


            socket.onopen = function () {
                console.log("Socket connection established");
                resolve(
                    $compositeConnectionStream
                );
            };

            socket.onmessage = function (event) {
                var message = event.data;
                console.log("Received message: " + atob(message));
                $compositeConnectionStream.emit('data', atob(message));
            };

            $write.on('data', function ($data) {
                console.log("Send message: " + $data);
                socket.send(btoa($data));
            })

            socket.onclose = function (event) {
                console.log("Socket connection closed with code: " + event.code);
                $compositeConnectionStream.close()
                reject(event)
            };

        })
    }
}

class SingleTunnel extends EventEmitter {
    constructor() {
        super()
        this.connections = {};
        this.connection = null;
        this.buffer = ''
    }
    overConnection($connection) {
        this.connection = $connection;
        this.connection.on('data', ($buffer) => {
            this.parseBuffer($buffer)
        });
    }
    parseBuffer($buffer) {
        if ($buffer === '') {
            return;
        }
        echo('parse buffer ' + $buffer + "\n");

        this.buffer += $buffer;

        let $pos = this.buffer.indexOf("\r\n\r\n");
        if ($pos > -1) {

            let $httpPos = this.buffer.indexOf("HTTP/1.1")
            if ($httpPos == -1) {
                $httpPos = 0
            }

            let $headers = this.buffer.substr($httpPos, $pos - $httpPos+4);
            let $response = null;
            try {
                $response = Util.parseResponse($headers);
            } catch (e) {
                console.log(e)
                this.connection.close();
                return;
            }

            this.buffer = this.buffer.substr($pos + 4);

            if ($response['statusCode'] === 310) {
                this.createConnection($response)
            }
            else if ($response['statusCode'] === 311) {
                this.handleData($response)
            }
            else if ($response['statusCode'] === 312) {
                this.handleClose($response)
            }
            else if ($response['statusCode'] === 300) {
                echo('server ping' + "\n")
                this.connection.write("HTTP/1.1 301 OK\r\n\r\n");
            }
            else {
                echo('ignore other response code'.$response['statusCode']);
            }
            this.parseBuffer('')

        }

    }

    createConnection($response) {
        let uuid = $response['headers']['Uuid'];
        let $read = new ThroughStream;
        let $write = new ThroughStream;
        let $connection = new CompositeConnectionStream($read, $write, null, 'single');

        $write.on('data', ($data) => {
            echo(`single tunnel send data-${uuid}\n`)

            $data = btoa($data);
            this.connection.write($data);
        })

        $read.on('close',  () => {
            this.connection.write("HTTP/1.1 312 OK\r\nUuid: {$uuid}\r\n\r\n")
            $connection.close();
            // remove connections
            delete this.connections[uuid];
        })

        this.connections[uuid] = $connection;

        this.emit('connection', $connection);
        this.connection.write(`HTTP/1.1 310 OK\r\nUuid: ${uuid}\r\n\r\n`);
    }

    handleData($response) {
        let uuid = $response['headers']['Uuid'];

        if (this.connections[uuid]) {
            echo(`single tunnel receive data-${uuid}\n`);

            let $data = atob($response['headers']['Data']);
            this.connections[uuid].emit('data', [$data]);
        }
        else {
            echo('connection not found ' + uuid + "\n")
        }
    }

    handleClose($response) {
       let  uuid = $response['headers']['Uuid'];
        if (this.connections[uuid]) {
            echo(`single tunnel receive close-${uuid}\n`);
            this.connections[uuid].close();
        }
        else {
            echo('connection not found ' + uuid + "\n")
        }
    }

    close() {
        Object.keys(this.connections).forEach(($uuid) => {
            this.connections[$uuid].close();
        })
    }
}

class Tunnel {

    constructor($config) {
        this.protocol = $config['tunnel_protocol'] || 'tcp';
        this.server_host = $config['server_host'];
        this.server_80_port = $config['server_80_port'];
        this.server_443_port = $config['server_443_port'];
        this.timeout = $config['timeout'] || 6;

    }

    getTunnel($protocol) {

        if (!$protocol) {
            $protocol = this.protocol;
        }

        console.log('protocol is ' + $protocol)

        if ($protocol == 'ws') {
            return new WebSocketTunnel().connect('ws://' + this.server_host + ':' + this.server_80_port + '/tunnel');
        }
        else if ($protocol == 'wsss') {
            return new WebSocketTunnel().connect('wss://' + this.server_host + ':' + this.server_443_port + '/tunnel');
        }

        return new Promise((resolve, reject) => {
            reject('not support protocol ' + $protocol)
        })

    }

}