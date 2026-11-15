<?php

declare(strict_types=1);

namespace BasilBook\Khqr\Tests;

use BasilBook\Khqr\Bakong\BakongClient;
use BasilBook\Khqr\Khqr;
use BasilBook\Khqr\KhqrInput;
use PHPUnit\Framework\TestCase;

/**
 * What the TypeScript `@basilbook/khqr` made, returned and threw for the same inputs and the same
 * answers from Bakong (tests/fixtures/ts-vectors.json, written by packages/php-vectors.mjs from the
 * built package). PHP must do exactly the same: a failure here is a difference between the two.
 */
final class TsVectorsTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function vectors(): array
    {
        return json_decode((string) file_get_contents(__DIR__ . '/fixtures/ts-vectors.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * What a call came to, as the vectors write it: `['result' => …]`, or the error's stem and properties.
     *
     * @param array<string, mixed> $case
     */
    private static function assertSaid(array $case, \Closure $call, string $label): void
    {
        try {
            $result = $call();
        } catch (\BasilBook\Khqr\Exception\KhqrException $e) {
            self::assertArrayHasKey('error', $case, "{$label}: PHP threw " . $e::class . ': ' . $e->getMessage());
            self::assertSame($case['error'], preg_replace('/^.*\\\\|Exception$/', '', $e::class), $label);
            self::assertSame($case['message'], $e->getMessage(), $label);
            if (array_key_exists('problem', $case)) {
                self::assertSame($case['problem'], $e->problem, $label);
            }
            return;
        }
        self::assertArrayNotHasKey('error', $case, "{$label}: TypeScript threw " . ($case['message'] ?? ''));
        self::assertSame($case['result'], $result, $label);
    }

    public function testEncodesAsTheTypeScriptDoes(): void
    {
        $v = self::vectors();
        foreach ($v['khqrEncode'] as $case) {
            $qr = Khqr::encode(KhqrInput::fromArray($case['input']));
            self::assertSame($case['qr'], $qr, (string) json_encode($case['input'], JSON_UNESCAPED_UNICODE));
            self::assertSame($case['md5'], Khqr::md5($qr));
            self::assertSame($qr, Khqr::encode($case['input']), 'an array makes the same code');
        }
        foreach ($v['khqrEncodeRefused'] as $case) {
            self::assertSaid($case, static fn () => Khqr::encode($case['input']), (string) json_encode($case['input']));
        }
    }

    public function testDecodesAsTheTypeScriptDoes(): void
    {
        foreach (self::vectors()['khqrDecode'] as $case) {
            $label = (string) json_encode($case['text'], JSON_UNESCAPED_UNICODE);
            try {
                $d = Khqr::decode($case['text']);
            } catch (\BasilBook\Khqr\Exception\KhqrDecodeException $e) {
                self::assertArrayHasKey('error', $case, "{$label}: PHP threw " . $e->getMessage());
                self::assertSame('KhqrDecode', $case['error'], $label);
                self::assertSame($case['problem'], $e->problem, "{$label}: {$e->getMessage()} / {$case['message']}");
                continue;
            }
            self::assertArrayNotHasKey('error', $case, "{$label}: TypeScript threw " . ($case['message'] ?? ''));
            $want = $case['result'];
            // The same fields, by the same names, in the same order.
            self::assertSame(array_keys($want), array_keys(get_object_vars($d)), $label);
            foreach ($want as $name => $value) {
                if (is_array($value)) {
                    // Tag maps: the same tags and values (PHP keeps the written order, JavaScript puts 10-99 first).
                    self::assertEquals(self::stringKeys($value), self::stringKeys($d->{$name}), "{$label} {$name}");
                } else {
                    self::assertSame($value, $d->{$name}, "{$label} {$name}");
                }
            }
        }
    }

    public function testReadsFieldsAndWritesThemAsTheTypeScriptDoes(): void
    {
        $v = self::vectors();
        foreach ($v['readKhqrFields'] as $case) {
            try {
                $fields = Khqr::readFields($case['text']);
            } catch (\BasilBook\Khqr\Exception\KhqrDecodeException $e) {
                self::assertArrayHasKey('error', $case, "{$case['text']}: PHP threw " . $e->getMessage());
                self::assertSame($case['problem'], $e->problem, $case['text']);
                continue;
            }
            self::assertArrayNotHasKey('error', $case, $case['text']);
            self::assertEquals(self::stringKeys($case['result']), self::stringKeys($fields), $case['text']);
        }
        foreach ($v['khqrField'] as $case) {
            self::assertSame($case['field'], Khqr::field($case['tag'], $case['value']));
        }
    }

    public function testChecksumsAmountsAndMoniesAsTheTypeScriptDoes(): void
    {
        $v = self::vectors();
        foreach ($v['crc16'] as $case) {
            self::assertSame($case['crc'], Khqr::crc16($case['text']), $case['text']);
        }
        foreach ($v['khqrAmount'] as $case) {
            self::assertSaid($case, static fn () => Khqr::amount($case['minor'], $case['currency']), "{$case['minor']} {$case['currency']}");
        }
        foreach ($v['khqrMinor'] as $case) {
            self::assertSame($case['minor'], Khqr::minor($case['amount'], $case['currency']), "{$case['amount']} {$case['currency']}");
        }
        foreach ($v['khqrDecimals'] as $case) {
            self::assertSaid($case, static fn () => Khqr::decimals($case['currency']), $case['currency']);
        }
        foreach ($v['khqrMd5'] as $case) {
            self::assertSame($case['md5'], Khqr::md5($case['text']));
        }
        foreach ($v['isBakongAccountId'] as $case) {
            self::assertSame($case['ok'], Khqr::isBakongAccountId($case['id']), $case['id']);
        }
        foreach ($v['khqrTakes'] as $case) {
            self::assertSame($case['ok'], Khqr::takes($case['currency']), $case['currency']);
        }
        self::assertEquals($v['constants']['KHQR_CURRENCY'], Khqr::CURRENCY);
        self::assertEquals(self::stringKeys($v['constants']['KHQR_CURRENCY_CODE']), self::stringKeys(Khqr::CURRENCY_CODE));
        self::assertEquals($v['constants']['KHQR_MAX'], Khqr::MAX);
    }

    public function testAsksBakongAsTheTypeScriptDoes(): void
    {
        foreach (self::vectors()['bakong'] as $case) {
            $http = new VectorHttp($case['answers']);
            $logs = [];
            $renewed = [];
            $bakong = new BakongClient($case['config'] + [
                'log' => static function (string $level, string $message) use (&$logs): void {
                    $logs[] = [$level, $message];
                },
                'onTokenRenewed' => static function (string $token) use (&$renewed): void {
                    $renewed[] = $token;
                },
            ], $http);
            $result = match ($case['call']) {
                'check' => $bakong->check($case['args'][0], accountId: $case['args'][1]['accountId'], amount: $case['args'][1]['amount'], currency: $case['args'][1]['currency']),
                'accountExists' => $bakong->accountExists($case['args'][0]),
                'renewToken' => $bakong->renewToken(),
            };
            self::assertSame($case['result'], $result, $case['name']);
            self::assertSame($case['requests'], $http->requests, $case['name']);
            self::assertSame($case['logs'], $logs, $case['name']);
            self::assertSame($case['token'], $bakong->token(), $case['name']);
            self::assertSame($case['configured'], $bakong->configured(), $case['name']);
            self::assertSame($case['renewed'], $renewed, $case['name']);
        }
    }

    /**
     * A tag map with string keys (PHP keeps '29' as the integer 29), to compare as maps.
     *
     * @param array<int|string, mixed> $map
     *
     * @return array<string, mixed>
     */
    private static function stringKeys(array $map): array
    {
        $out = [];
        foreach ($map as $k => $value) {
            $out[(string) $k] = $value;
        }
        ksort($out, SORT_STRING);
        return $out;
    }
}
