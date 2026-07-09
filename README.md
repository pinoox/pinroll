# Pinroll — Release Rollout Engine

**Pinroll** (`pinoox/pinroll`) provides atomic release rollout, rollback, and PinGate delivery for Pinoox.

| Concept | Meaning |
|---------|---------|
| **Target** | Where to deploy (server/environment) |
| **Bundle** | What to ship (platform-full, single-app, …) |
| **Transport** | How to send (pinion, ssh, ftp, local) |
| **PinGate** | Remote production entry point |

## Install

```bash
composer require pinoox/pinion pinoox/pinroll
```

## CLI

```bash
php pinoox pinroll:init
php pinoox pinroll:connect
php pinoox pinroll:push production
php pinoox pinroll:deploy production
php pinoox pinroll:apply production
php pinoox pinroll:push staging-app --package=com_pinoox_developer
php pinoox pinroll:build --bundle=single-app --package=com_pinoox_developer
php pinoox pinroll:status production
php pinoox pinroll:history
php pinoox pinroll:rollback production
php pinoox pinroll:gate production
php pinoox pinroll:vendor
php pinoox pinroll:pull --server=https://releases.example.com
```

- `pinroll:init` — scaffold `pinroll/` + `.env` key stubs (no prompts)
- `pinroll:connect` — ask deploy path + site URL, upload PinGate via FTP
- `pinroll:vendor` — export platform `vendor/` for host install or core update (`pinroll/vendor.zip`)
- `pinroll:gate` — rebuild/upload PinGate (`-z` optional zip; `--no-upload` keep local files)
- `pinroll:check` — verify target connectivity before push
- `pinroll:push` — build and upload only (no apply)
- `pinroll:deploy` — push + apply via PinGate (go live)
- `pinroll:apply` — apply a staged release on the target
- `pinroll:pull` — pull newer manifest from release server (alias: `pinroll:poll`)

Non-interactive wizard: `php pinoox pinroll:init -w`

## Tests

```bash
composer test
composer test:platform
```

MIT
