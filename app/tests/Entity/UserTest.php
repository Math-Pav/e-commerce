<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class UserTest extends TestCase
{
    public function testUserIdentifierIsTheEmail(): void
    {
        $user = (new User())->setEmail('client@example.com');

        self::assertSame('client@example.com', $user->getUserIdentifier());
    }

    public function testUserWithoutEmailCannotBeIdentified(): void
    {
        $this->expectException(\LogicException::class);

        (new User())->getUserIdentifier();
    }
}
