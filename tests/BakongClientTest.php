<?php

declare(strict_types=1);

namespace BasilBook\Khqr\Tests;

use BasilBook\Khqr\Bakong\BakongClient;
use PHPUnit\Framework\TestCase;

/** The Bakong client against a fake Bakong: the same cases as the TypeScript's bakong.spec.ts. */
final class BakongClientTest extends TestCase
{
    private const PAID = ['responseCode' => 0, 'data' => ['toAccountId' => 'Lotus_Lime@aclb', 'amount' => 4.5, 'currency' => 'usd', 'hash' => 'h']];

    /** @var list<array{string, string}> */
    private array $logged = [];

    /** @param array<string, mixed> $config */
    private function bakong(FakeHttp $http, array $config = []): BakongClient
    {
        return new BakongClient($config + ['token' => 't0', 'log' => fn (string $level, string $message) => $this->logged[] = [$level, $message]], $http);
    }

    public function testSaysPaidOnlyWhenTheAccountAmountAndMoneyMatch(): void
    {
        $http = FakeHttp::answering(self::PAID);
        $bakong = $this->bakong($http);
        self::assertSame('paid', $bakong->check('abc', 'lotus_lime@aclb', 450, 'USD'));
        $call = $http->calls[0];
        self::assertSame('https://api-bakong.nbc.gov.kh/v1/check_transaction_by_md5', $call['url']);
        self::assertSame(['md5' => 'abc'], $call['body']);
        self::assertSame(['Bearer t0'], $call['headers']['authorization']);
    }

    public function testKeepsMoneyThatDoesNotMatchPending(): void
    {
        $bakong = $this->bakong(FakeHttp::answering(self::PAID));
        self::assertSame('pending', $bakong->check('abc', 'lotus_lime@aclb', 500, 'USD'));
        self::assertSame('pending', $bakong->check('abc', 'other@aclb', 450, 'USD'));
        self::assertSame('pending', $bakong->check('abc', 'lotus_lime@aclb', 450, 'KHR'));
        self::assertSame(['error', 'Bakong abc: paid to Lotus_Lime@aclb 4.5 usd, not what the QR asked'], $this->logged[0]);
    }

    public function testSaysFailedAndNotYet(): void
    {
        self::assertSame('failed', $this->bakong(FakeHttp::answering(['responseCode' => 1, 'errorCode' => 3]))->check('abc', 'a@b', 450, 'USD'));
        self::assertSame('pending', $this->bakong(FakeHttp::answering(['responseCode' => 1, 'errorCode' => 1]))->check('abc', 'a@b', 450, 'USD'));
        self::assertSame('pending', $this->bakong(FakeHttp::answering('not json'))->check('abc', 'a@b', 450, 'USD'));
        $http = FakeHttp::answering(self::PAID, 500);
        self::assertSame('pending', $this->bakong($http)->check('abc', 'lotus_lime@aclb', 450, 'USD'));
        self::assertSame(['warn', 'Bakong check answered 500'], $this->logged[0]);
    }

    public function testAsksNothingWithoutATokenOrInAnotherMoney(): void
    {
        $http = FakeHttp::answering(self::PAID);
        self::assertSame('pending', $this->bakong($http, ['token' => ''])->check('abc', 'lotus_lime@aclb', 450, 'USD'));
        self::assertSame('pending', $this->bakong($http)->check('abc', 'lotus_lime@aclb', 450, 'THB'));
        self::assertSame([], $http->calls);
        self::assertFalse($this->bakong($http, ['token' => ''])->configured());
        self::assertNull($this->bakong($http, ['token' => ''])->accountExists('a@b'));
    }

    public function testNeverThrowsWhenBakongDoesNotAnswer(): void
    {
        self::assertSame('pending', $this->bakong(FakeHttp::offline())->check('abc', 'a@b', 450, 'USD'));
        self::assertSame(['warn', 'Bakong /v1/check_transaction_by_md5 did not answer: offline'], $this->logged[0]);
        self::assertNull($this->bakong(FakeHttp::offline())->accountExists('a@b'));
    }

    public function testRenewsARefusedTokenOnceAndAsksAgain(): void
    {
        $http = FakeHttp::inTurn([['responseCode' => 1], 401], [['data' => ['token' => 't1']], 200], [self::PAID, 200]);
        $kept = null;
        $bakong = $this->bakong($http, ['email' => 'you@example.com', 'onTokenRenewed' => function (string $t) use (&$kept): void {
            $kept = $t;
        }]);
        self::assertSame('paid', $bakong->check('abc', 'lotus_lime@aclb', 450, 'USD'));
        self::assertSame('t1', $bakong->token());
        self::assertSame('t1', $kept);
        self::assertSame('https://api-bakong.nbc.gov.kh/v1/renew_token', $http->calls[1]['url']);
        self::assertSame(['email' => 'you@example.com'], $http->calls[1]['body']);
        self::assertArrayNotHasKey('authorization', $http->calls[1]['headers']);
        self::assertSame(['Bearer t1'], $http->calls[2]['headers']['authorization']);
        self::assertContains(['info', 'Bakong token renewed'], $this->logged);
    }

    public function testDoesNotRenewWithoutAnEmailOrWhenBakongWillNot(): void
    {
        $http = FakeHttp::answering(['responseCode' => 1], 401);
        self::assertSame('pending', $this->bakong($http)->check('abc', 'a@b', 450, 'USD'));
        self::assertCount(1, $http->calls);
        $bakong = $this->bakong(FakeHttp::inTurn([['responseCode' => 1], 401], [['data' => []], 200]), ['email' => 'you@example.com']);
        self::assertFalse($bakong->renewToken());
        self::assertSame('t0', $bakong->token());
        self::assertContains(['error', 'Bakong refused the token and did not renew it: set a new token'], $this->logged);
    }

    public function testKnowsWhetherAnAccountExists(): void
    {
        $http = FakeHttp::answering(['responseCode' => 0, 'data' => []]);
        self::assertTrue($this->bakong($http)->accountExists('lotus_lime@aclb'));
        self::assertSame('https://api-bakong.nbc.gov.kh/v1/check_bakong_account', $http->calls[0]['url']);
        self::assertSame(['accountId' => 'lotus_lime@aclb'], $http->calls[0]['body']);
        self::assertFalse($this->bakong(FakeHttp::answering(['responseCode' => 1, 'errorCode' => 6]))->accountExists('no@aclb'));
        self::assertNull($this->bakong(FakeHttp::answering(['responseCode' => 1]))->accountExists('no@aclb'));
        self::assertNull($this->bakong(FakeHttp::answering([], 503))->accountExists('no@aclb'));
    }

    public function testTalksToTheAddressGiven(): void
    {
        $http = FakeHttp::answering(self::PAID);
        $this->bakong($http, ['baseUrl' => 'https://bakong.test/'])->check('abc', 'lotus_lime@aclb', 450, 'USD');
        self::assertSame('https://bakong.test/v1/check_transaction_by_md5', $http->calls[0]['url']);
    }
}
