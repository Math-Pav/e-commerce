<?php

declare(strict_types=1);

namespace App\Tests\Validator;

use App\Model\ShippingAddress;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class AddressConstraintsTest extends KernelTestCase
{
    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->validator = static::getContainer()->get(ValidatorInterface::class);
    }

    public function testValidShippingAddress(): void
    {
        self::assertCount(0, $this->validator->validate($this->createShippingAddress('69001')));
    }

    public function testShippingAddressRejectsInvalidPostalCode(): void
    {
        $violations = $this->validator->validate($this->createShippingAddress('6900A'));

        self::assertCount(1, $violations);
        self::assertSame('postalCode', $violations[0]->getPropertyPath());
        self::assertSame('Le code postal doit contenir 5 chiffres.', $violations[0]->getMessage());
    }

    public function testEmptyPostalCodeShowsOnlyTheNotBlankMessage(): void
    {
        $violations = $this->validator->validate($this->createShippingAddress(''));

        self::assertCount(1, $violations);
        self::assertSame('Veuillez saisir le code postal.', $violations[0]->getMessage());
    }

    public function testShippingAddressRejectsTooLongCity(): void
    {
        $address = $this->createShippingAddress('69001');
        $address->city = str_repeat('a', 101);

        $violations = $this->validator->validate($address);

        self::assertCount(1, $violations);
        self::assertSame('city', $violations[0]->getPropertyPath());
    }

    private function createShippingAddress(string $postalCode): ShippingAddress
    {
        $address = new ShippingAddress();
        $address->fullName = 'Camille Martin';
        $address->address = '12 rue des Lilas';
        $address->postalCode = $postalCode;
        $address->city = 'Lyon';

        return $address;
    }
}
