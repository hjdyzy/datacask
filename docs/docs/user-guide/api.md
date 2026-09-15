---
sidebar_position: 11
---

# REST API

Datacask provides a REST API for managing database servers, backups, snapshots, and storage volumes programmatically.

## Authentication

All API requests require a Bearer token. Create one from **Settings → API Tokens** in the Datacask UI.

Include the token in the `Authorization` header:

```
Authorization: Bearer YOUR_TOKEN
```

## API Documentation

Interactive API documentation is available at `/docs/api` on your Datacask instance. It is auto-generated from the codebase using [Scramble](https://scramble.dedoc.co/) and includes a "Try It" feature for testing endpoints directly.
