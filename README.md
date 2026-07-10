# Pinroll — Release Rollout Engine

**Pinroll** (`pinoox/pinroll`) **1.1.0** — atomic release rollout, rollback, and PinGate delivery for Pinoox.

| Concept | Meaning |
|---------|---------|
| **Host** | Where to deploy (`production`, `staging`, …) |
| **Bundle** | What to ship (auto-detect apps, or `--bundle=…`) |
| **Transport** | How to send (`ftp`, `ssh`, `pinion`, `local`) |
| **PinGate** | Remote HTTP entry for install / status / rollback |

## Documentation

Full guides (setup, hosts, retention, rollback, CLI):

- [Pinroll — release & deploy](https://github.com/pinoox/docs/blob/master/en/deploy/pinroll.md) (EN)
- [Pinroll — انتشار و دیپلوی](https://github.com/pinoox/docs/blob/master/fa/deploy/pinroll.md) (FA)
- [Pinroll overview](https://github.com/pinoox/docs/blob/master/en/advanced/pinroll.md)

## Install

On a Pinoox **platform** project:

```bash
composer require --dev pinoox/pinroll
```

## Quick start

```bash
php pinoox pinroll:init
# fill PINROLL_* credentials in .env
php pinoox pinroll:connect
php pinoox pinroll:apps
php pinoox pinroll:check
php pinoox pinroll:deploy
```

| Step | Command | What it does |
|------|---------|--------------|
| 1 | `pinroll:init` | Scaffold `pinroll/pinroll.config.php` |
| 2 | Edit `.env` | Set `PINROLL_*` FTP/SSH keys |
| 3 | `pinroll:connect` | Deploy path, site URL, upload PinGate (`--reset` to re-run) |
| 4 | `pinroll:apps` | Set default packages for the host |
| 5 | `pinroll:check` | Verify transport + PinGate |
| 6 | `pinroll:deploy` | Build, upload, and install (go live) |

## CLI

```bash
php pinoox pinroll:init
php pinoox pinroll:connect
php pinoox pinroll:apps
php pinoox pinroll:push
php pinoox pinroll:deploy
php pinoox pinroll:install
php pinoox pinroll:push --app=com_pinoox_developer
php pinoox pinroll:build --bundle=single-app --package=com_pinoox_developer
php pinoox pinroll:status
php pinoox pinroll:history
php pinoox pinroll:rollback
php pinoox pinroll:cleanup
php pinoox pinroll:gate
php pinoox pinroll:vendor
php pinoox pinroll:pull --server=https://releases.example.com
```

- `pinroll:init` — scaffold `pinroll/` + `.env` key stubs (no prompts)
- `pinroll:connect` — configure host + upload PinGate; verifies if already set (`--reset` to redo)
- `pinroll:apps` — set `hosts.*.apps` (interactive or `--apps=`)
- `pinroll:check` — verify host connectivity before push
- `pinroll:push` — build and upload only (no install)
- `pinroll:deploy` — push + install via PinGate (go live)
- `pinroll:install` — install a staged release (`pinroll:apply` is a deprecated alias)
- `pinroll:rollback` — switch the host back to a previous release
- `pinroll:cleanup` — prune local/remote archives by `keep` / `store`
- `pinroll:gate` — rebuild/upload PinGate (`-z` zip; `--no-upload` keep local)
- `pinroll:vendor` — export platform `vendor/` (`pinroll/vendor.zip`)
- `pinroll:pull` — pull newer manifest from a release server (alias: `pinroll:poll`)

## Tests

```bash
composer test
composer test:platform
```

## License

MIT — [Pinoox](https://www.pinoox.com)
