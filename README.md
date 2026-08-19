# Pinroll — Release Rollout Engine

**Pinroll** (`pinoox/pinroll`) **1.5.0** — atomic release rollout, rollback, PinGate delivery, and blank-host provision for Pinoox.

| Concept | Meaning |
|---------|---------|
| **Host** | Where to deploy (`production`, `staging`, …) |
| **Bundle** | What to ship (auto-detect apps, or `--bundle=…`) |
| **Transport** | How to send (`ftp`, `ssh`, `pinion`, `local`) |
| **PinGate** | One public file `pingate.php` for install / status / rollback / vendor extract |

## Documentation

Full guides (setup, hosts, retention, rollback, CLI):

- [Pinroll — release & deploy](https://github.com/pinoox/docs/blob/master/en/deploy/pinroll.md) (EN)
- [Pinroll — انتشار و دیپلوی](https://github.com/pinoox/docs/blob/master/fa/deploy/pinroll.md) (FA)
- [Pinroll overview](https://github.com/pinoox/docs/blob/master/en/advanced/pinroll.md)

## Install

CLI on the **dev machine** (recommended):

```bash
composer require --dev pinoox/pinroll
```

The host does **not** need Pinroll in `vendor/`. `pingate.php` applies releases with pincore (`pinx:install` / `pinx:update`) and Pinion.

Put Pinroll in `require` only if you want PinGate to use Pinroll classes on the server.

## Quick start

```bash
php pinoox pinroll:init
# fill FTP/SSH in .pinoox/pinroll.config.php or .env
php pinoox pinroll:provision          # first install on empty FTP
# later:
php pinoox pinroll:connect
php pinoox pinroll:apps
php pinoox pinroll:deploy --full      # platform + every installed app
```

| Step | Command | What it does |
|------|---------|--------------|
| 1 | `pinroll:init` | Scaffold `.pinoox/pinroll.config.php` |
| 2 | Edit `.env` | Set `PINROLL_*` FTP/SSH keys |
| 3 | `pinroll:provision` | **Blank host:** PinGate + platform.zip + installer setup |
| 3b | `pinroll:connect` | Existing site: deploy path, site URL, upload PinGate |
| 4 | `pinroll:apps` | Set default packages for the host |
| 5 | `pinroll:vendor --push` | Build production `vendor.zip`, upload, extract on host |
| 6 | `pinroll:check` | Verify transport + PinGate |
| 7 | `pinroll:deploy` | Build, upload, and install (go live) |
| 8 | `pinroll:setup` | Post-deploy migrate + patch (`--seed`, `--config`) |

Single-app (pinx-root): `pinx deploy` forwards to `pinroll:deploy`.

### Subdomain folders

`deploy_path` is the FTP folder at account root (e.g. `apps`) that is linked as a subdomain. The site URL is used **as entered** for PinGate — path and URL are not mixed:

| FTP folder | Site URL | Gate URL |
|------------|----------|----------|
| `apps` | `https://apps.example.com` | `https://apps.example.com/pingate.php?route=` |
| `public_html` | `https://example.com` | `https://example.com/pingate.php?route=` |
| `public_html/shop` | `https://example.com/shop` | `https://example.com/shop/pingate.php?route=` |

If `pinroll:check` reports **Not PinGate JSON**, upload `pingate.php` next to `index.php` and confirm the site URL matches the live domain. Do **not** use PATH_INFO (`pingate.php/push/…`); routing is `?route=`.

## CLI

```bash
php pinoox pinroll:init
php pinoox pinroll:provision
php pinoox pinroll:connect
php pinoox pinroll:config
php pinoox pinroll:apps
php pinoox pinroll:push
php pinoox pinroll:deploy
php pinoox pinroll:deploy --full
php pinoox pinroll:deploy --app=com_pinoox_developer
php pinoox pinroll:deploy --theme
php pinoox pinroll:deploy --platform
php pinoox pinroll:setup
php pinoox pinroll:setup --dry-run
php pinoox pinroll:setup --migrate --patch --seed
php pinoox pinroll:install
php pinoox pinroll:build --bundle=single-app --package=com_pinoox_developer
php pinoox pinroll:status
php pinoox pinroll:history
php pinoox pinroll:rollback
php pinoox pinroll:cleanup
php pinoox pinroll:gate
php pinoox pinroll:vendor
php pinoox pinroll:vendor --push
php pinoox pinroll:pull --server=https://releases.example.com
```

- `pinroll:init` — scaffold a short `.pinoox/pinroll.config.php` overlay + `.env` key stubs (canonical defaults live in the Pinroll library)
- `pinroll:provision` — blank-host install: PinGate + `platform.zip` extract + installer setup (welcome/manager router, installer disabled; `--setup-only` to retry setup)
- `pinroll:connect` — configure host + upload PinGate; writes **site origin + token** into the overlay; verifies if already set (`--reset` to redo)
- `pinroll:config` — print resolved host (origin, gate URL, via, path, token redacted)
- `pinroll:apps` — set `hosts.*.apps` (interactive or `--apps=`)
- `pinroll:check` — verify host connectivity before push
- `pinroll:push` — build and upload only (no install)
- `pinroll:deploy` — push + install via PinGate (go live); runs `fe:build` before `pinx:build`
- `pinroll:deploy --full` — platform zip (`pinx:update`) plus every installed/discovered app
- `pinroll:deploy --platform` — `pinx:build platform` then `pinx:update` on the host
- `pinroll:deploy --theme` — rebuild theme assets (`fe:build`) then include in the app `.pinx` / FTP dist
- `pinroll:setup` — post-deploy migrate + patch (add `--seed`, `--config`, `--dry-run`)
- `pinroll:install` — install a staged release (`pinroll:apply` is a deprecated alias)
- `pinroll:rollback` — switch the host back to a previous release
- `pinroll:cleanup` — prune local/remote archives by `keep` / `store`
- `pinroll:gate` — rebuild/upload a single `pingate.php` (`-z` zip; `--no-upload` keep local)
- `pinroll:vendor` — production `vendor.zip` via PlatformComposer (`--push` FTP + PinGate `POST ?route=vendor`)
- `pinroll:pull` — pull newer manifest from a release server (alias: `pinroll:poll`)

Updates on the host use the same pincore APIs as the CLI: **app/theme** → `PinxInstaller` (`pinx:install`), **platform/core** → `PlatformUpdater` (`pinx:update`).

## Tests

```bash
composer test
composer test:platform
```

## License

MIT — [Pinoox](https://www.pinoox.com)
