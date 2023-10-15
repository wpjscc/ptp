[[toc]]

# 快速开始

## 要求

* 服务端IP
* 服务端暴露32123

## 下载

### Linux
```
curl -o ptp https://github.com/wpjscc/ptp/releases/download/v1.1.0/release-ptp-linux-v1.1.0 && chmod +x ptp
```

### Mac
```
curl -o ptp https://github.com/wpjscc/ptp/releases/download/v1.1.0/release-ptp-mac-v1.1.0  && chmod +x ptp
```


## 配置


这个示例将本地的8080端口暴露出去


1 服务端配置 `ptps.ini`

```ini
[common]
tunnel_80_port = 32123
```

* `tunnel_80_port` 客户端连接的端口
* `http_port` 暴露的公网http端口,供用户访问

2 客户端配置 `ptpc.ini`

```ini
[common]
tunnel_host = x.x.x.x 或 www.domain.com
tunnel_80_port = 32123

[web]
local_host = 192.168.1.9
local_port = 8080
local_reaplce_host = true
domain = x.x.x.x:32123 或 www.domain.com:32123
```


* `tunnel_host` 为公网服务器的ip或者指向公网服务器的域名
* `tunnel_80_port` 上方的服务端监听的端口
* `local_host` 暴露的内网的ip
* `local_port` 暴露的内网的端口
* `local_reaplce_host` 替换host（http协议需要）
* `domain` 用户访问的域名或ip

3 运行

服务端
```bash
./ptp -s
```

客户端
```bash
./ptp -c
```

4 验证

能正常访问 x.x.x.x:32123 说明部署没问题


## 正在快速开发中...