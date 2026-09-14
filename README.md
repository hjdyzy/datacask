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

## MySQL 支持

MySQL 和 MariaDB 使用逻辑全量备份，可按全部数据库、指定数据库或名称模式创建计划。
Datacask 不提供 binlog 增量备份、时间点恢复或主从复制管理；这类能力应由 MySQL 专用方案承担。

## 部署

生产环境使用固定版本镜像：

```text
registry.cn-guangzhou.aliyuncs.com/zhisuaninfo/datacask:1.7.14-dc.1
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
