# Changelog

All notable changes to this project are documented in this file.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project follows
[Semantic Versioning](https://semver.org/).

## [3.0.0] - Unreleased

A rewrite on top of `lossik/device-communication` 3.0, a transport-independent contract, and phpseclib 3. No class
of 2.x remains, so mixed code fails with "Class not found" instead of changing behaviour silently. The upgrade guide
(in Czech) is `docs/upgrade-3.0.cs.md` in the repository of `lossik/mikrotik-api`.

### Changed

- **New namespace `Lossik\Mikrotik\Ssh\`.** `SshTransport::connect($host, $user, $password, SshOptions)` replaces
  `SSH\Connection`, `SSH\Options` and `SSH\Command`; commands run through `Client` and `Menu` of
  device-communication (`print`, `first`, `add`, `update`, `delete`, `run`, `Client::version()`).
  `FileTransfer::connect()` with `upload()` and `download()` replaces `SFTP\Connection` with `uploadfile()` and
  `downloadfile()`.
- Requires `lossik/device-communication` `^3.0` and **phpseclib 3** (`^3.0.57`, which fixes CVE-2026-84308) instead of
  phpseclib 2. Composer installs one phpseclib per project: a project that needs phpseclib 2 elsewhere cannot install
  3.0 until that code moves to phpseclib 3.
- Requires `ext-iconv` (the `charset` conversion).
- **Supported are only patched PHP releases: 8.2.35+, 8.3.36+, 8.4.27+ and 8.5.12+**, as in 2.1.0.
- Policy: the PHP minimum is raised only in a minor release, with a note in this changelog.
- **Supported RouterOS**: the whole 7.x line, tested at its boundaries, and 6.x from 6.34 (the first CHR image);
  other versions go the same way without a guarantee, nothing is refused because of the version. 5.x is not
  supported: its console does not report a failed write. The README lists what is tested on every CI run, weekly,
  only by hand and not at all.
- **Two console paths**, chosen per connection from the version the router reports (one extra command before the
  first one): RouterOS 7.17 and newer print through `:serialize to=json options=json.no-string-conversion`; older
  ones, and a router whose version cannot be read, through a serializer in the console language that prints every
  value with its length in bytes. `SshTransport::consolePath()` returns `'json'` or `'text'` (`null` before the first
  command). The text path is slower (3.6 to 10.8 times on an emulated CHR) and gets no proplist on 6.x.
- **Typed exceptions** instead of `Lossik\Device\Communication\RuntimeException` with code 2 for every failure:
  `ConnectionException` (unreachable, SSH handshake, lost connection, a call on a closed transport),
  `TimeoutException` (`->limit`: `connectTimeout`, `responseTimeout`), `ProtocolException`,
  `AuthenticationException` (wrong user name or password; 2.x could not tell it from an unreachable host) and
  `TrapException`. After a `ConnectionException` or a subclass the transport is closed and every further call throws
  `ConnectionException`; a `TrapException` leaves it open.
- **Time limits**: `connectTimeout` 10 s (one limit for TCP and the SSH banner, and phpseclib's timeout for the key
  exchange and the login) and `responseTimeout`
  60 s for the output of each console command (one `execute()` may run several) or one SFTP request (`null` = none); a device that goes silent ends the call
  with `TimeoutException` and closes the transport.
- **Login**: the password is always sent, an empty one too (2.x used the SSH method `none` for an empty one).
  phpseclib still tries `none` first, so an account with an empty password logs in with any password.
- **Charset**: `SshOptions::$charset`, `UTF-8` passed through unchanged by default; another charset (e.g.
  `windows-1250`) converts every value of a command and the output with `iconv`, like `ApiOptions::$charset` of
  `lossik/mikrotik-api`. A value it cannot represent is an `\InvalidArgumentException` before anything is sent.
- **Queries**: `Query` (`where`, `has`, `hasNot`, `less`, `greater`, `and`, `or`, `not`) replaces the `$where` array.
  The console forms select the same rows as the API on both paths (except an IPv4 address in `less()`/`greater()`
  and `has()`/`hasNot()` over an empty value, below), measured on RouterOS 6.34 … 7.24.5: `has()` is a non-empty
  value of a property, also of a flag (`k~"."`), where the API `?k` is the existence of the property, also with an
  empty value (the empty `address` of `/ip/service`: the API `has()` matches it, this library `hasNot()`);
  `where(k, 'true'/'false')` is exact on a flag and on a string attribute, also under `not()` (`k~"^true\$"`). `less()` and `greater()` accept only a non-negative integer
  or an IPv4 address and refuse anything else with `UnsupportedOperationException` before anything is sent. An
  integer is compared like the API does; an IPv4 address is compared as an address, where the API of RouterOS
  6.42.12 … 7.25rc3 compares it as text, so the rows differ where text and address order differ (see the README). (Earlier development states of this branch sent a bare `k` for
  `has()`, `[:tobool "true"]` for `true` and any operand to `less()`/`greater()`; on some versions these silently
  returned other rows than the API.)
- **The `~` value prefix is gone** (2.x: a regular expression of the console); filter the rows in PHP.
- **Rows are those of the API**: every value a string, a list joined by commas, an empty `comment` left out, the ID
  of `add()` in upper case; singleton menus (`/system/resource`, `/ip/dns`, …) give one row. Flags (`disabled`,
  `dynamic`, `invalid`, `running`) that the proplist names and the console does not print are filled in by one more
  command per flag (`'false'` for a flag the menu does not have). A proplist the console refuses makes the transport
  print once more without it and reduce the columns itself: a column the menu does not have (refused from 7.1 on),
  and a proplist on a singleton menu (refused by 7.1 … 7.17.2 while parsing the line; one more exec for such a print
  there, also for every `Client::version()`). What stays different (units of `/queue/tree` and of the times of
  `/ip/dns`, counters, the `password` of `/ppp/secret`, attributes that the console of 6.x does not print, some of them
  written by the user) is listed in the README.
- **Removed**: `SSH\Command::get()`, `add()`, `update()`, `del()`, `command()` and their `$filterCallback`; filter in
  PHP and use `Menu`.

### Fixed

The known issues of 2.x:

- **A failed command is an exception**: the console's message becomes a `TrapException` (2.x returned the output as
  success).
- **Values survive**: `print` no longer splits the output on `;` and `=`, so values with those characters, newlines,
  quotes and non-ASCII text come back whole, without a trailing `\r`; an empty result is `[]`. A value is sent quoted
  and escaped (2.x put a value with a space in double quotes and escaped nothing).
- **`onlyFirst`** of `update()` and `delete()` (2.x: `$onlyFirstItem`) is respected.
- **No `composer audit` report of CVE-2026-84308** any more (it applied to every phpseclib 2 release).

### Added

- `SshTransport::consolePath()`, `SshOptions` (`port`, `connectTimeout`, `responseTimeout`, `charset`).
- A unit suite on console outputs recorded on CHR, a `docker` group against OpenSSH and a live test suite against a
  real CHR in QEMU (`tools/chr`): RouterOS 7.24.5 and 6.49.18 on every CI run; 7.23.8, 7.17.2, 7.16.2, 7.12.2, 7.1,
  6.49.23 (installed as an upgrade from 6.49.20), 6.43, 6.42.12, 6.34 and 7.25rc3 (testing channel, allowed to fail)
  weekly. The groups `live-json` and `live-text` hold the tests of one console path; the run leaves out the other.

### Notes

- No streaming (`listen` throws `UnsupportedOperationException`) and no raw console line; use `lossik/mikrotik-api`
  for streaming.
- Known limits: see the README (text path speed, differences from the API, attributes missing on 6.x,
  `less()`/`greater()`, an integer compared with a string attribute, `set` with several IDs not atomic, a map value on
  the text path, an account with an empty password).

## [2.1.1] - 2026-10-22

Fix of the 2.1.0 audit. `src/` and the public API are unchanged.

### Fixed

- `composer.json` allowed `lossik/device-communication` 2.0.0, which lacks the `Command` class this package extends;
  the minimum is now `^2.0.1` (later 2.x releases stay allowed).

### Tests and CI

- New CI job `lowest` (`tools/ci.sh --prefer-lowest`) runs the suite against the lowest allowed version of every
  dependency, which would have caught the wrong minimum above; a new test (`CommandTest`) builds an `SSH\Command`
  for it, because the default suite had no test that did.

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
