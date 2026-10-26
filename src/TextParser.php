<?php

declare(strict_types=1);

namespace Lossik\Mikrotik\Ssh;

use Lossik\DeviceCommunication\ProtocolException;
use Lossik\DeviceCommunication\TrapException;

/**
 * Reads the output of the ser3 print line of CliCompiler::compileText() (RouterOS below 7.17). Pure: no I/O.
 *
 *     output = *row "END" CRLF
 *     row    = 1*field "--" CRLF      ; a list menu
 *            / 1*field                ; a singleton menu (/system/resource, /ip/dns …): one row, no "--"
 *     field  = key ":S:" len ":" value CRLF
 *            / key ":A:" count CRLF count*( "E:" type ":" len ":" value CRLF )
 *     key    = 1*( ALPHA / DIGIT / "." / "_" / "-" )
 *     type   = 1*( lower-case ALPHA / DIGIT / "-" )   ; [:typeof]: str, ip, ip6 (measured), ip-prefix …
 *
 * `len` counts bytes, so a value may hold anything (CRLF, `--`, `END`, `:`); the CRLF after it is checked. A list is
 * joined with `,` like the API does (an empty one is `''`), an element of type `array` is refused. An empty
 * `comment` is left out (the API omits it); other empty values stay. Bytes pass unchanged unless a charset other
 * than UTF-8 is given, then every value is converted from it.
 *
 * How the output ends decides between a refusal of the console and a broken output:
 *  - empty output: the line did not run (a print prints at least `END`) → ProtocolException;
 *  - a line that is not a field, at the start or right after a complete row, with no `END` line anywhere after it:
 *    the console's own error (`bad command name …`, possibly after rows) → TrapException with the text from that
 *    line on (the rows before it are dropped);
 *  - such a line with an `END` line after it ("END is there, the grammar is not"), a field that runs past the output
 *    or lacks its CRLF, a field-shaped line with an invalid key, a list row without `--`, a line that is not a field
 *    in the middle of a row, anything after `END`, an output that stops between rows without `END`
 *    → ProtocolException.
 *
 * @internal
 */
final class TextParser
{
    private const FIELD = '/\G([A-Za-z0-9._-]+):(?:S:([0-9]+):|A:([0-9]+)\r\n)/';

    /** The shape of a field with any key: a line like this is a broken field, never a message of the console. */
    private const FIELD_SHAPE = '/\G[^\r\n:]*:(?:S:[0-9]+:|A:[0-9]+\r\n)/';

    private const ELEMENT = '/\GE:([a-z0-9-]+):([0-9]+):/';

    /**
     * @param string $charset the charset of the device; values and the text of a TrapException are converted from it
     * @return list<array<string, string>>
     * @throws TrapException the console printed an error instead of (or after some of) the rows
     * @throws ProtocolException the output is not what the ser3 line prints
     */
    public static function parse(string $output, string $charset = 'UTF-8'): array
    {
        if ($output === '') {
            throw new ProtocolException('The console printed nothing, the print line did not run');
        }
        $length = strlen($output);
        $offset = 0;
        $rows = [];
        $row = [];
        // a row is open from its first field on (a row of only an empty comment is a row, too)
        $open = false;
        $list = false;
        while (true) {
            if ($offset === $length) {
                throw new ProtocolException('The output of the print ended without END');
            }
            if (substr_compare($output, "END\r\n", $offset, 5) === 0) {
                if ($offset + 5 !== $length) {
                    throw new ProtocolException('Unexpected bytes after END');
                }
                if ($open && $list) {
                    throw new ProtocolException('A row of a list menu is missing its -- terminator');
                }
                if ($open) {
                    $rows[] = $row;
                }

                return $rows;
            }
            if (substr_compare($output, "--\r\n", $offset, 4) === 0) {
                if (!$open) {
                    throw new ProtocolException('An empty row');
                }
                $rows[] = $row;
                $row = [];
                $open = false;
                $list = true;
                $offset += 4;
                continue;
            }
            if (preg_match(self::FIELD, $output, $field, PREG_UNMATCHED_AS_NULL, $offset) !== 1) {
                throw self::notAField($output, $offset, !$open, $charset);
            }
            $offset += strlen((string) $field[0]);
            $key = (string) $field[1];
            if ($field[3] !== null) {
                $values = [];
                for ($count = (int) $field[3]; $count > 0; $count--) {
                    if (preg_match(self::ELEMENT, $output, $element, 0, $offset) !== 1) {
                        throw new ProtocolException(sprintf('A malformed element of the list %s', $key));
                    }
                    if ($element[1] === 'array') {
                        throw new ProtocolException(sprintf('The list %s holds a list', $key));
                    }
                    $offset += strlen($element[0]);
                    $values[] = self::value($output, $offset, (int) $element[2], $length);
                }
                $value = implode(',', $values);
            } else {
                $value = self::value($output, $offset, (int) $field[2], $length);
            }
            if ($key !== 'comment' || $value !== '') {
                $row[$key] = CliCompiler::decode($value, $charset);
            }
            $open = true;
        }
    }

    /**
     * The value of `len` bytes at the offset, and the offset moved past its CRLF.
     *
     * @throws ProtocolException the value runs past the output or is not followed by CRLF
     */
    private static function value(string $output, int &$offset, int $len, int $length): string
    {
        if ($offset + $len + 2 > $length || substr_compare($output, "\r\n", $offset + $len, 2) !== 0) {
            throw new ProtocolException('A value of the print is not as long as its length says');
        }
        $value = substr($output, $offset, $len);
        $offset += $len + 2;

        return $value;
    }

    /**
     * What a line that is not a valid field means (see the class description).
     */
    private static function notAField(
        string $output,
        int $offset,
        bool $boundary,
        string $charset,
    ): ProtocolException|TrapException {
        if (preg_match(self::FIELD_SHAPE, $output, $shape, 0, $offset) === 1) {
            return new ProtocolException('A field of the print has an invalid key');
        }
        if (!$boundary) {
            return new ProtocolException('A line in the middle of a row of the print is not a field');
        }
        $end = strpos($output, "\nEND\r\n", $offset);
        if ($end !== false) {
            return new ProtocolException('The output of the print ends with END, but a line before it is not a field');
        }

        return new TrapException(CliCompiler::decode(trim(substr($output, $offset)), $charset));
    }
}
