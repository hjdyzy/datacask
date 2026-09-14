# Datacask 生产部署

本指南使用 Docker Compose 在单台 Linux 服务器运行 Datacask。默认包含一个 Web 服务、
两个备份 Worker 和一个 PostgreSQL 元数据库，备份文件可写入外部 MinIO。

## 前置条件

- Linux 服务器
- Docker Engine 与 Docker Compose 插件
- 服务器能够访问待备份 MySQL 实例和外部 MinIO
- 域名及 HTTPS 反向代理
- 阿里云 ACR 拉取凭据

## 首次部署

在服务器上创建部署目录：

```bash
sudo mkdir -p /opt/datacask/{data,postgres-data}
sudo chown -R "$(id -u):$(id -g)" /opt/datacask
cd /opt/datacask
```

下载部署文件：

```bash
set -euo pipefail
DATACASK_RELEASE=v1.7.14-dc.1
DATACASK_RAW_URL="https://raw.githubusercontent.com/hjdyzy/datacask/${DATACASK_RELEASE}/deploy"

curl --fail --location --retry 5 --connect-timeout 10 --max-time 60 \
    --output compose.yaml "${DATACASK_RAW_URL}/compose.yaml"
curl --fail --location --retry 5 --connect-timeout 10 --max-time 60 \
    --output .env.example "${DATACASK_RAW_URL}/.env.example"

test -f .env || cp .env.example .env
chmod 600 .env
```

登录阿里云 ACR：

```bash
docker login registry.cn-guangzhou.aliyuncs.com --username "<ACR 用户名>"
```

按提示输入 ACR 密码。

生成应用密钥：

```bash
docker run --rm registry.cn-guangzhou.aliyuncs.com/zhisuaninfo/datacask:1.7.14-dc.1 \
    php artisan key:generate --show
```

将生成的密钥写入 `.env`，并设置访问地址和数据库密码：

```dotenv
APP_URL=https://backup.example.com
APP_KEY=base64:replace-with-generated-key
DB_PASSWORD=replace-with-a-strong-password
```

请妥善备份 `.env`，丢失 `APP_KEY` 将导致已保存的数据库和存储凭据无法解密。

启动并检查服务：

```bash
docker compose pull
docker compose up -d
docker compose ps
curl -fsS http://127.0.0.1:2226/health
docker compose logs --tail=100 app worker
```

应用监听 `127.0.0.1:2226`，需要通过 HTTPS 反向代理访问。Caddy 示例：

```caddyfile
backup.example.com {
    reverse_proxy 127.0.0.1:2226
}
```

登录 Datacask 后，在“卷”中添加 S3 存储，填写 MinIO 端点、存储桶、区域和专用访问密钥。

## 首次验收

1. 添加一个测试用 MySQL 实例。
2. 执行备份并确认 MinIO 中存在备份文件。
3. 将备份恢复到隔离数据库，确认数据可用。
4. 验证通过后再接入生产实例。

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
