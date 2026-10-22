# Changelog

All notable changes to this project are documented in this file.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project follows
[Semantic Versioning](https://semver.org/).

## [2.1.0] - 2026-10-22

Maintenance release of the 2.x line: it runs on patched PHP and is MIT licensed. The public API is unchanged and
the defects of 2.x are **not** fixed (see Known issues; they are fixed in 3.0).

### Changed

- Requires PHP `^8.2` (was unconstrained). Users on older PHP stay on 2.0.x.
- **Supported are only patched PHP releases: 8.2.35+, 8.3.36+, 8.4.27+ and 8.5.12+** (CVE-2026-109618 and
  CVE-2026-109619, "PHP2Shell"). Composer cannot tell a patched build from an unpatched one, because Linux
  distributions backport the fixes without changing the version number, so `composer.json` says `^8.2` and only
  the CI of this project enforces the patched minima.
- Policy: the PHP minimum is raised only in a minor release, with a note in this changelog.
- Licensed under the MIT license (`LICENSE`).
- Requires `phpseclib/phpseclib` `^2.0.55` (was `~2.0`): 2.0.55 is the last 2.x release and carries the 2026
  security fixes of phpseclib.

### Fixed

- PHP 8.4+: no deprecation for the implicitly nullable parameters of `SSH\Connection::__construct()` and
  `SFTP\Connection::__construct()` (now `?Options $options = null`).

### Known issues

Not fixed in 2.x; fixed in 3.0 (which moves to phpseclib 3).

- **A failed RouterOS command is returned as success:** `comm()` and the `Command` methods return the console
  output only; neither the exit status nor an error message is checked.
- **`Command::get()` parses `:put [print as-value]` by `;` and `=`:** values containing `;` or `=` (comments,
  scripts) are split into wrong keys and values. RouterOS ends the output with `\r\n` and only `PHP_EOL` (`\n` on Linux) is removed:
  the last value of the last item keeps a trailing `\r`, and an empty result is returned as `[["\r"]]` instead of
  `[]`.
- **`$onlyFirstItem` is ignored** by `get()`, `update()` and `del()`: they always work on all matching items.
- **The `~` value prefix is a regular expression:** `get(['name' => '~abc'])` sends `name~abc`, which the RouterOS
  console evaluates as a regular expression (the API client of `lossik/mikrotik-api` treats it as a prefix).
- **An unreachable host and a wrong password are indistinguishable:** both throw
  `Lossik\Device\Communication\RuntimeException` with code 2 (`Login impossible`), as does a server that accepts the
  TCP connection but never answers.
- **PHP 8.5: `Deprecated: ord(): Providing an empty string is deprecated` on every SSH exec.** It comes from
  phpseclib 2 (the last release, 2.0.55, still has it), not from this package. An error handler that turns
  deprecations into exceptions breaks SSH on PHP 8.5.
- **`composer audit` reports CVE-2026-84308** (GHSA-q97c-8qh3-fpc6) for phpseclib below 3.0.57, i.e. for every
  phpseclib 2 release. According to the advisory, exploitation needs a reused X25519 private key, and the SSH path of
  phpseclib generates a fresh key per exchange and is not affected. This package uses phpseclib only for SSH and
  SFTP; its own CI ignores the advisory (`config.audit.ignore`, which applies only when this package is the root
  project, not in your project).

## [2.0.2] and older

Released without a changelog; see the git history.
