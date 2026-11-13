<?php

declare(strict_types=1);

namespace App\Tests\Util;

/**
 * Crée des fichiers temporaires pour tester l'envoi de photos, et les supprime à la fin du test.
 */
final class TestFiles
{
    // Image PNG valide de 1 × 1 pixel
    private const PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

    /** @var list<string> */
    private array $paths = [];

    public function png(string $name = 'photo.png'): string
    {
        return $this->create($name, (string) base64_decode(self::PNG_BASE64, true));
    }

    /**
     * Un vrai PNG (l'en-tête est valide) complété jusqu'à dépasser la limite de 2 Mo.
     */
    public function tooLargePng(): string
    {
        return $this->create('trop-lourd.png', base64_decode(self::PNG_BASE64, true).str_repeat("\0", 2_500_000));
    }

    /**
     * Un script PHP déguisé en image : seule l'extension est celle d'un PNG.
     */
    public function phpScriptNamedPng(): string
    {
        return $this->create('photo.png', "<?php echo 'piratage';\n");
    }

    public function create(string $name, string $content): string
    {
        $directory = sys_get_temp_dir().'/'.bin2hex(random_bytes(8));
        mkdir($directory);
        $path = $directory.'/'.$name;
        file_put_contents($path, $content);
        $this->paths[] = $path;

        return $path;
    }

    /**
     * Supprime les fichiers temporaires créés (s'ils n'ont pas été déplacés) et leurs dossiers.
     */
    public function cleanUp(): void
    {
        foreach ($this->paths as $path) {
            if (is_file($path)) {
                unlink($path);
            }
            if (is_dir(dirname($path))) {
                rmdir(dirname($path));
            }
        }

        $this->paths = [];
    }

    /**
     * Vide un dossier de photos créé par les tests.
     */
    public static function emptyDirectory(string $directory): void
    {
        foreach (glob($directory.'/*') ?: [] as $file) {
            unlink($file);
        }
    }
}
