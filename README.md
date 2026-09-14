# Datacask

面向团队的自托管数据库备份与恢复平台。

[![Upstream](https://img.shields.io/badge/upstream-Databasement-6366f1)](https://github.com/David-Crty/databasement)
[![Repository](https://img.shields.io/badge/repository-hjdyzy%2Fdatacask-0f766e)](https://github.com/hjdyzy/datacask)
[![License](https://img.shields.io/badge/license-MIT-2563eb)](LICENSE)

## 项目介绍

Datacask 基于开源项目 [Databasement](https://github.com/David-Crty/databasement)
进行二次开发，提供数据库服务器登记、自动备份、快照管理、跨实例恢复、保留策略、
多存储后端、失败通知和团队权限控制。

当前主要使用场景是 MySQL 数据库备份。一个 MySQL 实例可以按全部数据库、指定数据库
或名称模式进行选择，每个数据库生成独立的备份任务、快照和文件。

## MySQL 能力边界

- 使用 `mariadb-dump` 生成逻辑全量备份。
- 支持手动和周期备份、压缩、加密、校验、保留和跨实例恢复。
- 支持将备份恢复到原实例或兼容的其他实例。
- 不提供 MySQL 主从复制、GTID 管理、binlog 持续归档、增量备份或时间点恢复。
- MySQL 高可用与持续同步应由 MySQL Replication、InnoDB Cluster 等独立方案负责。

## 存储后端

备份文件可以保存到：

- 本地磁盘
- S3 兼容对象存储，包括 MinIO
- Azure Blob Storage
- Samba/SMB
- SFTP
- FTP

这里的 MinIO 仅作为备份文件的存储目标，不表示 Datacask 会备份 MinIO 中的对象数据。

## 简体中文

Datacask 默认使用简体中文，并保留英文、繁体中文、法语、西班牙语和希腊语。
用户可以在偏好设置中切换语言。部署时也可以通过环境变量指定：

```dotenv
APP_NAME=Datacask
APP_LOCALE=zh_CN
APP_FALLBACK_LOCALE=en
```

## 技术栈

- PHP 8.5
- Laravel 13
- Livewire 4
- Mary UI、daisyUI 和 Tailwind CSS
- Pest 5
- Docker Compose

## 本地开发

项目要求所有 PHP、Composer 和 Pest 命令在 Docker 中运行。准备好 Docker 后执行：

```bash
git clone https://github.com/hjdyzy/datacask.git
cd datacask
make setup
```

启动完成后访问 <http://localhost:2226>，首次使用时创建管理员账号。

常用命令：

```bash
make start
make test
make lint-check
make phpstan
npm run build
```

正式版本同时发布到阿里云 ACR 和 GitHub Container Registry。国内生产服务器默认使用
`registry.cn-guangzhou.aliyuncs.com/hjdyzy/datacask`，具体配置、验收和回滚步骤见
[生产部署说明](deploy/README.md)。

## 同步上游

本地仓库建议保留两个远程：

```bash
git remote add upstream https://github.com/David-Crty/databasement.git
git fetch upstream
git merge upstream/main
```

`origin` 指向 Datacask Fork，`upstream` 指向 Databasement。品牌修改尽量限制在配置、
页面展示和项目文档中，以降低后续合并上游更新的冲突。

## 上游文档

完整功能和部署文档目前沿用 Databasement 上游文档：

- [使用文档](https://david-crty.github.io/databasement/)
- [Docker 部署](https://david-crty.github.io/databasement/self-hosting/docker)
- [数据库服务器](https://david-crty.github.io/databasement/user-guide/database-servers)
- [备份配置](https://david-crty.github.io/databasement/user-guide/backups)

## 许可证与归属

Datacask 基于 Databasement 修改，遵循 MIT License。原项目版权归 David Courtey 及
Databasement Contributors 所有，详见 [LICENSE](LICENSE)。二次分发时必须保留原版权
声明和许可证文本。
