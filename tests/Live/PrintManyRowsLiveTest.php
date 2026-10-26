<?php

declare(strict_types=1);

namespace Lossik\Mikrotik\Ssh\Tests\Live;

use Lossik\DeviceCommunication\Query;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Large;

/**
 * A print of many rows (OL9). Large: creating and printing 300 items took up to 7.45 s on the emulated router
 * (`fd-ssh-report.md`, 7.16.2), too close to the 10 s of a medium test.
 */
#[Group('live')]
#[Large]
final class PrintManyRowsLiveTest extends LiveTestCase
{
    /**
     * 300 rows created by one `:for` exec and removed by one `remove [find …]` exec; the list is LIST, so tearDown
     * removes what a `:for` that stopped half-way created.
     */
    public function testPrintReturnsThreeHundredRows(): void
    {
        $this->execRaw(
            ':for i from=1 to=300 do={ /ip firewall address-list add list=' . self::LIST
            . ' address=("10.99." . ($i / 250) . "." . ($i % 250 + 1)) comment=("row " . $i) }',
        );
        try {
            $rows = $this->client()->menu('/ip/firewall/address-list')->print(
                Query::where('list', self::LIST),
                ['address', 'comment'],
            );
        } finally {
            $this->execRaw('/ip firewall address-list remove [find where list=' . self::LIST . ']');
        }

        self::assertCount(300, $rows);
        self::assertSame(['address' => '10.99.0.2', 'comment' => 'row 1'], $rows[0]);
        self::assertSame(['address' => '10.99.1.51', 'comment' => 'row 300'], $rows[299]);
    }
}
