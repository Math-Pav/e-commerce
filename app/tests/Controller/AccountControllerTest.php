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

    public function testChangePasswordWithWrongCurrentPasswordIsRejected(): void
    {
        $client = static::createClient();
        $this->logIn($client, 'client@example.com');
        $hashBefore = $this->findUser('client@example.com')->getPassword();

        $this->submitPasswordChange($client, 'mauvais-mot-de-passe', 'nouveauMotDePasse1', 'nouveauMotDePasse1');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Le mot de passe actuel est incorrect.');
        self::assertSame($hashBefore, $this->findUser('client@example.com')->getPassword());
    }

    public function testChangePasswordRequiresMatchingNewPasswords(): void
    {
        $client = static::createClient();
        $this->logIn($client, 'client@example.com');

        $this->submitPasswordChange($client, 'password', 'nouveauMotDePasse1', 'autreChose123');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Les deux mots de passe ne correspondent pas.');
    }

    public function testChangePasswordRequiresEightCharacters(): void
    {
        $client = static::createClient();
        $this->logIn($client, 'client@example.com');

        $this->submitPasswordChange($client, 'password', 'court', 'court');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Le mot de passe doit contenir au moins 8 caractères.');
    }

    public function testChangePasswordKeepsUserLoggedInAndAllowsLoginWithNewPassword(): void
    {
        $client = static::createClient();
        $this->logIn($client, 'client@example.com');

        $this->submitPasswordChange($client, 'password', 'nouveauMotDePasse1', 'nouveauMotDePasse1');

        self::assertResponseRedirects('/mon-compte');
        $user = $this->findUser('client@example.com');
        self::assertNotSame('nouveauMotDePasse1', $user->getPassword(), 'Le mot de passe ne doit jamais être stocké en clair.');

        // Toujours connecté : la page protégée s'affiche au lieu de rediriger vers /connexion
        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.flash--success', 'Votre mot de passe a bien été modifié.');
        self::assertSelectorTextContains('nav', 'Connecté en tant que Camille');
        $client->request('GET', '/mes-commandes');
        self::assertResponseIsSuccessful();

        // Déconnexion, puis connexion avec le nouveau mot de passe ; l'ancien ne fonctionne plus
        $client->request('GET', '/deconnexion');
        self::assertFalse($this->logInWithForm($client, 'password'), "L'ancien mot de passe ne doit plus fonctionner.");
        self::assertTrue($this->logInWithForm($client, 'nouveauMotDePasse1'));
    }

    public function testChangePasswordLogsOutOtherSessions(): void
    {
        $client = static::createClient();
        // Premier appareil : une session ouverte avant le changement de mot de passe
        $this->logIn($client, 'client@example.com');
        $otherDeviceCookie = $client->getCookieJar()->get('MOCKSESSID');
        self::assertNotNull($otherDeviceCookie);

        // Second appareil : nouvelle session, depuis laquelle le mot de passe est changé
        $client->getCookieJar()->clear();
        $this->logIn($client, 'client@example.com');
        $this->submitPasswordChange($client, 'password', 'nouveauMotDePasse1', 'nouveauMotDePasse1');
        $currentCookie = $client->getCookieJar()->get('MOCKSESSID');
        self::assertNotSame($otherDeviceCookie->getValue(), $currentCookie->getValue());

        // Retour sur le premier appareil : son jeton contient l'ancien hash, il est déconnecté
        $client->getCookieJar()->clear();
        $client->getCookieJar()->set($otherDeviceCookie);
        $client->request('GET', '/mon-compte');
        self::assertResponseRedirects('/connexion');
    }

    public function testPasswordFormDoesNotValidateProfileForm(): void
    {
        $client = static::createClient();
        $this->logIn($client, 'client@example.com');
        $this->submitProfile($client, ['profile[city]' => 'Lyon']);

        $this->submitPasswordChange($client, 'mauvais-mot-de-passe', 'nouveauMotDePasse1', 'nouveauMotDePasse1');

        self::assertResponseStatusCodeSame(422);
        // Le formulaire de profil n'a pas été envoyé : aucune erreur, et il affiche toujours les valeurs enregistrées
        self::assertSelectorCount(1, '.form ul li');
        self::assertInputValueSame('profile[firstName]', 'Camille');
        self::assertInputValueSame('profile[city]', 'Lyon');
    }

    public function testProfileFormDoesNotValidatePasswordForm(): void
    {
        $client = static::createClient();
        $this->logIn($client, 'client@example.com');

        // Le formulaire du mot de passe est vide : s'il était validé, il afficherait « Veuillez saisir… »
        $this->submitProfile($client, ['profile[postalCode]' => 'abc']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorCount(1, '.form ul li');
        self::assertSelectorTextNotContains('body', 'Veuillez saisir votre mot de passe actuel.');
        self::assertInputValueSame('profile[postalCode]', 'abc');
    }

    public function testFormsHaveDistinctNamesAndCsrfTokens(): void
    {
        $client = static::createClient();
        $this->logIn($client, 'client@example.com');

        $crawler = $client->request('GET', '/mon-compte');

        self::assertSelectorExists('form[name="profile"]');
        self::assertSelectorExists('form[name="change_password"]');
        $profileToken = $crawler->filter('input[name="profile[_token]"]')->attr('value');
        $passwordToken = $crawler->filter('input[name="change_password[_token]"]')->attr('value');
        self::assertNotEmpty($profileToken);
        self::assertNotSame($profileToken, $passwordToken);
    }

    /**
     * @param array<string, string> $values
     */
    private function submitProfile(KernelBrowser $client, array $values): void
    {
        $client->request('GET', '/mon-compte');
        $client->submitForm('Enregistrer mes informations', $values);
    }

    private function submitPasswordChange(KernelBrowser $client, string $current, string $new, string $confirmation): void
    {
        $client->request('GET', '/mon-compte');
        $client->submitForm('Changer mon mot de passe', [
            'change_password[currentPassword]' => $current,
            'change_password[newPassword][first]' => $new,
            'change_password[newPassword][second]' => $confirmation,
        ]);
    }

    /**
     * Se connecte avec le formulaire de connexion et indique si la connexion a réussi.
     */
    private function logInWithForm(KernelBrowser $client, string $password): bool
    {
        $client->request('GET', '/connexion');
        $client->submitForm('Se connecter', [
            '_username' => 'client@example.com',
            '_password' => $password,
        ]);

        // En cas d'échec, Symfony renvoie vers le formulaire de connexion (adresse absolue : http://localhost/connexion)
        return !str_ends_with((string) $client->getResponse()->headers->get('Location'), '/connexion');
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
