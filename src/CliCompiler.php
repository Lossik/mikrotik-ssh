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
 * Every line is wrapped so that a failing command prints the marker (see wrap()); the marker in the output is a
 * TrapException. Everything that is put into the line is checked first: names must be plain, values are quoted as
 * RouterOS strings, so an attribute cannot end the command and start another.
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
     * @param string $marker printed by the line when the command fails
     * @throws UnsupportedOperationException a menu, verb, name or query the CLI line cannot express (nothing is sent)
     */
    public static function compile(Command $command, string $marker): string
    {
        $menu = $command->menu;
        if (preg_match(self::MENU, $menu) !== 1) {
            throw new UnsupportedOperationException(sprintf('Menu %s cannot be used in a CLI line', $menu));
        }
        self::assertName($command->verb, 'verb');
        $path = rtrim($menu, '/') . '/' . $command->verb;

        return match ($command->verb) {
            'print' => self::wrap(
                ':put [:serialize to=json options=' . self::JSON_OPTIONS
                    . ' [' . self::printCommand($path, $command) . ']]',
                $marker,
            ),
            'add' => self::wrap(':put [' . self::withAttributes($path, $command->attributes, false) . ']', $marker),
            default => self::wrap(self::withAttributes($path, $command->attributes, true), $marker),
        };
    }

    /**
     * The Result of a line made by compile().
     *
     * @param string $output what the line printed (stdout of the exec channel)
     * @throws TrapException the line failed, or printed something a command of this verb does not print on success
     * @throws ProtocolException a print answered with valid JSON that is not a list of objects
     */
    public static function result(Command $command, string $output, string $marker): Result
    {
        $text = trim($output);
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
     * The one place that decides how a failing command is reported. A syntax error of the whole line is reported by
     * the console before anything runs, so it prints no marker.
     */
    private static function wrap(string $line, string $marker): string
    {
        return ':do { ' . $line . ' } on-error={ :put ' . self::quote($marker) . ' }';
    }

    private static function printCommand(string $path, Command $command): string
    {
        if ($command->attributes !== []) {
            throw new UnsupportedOperationException('print takes no attributes; use the query and the proplist');
        }
        $line = $path . ' as-value';
        foreach ($command->proplist as $name) {
            self::assertName($name, 'proplist entry');
        }
        // the console rejects `.id` as a column ("input does not match any value of value-name") and prints it anyway
        $columns = array_diff($command->proplist, ['.id']);
        if ($columns !== []) {
            $line .= ' proplist=' . implode(',', $columns);
        }
        if ($command->query !== null) {
            $line .= ' where ' . self::condition($command->query, true);
        }

        return $line;
    }

    /**
     * @param array<string, string> $attributes
     */
    private static function withAttributes(string $path, array $attributes, bool $idIsNumbers): string
    {
        $line = $path;
        foreach ($attributes as $name => $value) {
            $name = (string) $name;
            self::assertName($name, 'attribute');
            if ($idIsNumbers && $name === '.id') {
                $line .= ' numbers=' . (preg_match(self::ID_LIST, $value) === 1 ? $value : self::quote($value));
            } else {
                $line .= ' ' . $name . '=' . self::quote($value);
            }
        }

        return $line;
    }

    private static function condition(Query $query, bool $top): string
    {
        return match ($query->operator) {
            QueryOperator::Where => self::name($query) . '=' . self::quote((string) $query->value),
            QueryOperator::Less => self::name($query) . '<' . self::quote((string) $query->value),
            QueryOperator::Greater => self::name($query) . '>' . self::quote((string) $query->value),
            QueryOperator::Has => self::name($query),
            QueryOperator::HasNot => '!' . self::name($query),
            QueryOperator::And => self::join($query, ' and ', $top),
            QueryOperator::Or => self::join($query, ' or ', $top),
            QueryOperator::Not => '!(' . self::condition($query->operands[0], true) . ')',
        };
    }

    private static function join(Query $query, string $glue, bool $top): string
    {
        $text = implode(
            $glue,
            array_map(static fn (Query $operand): string => self::condition($operand, false), $query->operands),
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
        if (!is_array($decoded) || !array_is_list($decoded)) {
            throw new ProtocolException('print did not return a list of rows');
        }
        $rows = [];
        foreach ($decoded as $item) {
            if (!is_array($item)) {
                throw new ProtocolException('print did not return a list of rows');
            }
            $row = [];
            foreach ($item as $key => $value) {
                $row[(string) $key] = self::text($value);
            }
            $rows[] = $columns === [] ? $row : array_intersect_key($row, array_flip($columns));
        }

        return $rows;
    }

    /**
     * Booleans and numbers come out of `:serialize to=json` as JSON booleans and numbers (strings that look like
     * numbers stay strings, thanks to `options=json.no-string-conversion`). The API gives strings, so do we.
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
            default => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        };
    }
}
