# guojici 云服务商城

> 基于 PHP + MySQL 的虚拟主机与 KVM 云服务器销售管理系统（社区版）

![PHP](https://img.shields.io/badge/PHP-7.4-777BB4?logo=php&logoColor=white)
![MySQL](https://img.shields.io/badge/MySQL-5.7%2B-44791A?logo=mysql&logoColor=white)
![KVM](https://img.shields.io/badge/KVM-Libvirt-55A454?logo=linux&logoColor=white)
![Version](https://img.shields.io/badge/Version-v2.4.1-883696)

一个开箱即用的云计算服务销售平台，支持虚拟主机和 KVM 虚拟机的在线销售、自动开通、远程管理。内置用户系统、订单支付、工单客服、代理分销、API 开放平台等完整业务闭环。

## 功能特性

### 核心业务
- **主机销售**：支持虚拟主机和 KVM 虚拟机两种产品线，按月/季/年计费
- **自动开通**：支付成功后自动创建实例，分配 IP、开通服务
- **远程管理**：WebSSH 终端 + noVNC 图形控制台，浏览器内完成所有操作
- **规格升降级**：支持 CPU、内存、磁盘、带宽的在线调整
- **快照备份**：一键创建/恢复系统快照

### 用户中心
- 注册登录、邮箱验证、密码找回
- 实名认证（身份证 OCR 自动识别）
- 账户余额、消费记录、积分商城
- API 密钥管理（开放 API 调用）
- 消息通知中心

### 订单与支付
- 多周期计费（月付/季付/年付）
- 优惠券系统
- 易支付（EPay）集成，支持微信/支付宝
- 订单退款流程

### 代理分销
- 三级代理体系（超级总代 / 高级代理 / 普通代理）
- 自定义拿货折扣、佣金比例
- 邀请码推广、佣金结算、提现
- 代理专属定价

### 工单客服
- 多类型工单（技术/账户/退款）
- 工单流转、客服分配、处理追踪
- 企业话术库、智能质检

### 管理后台
- 仪表盘、营收统计、财务报表
- 用户管理、主机管理、订单管理
- 套餐管理、镜像管理、区域管理
- 系统设置（支付/邮件/KVM/FRP/宝塔/实名认证）
- 管理员账号、操作日志、安全告警

### 系统能力
- **KVM 管理**：通过 SSH/WebVirtCloud API 管理虚拟机生命周期
- **FRP 内网穿透**：自动配置端口映射，让内网服务对外可访问
- **宝塔面板对接**：虚拟主机自动开通
- **邮件服务**：PHPMailer 驱动，支持验证码、通知邮件
- **安全防护**：WAF 防火墙、IP 限流、安全响应头、验证码（图形/滑动）
- **定时任务**：节点监控、流量统计、安全巡检
- **多语言**：内置中文（zh-CN），支持扩展

## 技术栈

| 层级 | 技术 |
|------|------|
| 后端 | PHP 7.4（原生，无框架） |
| 数据库 | MySQL 5.7+ / MariaDB 10.3+ |
| 缓存 | Redis（可选，提升性能） |
| Web 服务器 | Nginx + PHP-FPM / Apache |
| 前端 | 原生 HTML + CSS + JavaScript |
| 远程控制台 | noVNC 1.4.0 |
| 邮件 | PHPMailer 6.x |
| 支付 | 易支付（EPay）SDK |
| 虚拟化 | KVM / libvirt / WebVirtCloud |

## 目录结构

```
api/
├── admin/              # 管理后台
│   ├── settings.php    # 系统设置
│   ├── packages.php    # 套餐管理
│   ├── hosts.php       # 主机管理
│   ├── orders.php      # 订单管理
│   ├── users.php       # 用户管理
│   ├── finance.php     # 财务管理
│   └── ...
├── api/                # 开放 API 接口
│   ├── api_vms.php     # 虚拟机 API
│   ├── api_orders.php  # 订单 API
│   ├── api_users.php   # 用户 API
│   └── ...
├── config/             # 核心配置
│   ├── app.php         # 主配置（数据库/支付/KVM 等）
│   ├── db.php          # 数据库封装
│   ├── helper.php      # 核心助手函数（表迁移/业务逻辑）
│   ├── auth.php        # 认证与权限
│   ├── security.php    # 安全防护（WAF/限流）
│   ├── KvmManager.php  # KVM 虚拟机管理
│   ├── Mailer.php      # 邮件发送
│   ├── CacheManager.php# 缓存管理
│   └── optimize/       # 性能优化配置
│       ├── nginx.conf
│       ├── php-fpm-www.conf
│       ├── opcache.ini
│       └── my.cnf
├── cron/               # 定时任务
│   ├── node_monitor.php
│   ├── traffic_monitor.php
│   └── security.php
├── data/               # 数据与缓存
│   ├── init.sql        # 数据库初始化脚本
│   └── settings_cache.php
├── lang/               # 多语言
│   └── zh-CN.php
├── novnc/              # noVNC 远程控制台
├── SDK/                # 易支付 SDK
├── PHPMailer-master/   # PHPMailer 邮件库
├── hym_license/        # 授权验证
├── templates/          # 页面模板
├── assets/             # 静态资源（CSS/JS）
├── index.php           # 首页
├── install.php         # 安装向导
├── init_db.php         # 数据库初始化
├── login.php           # 用户登录
├── checkout.php        # 结算支付
└── captcha.php         # 验证码
```

## 快速开始

### 环境要求

- PHP 7.4（仅支持此版本）
- MySQL 5.7 或更高版本
- Nginx 或 Apache
- Redis（可选，强烈推荐）
- cURL、PDO、mbstring、openssl 扩展

### 安装步骤

1. **上传代码**

   将本项目所有文件上传到 Web 服务器根目录。

2. **设置目录权限**

   ```bash
   chmod -R 755 .
   chmod -R 777 data/ config/.installed
   ```

3. **创建数据库用户**（使用 root 登录 MySQL）

   ```sql
   CREATE DATABASE guojici DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   CREATE USER 'guojici'@'localhost' IDENTIFIED BY '你的强密码';
   GRANT ALL PRIVILEGES ON guojici.* TO 'guojici'@'localhost';
   FLUSH PRIVILEGES;
   ```

4. **运行安装向导**

   浏览器访问 `http://你的域名/install.php`，按提示填写：
   - 数据库主机、端口、库名、用户名、密码
   - 管理员账号、密码
   - 平台基本信息

   安装向导会自动创建 `config/app.php` 并导入 `data/init.sql`。

5. **初始化数据库**（如安装向导未完成建表）

   访问 `http://你的域名/init_db.php`，点击「开始初始化」即可创建全部数据表。

6. **删除安装文件**（安全要求）

   ```bash
   rm install.php init_db.php
   # 或重命名备份
   mv install.php install.php.bak
   mv init_db.php init_db.php.bak
   ```

7. **登录后台**

   访问 `http://你的域名/admin/login.php`
   - 默认账号：`admin`
   - 默认密码：`admin123`
   - **请立即修改默认密码！**

### Nginx 配置示例

```nginx
server {
    listen 80;
    server_name your-domain.com;
    root /www/wwwroot/api;
    index index.php index.html;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/tmp/php-cgi.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }

    # 禁止访问敏感文件
    location ~ /\.(git|env|installed) {
        deny all;
    }
    location ~ /(data|config)/(?!.*\.css$) {
        deny all;
    }
}
```

## 配置说明

主配置文件 `config/app.php`，安装后也可在管理后台「系统设置」中在线修改（存储于 `settings` 数据表）。

### 关键配置项

| 配置块 | 说明 |
|--------|------|
| `db` | 数据库连接 |
| `kvm` | KVM 虚拟机管理（SSH/WebVirtCloud） |
| `payment` / `epay` | 支付配置 |
| `frp` | FRP 内网穿透 |
| `bt_panel` | 宝塔面板对接 |
| `smtp` | 邮件发送 |
| `idverify` | 实名认证（聚合数据） |
| `redis` | Redis 缓存 |
| `site` | 站点信息 |

> 生产环境请务必将 `app.debug` 设为 `false`。

## 数据库表

系统共 29+ 张表，核心表包括：

| 表名 | 用途 |
|------|------|
| `users` | 用户 |
| `packages` | 套餐 |
| `orders` | 订单 |
| `hosts` | 主机实例 |
| `admin_users` | 管理员 |
| `settings` | 系统设置（键值对） |
| `tickets` | 工单 |
| `agent_levels` | 代理等级 |
| `agents` | 代理账号 |
| `point_products` | 积分商品 |
| `ip_pools` | IP 池 |
| `vm_images` | 镜像 |
| ... | ... |

完整表结构见 [data/init.sql](data/init.sql)。

## 定时任务

建议添加以下 crontab：

```bash
# 节点监控（每分钟）
* * * * * php /www/wwwroot/api/cron/node_monitor.php

# 流量统计（每5分钟）
*/5 * * * * php /www/wwwroot/api/cron/traffic_monitor.php

# 安全巡检（每小时）
0 * * * * php /www/wwwroot/api/cron/security.php
```

## 默认账号

| 角色 | 用户名 | 密码 |
|------|--------|------|
| 管理员 | `admin` | `admin123` |
| 测试用户 | `test@example.com` | `123456` |

> 部署后请立即修改默认密码并删除测试账号。

## 安全建议

1. **修改默认密码**：安装后立即修改 admin 密码
2. **删除安装文件**：`install.php`、`init_db.php` 用完即删
3. **关闭调试模式**：生产环境 `app.debug = false`
4. **配置 HTTPS**：使用 Let's Encrypt 等免费证书
5. **限制后台访问**：通过 Nginx/IP 白名单限制 `/admin/` 访问
6. **定期备份**：数据库和代码定期备份
7. **敏感目录**：确保 `data/`、`config/` 不可被直接访问

## 性能优化

项目内置优化配置，位于 `config/optimize/`：

- `opcache.ini` — PHP OPcache 加速
- `php-fpm-www.conf` — PHP-FPM 进程池调优
- `nginx.conf` — Nginx 优化配置
- `my.cnf` — MySQL 参数调优
- `boot_optimize.sh` — 一键优化脚本

配合 Redis 缓存可显著提升并发性能。

## 开放 API

系统提供 RESTful 风格的开放 API，用户可在「个人中心 → API 密钥」申请密钥后调用。

主要接口模块：
- 虚拟机管理（创建/启动/停止/重启/重装）
- 订单查询
- 用户信息
- 套餐查询

详见 `api/` 目录。

## 版本功能矩阵

| 功能 | 社区版 | 试用版 | 标准版 | 企业版 |
|------|--------|--------|--------|--------|
| | 开源免费 | 5用户/5VM/2天 | 不限VM/用户 | 全部功能 |
| 基础主机管理 | ✅ | ✅ | ✅ | ✅ |
| KVM 云服务器 | ✅ | ✅ | ✅ | ✅ |
| NAT 共享机型 | ❌ | ❌ | ❌ | ✅ |
| 广告联盟 | ❌ | ❌ | ❌ | ✅ |
| 推广返现 | ❌ | ❌ | ❌ | ✅ |
| 积分兑换 | ❌ | ❌ | ❌ | ✅ |
| 工单系统 | ✅ | ✅ | ✅ | ✅ |
| API 密钥 | ✅ | ✅ | ✅ | ✅ |
| 快照管理 | ✅ | ✅ | ✅ | ✅ |
| 防火墙管理 | ✅ | ✅ | ✅ | ✅ |
| 知识库系统 | ✅ | ✅ | ✅ | ✅ |
| 在线客服系统 | ❌ | ❌ | ❌ | ✅ |
| 服务器监控 | ❌ | ❌ | ❌ | ✅ |
| 性能监控 | ❌ | ❌ | ❌ | ✅ |
| 角色权限管理 | ❌ | ❌ | ❌ | ✅ |

## 许可证

本项目基于 [MIT License](LICENSE) 开源。

任何人可自由使用、修改、分发、商用，只需在副本中保留原始版权声明和许可声明。

## 相关技术

- [noVNC](https://github.com/novnc/noVNC) — 浏览器 VNC 客户端
- [PHPMailer](https://github.com/PHPMailer/PHPMailer) — PHP 邮件发送库
- [KVM/libvirt](https://www.libvirt.org/) — Linux 内核虚拟机
- [FRP](https://github.com/fatedier/frp) — 内网穿透
