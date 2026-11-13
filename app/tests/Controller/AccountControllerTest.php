<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AccountControllerTest extends WebTestCase
{
    public function testAccountRequiresLogin(): void
    {
        $client = static::createClient();

        $client->request('GET', '/mon-compte');

        self::assertResponseRedirects('/connexion');
    }

    public function testHeaderLinksToAccount(): void
    {
        $client = static::createClient();
        $this->logIn($client, 'client@example.com');

        $client->request('GET', '/produits');
        $client->clickLink('Camille');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Mon compte');
        self::assertSelectorExists('nav a[href="/mon-compte"][aria-current="page"]');
        self::assertInputValueSame('profile[firstName]', 'Camille');
        self::assertInputValueSame('profile[lastName]', 'Martin');
    }

    public function testUpdateProfile(): void
    {
        $client = static::createClient();
        $this->logIn($client, 'client@example.com');
        $client->request('GET', '/mon-compte');

        $client->submitForm('Enregistrer mes informations', [
            'profile[firstName]' => 'Camille-Anne',
            'profile[phone]' => '06 12 34 56 78',
            'profile[address]' => '3 place Bellecour',
            'profile[postalCode]' => '69002',
            'profile[city]' => 'Lyon',
        ]);

        self::assertResponseRedirects('/mon-compte');
        $client->followRedirect();
        self::assertSelectorTextContains('.flash--success', 'Vos informations ont bien été enregistrées.');
        self::assertSelectorTextContains('nav', 'Camille-Anne');
        self::assertInputValueSame('profile[address]', '3 place Bellecour');

        $user = $this->findUser('client@example.com');
        self::assertSame('Camille-Anne', $user->getFirstName());
        self::assertSame('Martin', $user->getLastName());
        self::assertSame('06 12 34 56 78', $user->getPhone());
        self::assertSame('3 place Bellecour', $user->getAddress());
        self::assertSame('69002', $user->getPostalCode());
        self::assertSame('Lyon', $user->getCity());
    }

    public function testEmptyOptionalFieldsAreStoredAsNull(): void
    {
        $client = static::createClient();
        $this->logIn($client, 'client@example.com');
        $this->submitProfile($client, ['profile[city]' => 'Lyon']);

        $this->submitProfile($client, ['profile[city]' => '']);

        self::assertResponseRedirects('/mon-compte');
        self::assertNull($this->findUser('client@example.com')->getCity());
    }

    public function testInvalidPostalCodeIsRejected(): void
    {
        $client = static::createClient();
        $this->logIn($client, 'client@example.com');

        $this->submitProfile($client, [
            'profile[firstName]' => 'Autre prénom',
            'profile[postalCode]' => '6900',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Le code postal doit contenir 5 chiffres.');
        // La saisie est conservée pour être corrigée…
        self::assertInputValueSame('profile[postalCode]', '6900');
        // … mais rien n'est enregistré, et l'en-tête affiche toujours le prénom réel (le DTO protège l'entité)
        self::assertSelectorTextContains('nav', 'Camille');
        self::assertSelectorTextNotContains('nav', 'Autre prénom');
        $user = $this->findUser('client@example.com');
        self::assertSame('Camille', $user->getFirstName());
        self::assertNull($user->getPostalCode());
    }

    public function testInvalidPhoneIsRejected(): void
    {
        $client = static::createClient();
        $this->logIn($client, 'client@example.com');

        $this->submitProfile($client, ['profile[phone]' => 'appelez-moi']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', "Le numéro de téléphone n'est pas valide.");
    }

    public function testProfileWithoutCsrfTokenIsRejected(): void
    {
        $client = static::createClient();
        $this->logIn($client, 'client@example.com');

        $client->request('POST', '/mon-compte', ['profile' => ['firstName' => 'Pirate', 'lastName' => 'Martin']]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('Camille', $this->findUser('client@example.com')->getFirstName());
    }

    /**
     * @param array<string, string> $values
     */
    private function submitProfile(KernelBrowser $client, array $values): void
    {
        $client->request('GET', '/mon-compte');
        $client->submitForm('Enregistrer mes informations', $values);
    }

    private function findUser(string $email): User
    {
        // Vide le cache de Doctrine pour relire les valeurs réellement enregistrées en base
        static::getContainer()->get(EntityManagerInterface::class)->clear();

        return static::getContainer()->get(UserRepository::class)->findOneBy(['email' => $email]);
    }

    private function logIn(KernelBrowser $client, string $email): void
    {
        $client->loginUser($this->findUser($email));
    }
}
