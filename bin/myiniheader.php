<?php
$myini = "
openssl.cafile=/etc/ssl/cacert.pem
";
$f=fopen("myiniheader.bin", "wb");
fwrite($f, "\xfd\xf6\x69\xe6");
fwrite($f, pack("N", strlen($myini)));
fwrite($f, $myini);
fclose($f);