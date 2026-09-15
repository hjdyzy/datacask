---
sidebar_position: 6
---

# Versioning

Datacask follows [semantic versioning](https://semver.org/). The Docker images, Helm chart, and application all share the same version number — version `1.0.1` means the same release everywhere.

Every change is listed on the [Changelog](../changelog.md) page, which is also shown inside the application (linked from the sidebar and the update dialog). Available versions are listed on [GitHub Releases](https://github.com/hjdyzy/datacask/releases).

## Docker Image Tags

Docker images are published to the public Alibaba Cloud ACR repository. When a new version is released (e.g., `1.0.1`), the following tags are published:

- `registry.cn-guangzhou.aliyuncs.com/zhisuaninfo/datacask:1.0.1` — exact version (pinned)
- `registry.cn-guangzhou.aliyuncs.com/zhisuaninfo/datacask:1.0` — latest patch in the 1.0.x line
- `registry.cn-guangzhou.aliyuncs.com/zhisuaninfo/datacask:1` — latest release in the 1.x.x line
- `registry.cn-guangzhou.aliyuncs.com/zhisuaninfo/datacask:latest` — most recent release

The deployment guides use `latest` to follow the newest production release. Pin an exact version when you need a controlled upgrade or rollback target.

There is also `registry.cn-guangzhou.aliyuncs.com/zhisuaninfo/datacask:edge`, rebuilt from every push to `main`. It contains unreleased changes and carries no version number, so use it to try something out, never in production.

## Helm Chart

The Helm chart uses the same version as the application — installing chart version `1.0.1` deploys app version `1.0.1`.

### Install

```bash
helm repo add datacask https://hjdyzy.github.io/datacask
helm repo update
helm install datacask datacask/databasement --version 1.0.1
```

### Update

```bash
helm repo update
helm upgrade datacask datacask/databasement --version 1.0.1
```

### Use as a dependency

In your `Chart.yaml`:

```yaml
dependencies:
  - name: databasement
    version: "1.0.1"
    repository: "https://hjdyzy.github.io/datacask"
```

Then run `helm dependency update`.

For full Helm configuration options, see the [Kubernetes + Helm](./kubernetes-helm) guide.
