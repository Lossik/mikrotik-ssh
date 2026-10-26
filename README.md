# lossik/mikrotik-ssh

PHP client for MikroTik RouterOS over SSH (console commands) and SFTP (file transfer), built on
[`lossik/device-communication`](https://github.com/Lossik/device-communication) and
[phpseclib 3](https://github.com/phpseclib/phpseclib): `SshTransport` is the console implementation of its
`Transport`, and you talk to the router through its `Client` and `Menu`; `FileTransfer` moves files over SFTP.

**3.0 is in development on this branch and not released.** It has a new namespace (`Lossik\Mikrotik\Ssh\`) and is
built on `lossik/device-communication` 3.0 and phpseclib 3. For the released version see the `2.x` branch and
`composer require lossik/mikrotik-ssh:^2.1`. This README describes 3.0; the changes against 2.x are in the
[changelog](CHANGELOG.md), the upgrade guide (in Czech) is `docs/upgrade-3.0.cs.md` in the repository of
`lossik/mikrotik-api`. The links to `lossik/device-communication` and `lossik/mikrotik-api` point to their
repositories, whose published documentation is that of 2.x; until 3.0 is released, their 3.0 documentation is on
their 3.0 development branches.

The same `Client` code runs over the RouterOS API with
[`lossik/mikrotik-api`](https://github.com/Lossik/mikrotik-api), which is faster, can stream (`listen`) and has none of
the console differences listed under [Rows compared with the API](#rows-compared-with-the-api). Use SSH where the API
service is off or where you need SFTP.

## Requirements

- **PHP**: see [Supported PHP](#supported-php).
- **Packages**: `lossik/device-communication` `^3.0` and `phpseclib/phpseclib` `^3.0.57` (3.0.57 fixes
  CVE-2026-84308). Composer installs one phpseclib per project: a project that needs phpseclib 2 elsewhere cannot
  install this version until that code moves to phpseclib 3.
- **Extensions**: `ext-iconv`, declared in `composer.json` (used when `charset` is not UTF-8, see
  [Charset](#charset)).
- **RouterOS**: see [Supported RouterOS](#supported-routeros).

## Supported RouterOS

The whole 7.x line is supported and tested at its boundaries; 6.x is tested from 6.34, the first CHR image. Other
versions go the same way without a guarantee: nothing is refused because of the version, and what the console does
not do ends loudly (`TrapException` with the console's own message, or `ProtocolException`), not with silently
wrong data. Below 7.17 the library reads the console in another way than from 7.17 on, see
[Two console paths](#two-console-paths).

| | RouterOS |
|---|---|
| **tested on every CI run** (live suite against CHR) | 7.24.5 (JSON path), 6.49.18 (text path) |
| **tested weekly** (live suite against CHR) | 7.23.8, 7.17.2 (JSON path); 7.16.2, 7.12.2, 7.1, 6.49.23 (installed as an upgrade from 6.49.20, see below), 6.43, 6.42.12, 6.34 (text path); 7.25rc3 of the testing channel (JSON path, allowed to fail) |
| **probed by hand, outside CI** (console commands through measurement scripts, not this test suite) | 7.17, 7.17.1, 7.13, 7.13.5; 6.33 from the x86 ISO |
| **not verified** | every other version, among them 6.0–6.32, the 6.x releases between the measured ones (e.g. 6.43.1–6.49.17, 6.49.19–6.49.22), 7.0 (there is no CHR image of it) and the 7.x releases between the measured ones (above all 7.17.3–7.23.7 and 7.24.0–7.24.4) |
| **not supported** | 5.x: its console does not report a failed write (`set` of a missing item, a bad value, a duplicate `add` print nothing), so a failure could not be told from success. Use `lossik/mikrotik-api` for 5.x |

The live suite passes on all 12 versions of the CI matrix above.

- **6.49.23 is tested as an installation upgraded from 6.49.20.** Fresh official CHR images 6.49.21 and newer lose
  their configuration in QEMU (a defect of the image, not of the library); 6.49.19–6.49.22 are not verified (a fresh
  6.49.22 image was seen losing its configuration like the one of 6.49.23).
- **Verified on CHR only**, not on hardware: menus a CHR does not have (`ether` settings of real ports, wireless,
  switch chips) are not covered.

## Installation

3.0 has not been released yet, so there is nothing to install. It needs PHP `^8.2`, `ext-iconv`,
`lossik/device-communication` `^3.0` and `phpseclib/phpseclib` `^3.0.57` (see `composer.json`); installation instructions come with the release.

## Quick start

```php
use Lossik\DeviceCommunication\Client;
use Lossik\DeviceCommunication\Query;
use Lossik\Mikrotik\Ssh\SshOptions;
use Lossik\Mikrotik\Ssh\SshTransport;

$transport = SshTransport::connect('192.0.2.1', 'admin', 'secret', new SshOptions(responseTimeout: 120.0));
$client = new Client($transport);

// rows are lists of string => string, the same as over the API; the proplist limits the returned properties
$addresses = $client->menu('/ip/address')->print(Query::where('interface', 'ether1'), proplist: ['.id', 'address']);

$row = $client->menu('/queue/tree')->first(Query::where('name', 'q1'));   // ?array: null = nothing matches

$id = $client->menu('/ip/firewall/address-list')->add(['list' => 'blocked', 'address' => '192.0.2.9']);   // "*1B"

// update() and delete() print the matching .id values first, then send one set / remove with numbers=*1,*2
// (only the first match with onlyFirst: true); they return the number of items and send nothing when none match
$changed = $client->menu('/ip/firewall/address-list')->update(Query::where('list', 'blocked'), ['comment' => 'x']);
$removed = $client->menu('/ip/firewall/address-list')->delete(Query::where('list', 'blocked'), onlyFirst: true);

// any other verb of a menu; the ID of an item goes to the attribute .id
$client->menu('/system/identity')->run('set', ['name' => 'R1']);

$version = $client->version();       // e.g. "7.24.5 (stable)"
$path = $transport->consolePath();   // 'json' or 'text', see below; null before the first command

$transport->close();
```

The menu may be written `/ip/address` or `ip address`. The host may be an IPv6 address, with or without brackets.
The shared contract (`Client`, `Menu`, `Query`, `Command`, `Result`) is documented in
[`lossik/device-communication`](https://github.com/Lossik/device-communication).

## Options

`SshTransport::connect(string $host, string $user, string $password, SshOptions $options = new SshOptions())`, the
same for `FileTransfer::connect()`. All times are seconds, all arguments of `SshOptions` are named and optional:

| option | default | meaning |
|---|---|---|
| `port` | `22` | the SSH port |
| `connectTimeout` | `10.0` | one limit for opening the TCP connection and receiving the SSH banner together, and the timeout phpseclib gets for the key exchange and the login; running out is a `TimeoutException` with `->limit === 'connectTimeout'` |
| `responseTimeout` | `60.0` | how long to wait for the output of one console command (`SshTransport`) or for the reply to one SFTP request (`FileTransfer`); `null` = no limit. The console answers within a fraction of a second, so the limit only guards against a device that went silent; raise it for commands that run long and for large tables on the [text path](#two-console-paths) |
| `charset` | `'UTF-8'` | `SshTransport` only: the charset of the text on the device, see [Charset](#charset) |

The password is always sent, an empty one too (2.x used the SSH method `none` for an empty one). phpseclib itself
still tries `none` before the password, so **an account with an empty password logs in with any password**
(measured on 6.49.23): a wrong password is refused only for an account that has one.

## Two console paths

The first command on a connection asks the router for its version (`:put [/system resource get version]`, one more
console exec, 0.1–0.2 s measured on an emulated CHR), and the transport keeps the answer until `close()`:

| path | RouterOS | how a `print` is read |
|---|---|---|
| `'json'` | 7.17 and newer (also `7.17rc*` and later pre-releases) | `:serialize to=json options=json.no-string-conversion`. The option exists from 7.17 on (measured on 7.17, 7.17.1, 7.17.2 and 7.17rc1); 7.13–7.16 have `:serialize`, but without the option it turns a string that looks like a number into a number (`007` into 7), so they take the text path |
| `'text'` | anything else, an unreadable answer included | a serializer written in the console language: every value is printed with its length in bytes, so a value may hold anything (`;`, `=`, quotes, newlines, `--`, `END`) |

`$transport->consolePath()` returns the path in use, `null` before the first command. An unreadable version picks
the text path: it runs on every measured version, 6.34 … 7.24.5, while the JSON path on an old router would only end
with a syntax error. A lost connection or a timeout during the version query is thrown as it is and closes the
transport.

On the text path:

- **It is slower**: a `print` costs 6–14 ms per row on an emulated CHR (1000 rows of 5 attributes: 6–14 s), 3.6 to
  10.8 times the JSON path; on hardware it is faster, by how much is not measured. For large tables
  (`/ip/firewall/connection`) raise `responseTimeout`.
- **No command is wrapped**: an error is the bare text of the console (`no such item (4)`, `invalid value for
  argument address`), and it differs between versions (7.24.5 adds a suffix like ` (/ip/address/set; line 1)`; an
  unknown attribute is `expected end of command` up to 7.17.2 and `bad parameter …` on 7.24.5). Match the reason,
  not the whole message. The exit status of the console is never used (it is 0 on errors on 6.43 and older and on
  7.24.5).
- **The menu is written with spaces** (`/ip address print`): RouterOS 6 has no slash form.
- **The proplist is sent from 7.1 on**; the console of 6.x has none, every column is printed and the row is reduced
  afterwards. The attributes the console prints only when the proplist names them are therefore **missing on 6.x**,
  see [RouterOS 6.x](#routeros-6x-attributes-the-console-does-not-print).

## Errors

Every runtime error implements the marker interface `Lossik\DeviceCommunication\CommunicationException`:

```
CommunicationException                     (interface)
|- ConnectionException                     closes the transport
|    |- TimeoutException                   ->limit: 'connectTimeout' | 'responseTimeout'
|    '- ProtocolException
|- AuthenticationException                 wrong user name or password; from connect() only
'- TrapException                           the console refused the command; the connection stays open
\UnsupportedOperationException             not a CommunicationException (\LogicException)
```

| exception | when | after it |
|---|---|---|
| `ConnectionException` | the router is unreachable, the SSH handshake failed, the connection was lost, a call on a closed transport | closed |
| `TimeoutException` | `->limit` names the option that ran out | closed |
| `ProtocolException` | the output of a `print` is not what its line prints (a broken field, no end mark), or the output is not valid in the configured charset | closed |
| `AuthenticationException` | the router rejected the user name or password; no transport is returned | - |
| `TrapException` | the console printed an error: `->getMessage()` is its text (converted from the charset); `->category` is `null` and `->traps()` is empty, the console has neither | **open** |
| `UnsupportedOperationException` | a command or query the console line cannot express (see [Queries](#queries)), or `listen`; nothing was sent | open |
| `\InvalidArgumentException` | a value of the command cannot be converted to `charset`; nothing was sent | open |

After any `ConnectionException` (or subclass) every further call throws `ConnectionException` without I/O; create a
new transport. `$transport->isOpen()` tells whether it is still usable.

**A change of several items is not atomic**: `set` with several IDs (`.id=*1,*FFFF`, through `run('set', …)` or
`update()`) changes the items before the first unknown ID and then fails with `TrapException` (`no such item`); the
earlier items stay changed. The API does the same (measured on all 12 versions of the matrix).

## Queries

`Query` conditions become a `where` of the console, the same on both paths. Every form below was compared with the
API by console probes on RouterOS 6.34 … 7.24.5 (each on at least 6.34, 7.17.2 and 7.24.5) and selects the same
rows, **except `less()`/`greater()` with an IPv4 address** (see below the table):

| condition | console | note |
|---|---|---|
| `where(k, v)` | `k="v"` | `yes` and `no` stay unquoted (`k=yes`); `where(k, '')` matches nothing, as on the API |
| `where(k, 'true')`, `where(k, 'false')` | `k~"^true\$"`, `k~"^false\$"` | exact on a flag (`disabled`) and on a string attribute (a `comment` "true"), also under `not()` |
| `has(k)`, `hasNot(k)` | `k~"."`, `!(k~".")` | the existence of the property, also for a flag and an IP address: `has('disabled')` matches every item that has the property, not the disabled ones (as `?disabled` of the API) |
| `less(k, v)`, `greater(k, v)` | `k<"v"`, `k>"v"` | **only a non-negative integer** (`0` or digits without a leading zero) **or an IPv4 address** without a prefix; anything else (a time like `1h`, an IPv6 address, a prefix, a negative or decimal number, a string) is an `UnsupportedOperationException` before anything is sent, because the console does not compare it like the API (a string: nothing on 6.x, every row on 7.x) |
| `and()`, `or()`, `not()` | `a and b`, `(a or b)`, `!(…)` | |

- **An IPv4 address in `less()`/`greater()` is compared as an address** on every version (the console treats the
  bare, the quoted and the `[:toip]` form alike), **but the API compares it as text** on RouterOS 6.42.12 … 7.25rc3,
  also over an IP-typed attribute (6.34 compared addresses). `less('address', '10.97.3.10')` over the console
  matches `10.97.3.1` … `10.97.3.9`, over the API only `10.97.3.1`: wherever text order and address order differ,
  this library and `lossik/mikrotik-api` return different rows. An integer is compared the same on both.
- **An integer compared with a string attribute** (`less('comment', '5')`) is sent, but the API, the console of 6.x
  and the console of 7.x give three different results. The library does not know the type of an attribute, so it
  cannot refuse it: compare numbers only with numeric attributes.
- There is no prefix or regular expression condition (2.x: the `~` prefix); filter in PHP (`array_filter()` with
  `str_starts_with()` or `preg_match()`).
- Menu levels must consist of `A-Z a-z 0-9 _ -`; verbs, attributes, query keys and proplist entries may also hold
  `.` (`.id`). Anything else is an `UnsupportedOperationException`.
- A value is sent as a quoted console string: `\`, `"`, `$`, `?` and line breaks are escaped, and every byte outside
  printable ASCII is written as `\HH` (the console silently drops such a byte written as it is), so UTF-8 text in a
  value or in a `where` arrives whole.

## Rows compared with the API

The rows are normalised to what the API returns: every value is a string (`true`, `1500`), a list is joined by
commas (`10.0.0.0/24,10.0.1.0/24`, an empty one `''`), an empty `comment` is left out, and the ID `add()` returns is
in upper case (`*1B`; the console of 7.17.x and older prints `*1b`). The keys of a row do not come in the order of
the API (on the text path they are alphabetical, a filled-in flag comes last); do not rely on the order.

What stays different from the API (measured on 7.23.8 and 7.24.5):

- another unit: `bucket-size` and `burst-time` of `/queue/tree` (console `100`, `00:00:00`; API `0.1`, `0s`),
  `life-time` of `/ipv6/dhcp-server/binding` (a date like `1970-01-08 00:00:00`; API `1w`);
- not printed by the console at all: the counters (`bytes`, `packets`, `rate`, `dropped`, `queued-bytes` … of queues
  and firewall rules) and the `password` of `/ppp/secret`;
- on 6.x: the attributes printed only with a proplist (see
  [RouterOS 6.x](#routeros-6x-attributes-the-console-does-not-print));
- `/ip/dns` and `/system/resource`: times in another format (console `00:00:02`, `1w00:00:00`; API `2s`, `1w`),
  e.g. `cache-max-ttl`, `query-server-timeout`, `uptime`.

**Flags** (`disabled`, `dynamic`, `invalid`, `running`): RouterOS 7.23.8 and 7.24.5 print them in a `print`, 7.17.2
and 6.x do not. When the proplist names a flag that is missing in every returned row, the transport asks the
console for the IDs of the items with that flag true (one more exec per such flag) and fills in `'true'` or
`'false'`. A flag the menu does not have (`running` of `/ip/firewall/address-list`) comes out as `'false'`, where the
API has no such key.

**A proplist the console refuses** is retried once without it, and the transport reduces the columns itself, as on
6.x. Two refusals trigger it, on both paths:

- **a column the menu does not have**: from 7.1 on the console refuses the whole `print` ("input does not match any
  value of value-name"). Such a column is then absent from the row, a missing flag is `'false'` (above), on every
  version;
- **a proplist on a singleton menu** (`/system/resource`, `/system/identity`, `/ip/dns`, …): RouterOS 7.1 … 7.17.2
  refuse it while parsing the line ("expected end of command"). This costs one more exec for every such `print` on
  those versions, including every `Client::version()` (it prints `/system/resource` with the proplist `version`).
  7.23.8 and newer accept the line and the JSON path falls back inside it, without another exec.

Any other refusal is a `TrapException` without a retry. Singleton menus give one row, as on the API, on every
version of the matrix.

**A value that is a map** (a list with named keys instead of a plain list) would lose its keys on the text path,
where a list is joined by commas; the JSON path keeps it as JSON text. No measured menu has such a value, and the
text path does not detect it.

### RouterOS 6.x: attributes the console does not print

The console of 6.x has no `proplist`, and its `print as-value` leaves out attributes that the API returns, also when
you name them in the proplist. Measured in the menus of the live suite (6.34 … 6.49.23):

| menu | missing on 6.x |
|---|---|
| `/ip/address` | `actual-interface` |
| `/ip/arp` | `DHCP`, `complete`, `published` |
| `/ppp/secret` | `ipv6-routes`, `last-logged-out`, `limit-bytes-in`, `limit-bytes-out`, `routes` |
| `/ip/dhcp-server/lease` | `address-lists`, `blocked`, `dhcp-option`, `last-seen`, `radius` |
| `/ipv6/dhcp-server/binding` | `active-server`, `address-lists`, `client-address`, `dhcp-option`, `iaid`, `last-seen`, `prefix-pool`, `radius` |

Not only state is missing: values you wrote yourself are among them (the `address-lists` of a lease, the
`limit-bytes-*` of a PPP secret). Other menus may have more. On 6.x read such attributes through the API
(`lossik/mikrotik-api`). From 7.1 on the transport sends the proplist and the rows match the API.

## Files (SFTP)

```php
use Lossik\Mikrotik\Ssh\FileTransfer;

$files = FileTransfer::connect('192.0.2.1', 'admin', 'secret');
$files->upload('/local/backup.rsc', 'backup.rsc');
$content = $files->download('backup.rsc');              // the content as a string
$written = $files->download('backup.rsc', '/local/copy.rsc');   // true; written to the local file
$files->close();
```

A request the router refuses (no such file, no permission) is a `TrapException` and the session stays open; a local
file that is not readable is an `\InvalidArgumentException` before anything is sent; a lost connection or a
`responseTimeout` is a `ConnectionException` / `TimeoutException` and closes the session. File names and contents
are bytes, `charset` does not apply.

SFTP works the same on every version of the matrix, 6.34 … 7.25rc3. On 7.16.2 and 7.17.2 a file just uploaded
appears in the `/file` menu (API, console) only about 100–500 ms after the transfer has closed; SFTP itself sees it
at once. Wait or retry before you look for a fresh upload in `/file`.

## Charset

By default (`charset: 'UTF-8'`) values and output pass unchanged. Any other ASCII-compatible single-byte charset
(e.g. `'windows-1250'`, the default of 2.x) converts every value of a command to it, and the output back to UTF-8,
with `iconv`, like `ApiOptions::$charset` of `lossik/mikrotik-api`. A value the charset cannot represent is an
`\InvalidArgumentException` before anything is sent; output that is not valid in the charset is a
`ProtocolException` and closes the transport. On the text path the console returns the stored bytes unchanged, so
cp1250 text written by 2.x is read back with `charset: 'windows-1250'`.

## Known limits

- **One command at a time per connection**: every command opens its own exec channel on the one SSH session; do not
  share a transport between concurrent users.
- **No streaming**: `listen` throws `UnsupportedOperationException` (use `lossik/mikrotik-api`).
- **No raw console line**: the transport runs a `Command` only (a menu, a verb, attributes, a query, a proplist).
- **The text path is slower**; raise `responseTimeout` for large tables (see [Two console paths](#two-console-paths)).
- **The [differences from the API](#rows-compared-with-the-api)** stay; on 6.x attributes the API returns are
  missing, some of them written by the user (see
  [RouterOS 6.x](#routeros-6x-attributes-the-console-does-not-print)).
- **A proplist on a singleton menu costs one more exec on 7.1 … 7.17.2**, also every `Client::version()`.
- **A map value would lose its keys on the text path** (not seen in any measured menu).
- **`set` with several IDs is not atomic** (as on the API, see [Errors](#errors)).
- **`less()`/`greater()`** accept only a non-negative integer or an IPv4 address, and an IPv4 address is compared
  as an address, where the API compares it as text (see [Queries](#queries)).
- **An account with an empty password logs in with any password** (phpseclib tries `none` first, see
  [Options](#options)).
- **RouterOS 5.x is not supported** over SSH.

## Tests

`composer test` runs the unit suite without a router (a fake phpseclib session and console outputs recorded on
CHR); `composer ci` adds `phpcs` and PHPStan. The group `docker` runs `SshTransport` and `FileTransfer` against
OpenSSH started inside a `php:8.2.35-cli` container (from the repository root: `bash tools/ci.sh --deps-only && bash
tools/sshd/start.sh && vendor/bin/phpunit --group docker`); the CI job `docker` runs it on every pipeline.

The live suite runs against a real CHR in QEMU inside one `php` container, with Docker as the only requirement: see
[tools/chr/README.md](tools/chr/README.md) for the local run. In CI it runs on every pipeline for 7.24.5 and 6.49.18
and weekly for the rest of the [matrix](#supported-routeros), one version per job. The column `ssh-path` of
`tools/chr/versions.txt` says which path a version takes, and the tests of the other path (group `live-json` or
`live-text`) are left out. On CHR 6.x the harness enables the `ipv6` package (installed but disabled on those
images), which costs one extra reboot per job; 6.49.23 is booted from the 6.49.20 image and upgraded.

## Supported PHP

Only patched PHP releases are supported: **8.2.35+, 8.3.36+, 8.4.27+ and 8.5.12+** (CVE-2026-109618 and
CVE-2026-109619, "PHP2Shell"). `composer.json` requires `"php": "^8.2"`; only the CI of this project enforces the
patched minima.

## License

MIT, see [LICENSE](LICENSE).
