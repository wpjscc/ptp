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
php client.php --local-host=127.0.0.1 --local-port=80 --domain=pszwunktoi.xiaofuwu.wpjs.cc	 --token=123456

```
### 免费域名2

```
php client.php --local-host=127.0.0.1 --local-port=80 --domain=zpoijsyhnk.xiaofuwu.wpjs.cc --token=123456

```
### 免费域名3

```
php client.php --local-host=127.0.0.1 --local-port=80 --domain=wkcpzoetjr.xiaofuwu.wpjs.cc --token=123456

```
### 免费域名4

```
php client.php --local-host=127.0.0.1 --local-port=80 --domain=dpjkmfrqox.xiaofuwu.wpjs.cc --token=123456

```

### 免费域名5

```
php client.php --local-host=127.0.0.1 --local-port=80 --domain=zlcpmkidyt.xiaofuwu.wpjs.cc --token=123456

```

## docker运行客户端

在上方例子前统一加个docker run -it wpjscc/reactphp-intranet-penetration 

例如免费域名1

```
docker run -it wpjscc/reactphp-intranet-penetration php client.php --local-host=127.0.0.1 --local-port=80 --domain=pszwunktoi.xiaofuwu.wpjs.cc	--token=123456
```

注意替换掉 127.0.0.1 80端口 和 token 123456

停止运行docker

```
docker ps | grep reactphp-intranet-penetration
```

找到输出的container id

```
docker stop id
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


## 增大 udp 缓冲区


```
sysctl -w net.core.rmem_max=5000000
sysctl -w net.core.wmem_max=5000000
sysctl -w net.core.rmem_default=5000000
sysctl -w net.core.wmem_default=5000000
```

https://github.com/xtaci/kcptun#quickstart
```
net.core.rmem_max=26214400 // BDP - bandwidth delay product
net.core.rmem_default=26214400
net.core.wmem_max=26214400
net.core.wmem_default=26214400
net.core.netdev_max_backlog=2048 // proportional to -rcvwnd
```
## todo

* 带宽限制
* 加密与压缩
* udp 传输长度
* 最大数量限制
* 服务描述（新开一个描述码，统一处理这个描述码）
* 水管
