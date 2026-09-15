# Datacask Helm Chart

Deploy [Datacask](https://github.com/hjdyzy/datacask) on Kubernetes using Helm.

The chart version matches the application version — chart `1.0.1` deploys app `1.0.1`.

## Links

- [Documentation](https://hjdyzy.github.io/datacask)
- [GitHub Repository](https://github.com/hjdyzy/datacask)
- [GitHub Releases](https://github.com/hjdyzy/datacask/releases)
- Container image: `registry.cn-guangzhou.aliyuncs.com/zhisuaninfo/datacask`

## Installation

### 1. Add the Helm Repository

```bash
helm repo add datacask https://hjdyzy.github.io/datacask
helm repo update
```

### 2. Generate an Application Key

Before deploying, generate an application encryption key:

```bash
docker run --rm registry.cn-guangzhou.aliyuncs.com/zhisuaninfo/datacask:latest php artisan key:generate --show
```

Copy the output (e.g., `base64:abc123...`) for use in your values file.

### 3. Create a Values File

#### Minimal Configuration (SQLite)

For simple deployments using SQLite:

```yaml
app:
  url: https://backup.yourdomain.com
  appKey:
    value: "base64:your-generated-key-here"

ingress:
  enabled: true
  className: nginx
  host: backup.yourdomain.com
  # For HTTPS using cert-manager:
  # tlsSecretName: databasement-tls
  # annotations:
  #   cert-manager.io/cluster-issuer: letsencrypt-prod
```

#### Production Configuration (External Database)

For production, we recommend using MySQL or PostgreSQL instead of SQLite:

```yaml
database:
  connection: mysql  # or pgsql
  host: your-mysql-host.example.com
  port: 3306
  name: databasement
  username: databasement
  password:
    value: "your-secure-password"

ingress:
  enabled: true
  className: nginx
  host: backup.yourdomain.com
```

#### Using Existing Secrets

For sensitive values, you can reference existing Kubernetes secrets:

```yaml
app:
  appKey:
    fromSecret:
      secretName: "my-app-secret"
      secretKey: "APP_KEY"

database:
  connection: mysql
  host: mysql.example.com
  name: databasement
  username: databasement
  password:
    fromSecret:
      secretName: "my-db-secret"
      secretKey: "password"
```

### 4. Install the Chart

```bash
helm upgrade --install datacask datacask/databasement -f values.yaml
```

## Updating

```bash
helm repo update
helm upgrade --install datacask datacask/databasement --version X.X.X -f values.yaml
```

Migrations run automatically on startup.

## Configuration

See [values.yaml](values.yaml) for the full list of configurable parameters.

For all available environment variables, see the [Configuration Documentation](https://hjdyzy.github.io/datacask/self-hosting/configuration).

### Custom Environment Variables

Use `extraEnv` to pass additional environment variables:

```yaml
extraEnv:
  AWS_ACCESS_KEY_ID: "your-access-key"
  AWS_SECRET_ACCESS_KEY: "your-secret-key"
  AWS_DEFAULT_REGION: "us-east-1"
```

### Environment Variables from Secrets/ConfigMaps

Use `extraEnvFrom` to load environment variables from existing secrets or configmaps:

```yaml
extraEnvFrom:
  - secretRef:
      name: aws-credentials
  - configMapRef:
      name: app-config
```

This is useful for injecting credentials managed by external secret management tools (e.g., External Secrets Operator, Sealed Secrets).

### Persistence

By default, persistence is enabled with a 10Gi volume:

```yaml
persistence:
  enabled: true
  storageClass: ""  # Uses default storage class
  size: 10Gi
  accessModes:
    - ReadWriteOnce
```

### Worker Configuration

The queue worker runs as a sidecar container by default. You can customize its behavior:

```yaml
worker:
  enabled: true
  command: "php artisan queue:work --queue=backups,default --tries=3 --timeout=3600"
  resources:
    limits:
      cpu: 500m
      memory: 512Mi
    requests:
      cpu: 100m
      memory: 256Mi
```

For high-availability setups with an external database, you can run the worker as a separate deployment:

```yaml
worker:
  separateDeployment: true
  replicaCount: 2
```

 > ℹ️ Separate worker deployment requires either ReadWriteMany storage or AWS S3 storage + an external database (MySQL/PostgreSQL).

## License

This project is licensed under the MIT License - see the [LICENSE](https://github.com/hjdyzy/datacask/blob/main/LICENSE) file for details.
