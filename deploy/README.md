# Datacask 生产部署

该方案适用于单台 Linux 服务器运行 Datacask，并将备份文件写入外部 MinIO。Compose 包含一个 Web 应用、两个备份 Worker 和一个 PostgreSQL 元数据库。

## 前置条件

- Ubuntu 24.04 LTS 或其他受支持的 Linux 发行版
- Docker Engine 与 Docker Compose 插件
- 服务器能够访问待备份 MySQL 实例和外部 MinIO
- 一个指向服务器的域名，以及宿主机上的 Nginx、Caddy 或其他 HTTPS 反向代理
- 阿里云 ACR 镜像为私有时，需要具有拉取权限的固定密码或访问凭据

## 首次部署

在服务器上创建部署目录：

```bash
sudo mkdir -p /opt/datacask/{data,postgres-data}
sudo chown -R "$(id -u):$(id -g)" /opt/datacask
cd /opt/datacask
```

将 `compose.yaml` 和 `.env.example` 放入该目录，然后生成正式环境文件：

```bash
cp .env.example .env
chmod 600 .env
```

阿里云 ACR 镜像为私有时先登录：

```bash
printf '%s' "$ALIYUN_REGISTRY_PASSWORD" | docker login registry.cn-guangzhou.aliyuncs.com \
    --username "$ALIYUN_REGISTRY_USERNAME" --password-stdin
```

生成应用密钥：

```bash
docker run --rm registry.cn-guangzhou.aliyuncs.com/zhisuaninfo/datacask:1.7.14-dc.1 \
    php artisan key:generate --show
```

编辑 `.env`，至少替换 `APP_URL`、`APP_KEY` 和 `DB_PASSWORD`。`APP_KEY` 用于加密数据库和存储凭据，必须在独立的安全位置长期备份。

启动并检查服务：

```bash
docker compose pull
docker compose up -d
docker compose ps
curl -fsS http://127.0.0.1:2226/health
docker compose logs --tail=100 app worker
```

应用端口只监听在 `127.0.0.1:2226`。以 Caddy 为例，宿主机反向代理配置为：

```caddyfile
backup.example.com {
    reverse_proxy 127.0.0.1:2226
}
```

登录 Datacask 后，在卷配置中添加外部 MinIO，使用 S3 兼容端点、存储桶、区域和专用访问密钥。不要把 MinIO 凭据写入仓库。

## 镜像发布准备

在阿里云容器镜像服务广州地域的 `zhisuaninfo` 命名空间中创建以下镜像仓库：

- `datacask`
- `datacask-php`
- `node`
- `postgres`

外部依赖镜像使用固定的多架构索引摘要：

- `node`: `sha256:83f487e0a63425e5b4d146fb5e5be574bcbe1b7b843d3ebafdd95eaf7767a7e5`
- `postgres`: `sha256:18cfe3ef5e6815560c98237d6216d1e5119702fb0f3894c8785dd58b8bbe5d73`

`datacask-php` 与上游采用相同维护方式：镜像源码位于本仓库 `docker/php/`，仅在该目录变化或 ACR 中缺少 `latest` 时构建，并同时发布到 ACR 与 GHCR。Datacask 成品镜像的摘要由每次版本构建生成，应在部署前从 ACR 或 GHCR 核对。

在 GitHub 仓库的 Actions secrets 中配置：

- `ALIYUN_REGISTRY_USERNAME`
- `ALIYUN_REGISTRY_PASSWORD`

推送 `main` 或 `v*` 标签时，工作流会按需构建 `datacask-php`，将固定摘要的 Node 和 PostgreSQL 镜像复制到阿里云，再把 Datacask 成品镜像同时发布到阿里云 ACR 与 GHCR。生产环境默认拉取阿里云镜像。

Datacask 标签与上游规则保持一致：

- `1.7.14-dc.1`：不可变的精确发布版本，生产环境使用此类标签。
- `1.7` 和 `1`：指向对应版本系列中的最新正式版本。
- `latest`：指向最近一次正式发布。
- `edge`：指向 `main` 分支的最新构建，可能尚未发布，仅用于验证。
- 其他分支名：指向该分支的最新构建。

工作流不再创建新的 `sha-*` 标签；已存在的历史标签可以暂时保留用于定位旧提交。

## 首次验收

1. 添加一个数据量较小的 MySQL 实例。
2. 手动执行一次备份并确认 MinIO 中存在文件。
3. 将备份恢复到隔离的测试数据库。
4. 确认校验值、任务日志和通知均正常。
5. 分批接入剩余实例，并将计划分散到至少 6 小时的时间窗口。

## 升级

生产环境只使用精确版本标签。升级前保存 `.env`、`data/`，并备份 PostgreSQL：

```bash
docker compose exec -T postgres pg_dump -U datacask -d datacask -Fc > "datacask-$(date +%Y%m%d-%H%M%S).dump"
```

等待运行中的备份任务完成，修改 `.env` 中的 `DATACASK_IMAGE` 后执行：

```bash
docker compose pull
docker compose up -d --remove-orphans
docker compose ps
curl -fsS http://127.0.0.1:2226/health
```

## 回滚

将 `DATACASK_IMAGE` 改回上一个已验证版本，再运行：

```bash
docker compose pull
docker compose up -d --remove-orphans
```

应用启动时会自动执行数据库迁移。如果新版本迁移了元数据库，仅回退镜像可能不足，应先恢复升级前的 PostgreSQL dump 和 `data/` 快照，再启动旧版本。
