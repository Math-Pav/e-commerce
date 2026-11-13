<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\PromoCode;
use App\Form\PromoCodeType;
use App\Model\PromoCodeData;
use App\Repository\PromoCodeRepository;
use App\Service\PromoCodeService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/codes-promo', name: 'admin_promo_code_')]
#[IsGranted('ROLE_ADMIN')]
final class PromoCodeController extends AbstractController
{
    private const STATUS_CSRF_TOKEN_ID = 'promo-code-status';

    public function __construct(private readonly PromoCodeService $promoCodeService)
    {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(PromoCodeRepository $promoCodeRepository): Response
    {
        return $this->render('admin/promo_code/index.html.twig', [
            'promoCodes' => $promoCodeRepository->findBy([], ['code' => 'ASC']),
        ]);
    }

    #[Route('/nouveau', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $data = new PromoCodeData();
        $form = $this->createForm(PromoCodeType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $promoCode = $this->promoCodeService->create($data);
            $this->addFlash('success', sprintf('Le code promo « %s » a été créé.', $promoCode->getCode()));

            return $this->redirectToRoute('admin_promo_code_index');
        }

        return $this->render('admin/promo_code/new.html.twig', [
            'promoCodeForm' => $form,
        ]);
    }

    #[Route('/{id}/modifier', name: 'edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(PromoCode $promoCode, Request $request): Response
    {
        $data = PromoCodeData::fromPromoCode($promoCode);
        $form = $this->createForm(PromoCodeType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->promoCodeService->update($promoCode, $data);
            $this->addFlash('success', sprintf('Le code promo « %s » a été modifié.', $promoCode->getCode()));

            return $this->redirectToRoute('admin_promo_code_index');
        }

        return $this->render('admin/promo_code/edit.html.twig', [
            'promoCode' => $promoCode,
            'promoCodeForm' => $form,
        ]);
    }

    #[Route('/{id}/activer', name: 'activate', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function activate(PromoCode $promoCode, Request $request): Response
    {
        $this->denyUnlessCsrfTokenValid($request);

        $this->promoCodeService->activate($promoCode);
        $this->addFlash('success', sprintf('Le code promo « %s » est activé.', $promoCode->getCode()));

        return $this->redirectToRoute('admin_promo_code_index');
    }

    #[Route('/{id}/desactiver', name: 'deactivate', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function deactivate(PromoCode $promoCode, Request $request): Response
    {
        $this->denyUnlessCsrfTokenValid($request);

        $this->promoCodeService->deactivate($promoCode);
        $this->addFlash('success', sprintf('Le code promo « %s » est désactivé.', $promoCode->getCode()));

        return $this->redirectToRoute('admin_promo_code_index');
    }

    private function denyUnlessCsrfTokenValid(Request $request): void
    {
        if (!$this->isCsrfTokenValid(self::STATUS_CSRF_TOKEN_ID, $request->getPayload()->getString('_token'))) {
            throw new AccessDeniedHttpException('Jeton CSRF invalide.');
        }
    }
}
