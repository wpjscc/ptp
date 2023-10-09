
(function (that) {


    let echo = console.log
    let warn = console.warn

    var EventEmitter = require('events')
    const { Transform, pipeline } = require('stream');
    const { HTTPParser } = require('http-parser-js');
    const { Buffer } = require('buffer');
    // const { createGzip,gzip } = require('zlib');
    const zlib = require('zlib');
    const http = require('http');
    var net;
    var udp;
    var ini = require('ini')
    let isNode = false
    let Base64
    let _Base64
    let nodeWebsocket
    // 在node中
    if (typeof WebSocket === 'undefined') {
        isNode = true
        nodeWebsocket = require('ws').WebSocket;
        // _Base64 = {
        //     encode: function (input) {
        //         return Buffer.from(input).toString('base64');
        //     },
        //     decode: function (input) {
        //         return Buffer.from(input, 'base64').toString();
        //     }
        // }
        _Base64 = require('js-base64').Base64;
        udp = require('dgram');
        net = require('net');



    } else {
        _Base64 = require('js-base64').Base64;
        // WebSocket = window.WebSocket;
    }
    Base64 = {
        encode: function (input) {
            if (Buffer.isBuffer(input)) {
                // console.log('encode data-length-' + input.length)
                // console.log(input)
                input = input.toString();
                // input = new TextDecoder().decode(input)
            }
            return _Base64.encode(input);
        },
        decode: function (input) {
            if (Buffer.isBuffer(input)) {
                // console.log('decode data-length-' + input.length)
                // console.log(input)
                input = input.toString();
                // input = new TextDecoder().decode(input)
            }
            return _Base64.decode(input);
        }
    }

    class ThroughStream extends Transform {
        constructor(fn) {
            super();
            this.fn = fn;
        }
        // 重写_transform方法来处理输入数据
        _transform(chunk, encoding, callback) {
            // 对输入数据进行处理
            // const transformedChunk = chunk.toString().toUpperCase();
            const transformedChunk = this.fn ? this.fn(chunk) : chunk;
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
            // console.log(input)
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
                console.log('after close write')
                return;
            }
            if ($data !== null && $data !== undefined) {
                if (Buffer.isBuffer($data)) {
                    // console.log('11write data-length-' + $data.length)
                } else {
                    // console.log('write data-length-' + Buffer.from($data).length)
                }
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

        isReadable() {
            return this.readable.isReadable();
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

                if (!isNode) {

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
                        console.log("Received message: " + Base64.decode(message));
                        $compositeConnectionStream.emit('data', Base64.decode(message));
                    };

                    $write.on('data', function ($data) {
                        console.log("Send message length11: " + $data.length);
                        socket.send(Base64.encode($data));
                    })

                    socket.onclose = function (event) {
                        console.log("Socket connection closed with code: " + event.code);
                        $compositeConnectionStream.close()
                        reject(event)
                    };
                } else {
                    let socket = new nodeWebsocket($uri);

                    let $read = new ThroughStream;
                    let $write = new ThroughStream;

                    let $compositeConnectionStream = new CompositeConnectionStream($read, $write, '', 'ws');


                    socket.on('open', function () {
                        console.log("Socket connection established");
                        resolve(
                            $compositeConnectionStream
                        );
                    })

                    socket.on('message', function (data) {
                        // console.log("ws Received message1111: " + Base64.decode(data));
                        $compositeConnectionStream.emit('data', Base64.decode(data));
                    })

                    $write.on('data', function ($data) {
                        console.log(`\x1b[0;31mws Send message length: ` + $data.length);
                        // socket.send(Base64.encode($data));
                        // console.log($data.toString())
                        // try {
                        //     const fs = require('fs');
                        //     const response = Util.parseResponse($data.toString());
                        //     if (response['headers']['Data']) {
                        //         console.log(Base64.decode(response['headers']['Data']))
                        //         console.log(response['headers']['Data'].substr(response['headers']['Data'].indexOf(`\r\n\r\n`) + 4).length)
                        //         fs.appendFile('test.txt', Base64.decode(response['headers']['Data']), function (err) {
                        //             if (err) {
                        //                 return console.error(err);
                        //             }
                        //             console.log("数据写入成功！");
                        //         });
                        //     }
                        // } catch (error) {

                        // }


                        // socket.send(Base64.encode($data));
                        socket.send($data.toString('base64'));
                    })

                    socket.on('error', function (error) {
                        console.log(error)
                        console.log("Socket connection closed with code: ");
                        $compositeConnectionStream.close()
                        reject(error)
                    });
                    socket.on('close', function (event) {
                        console.log("Socket connection closed with code: ", event);
                        $compositeConnectionStream.close()
                        reject(event)
                    });
                }


            })
        }
    }

    class TcpTunnel {
        connect($uri) {
            warn('tcp tunnel connect ' + $uri)
            return new Promise((resolve, reject) => {
                let ip, port
                if ($uri.indexOf('://') > -1) {
                    let $uriArr = $uri.split('://')
                    let $host = $uriArr[1]
                    let $hostArr = $host.split(':')
                    ip = $hostArr[0]
                    port = $hostArr[1]
                } else {
                    let $hostArr = $uri.split(':')
                    ip = $hostArr[0]
                    port = $hostArr[1]
                }
                let $read = new ThroughStream;
                let $write = new ThroughStream;
                let $compositeConnectionStream = new CompositeConnectionStream($read, $write, '', 'tcp');
                var client = new net.Socket()
                // client.setEncoding('utf8');

                client.on('connect', function () {
                    console.log('tcp client connection established')
                    console.log('tcp connect to ' + ip + ':' + port)

                    resolve($compositeConnectionStream)
                })
                client.connect({
                    host: ip,
                    port: port
                })


                $write.on('data', function ($data) {

                    // if (Buffer.isBuffer($data)) {
                    //     console.log("-tcp Send message length: " + $data.length);
                    // } else {
                    //     console.log("tcp Send message length: " + Buffer.from($data).length);
                    // }

                    client.write($data);
                })

                $compositeConnectionStream.on('close', function () {
                    console.log('tcp write closed')

                    client.end();
                })

                client.on('data', function ($data) {
                    // if (Buffer.isBuffer($data)) {

                    //     console.log("-----tcp Received message length: " + $data.length);
                    //     // $data = new TextDecoder().decode($data)

                    //     // console.log($data.toString())

                    // } else {
                    //     console.log("tcp Received message length: " + Buffer.from($data).length);
                    // }
                    $compositeConnectionStream.emit('data', $data);
                })

                client.on('end', function () {
                    console.log('tcp connection end')
                    $compositeConnectionStream.end()
                })
                client.on('close', function () {
                    console.log('tcp connection closed')
                    $compositeConnectionStream.close()
                })

            })
        }
    }

    class UdpTunnel {
        connect($uri) {
            return new Promise((resolve, reject) => {
                const client = udp.createSocket('udp4');
                let ip, port
                port = $uri.split(':')[1]
                ip = $uri.split(':')[0]
                let $read = new ThroughStream;
                let $write = new ThroughStream;
                let $compositeConnectionStream = new CompositeConnectionStream($read, $write, '', 'udp');

                $write.on('data', function ($data) {
                    console.log("Send message length: " + $data.length);
                    client.send(Buffer.from($data), port, ip, function (error) {
                        if (error) {
                            console.log('An error occurred while sending the message', error);
                            client.close();
                            $compositeConnectionStream.close()
                        } else {
                            console.log('Message sent successfully to ' + ip + ':' + port);
                        }
                    });
                })
                client.on('message', function (msg, info) {
                    console.log('Received %d bytes from %s:%d\n', msg.length, info.address, info.port);
                    console.log(msg.toString())
                    $compositeConnectionStream.emit('data', msg.toString());
                })

                $compositeConnectionStream.on('close', function () {
                    console.log('udp connection closed')
                    setTimeout(() => {
                        client.close()
                    }, 1);
                })

                resolve($compositeConnectionStream)
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
                this.buffer += $buffer;
                this.parseBuffer()
            });
        }
        parseBuffer() {

            let $pos = this.buffer.indexOf("\r\n\r\n");
            if ($pos > -1) {

                let $httpPos = this.buffer.indexOf("HTTP/1.1")
                if ($httpPos == -1) {
                    $httpPos = 0
                }

                let $headers = this.buffer.substr($httpPos, $pos - $httpPos + 4);
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
                this.parseBuffer()

            }

        }

        createConnection($response) {
            let uuid = $response['headers']['Uuid'];
            let $read = new ThroughStream;
            let $write = new ThroughStream;
            let $connection = new CompositeConnectionStream($read, $write, null, 'single');

            $write.on('data', ($data) => {
                echo(`single tunnel send data-${uuid}`)
                if (Buffer.isBuffer($data)) {
                    echo(`-single tunnel send data-lenght-${$data.length}`)
                } else {
                    echo(`single tunnel send data-lenght-${Buffer.from($data).length}`)
                }
                // echo(`single tunnel send data-${$data}\n`)

                // console.log($data)
                // $data = $data.toString()
                // let i = 0;
                // let j = 0;
                // let chunk = 1000
                // let pinlv = 1
                
                // while (i < $data.length) { 
                //     let $chunk = $data.substr(i, chunk)
                //     // console.log($chunk,i)
                //     setTimeout(() => {
                //         this.connection.write(`HTTP/1.1 311 OK\r\nUuid: ${uuid}\r\nData: ${Base64.encode($chunk)}\r\n\r\n`);
                //     }, pinlv * j);
                //     j++
                //     i += chunk;
                // }

                $data = Base64.encode($data);
                // // console.log($data)
                this.connection.write(`HTTP/1.1 311 OK\r\nUuid: ${uuid}\r\nData: ${$data}\r\n\r\n`);
            })

            $read.on('close', () => {
                this.connection.write(`HTTP/1.1 312 OK\r\nUuid: ${uuid}\r\n\r\n`)
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

                let $data = Base64.decode($response['headers']['Data']);
                // console.log($data)
                this.connections[uuid].emit('data', $data);
            }
            else {
                echo('connection not found ' + uuid + "\n")
            }
        }

        handleClose($response) {
            let uuid = $response['headers']['Uuid'];
            if (this.connections[uuid]) {
                echo(`single tunnel receive close-${uuid}\n`);
                this.connections[uuid].close();
                delete this.connections[uuid];
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
            this.tunnel_host = $config['tunnel_host'];
            this.tunnel_80_port = $config['tunnel_80_port'];
            this.tunnel_443_port = $config['tunnel_443_port'];
            this.timeout = $config['timeout'] || 6;

            this.local_host = $config['local_host'];
            this.local_port = $config['local_port'];

        }

        getTunnel($protocol) {

            if (!$protocol) {
                $protocol = this.protocol;
            }

            console.log('protocol is ' + $protocol)

            if ($protocol == 'ws') {
                return new WebSocketTunnel().connect('ws://' + this.tunnel_host + ':' + this.tunnel_80_port + '/tunnel');
            }
            else if ($protocol == 'wss') {
                return new WebSocketTunnel().connect('wss://' + this.tunnel_host + ':' + this.tunnel_443_port + '/tunnel');
            }
            else if ($protocol == 'tcp') {
                return new TcpTunnel().connect(this.tunnel_host + ':' + this.tunnel_80_port);
            }
            else if ($protocol == 'udp') {
                return new UdpTunnel().connect(this.tunnel_host + ':' + this.tunnel_80_port);
            }

            return new Promise((resolve, reject) => {
                reject('not support protocol ' + $protocol)
            })

        }
        getLocalTunnel($protocol) {
            if (!$protocol) {
                $protocol = this.protocol;
            }

            if ($protocol == 'tcp') {
                return new TcpTunnel().connect(this.local_host + ':' + this.local_port);
            }


            return new Promise((resolve, reject) => {
                reject('not support protocol ' + $protocol)
            })
        }



    }





    var inisString = `
[common]
    pool_count = 1
    tunnel_host = 192.168.1.9
    tunnel_80_port = 32126
    tunnel_443_port = 32125
    protocol = ws
    tunnel_protocol = tcp


[web]
    local_host = 192.168.1.9
    local_port = 8080
    domain = 192.168.1.9:9010
`;
    
    if (isNode) {
        var fs = require('fs')
        inisString = fs.readFileSync('client.ini', 'utf8');
    }



    console.log(parseINIString(inisString))


    function parseINIString(data) {
        if (isNode) {
            return JSON.parse(JSON.stringify(ini.parse(data)));
        }
        var regex = {
            section: /^\s*\[\s*([^\]]*)\s*\]\s*$/,
            param: /^\s*([^=]+?)\s*=\s*(.*?)\s*$/,
            comment: /^\s*;.*$/
        };
        var value = {};
        var lines = data.split(/[\r\n]+/);
        var section = null;
        lines.forEach(function (line) {
            if (regex.comment.test(line)) {
                return;
            } else if (regex.param.test(line)) {
                var match = line.match(regex.param);
                if (section) {
                    value[section][match[1]] = match[2];
                } else {
                    value[match[1]] = match[2];
                }
            } else if (regex.section.test(line)) {
                var match = line.match(regex.section);
                value[match[1]] = {};
                section = match[1];
            } else if (line.length == 0 && section) {
                section = null;
            };
        });
        return value;
    }

    class ClientManager {
        static $localTunnelConnections = {};
        static $localDynamicConnections = {};
        static $configs = [];

        static createLocalTunnelConnection($inis) {
            let $common = $inis['common'];
            $common['timeout'] = $common['timeout'] || 6;
            $common['single_tunnel'] = $common['single_tunnel'] || false;
            $common['pool_count'] = $common['pool_count'] || 1;
            $common['server_tls'] = $common['server_tls'] || false;
            $common['protocol'] = $common['protocol'] || '';
            $common['tunnel_protocol'] = $common['tunnel_protocol'] || 'tcp';
            delete $common['common'];

            Object.keys($inis).forEach(function ($key) {
                if ($key != 'common') {
                    let $config = $inis[$key];
                    ClientManager.$configs.push(Object.assign(JSON.parse(JSON.stringify($common)), $config));
                }
            })

            let $function = function ($config) {

                let $protocol = $config['protocol']

                let $tunnel_protocol = $config['tunnel_protocol']

                new Tunnel($config).getTunnel($protocol).then(function ($connection) {
                    console.log($config)
                    let $headers = [
                        'GET /client HTTP/1.1',
                        'Host: ' + $config['tunnel_host'],
                        'User-Agent: ReactPHP',
                        'Tunnel: 1',
                        'Authorization: ' + ($config['token'] || ''),
                        'Local-Host: ' + $config['local_host'] + ':' + $config['local_port'],
                        'Domain: ' + $config['domain'],
                        'Single-Tunnel: ' + ($config['single_tunnel'] || 0),
                        'Local-Tunnel-Address: ' + ($connection.getLocalAddress() || 'no local address'),
                    ];
                    $connection.write($headers.join("\r\n") + "\r\n\r\n");

                    let $bufferObj = {
                        buffer: ''
                    };

                    let $fn = null
                    $connection.on('data', $fn = function ($chunk) {
                        $bufferObj.buffer += $chunk;
                        ClientManager.handleLocalTunnelBuffer($connection, $bufferObj, $config, $fn);
                    });

                    $connection.on('close', function () {
                        console.log('connection closed')
                        setTimeout(() => {
                            $function($config)
                        }, 3000);
                    });

                }).catch(($error) => {
                    console.log($error)

                    setTimeout(() => {
                        $function($config)
                    }, 3000);
                })

            }
            echo(ClientManager.$configs)

            for (let i = 0; i < ClientManager.$configs.length; i++) {
                echo(i)
                let $number = ClientManager.$configs[i]['pool_count'] || 1;
                for (let j = 0; j < $number; j++) {
                    $function(ClientManager.$configs[i])
                }
            }


        }

        static handleLocalTunnelBuffer($connection, $bufferObj, $config, $fn) {
            let $pos = $bufferObj.buffer.indexOf("\r\n\r\n");
            if ($pos > -1) {

                let $httpPos = $bufferObj.buffer.indexOf("HTTP/1.1")
                if ($httpPos == -1) {
                    $httpPos = 0
                }

                let $headers = $bufferObj.buffer.substr($httpPos, $pos - $httpPos + 4);
                // console.log($headers)
                let $response = null
                try {
                    $response = Util.parseResponse($headers);
                } catch (e) {
                    console.log(e)
                    $connection.close();
                    return;
                }

                $bufferObj.buffer = $bufferObj.buffer.substr($pos + 4);

                if ($response['statusCode'] === 200) {
                    ClientManager.addLocalTunnelConnection($connection, $response, $config);
                }
                else if ($response['statusCode'] === 201) {
                    ClientManager.createLocalDynamicConnections($connection, $config);
                }
                else if ($response['statusCode'] === 300) {
                    echo('server ping' + "\n")
                    $connection.write("HTTP/1.1 301 OK\r\n\r\n");
                }
                else {
                    console.error($response)
                    $connection.close();
                    return;
                }

                ClientManager.handleLocalTunnelBuffer($connection, $bufferObj, $config);


            }

        }

        static addLocalTunnelConnection($connection, $response, $config) {
            let $uri = $response['headers']['Uri'];
            let $uuid = $response['headers']['Uuid'];
            echo('local tunnel success ' + $uri + "\n");
            $config['uri'] = $uri;
            $config['uuid'] = $uuid;

            if (!ClientManager.$localTunnelConnections[$uri]) {
                ClientManager.$localTunnelConnections[$uri] = [];
            }
            ClientManager.$localTunnelConnections[$uri].push($connection);

            $connection.on('close', function () {
                let $index = ClientManager.$localTunnelConnections[$uri].indexOf($connection);
                if ($index > -1) {
                    ClientManager.$localTunnelConnections[$uri].splice($index, 1);
                }
            });

            if ($config['single_tunnel'] || false) {
                $connection.removeAllListeners('data');
                let $singleTunnel = new SingleTunnel();
                $singleTunnel.overConnection($connection);
                $singleTunnel.on('connection', function ($connection) {
                    let $bufferObj = {
                        buffer: ''
                    };
                    ClientManager.handleLocalConnection($connection, $config, $bufferObj, null);
                });
            }
        }

        static addLocalDynamicConnection($connection, $response) {
            let $uri = $response['headers']['Uri'];
            if (!ClientManager.$localDynamicConnections[$uri]) {
                ClientManager.$localDynamicConnections[$uri] = [];
            }
            ClientManager.$localDynamicConnections[$uri].push($connection);

            $connection.on('close', function () {
                let $index = ClientManager.$localDynamicConnections[$uri].indexOf($connection);
                if ($index > -1) {
                    ClientManager.$localDynamicConnections[$uri].splice($index, 1);
                }
            })
        }
        static createLocalDynamicConnections($tunnelConnection, $config) {
            let $protocol = $config['protocol']

            let $tunnel_protocol = $config['tunnel_protocol']
            new Tunnel($config).getTunnel($tunnel_protocol || $protocol).then(function ($connection) {
                let $headers = [
                    'GET /client HTTP/1.1',
                    'Host: ' + $config['tunnel_host'],
                    'User-Agent: ReactPHP',
                    'Authorization: ' + ($config['token'] || ''),
                    'Domain: ' + $config['domain'],
                    'Uuid: ' + $config['uuid'],
                ];
                $connection.write($headers.join(`\r\n`) + '\r\n\r\n');
                ClientManager.handleLocalDynamicConnection($connection, $config);
            })
        }

        static handleLocalDynamicConnection($connection, $config) {
            let $bufferObj = {
                buffer: ''
            };
            let fn
            $connection.on('data', fn = function ($chunk) {
                $bufferObj.buffer += $chunk;
                while ($bufferObj.buffer) {

                    let $pos = $bufferObj.buffer.indexOf("\r\n\r\n");
                    if ($pos > -1) {

                        let $httpPos = $bufferObj.buffer.indexOf("HTTP/1.1")
                        
                        if ($httpPos == -1) {
                            $httpPos = 0
                        }

                        let $headers = $bufferObj.buffer.substr($httpPos, $pos - $httpPos + 4);
                        console.log($headers)
                        let $response = null
                        try {
                            $response = Util.parseResponse($headers);
                        } catch (e) {
                            console.log(e)
                            $connection.close();
                            return;
                        }

                        $bufferObj.buffer = $bufferObj.buffer.substr($pos + 4);

                        if ($response['statusCode'] === 200) {
                            ClientManager.addLocalDynamicConnection($connection, $response);
                        }
                        else if ($response['statusCode'] === 201) {
                            $connection.removeListener('data', fn);
                            fn = null
                            ClientManager.handleLocalConnection($connection, $config, $bufferObj, $response);
                            return
                        }

                        else {
                            console.error($response)
                            $connection.removeListener('data', fn);
                            $fn = null
                            $connection.close();
                            return
                        }

                    } else {
                        break
                    }
                }


            });


        }


        static async handleLocalConnection($connection, $config, $bufferObj, $response) {

            if (!isNode) {
                echo('start handleLocalConnection22' + "\n");
                $connection.on('data', async ($chunk) => {
                    $bufferObj.buffer += $chunk;

                    echo('start handleLocalConnection' + "\n");
                    let $proxy = null

                    if ($config['local-proxy']) {
                        // todo $proxy

                    }
                    let $r = function () {
                        return new Promise((resolve, reject) => {
                            try {
                                let $request = Util.parseRequest($bufferObj.buffer);
                                $bufferObj.buffer = '';
                                resolve($request)
                            } catch (e) {
                                console.error(e)
                                console.log('current buffer: ' + $bufferObj.buffer)
                                reject(e)
                            }
                        })
                    }

                    let $request = null

                    if (!$bufferObj.buffer) {
                        console.error('no buffer')
                        return;
                    }
                    try {

                        console.log('try parse')
                        console.log('current buffer ' + $bufferObj.buffer)
                        $request = await $r();

                        $bufferObj.buffer = ''
                    } catch (e) {
                        console.error(e, $bufferObj.buffer)
                    }
                    if ($request) {
                        console.log('parse requesr success', $request)

                        let req = http.request($request, (res) => {

                            const statusLine = `HTTP/1.1 ${res.statusCode} ${res.statusMessage}`;
                            let isChunked = false
                            let isZip = false

                            if (res.headers['transfer-encoding'] == 'chunked') {
                                // delete res.headers['transfer-encoding']
                                // delete res.headers['content-encoding']
                                isChunked = true

                                if (res.headers['content-encoding']) {
                                    isZip = true
                                }
                                res.headers['delete-content-encoding'] = res.headers['content-encoding']
                                delete res.headers['content-encoding']
                            } else {
                                if (res.headers['content-encoding']) {
                                    isZip = true
                                    res.headers['delete-content-encoding'] = res.headers['content-encoding']

                                    delete res.headers['content-encoding']
                                    res.headers['transfer-encoding'] = 'chunked'
                                    res.headers['Append'] = 'chunked'
                                    isChunked = true

                                }


                            }

                            if (isChunked) {
                                let headers = Object.entries(res.headers)
                                    .map(([name, value]) => `${name}: ${value}`)
                                    .join('\r\n');
                                // 将它们拼接成源字符串
                                let sourceString = `${statusLine}\r\n${headers}\r\n\r\n`;
                                console.log(sourceString);
                                $connection.write(sourceString);
                            }



                            res.on('data', function (buf) {
                                // console.log(res)
                                // console.log(res.statusCode)
                                // console.log(res.headers)
                                // console.log(buf)
                                var string = new TextDecoder().decode(buf);
                                if (isChunked) {
                                    if (isZip) {
                                        console.log('gzip')
                                        // zlib.gzip(buf, function (err, result) { 
                                        //     if (err) throw err;
                                        //     console.log(2321321321321,result)
                                        //     let length = result.length.toString(16);
                                        //     let _string = result.toString()
                                        //     console.log(555555555,_string)

                                        //     $connection.write(length + `\r\n` + _string + `\r\n`);
                                        // })
                                        let length = buf.length.toString(16);
                                        $connection.write(length + `\r\n` + string + `\r\n`);
                                    } else {
                                        // let length = string.length.toString(16);
                                        let length = buf.length.toString(16);

                                        $connection.write(length + `\r\n` + string + `\r\n`);
                                    }

                                } else {
                                    $connection.write(string);
                                }
                            });

                            res.on('end', function () {
                                console.log('end')

                                if (isChunked) {
                                    $connection.write(`0\r\n\r\n`);
                                }

                            });
                        })

                        req.end($request.body)

                    } else {
                        console.error('timeout' + "\n");
                        $connection.close();
                    }
                });
            } else {
                echo('node start handleLocalConnection' + "\n");

                let fn

                $connection.on('data', fn = function ($chunk) {
                    echo('dynamic connection receive data2222')
                    echo($chunk)
                    $bufferObj.buffer += $chunk;
                    echo('dynamic connection receive data2222')

                })
                echo('start handleLocalConnection11' + "\n");
                echo(
                    {
                        "tunnel_uuid": $config['uuid'],
                        // "dynamic_tunnel_uuid": $response['headers']['Uuid'],
                    }
                )
                // return;
                console.error('start handleLocalConnection22' + "\n")
                new Tunnel($config).getLocalTunnel($config['local_protocol'] || 'tcp').then(function ($localConnection) {
                    echo('local connection success' + "\n");
                    $connection.removeListener('data', fn);
                    fn = null
                    echo('local connection success22' + "\n");
                    echo(
                        {
                            "tunnel_uuid": $config['uuid'],
                            // "dynamic_tunnel_uuid": $response['headers']['Uuid'],
                        }
                    )
                    $connection.on('data', function ($data) {
                        if ($connection.protocol == 'udp') {
                            if ($data.indexOf('POST /close HTTP/1.1') > -1) {
                                $connection.close()
                                return;
                            }
                        }
                        // console.log(55555, $data)
                        $localConnection.write($data)
                    });

                    $localConnection.on('data', function ($data) {
                        // console.log($data)
                        // console.log('local connection receive data length-' + $data.toString().length)

                        // try {
                        //     fs.appendFile('test.txt', $data.toString(), function (err) { 

                        //     })
                        // } catch (error) {
                        //     console.log(error)
                        // }

                        console.log('local connection receive data length-' + $data.length)
                        $connection.write($data)
                    })




                    $localConnection.on('end', function () {
                        echo('local connection end')
                        echo({
                            'tunnel_uuid': $config['uuid'],
                            // 'dynamic_tunnel_uuid': $response['headers']['Uuid'],
                        })

                        if ($connection.protocol == 'udp') {
                            console.warn('udp dynamic connection close and try send close requset')
                            $connection.write(`POST /close HTTP/1.1\r\n\r\n`)
                        }
                    })

                    $localConnection.on('close', function () {
                        echo('local connection close')
                        $connection.close()
                    })

                    $connection.on('end', function () {
                        echo('udp dynamic connection end')
                    })

                    $connection.on('close', function () {
                        if ($connection.protocol == 'udp') {
                            echo('udp dynamic connection close')
                        }
                        $localConnection.close()
                    })

                    if ($bufferObj.buffer) {
                        console.log('write buffer', $bufferObj.buffer)
                        $localConnection.write($bufferObj.buffer)
                        $bufferObj.buffer = ''
                    }

                }).catch(function ($error) {
                    console.log($error)
                    let $content = $error.toString();

                    $headers = [
                        'HTTP/1.0 404 OK',
                        'Server: ReactPHP/1',
                        'Content-Type: text/html; charset=UTF-8',
                        'Content-Length: ' + ($content.length),
                    ];
                    $header = $headers.join(`\r\n`) + "\r\n\r\n";
                    $connection.write($header.$content);
                })

            }

        }


    }

    function timeout(ms) {
        return new Promise(resolve => setTimeout(resolve, ms));
    }


    ClientManager.createLocalTunnelConnection(parseINIString(inisString));

})(this);
