<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Form\ProfileType;
use App\Model\ProfileData;
use App\Service\AccountService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class AccountController extends AbstractController
{
    public function __construct(
        private readonly AccountService $accountService,
    ) {
    }

    #[Route('/mon-compte', name: 'account_index', methods: ['GET', 'POST'])]
    public function index(Request $request, #[CurrentUser] User $user): Response
    {
        $profileForm = $this->createForm(ProfileType::class, ProfileData::fromUser($user));
        $profileForm->handleRequest($request);

        if ($profileForm->isSubmitted() && $profileForm->isValid()) {
            $this->accountService->updateProfile($user, $profileForm->getData());
            $this->addFlash('success', 'Vos informations ont bien été enregistrées.');

            return $this->redirectToRoute('account_index');
        }

        return $this->render('account/index.html.twig', [
            'profileForm' => $profileForm,
        ]);
    }
}
