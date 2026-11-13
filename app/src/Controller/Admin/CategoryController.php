<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Category;
use App\Form\CategoryType;
use App\Model\CategoryData;
use App\Repository\CategoryRepository;
use App\Service\CategoryService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/categories', name: 'admin_category_')]
#[IsGranted('ROLE_ADMIN')]
final class CategoryController extends AbstractController
{
    private const DELETE_CSRF_TOKEN_ID = 'category-delete';

    public function __construct(private readonly CategoryService $categoryService)
    {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(CategoryRepository $categoryRepository): Response
    {
        return $this->render('admin/category/index.html.twig', [
            'rows' => $categoryRepository->findAllWithProductCount(),
        ]);
    }

    #[Route('/nouvelle', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $data = new CategoryData();
        $form = $this->createForm(CategoryType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $category = $this->categoryService->create($data);
            $this->addFlash('success', sprintf('La catégorie « %s » a été créée.', $category->getName()));

            return $this->redirectToRoute('admin_category_index');
        }

        return $this->render('admin/category/new.html.twig', [
            'categoryForm' => $form,
        ]);
    }

    #[Route('/{id}/modifier', name: 'edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(Category $category, Request $request): Response
    {
        // Le formulaire modifie une copie des données : la catégorie n'est touchée qu'une fois la saisie validée
        $data = CategoryData::fromCategory($category);
        $form = $this->createForm(CategoryType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->categoryService->rename($category, $data);
            $this->addFlash('success', sprintf('La catégorie a été renommée en « %s ».', $category->getName()));

            return $this->redirectToRoute('admin_category_index');
        }

        return $this->render('admin/category/edit.html.twig', [
            'category' => $category,
            'categoryForm' => $form,
        ]);
    }

    #[Route('/{id}/supprimer', name: 'delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(Category $category, Request $request): Response
    {
        if (!$this->isCsrfTokenValid(self::DELETE_CSRF_TOKEN_ID, $request->getPayload()->getString('_token'))) {
            throw new AccessDeniedHttpException('Jeton CSRF invalide.');
        }

        $name = $category->getName();

        try {
            $this->categoryService->delete($category);
            $this->addFlash('success', sprintf('La catégorie « %s » a été supprimée.', $name));
        } catch (\DomainException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('admin_category_index');
    }
}
