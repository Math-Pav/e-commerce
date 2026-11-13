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
    // Types acceptés (détectés d'après le contenu du fichier) => extension utilisée pour l'enregistrer.
    // Pas de SVG : c'est du texte qui peut contenir du JavaScript.
    private const EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    public function __construct(
        #[Autowire(param: 'app.product_images_directory')]
        private readonly string $directory,
    ) {
    }

    /**
     * Renvoie l'extension correspondant au contenu du fichier, ou null si ce n'est pas une image acceptée.
     *
     * finfo (extension PHP fileinfo) lit les premiers octets du fichier : le nom du fichier et
     * le type annoncé par le navigateur, que l'utilisateur contrôle, sont ignorés.
     */
    public static function guessExtension(string $path): ?string
    {
        $mimeType = (new \finfo(\FILEINFO_MIME_TYPE))->file($path);

        return self::EXTENSIONS[$mimeType] ?? null;
    }

    /**
     * Déplace le fichier envoyé dans le dossier des photos et renvoie le nom sous lequel il est enregistré.
     * Ce nom est aléatoire : le nom d'origine, choisi par l'utilisateur, n'est jamais utilisé.
     */
    public function store(UploadedFile $file): string
    {
        $extension = self::guessExtension($file->getPathname());

        // Le formulaire a déjà refusé ce fichier : on vérifie quand même, au cas où le service serait appelé ailleurs
        if ($extension === null) {
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
