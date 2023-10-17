var browserify = require('browserify');
var b = browserify();
b.add('./src/client.js');
b.bundle().pipe(process.stdout);