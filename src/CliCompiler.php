<?php

declare(strict_types=1);

namespace Lossik\Mikrotik\Ssh;

use Lossik\DeviceCommunication\Command;
use Lossik\DeviceCommunication\ProtocolException;
use Lossik\DeviceCommunication\Query;
use Lossik\DeviceCommunication\QueryOperator;
use Lossik\DeviceCommunication\Result;
use Lossik\DeviceCommunication\TrapException;
use Lossik\DeviceCommunication\UnsupportedOperationException;

/**
 * Translates a Command to one line of the RouterOS CLI and the output of that line to a Result. Pure: no I/O.
 *
 *  - `print` → `:put [:serialize to=json options=json.no-string-conversion [/menu/print as-value proplist=… where …]]`,
 *    the JSON read back as rows with every value a string and, with a proplist, only the requested columns (the Result
 *    equals the one of the API transport; the console takes no `.id` in a proplist and always prints `.id` and
 *    `comment`);
 *  - `add` → `:put [/menu/add k="v" …]` (the output is the ID of the new item → Result::$ret);
 *  - any other verb → `/menu/verb numbers=*1,*2 k="v" …` (`.id` becomes `numbers=`), which prints nothing.
 *
 * Rows are normalised to what the API gives (measured on RouterOS 7.23.8 and 7.24.5): a list (`["a","b"]`) becomes
 * `a,b` and an empty one `""`; an empty `comment` is left out (the API omits it, and 7.17.x printed `""`). What the
 * console prints in another unit (`bucket-size` and `burst-time` of /queue/tree, `life-time` of
 * /ipv6/dhcp-server/binding) or does not print at all (counters, the `password` of /ppp/secret) stays different.
 *
 * Singleton menus (/system/resource, /system/identity, /system/clock, /ip/dns …) print one JSON object instead of a
 * list and reject `proplist` ("bad parameter proplist"). With a proplist the print line therefore tries the proplist
 * and, on that very error, prints without it, in the same console line (one round trip); an object is one row, and
 * the rows are reduced to the requested columns here either way. Any other error of the print is reported as it is.
 *
 * Every line is wrapped so that a failing command prints the marker and the reason of the console (see wrap()); the
 * marker in the output is a TrapException. Everything that is put into the line is checked first: names must be plain,
 * values are converted to the charset of the device and quoted as RouterOS strings, so an attribute cannot end the
 * command and start another.
 *
 * @internal
 */
final class CliCompiler
{
    private const NAME = '/^[A-Za-z0-9._-]+$/';

    private const MENU = '~^(/[A-Za-z0-9_-]+)*/?$~';

    private const ID_LIST = '/^\*[0-9A-Fa-f]+(,\*[0-9A-Fa-f]+)*$/';

    private const ID = '/^\*[0-9A-Fa-f]+$/';

    /**
     * Without it `:serialize` turns a string that looks like a number into a number (`007` → 7, `1e3` → 1000,
     * `0x10` → 16, a long digit string is rounded); with it the strings stay as they are.
     */
    private const JSON_OPTIONS = 'json.no-string-conversion';

    /**
     * The line to send for a command.
     *
     * @param string $marker printed by the line (with the reason) when the command fails
     * @param string $charset the charset of the device; values of the command are converted from UTF-8 to it
     * @throws UnsupportedOperationException a menu, verb, name or query the CLI line cannot express (nothing is sent)
     * @throws \InvalidArgumentException a value cannot be converted to the charset (nothing is sent)
     */
    public static function compile(Command $command, string $marker, string $charset = 'UTF-8'): string
    {
        $menu = $command->menu;
        if (preg_match(self::MENU, $menu) !== 1) {
            throw new UnsupportedOperationException(sprintf('Menu %s cannot be used in a CLI line', $menu));
        }
        self::assertName($command->verb, 'verb');
        $path = rtrim($menu, '/') . '/' . $command->verb;

        return match ($command->verb) {
            'print' => self::wrap(self::printLine($path, $command, $charset), $marker),
            'add' => self::wrap(
                ':put [' . self::withAttributes($path, $command->attributes, false, $charset) . ']',
                $marker,
            ),
            default => self::wrap(self::withAttributes($path, $command->attributes, true, $charset), $marker),
        };
    }

    /**
     * The Result of a line made by compile().
     *
     * @param string $charset the charset of the device; the output is converted from it to UTF-8
     * @throws TrapException the line failed, or printed something a command of this verb does not print on success
     * @throws ProtocolException the output is not valid in the charset, or a print answered with valid JSON that is
     *                           neither a list of objects nor one object
     */
    public static function result(Command $command, string $output, string $marker, string $charset = 'UTF-8'): Result
    {
        $text = trim(self::fromDevice($output, $charset));
        $at = strpos($text, $marker);
        if ($at !== false) {
            $reason = trim(substr($text, $at + strlen($marker)));
            throw new TrapException($reason !== '' ? $reason : sprintf('%s %s failed', $command->menu, $command->verb));
        }

        return match ($command->verb) {
            'print' => new Result(self::rows($text, $command->proplist)),
            'add' => self::added($text),
            default => $text === '' ? new Result([]) : throw new TrapException($text),
        };
    }

    /**
     * A value as a RouterOS string: in double quotes, `\`, `"`, `$` and `?` escaped, a line break as `\n` / `\r`.
     * Every other byte outside printable ASCII (UTF-8 text, control characters) is written as `\HH`: the console
     * silently drops such a byte typed into a string as it is (measured on RouterOS 7.24.5).
     */
    public static function quote(string $value): string
    {
        $escaped = strtr($value, [
            '\\' => '\\\\',
            '"' => '\\"',
            '$' => '\\$',
            '?' => '\\?',
            "\n" => '\\n',
            "\r" => '\\r',
        ]);

        return '"' . preg_replace_callback(
            '/[^\x20-\x7E]/',
            static fn (array $byte): string => sprintf('\\%02X', ord($byte[0])),
            $escaped,
        ) . '"';
    }

    /**
     * The one place that decides how a failing command is reported: the marker, a space and the reason the console
     * gives (`:onerror`, RouterOS 7.13.5 and later). A syntax error of the whole line is reported by the console
     * before anything runs, so it prints no marker (and no reason of ours; the console's message is the refusal).
     */
    private static function wrap(string $line, string $marker): string
    {
        return ':onerror e in={ ' . $line . ' } do={ :put (' . self::quote($marker . ' ') . ' . $e) }';
    }

    private static function printLine(string $path, Command $command, string $charset): string
    {
        if ($command->attributes !== []) {
            throw new UnsupportedOperationException('print takes no attributes; use the query and the proplist');
        }
        foreach ($command->proplist as $name) {
            self::assertName($name, 'proplist entry');
        }
        // the console rejects `.id` as a column ("input does not match any value of value-name") and prints it anyway
        $columns = array_values(array_diff($command->proplist, ['.id']));
        $plain = self::printCommand($path, [], $command->query, $charset);
        $serialize = ':serialize to=json options=' . self::JSON_OPTIONS;
        if ($columns === []) {
            return ':put [' . $serialize . ' [' . $plain . ']]';
        }

        return ':local r; :onerror p in={ :set r [' . self::printCommand($path, $columns, $command->query, $charset)
            . '] } do={ :if ($p ~ "^bad parameter proplist") do={ :set r [' . $plain . '] } else={ :error $p } }; '
            . ':put [' . $serialize . ' $r]';
    }

    /**
     * @param list<string> $columns
     */
    private static function printCommand(string $path, array $columns, ?Query $query, string $charset): string
    {
        $line = $path . ' as-value';
        if ($columns !== []) {
            $line .= ' proplist=' . implode(',', $columns);
        }
        if ($query !== null) {
            $line .= ' where ' . self::condition($query, true, $charset);
        }

        return $line;
    }

    /**
     * @param array<string, string> $attributes
     */
    private static function withAttributes(string $path, array $attributes, bool $idIsNumbers, string $charset): string
    {
        $line = $path;
        foreach ($attributes as $name => $value) {
            $name = (string) $name;
            self::assertName($name, 'attribute');
            if ($idIsNumbers && $name === '.id') {
                $line .= ' numbers=' . (preg_match(self::ID_LIST, $value) === 1
                    ? $value
                    : self::literal($value, $charset));
            } else {
                $line .= ' ' . $name . '=' . self::literal($value, $charset);
            }
        }

        return $line;
    }

    private static function condition(Query $query, bool $top, string $charset): string
    {
        return match ($query->operator) {
            QueryOperator::Where => self::name($query) . '=' . self::comparand((string) $query->value, $charset),
            QueryOperator::Less => self::name($query) . '<' . self::literal((string) $query->value, $charset),
            QueryOperator::Greater => self::name($query) . '>' . self::literal((string) $query->value, $charset),
            QueryOperator::Has => self::name($query),
            QueryOperator::HasNot => '!' . self::name($query),
            QueryOperator::And => self::join($query, ' and ', $top, $charset),
            QueryOperator::Or => self::join($query, ' or ', $top, $charset),
            QueryOperator::Not => '!(' . self::condition($query->operands[0], true, $charset) . ')',
        };
    }

    /**
     * What `where name=…` compares with. The query does not know the type of the attribute, and a quoted "true" or
     * "yes" matches no boolean (`disabled`, `dynamic`, `running` …), while the API takes `?disabled=yes` and
     * `?disabled=true` for a boolean and compares a string attribute as text. Measured (RouterOS 7.23.8, 7.24.5): an
     * unquoted `yes` / `no` does exactly that on both kinds of attribute, and so does `[:tobool "true"]` /
     * `[:tobool "false"]` (unquoted `true` is an error on a boolean); other spellings (`Yes`, `1`) match nothing
     * on either side and stay quoted strings.
     */
    private static function comparand(string $value, string $charset): string
    {
        return match ($value) {
            'yes', 'no' => $value,
            'true', 'false' => '[:tobool "' . $value . '"]',
            default => self::literal($value, $charset),
        };
    }

    private static function join(Query $query, string $glue, bool $top, string $charset): string
    {
        $text = implode(
            $glue,
            array_map(
                static fn (Query $operand): string => self::condition($operand, false, $charset),
                $query->operands,
            ),
        );

        return $top ? $text : '(' . $text . ')';
    }

    private static function name(Query $query): string
    {
        $name = (string) $query->key;
        self::assertName($name, 'query key');

        return $name;
    }

    private static function assertName(string $name, string $what): void
    {
        if (preg_match(self::NAME, $name) !== 1) {
            throw new UnsupportedOperationException(
                sprintf('%s %s cannot be used in a CLI line', $what, var_export($name, true)),
            );
        }
    }

    /**
     * A value of the command as a RouterOS string in the charset of the device (`\HH` for every byte of it ≥ 0x80).
     */
    private static function literal(string $value, string $charset): string
    {
        return self::quote(self::toDevice($value, $charset));
    }

    /**
     * @throws \InvalidArgumentException
     */
    private static function toDevice(string $value, string $charset): string
    {
        if (strcasecmp($charset, 'UTF-8') === 0) {
            return $value;
        }
        $converted = @iconv('UTF-8', $charset, $value);
        if ($converted === false) {
            throw new \InvalidArgumentException(
                sprintf('A value of the command cannot be converted from UTF-8 to %s', $charset),
            );
        }

        return $converted;
    }

    /**
     * @throws ProtocolException
     */
    private static function fromDevice(string $output, string $charset): string
    {
        if (strcasecmp($charset, 'UTF-8') === 0) {
            return $output;
        }
        $converted = @iconv($charset, 'UTF-8', $output);
        if ($converted === false) {
            throw new ProtocolException(sprintf('The output of the device is not valid %s', $charset));
        }

        return $converted;
    }

    private static function added(string $text): Result
    {
        if ($text === '') {
            return new Result([]);
        }
        if (preg_match(self::ID, $text) !== 1) {
            throw new TrapException($text);
        }

        return new Result([], $text);
    }

    /**
     * @param list<string> $columns the requested columns; none = every column the console printed
     * @return list<array<string, string>>
     */
    private static function rows(string $json, array $columns): array
    {
        if ($json === '') {
            return [];
        }
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\JsonException) {
            // not JSON: the console's own message about a line it did not accept
            throw new TrapException($json);
        }
        if (!is_array($decoded)) {
            throw new ProtocolException('print did not return a list of rows');
        }
        // a singleton menu prints one object, a list menu a list of objects
        $items = array_is_list($decoded) ? $decoded : [$decoded];
        $rows = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                throw new ProtocolException('print did not return a list of rows');
            }
            $row = [];
            foreach ($item as $key => $value) {
                $key = (string) $key;
                $text = self::text($value);
                // the API leaves an empty comment out; 7.17.x printed "comment":""
                if ($key === 'comment' && $text === '') {
                    continue;
                }
                $row[$key] = $text;
            }
            $rows[] = $columns === [] ? $row : array_intersect_key($row, array_flip($columns));
        }

        return $rows;
    }

    /**
     * Booleans and numbers come out of `:serialize to=json` as JSON booleans and numbers (strings that look like
     * numbers stay strings, thanks to `options=json.no-string-conversion`), a list as a JSON array. The API gives
     * strings, a list joined by commas (an empty one `""`), so do we.
     */
    private static function text(mixed $value): string
    {
        return match (true) {
            is_string($value) => $value,
            $value === true => 'true',
            $value === false => 'false',
            $value === null => '',
            is_int($value) => (string) $value,
            // 1234.000000 is how RouterOS writes an integer without json.no-string-conversion
            is_float($value) => floor($value) === $value && abs($value) < 1e15
                ? sprintf('%.0f', $value)
                : (string) $value,
            is_array($value) && array_is_list($value) && array_filter($value, is_array(...)) === []
                => implode(',', array_map(self::text(...), $value)),
            default => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        };
    }
}
