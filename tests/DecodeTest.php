<?php

declare(strict_types=1);

namespace BasilBook\Khqr\Tests;

use BasilBook\Khqr\Exception\KhqrDecodeException;
use BasilBook\Khqr\Khqr;
use BasilBook\Khqr\KhqrInput;
use PHPUnit\Framework\TestCase;

final class DecodeTest extends TestCase
{
    private const NBC = '00020101021229180014vandoeurn@devb52045999530384054031015802KH5909Vandoeurn6010Phnom Penh9934001317272493649740113172724948497463047BD0';

    public function testReadsNbcsOwnCodeBack(): void
    {
        $k = Khqr::decode(self::NBC);
        self::assertSame('12', $k->pointOfInitiation);
        self::assertSame('vandoeurn@devb', $k->accountId);
        self::assertSame('individual', $k->accountType);
        self::assertSame(['00' => 'vandoeurn@devb'], array_map('strval', $k->account));
        self::assertNull($k->acquiringBank);
        self::assertNull($k->merchantId);
        self::assertSame('5999', $k->merchantCategoryCode);
        self::assertSame('USD', $k->currency);
        self::assertSame(10100, $k->amount);
        self::assertSame('KH', $k->countryCode);
        self::assertSame('Vandoeurn', $k->merchantName);
        self::assertSame('Phnom Penh', $k->merchantCity);
        self::assertSame(1727249364974, $k->createdAt);
        self::assertSame(1727249484974, $k->expiresAt);
        self::assertSame('7BD0', $k->crc);
        self::assertSame('0014vandoeurn@devb', $k->tags['29']);
        self::assertNull($k->billNumber);
        self::assertNull($k->purpose);
        self::assertSame([], $k->additional);
        self::assertSame([], $k->alternateLanguage);
    }

    public function testNamesItsFieldsAsTheTypeScriptDoes(): void
    {
        $names = array_map(static fn (\ReflectionParameter $p): string => $p->getName(), (new \ReflectionMethod(\BasilBook\Khqr\KhqrDecoded::class, '__construct'))->getParameters());
        self::assertSame([
            'tags', 'pointOfInitiation', 'accountType', 'accountId', 'account', 'acquiringBank', 'merchantId',
            'merchantCategoryCode', 'merchantName', 'merchantCity', 'countryCode', 'currency', 'amount', 'billNumber',
            'mobileNumber', 'storeLabel', 'terminalLabel', 'purpose', 'additional', 'alternateLanguage', 'createdAt',
            'expiresAt', 'crc',
        ], $names);
    }

    public function testReadsTheBillAndStoreAndRielBack(): void
    {
        $qr = Khqr::encode(new KhqrInput('jonhsmith@nbcq', 'Jonh Smith', 50000, 'KHR', 1, 2, billNumber: 'INV-1', storeLabel: 'BKK-1'));
        $k = Khqr::decode($qr);
        self::assertSame('KHR', $k->currency);
        self::assertSame(50000, $k->amount);
        self::assertSame('INV-1', $k->billNumber);
        self::assertSame('BKK-1', $k->storeLabel);
        self::assertSame(450, Khqr::decode(Khqr::encode(new KhqrInput('a@b', 'A', 450, 'USD', 1, 2)))->amount);
        // Whitespace around the text is not part of the code.
        self::assertSame(50000, Khqr::decode(" {$qr}\n")->amount);
    }

    public function testReadsAStaticMerchantCodeWithNoAmount(): void
    {
        // A merchant account (tag 30) with its bank, static (11), no amount, no lifetime.
        $body = '000201010211' . '3047' . '0015lotus_lime@aclb' . '0112LOTUSLIME001' . '0208ACLBKHPP' . '5204599953031165802KH5909Lotus Lim6010Phnom Penh6304';
        $k = Khqr::decode($body . Khqr::crc16($body));
        self::assertSame('11', $k->pointOfInitiation);
        self::assertSame('merchant', $k->accountType);
        self::assertSame('lotus_lime@aclb', $k->accountId);
        self::assertSame('LOTUSLIME001', $k->merchantId);
        self::assertSame('ACLBKHPP', $k->acquiringBank);
        self::assertNull($k->amount);
        self::assertNull($k->expiresAt);
    }

    public function testReadsKhmerInTheOtherLanguageFieldEitherChecksum(): void
    {
        $khmer = 'ឡូតឹស';
        $lang = '0002km' . '01' . sprintf('%02d', mb_strlen($khmer)) . $khmer;
        $body = '000201010212' . '29070003a@b' . '520459995303840540115802KH5901A6010Phnom Penh'
            . '64' . sprintf('%02d', mb_strlen($lang)) . $lang . '6304';
        $k = Khqr::decode($body . Khqr::crc16($body));
        self::assertSame('km', $k->alternateLanguage['00']);
        self::assertSame($khmer, $k->alternateLanguage['01']);
        // The checksum a JavaScript encoder makes over UTF-16 units is read too.
        $units = mb_convert_encoding($body, 'UTF-16BE', 'UTF-8');
        $crc = 0xFFFF;
        for ($i = 1; $i < strlen($units); $i += 2) {
            $crc ^= ord($units[$i]) << 8;
            for ($b = 0; $b < 8; $b++) {
                $crc = ($crc & 0x8000) !== 0 ? (($crc << 1) ^ 0x1021) & 0xFFFF : ($crc << 1) & 0xFFFF;
            }
        }
        self::assertSame($khmer, Khqr::decode($body . sprintf('%04X', $crc))->alternateLanguage['01']);
    }

    public function testRefusesAChangedCode(): void
    {
        // The amount changed from 101 to 901: the checksum gives it away.
        $changed = str_replace('5403101', '5403901', self::NBC);
        self::assertSame('crc', $this->decodeError($changed)->problem);
        self::assertSame('crc', $this->decodeError(substr(self::NBC, 0, -4) . '0000')->problem);
    }

    public function testRefusesABrokenLayout(): void
    {
        foreach (['', 'hello', substr(self::NBC, 0, -8), '0002016304' . 'ZZZZ', substr(self::NBC, 0, 40)] as $broken) {
            self::assertSame('format', $this->decodeError($broken)->problem, $broken);
        }
        // A checksum that is right over a code with no account, with another payload format, or a tag twice.
        foreach ([
            '0002010102125204599953038405802KH5901A6010Phnom Penh6304',
            '000202010212' . '29070003a@b' . '5204599953038405802KH5901A6010Phnom Penh6304',
            '000201010212' . '29070003a@b' . '52045999520459995303840' . '5802KH5901A6010Phnom Penh6304',
        ] as $body) {
            self::assertSame('format', $this->decodeError($body . Khqr::crc16($body))->problem, $body);
        }
    }

    public function testRefusesAMoneyAnAmountOrATimeKhqrDoesNotCarry(): void
    {
        foreach ([
            '000201010212' . '29070003a@b' . '52045999530376454031005802KH5901A6010Phnom Penh6304',
            '000201010212' . '29070003a@b' . '5204599953038405406' . '10.125' . '5802KH5901A6010Phnom Penh6304',
            '000201010212' . '29070003a@b' . '52045999530384054010' . '5802KH5901A6010Phnom Penh6304',
            '000201010212' . '29070003a@b' . '5204599953038405802KH5901A6010Phnom Penh' . '99080004soon' . '6304',
        ] as $body) {
            self::assertSame('field', $this->decodeError($body . Khqr::crc16($body))->problem, $body);
        }
    }

    public function testReadsFieldsAndAmountsAsTheTypeScriptHelpersDo(): void
    {
        self::assertSame(['00' => '01', '01' => '12'], array_map('strval', Khqr::readFields('000201010212')));
        self::assertSame('5906Lotus!', Khqr::field('59', 'Lotus!'));
        self::assertSame(450, Khqr::minor('4.5', 'USD'));
        self::assertSame(450, Khqr::minor('4.50', 'USD'));
        self::assertSame(20900, Khqr::minor('20900', 'KHR'));
        self::assertNull(Khqr::minor('4.505', 'USD'));
        self::assertNull(Khqr::minor('0', 'USD'));
        self::assertNull(Khqr::minor('4.', 'USD'));
        self::assertNull(Khqr::minor('5', 'THB'));
        self::assertSame(0, Khqr::decimals('KHR'));
        self::assertSame(2, Khqr::decimals('USD'));
    }

    private function decodeError(string $qr): KhqrDecodeException
    {
        try {
            Khqr::decode($qr);
        } catch (KhqrDecodeException $e) {
            return $e;
        }
        self::fail("Read a KHQR that should have been refused: {$qr}");
    }
}
