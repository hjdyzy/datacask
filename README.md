# Datacask

面向团队的自托管数据库备份与恢复平台。

[![Repository](https://img.shields.io/badge/repository-hjdyzy%2Fdatacask-0f766e)](https://github.com/hjdyzy/datacask)
[![License](https://img.shields.io/badge/license-MIT-2563eb)](LICENSE)

Datacask 统一管理数据库实例、备份计划、备份文件和恢复任务，适合在企业内网或私有云中部署。

## 核心能力

- 支持 MySQL、MariaDB、PostgreSQL、SQL Server、MongoDB、SQLite、Firebird 和 Redis/Valkey。
- 集中管理多个数据库实例和数据库。
- 支持手动备份、定时备份及独立的保留策略。
- 每个数据库生成独立备份，便于检索、下载和恢复。
- 支持压缩、加密、完整性校验、失败通知和操作审计。
- 支持恢复到原实例或兼容的其他实例。
- 支持本地磁盘、MinIO/S3、Azure Blob、Samba、SFTP 和 FTP。
- 支持组织、成员和权限管理，以及 SSH 隧道连接。
- 默认提供简体中文界面，并支持多语言切换。

## 与上游 Databasement 的区别

Datacask 基于 [Databasement](https://github.com/David-Crty/databasement) 持续开发，
继承上游的数据库类型、备份和恢复能力，并增加：

- 默认使用简体中文，保留上游已有的多语言切换能力。
- 原生企业微信机器人通知渠道，支持发送备份失败等告警。
- 每 5 分钟巡检直连或 SSH 连接的数据库服务器；连续两次失败后告警，连续两次成功后通知恢复。
  巡检告警遵循服务器的通知渠道和“失败”通知设置；Agent 管理的数据库暂不支持数据库级巡检。
- 在失败快照上单独重备对应数据库，生成新的快照和任务，保留原失败记录；同库任务未完成时禁止重复提交。
- 独立的 [Datacask 文档站](https://hjdyzy.github.io/datacask/)和阿里云 ACR 公共镜像，
  正式发布镜像使用 `latest`，开发构建使用 `edge`。

## MySQL 支持

MySQL 和 MariaDB 使用逻辑全量备份，可按全部数据库、指定数据库或名称模式创建计划。
Datacask 不提供 binlog 增量备份、时间点恢复或主从复制管理；这类能力应由 MySQL 专用方案承担。

## 部署

生产环境默认跟随最新正式版本：

```text
registry.cn-guangzhou.aliyuncs.com/zhisuaninfo/datacask:latest
```

完整安装、升级和回滚步骤见 [生产部署指南](deploy/README.md)。

## 开发

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

## 许可证

Datacask 基于 Databasement 修改，遵循 MIT License。原项目版权归 David Courtey 及
Databasement Contributors 所有，详见 [LICENSE](LICENSE)。二次分发时必须保留原版权
声明和许可证文本。
