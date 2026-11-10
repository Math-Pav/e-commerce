<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class RegistrationControllerTest extends WebTestCase
{
    public function testRegistrationCreatesAccountAndLogsIn(): void
    {
        $client = static::createClient();
        // E-mail unique à chaque exécution, pour ne pas entrer en conflit avec les exécutions précédentes
        $email = 'lea.' . uniqid() . '@example.com';

        $this->submitRegistration($client, $email, 'motdepasse123', 'motdepasse123');

        self::assertResponseRedirects('/produits');
        $client->followRedirect();
        self::assertSelectorTextContains('nav', 'Connecté en tant que Léa');
        self::assertSelectorTextContains('body', 'Bienvenue Léa');

        $user = static::getContainer()->get(UserRepository::class)->findOneBy(['email' => $email]);
        self::assertNotNull($user);
        self::assertNotSame('motdepasse123', $user->getPassword(), 'Le mot de passe ne doit jamais être stocké en clair.');
    }

    public function testRegistrationWithExistingEmailFails(): void
    {
        $client = static::createClient();

        $this->submitRegistration($client, 'client@example.com', 'motdepasse123', 'motdepasse123');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Un compte existe déjà avec cet e-mail.');
    }

    public function testRegistrationWithDifferentPasswordsFails(): void
    {
        $client = static::createClient();

        $this->submitRegistration($client, 'lea.' . uniqid() . '@example.com', 'motdepasse123', 'autrechose456');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Les deux mots de passe ne correspondent pas.');
    }

    private function submitRegistration(KernelBrowser $client, string $email, string $password, string $confirmation): void
    {
        $client->request('GET', '/inscription');

        $client->submitForm('Créer mon compte', [
            'registration_form[firstName]' => 'Léa',
            'registration_form[lastName]' => 'Bernard',
            'registration_form[email]' => $email,
            'registration_form[plainPassword][first]' => $password,
            'registration_form[plainPassword][second]' => $confirmation,
        ]);
    }
}