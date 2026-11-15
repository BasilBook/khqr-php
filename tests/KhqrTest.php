<?php

declare(strict_types=1);

namespace BasilBook\Khqr\Tests;

use BasilBook\Khqr\Exception\KhqrInputException;
use BasilBook\Khqr\Khqr;
use BasilBook\Khqr\KhqrInput;
use BasilBook\Khqr\Latin;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The TypeScript spec (apps/api/src/common/khqr.spec.ts), case for case. Expected strings are
 * NBC's own, from the `bakong-khqr` SDK's tests (1.0.20).
 */
final class KhqrTest extends TestCase
{
    public function testWritesTheCodeNbcSdkWritesTimestampsAndChecksumIncluded(): void
    {
        self::assertSame(
            '00020101021229180014vandoeurn@devb52045999530384054031015802KH5909Vandoeurn6010Phnom Penh9934001317272493649740113172724948497463047BD0',
            Khqr::encode(new KhqrInput(
                accountId: 'vandoeurn@devb',
                merchantName: 'Vandoeurn',
                amount: 10100,
                currency: 'USD',
                createdAt: 1727249364974,
                expiresAt: 1727249484974,
            )),
        );
    }

    public function testCarriesTheBillNumberAndTheStoreAsTheSdkDoes(): void
    {
        self::assertStringStartsWith(
            '00020101021229180014jonhsmith@nbcq520459995303840540115802KH5910Jonh Smith6010PHNOM PENH62210117INV-2021-07-65822',
            Khqr::encode(new KhqrInput('jonhsmith@nbcq', 'Jonh Smith', 100, 'USD', 1, 2, merchantCity: 'PHNOM PENH', billNumber: 'INV-2021-07-65822')),
        );
        self::assertStringStartsWith(
            '00020101021229180014jonhsmith@nbcq5204599953031165405500005802KH5910Jonh Smith6010Phnom Penh62090305BKK-1',
            Khqr::encode(new KhqrInput('jonhsmith@nbcq', 'Jonh Smith', 50000, 'KHR', 1, 2, storeLabel: 'BKK-1')),
        );
    }

    public function testChecksItselfWithCrc16CcittFalse(): void
    {
        self::assertSame('29B1', Khqr::crc16('123456789'));
        self::assertSame('F7FD', Khqr::crc16('00020101021129180014jonhsmith@nbcq52041A2B53031165802KH5910Jonh Smith6010Phnom Penh6304'));
        // Over the UTF-8 bytes: é is two of them.
        self::assertSame(Khqr::crc16("Caf\u{E9}"), Khqr::crc16("Caf\xC3\xA9"));
        self::assertNotSame(Khqr::crc16("Caf\xE9"), Khqr::crc16("Caf\u{E9}"));
    }

    public function testWritesRielWholeAndDollarsPlainOrToTheCent(): void
    {
        self::assertSame('20900', Khqr::amount(20900, 'KHR'));
        self::assertSame('5', Khqr::amount(500, 'USD'));
        self::assertSame('4.50', Khqr::amount(450, 'USD'));
        self::assertSame('0.01', Khqr::amount(1, 'USD'));
        $this->expectException(KhqrInputException::class);
        Khqr::amount(0, 'USD');
    }

    public function testKeepsNamesToWhatAKhqrFieldHolds(): void
    {
        $qr = Khqr::encode(new KhqrInput('a@b', 'ស្រះ Lotus & Lime Café — Riverside branch', 100, 'USD', 1, 2));
        // Khmer and the dash dropped, the accent folded, cut to 25.
        self::assertStringContainsString('5925Lotus & Lime Cafe Riversi6010', $qr);
    }

    public function testFoldsTheSameWithoutExtIntl(): void
    {
        foreach (['ស្រះ Lotus & Lime Café — Riverside branch', 'Ñandú Ölçek ﬁne Ｔea', 'Crème brûlée  à la carte'] as $name) {
            self::assertSame(Latin::field($name, 25, true), Latin::field($name, 25, false), $name);
        }
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function refusals(): iterable
    {
        $ok = ['accountId' => 'a@b', 'merchantName' => 'A', 'amount' => 100, 'currency' => 'USD', 'createdAt' => 1, 'expiresAt' => 2];
        yield 'a money KHQR does not take' => [['currency' => 'THB'] + $ok, 'currency'];
        yield 'not a Bakong account' => [['accountId' => 'no account'] + $ok, 'account'];
        yield 'expires as it is made' => [['expiresAt' => 1] + $ok, 'lifetime'];
        yield 'no amount' => [['amount' => 0] + $ok, 'amount'];
        yield 'an amount that is not minor units' => [['amount' => 1.5] + $ok, 'amount'];
    }

    /** @param array<string, mixed> $input */
    #[DataProvider('refusals')]
    public function testRefusesWhatABankAppWouldRefuse(array $input, string $problem): void
    {
        try {
            Khqr::encode($input);
            self::fail('Made a KHQR a bank app would refuse');
        } catch (KhqrInputException $e) {
            self::assertSame($problem, $e->problem);
        }
    }

    public function testWritesAmountsOnlyInRielOrDollars(): void
    {
        try {
            Khqr::amount(100, 'THB');
            self::fail('Wrote a baht amount');
        } catch (KhqrInputException $e) {
            self::assertSame('currency', $e->problem);
        }
    }

    public function testKnowsABakongAccountId(): void
    {
        self::assertTrue(Khqr::isBakongAccountId('lotus_lime@aclb'));
        self::assertFalse(Khqr::isBakongAccountId('lotus lime'));
        self::assertFalse(Khqr::isBakongAccountId("a@b\n"));
    }

    public function testTakesRielAndDollarsOnly(): void
    {
        self::assertTrue(Khqr::takes('KHR'));
        self::assertTrue(Khqr::takes('USD'));
        self::assertFalse(Khqr::takes('THB'));
    }

    public function testKnowsAKhqrByTheMd5OfItsText(): void
    {
        $qr = '00020101021229180014vandoeurn@devb52045999530384054031015802KH5909Vandoeurn6010Phnom Penh9934001317272493649740113172724948497463047BD0';
        self::assertSame(md5($qr), Khqr::md5($qr));
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', Khqr::md5($qr));
    }
}
