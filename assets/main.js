// var browserify = require('browserify');
// var watchify = require('watchify');
// var b = browserify({ cache: {}, packageCache: {} });
// b.plugin(watchify);
// b.add('./src/client.js');
// b.bundle().pipe(process.stdout);

var fs = require('fs');
var browserify = require('browserify');
var watchify = require('watchify');

var b = browserify({
  entries: ['./src/client.js'],
  cache: {},
  packageCache: {},
  plugin: [watchify]
});

b.on('update', bundle);
bundle();

function bundle() {
  b.bundle()
    .on('error', console.error)
    .pipe(fs.createWriteStream('build/client.js'))
  ;
}