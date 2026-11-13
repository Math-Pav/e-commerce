<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\ApiToken;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class ApiTokenTest extends TestCase
{
    public function testTokenIsExpiredFromItsExpirationDate(): void
    {
        $token = new ApiToken(new User(), 'Mon téléphone', str_repeat('a', 64), 'abcd1234', new \DateTimeImmutable('2026-03-01 12:00:00'));

        self::assertFalse($token->isExpired(new \DateTimeImmutable('2026-03-01 11:59:59')));
        self::assertTrue($token->isExpired(new \DateTimeImmutable('2026-03-01 12:00:00')));
    }

    public function testNewTokenHasNeverBeenUsed(): void
    {
        $token = new ApiToken(new User(), 'Mon téléphone', str_repeat('a', 64), 'abcd1234', new \DateTimeImmutable('+90 days'));
        $usedAt = new \DateTimeImmutable('2026-03-01 12:00:00');

        self::assertNull($token->getLastUsedAt());
        $token->markAsUsed($usedAt);
        self::assertSame($usedAt, $token->getLastUsedAt());
    }
}
