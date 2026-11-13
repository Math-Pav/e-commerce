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
        $email = 'lea.'.uniqid().'@example.com';

        $this->submitRegistration($client, $email, 'motdepasse123', 'motdepasse123');

        self::assertResponseRedirects('/produits');
        $client->followRedirect();
        self::assertSelectorTextContains('nav', 'Connecté en tant que Léa');
        self::assertSelectorTextContains('body', 'Bienvenue Léa');

        $user = static::getContainer()->get(UserRepository::class)->findOneBy(['email' => $email]);
        self::assertNotNull($user);
        self::assertNotSame('motdepasse123', $user->getPassword(), 'Le mot de passe ne doit jamais être stocké en clair.');
    }

    public function testRegistrationSendsWelcomeEmail(): void
    {
        $client = static::createClient();
        $email = 'lea.'.uniqid().'@example.com';

        $this->submitRegistration($client, $email, 'motdepasse123', 'motdepasse123');

        // Vérification avant followRedirect() : chaque nouvelle requête vide la liste des e-mails collectés
        self::assertEmailCount(1);
        $message = self::getMailerMessage();
        self::assertNotNull($message);
        self::assertEmailAddressContains($message, 'To', $email);
        self::assertEmailAddressContains($message, 'From', 'boutique@atelier-papier.test');
        self::assertEmailSubjectContains($message, 'Bienvenue chez Atelier Papier');
        self::assertEmailHtmlBodyContains($message, 'Bienvenue Léa');
        self::assertEmailTextBodyContains($message, $email);
        // Lien absolu : il doit fonctionner depuis la messagerie, hors du site
        self::assertEmailHtmlBodyContains($message, 'href="http://localhost/produits"');
    }

    public function testRegistrationWithExistingEmailFails(): void
    {
        $client = static::createClient();

        $this->submitRegistration($client, 'client@example.com', 'motdepasse123', 'motdepasse123');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Un compte existe déjà avec cet e-mail.');
        self::assertEmailCount(0);
    }

    public function testRegistrationWithDifferentPasswordsFails(): void
    {
        $client = static::createClient();

        $this->submitRegistration($client, 'lea.'.uniqid().'@example.com', 'motdepasse123', 'autrechose456');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Les deux mots de passe ne correspondent pas.');
    }

    public function testDefaultConstraintMessageIsInFrench(): void
    {
        $client = static::createClient();

        // Length(max: 50) sur le prénom n'a pas de message personnalisé : c'est le message par défaut de Symfony
        $this->submitRegistration($client, 'lea.'.uniqid().'@example.com', 'motdepasse123', 'motdepasse123', str_repeat('a', 51));

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Cette chaîne est trop longue. Elle doit contenir au maximum 50 caractères.');
        self::assertSelectorTextNotContains('body', 'This value is too long');
    }

    public function testPageLanguageIsFrench(): void
    {
        $client = static::createClient();
        $client->request('GET', '/inscription');

        self::assertSelectorExists('html[lang="fr"]');
    }

    private function submitRegistration(
        KernelBrowser $client,
        string $email,
        string $password,
        string $confirmation,
        string $firstName = 'Léa',
    ): void {
        $client->request('GET', '/inscription');

        $client->submitForm('Créer mon compte', [
            'registration_form[firstName]' => $firstName,
            'registration_form[lastName]' => 'Bernard',
            'registration_form[email]' => $email,
            'registration_form[plainPassword][first]' => $password,
            'registration_form[plainPassword][second]' => $confirmation,
        ]);
    }
}
