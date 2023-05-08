## install

```
composer create-project wpjscc/reactphp-intranet-penetration -vvv
```

## 运行免费客户端

先到先得有5个免费域名，支持https

127.0.0.1 换成自己要代理的主机

80 换成自己代理端口

123456 换成随机字符串，和domain绑定在一起（先绑先得）

### 免费域名1

```
php client.php --local-host=127.0.0.1 --local-port=80--domain=https://pszwunktoi.xiaofuwu.wpjs.cc	 --token=123456

```
### 免费域名2

```
php client.php --local-host=127.0.0.1 --local-port=80--domain=https://zpoijsyhnk.xiaofuwu.wpjs.cc --token=123456

```
### 免费域名3

```
php client.php --local-host=127.0.0.1 --local-port=80--domain=https://wkcpzoetjr.xiaofuwu.wpjs.cc --token=123456

```
### 免费域名4

```
php client.php --local-host=127.0.0.1 --local-port=80--domain=https://dpjkmfrqox.xiaofuwu.wpjs.cc --token=123456

```

### 免费域名5

```
php client.php --local-host=127.0.0.1 --local-port=80--domain=https://zlcpmkidyt.xiaofuwu.wpjs.cc --token=123456

```


## 自己搭建服务端

运行
```
php server.php --server-port=32123 --http-port=8080
```

客户端连接

```
php client.php --local-host=127.0.0.1 --local-port=80 --domain=yourdomain.com --token=123456 --remote-host=你的服务器ip --remote-port=32123
```

然后访问 yourdomain.com:8080



## 打包docker


```
docker build -t wpjscc/reactphp-intranet-penetration . -f Dockerfile
docker push wpjscc/reactphp-intranet-penetration
```

