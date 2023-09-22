var browserify = require('browserify');
var b = browserify();
b.require('events');
b.require('stream');
b.require('buffer');
b.require('http');
b.require('http-parser-js');
b.bundle().pipe(process.stdout);