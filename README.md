# lossik/mikrotik-ssh

PHP client for MikroTik RouterOS over SSH (console commands) and SFTP (file transfer), built on
[`lossik/device-communication`](https://github.com/Lossik/device-communication) and
[phpseclib 2](https://github.com/phpseclib/phpseclib/tree/2.0).

**2.x is maintenance only.** It receives fixes needed to run on supported PHP releases, nothing else. New
development happens in 3.0 (to be released), which fixes the known issues of 2.x, moves to
phpseclib 3 and changes the API.

## Supported PHP

Only patched PHP releases are supported: **8.2.35+, 8.3.36+, 8.4.27+ and 8.5.12+** (CVE-2026-109618 and
CVE-2026-109619, "PHP2Shell"). `composer.json` requires `"php": "^8.2"`, so Composer also installs the package on
an unpatched PHP: Linux distributions backport the fixes without changing the version number, and Composer cannot
tell the two apart. Only the CI of this project enforces the patched minima. The PHP minimum is raised only in a
minor release, announced in the [changelog](CHANGELOG.md).

## phpseclib 2

2.x requires `phpseclib/phpseclib` `^2.0.55`. On PHP 8.5 phpseclib 2 itself reports `Deprecated: ord()` on every
SSH exec; this comes from phpseclib, not from this package, and an error handler that turns deprecations into
exceptions breaks SSH there. `composer audit` reports CVE-2026-84308 for phpseclib below 3.0.57; according to the
advisory the SSH path of phpseclib is not affected. Both go away in 3.0 (phpseclib 3). Details in the
[changelog](CHANGELOG.md#known-issues).

## Installation

```bash
composer require lossik/mikrotik-ssh:^2.1
```

## Usage

```php
use Lossik\Device\Mikrotik\SFTP;
use Lossik\Device\Mikrotik\SSH;

$options = new SSH\Options();
$options->port = 22;

$ssh = (new SSH\Connection($options))->connect('192.0.2.1', 'admin', 'secret');
$addresses = $ssh->Command('/ip/address')->get(['interface' => 'ether1']);
$output = $ssh->comm('/system/identity/print');

$sftp = (new SFTP\Connection($options))->connect('192.0.2.1', 'admin', 'secret');
$sftp->uploadfile('/local/backup.rsc', 'backup.rsc');
$content = $sftp->downloadfile('backup.rsc');
```

## Known issues

2.x has defects that are fixed only in 3.0 (for example, a failed RouterOS command is returned as success). They
are listed in the [changelog](CHANGELOG.md#known-issues).

## License

MIT, see [LICENSE](LICENSE).
