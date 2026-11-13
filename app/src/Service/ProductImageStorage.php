<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Enregistre et supprime les photos de produits sur le disque (voir ADR 0010).
 */
final class ProductImageStorage
{
    // Extensions qui peuvent être écrites dans public/uploads/products/ (JPEG, PNG, WebP ; jamais .svg ni .php)
    private const ALLOWED_EXTENSIONS = ['jpg', 'png', 'webp'];

    public function __construct(
        #[Autowire(param: 'app.product_images_directory')]
        private readonly string $directory,
    ) {
    }

    /**
     * Déplace le fichier envoyé dans le dossier des photos et renvoie le nom sous lequel il est enregistré.
     * Ce nom est aléatoire : le nom d'origine, choisi par l'utilisateur, n'est jamais utilisé.
     */
    public function store(UploadedFile $file): string
    {
        // guessExtension() lit le contenu du fichier (symfony/mime) : le nom et le type annoncés par le navigateur sont ignorés
        $extension = $file->guessExtension();

        // Le formulaire a déjà refusé ce fichier ; on vérifie quand même, car c'est ce service qui écrit dans un dossier public
        if (!in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            throw new \InvalidArgumentException('Seules les images JPEG, PNG et WebP peuvent être enregistrées.');
        }

        $filename = sprintf('%s.%s', bin2hex(random_bytes(16)), $extension);
        $file->move($this->directory, $filename);

        return $filename;
    }

    public function remove(string $filename): void
    {
        // basename() empêche de sortir du dossier avec un nom comme « ../../config/secrets »
        $path = $this->directory.'/'.basename($filename);

        if (is_file($path)) {
            unlink($path);
        }
    }
}
