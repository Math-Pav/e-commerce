<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\ProductImageStorage;
use App\Tests\Util\TestFiles;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class ProductImageStorageTest extends TestCase
{
    private string $directory;

    private TestFiles $files;

    private ProductImageStorage $storage;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/product-images-'.bin2hex(random_bytes(8));
        $this->files = new TestFiles();
        $this->storage = new ProductImageStorage($this->directory);
    }

    protected function tearDown(): void
    {
        $this->files->cleanUp();
        TestFiles::emptyDirectory($this->directory);
        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }

    /**
     * Début d'un fichier de chaque format : c'est ce que fileinfo lit pour reconnaître le type.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function imageProvider(): iterable
    {
        yield 'JPEG' => ["\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00", 'jpg'];
        yield 'PNG' => [(string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=', true), 'png'];
        yield 'WebP' => ["RIFF\x1A\x00\x00\x00WEBPVP8L\x0D\x00\x00\x00\x2F\x00\x00\x00\x10\x07\x10\x11\x11\x88\x88\xFE\x07\x00", 'webp'];
    }

    #[DataProvider('imageProvider')]
    public function testStoreGivesRandomNameWithExtensionFromContent(string $content, string $expectedExtension): void
    {
        // Le nom d'origine (et son extension trompeuse) ne doit pas être réutilisé
        $file = $this->upload($this->files->create('Mon image.gif', $content));

        $filename = $this->storage->store($file);

        self::assertMatchesRegularExpression('/^[0-9a-f]{32}\.'.$expectedExtension.'$/', $filename);
        self::assertFileExists($this->directory.'/'.$filename);
    }

    public function testStoreGivesDifferentNamesForTheSameFile(): void
    {
        $first = $this->storage->store($this->upload($this->files->png()));
        $second = $this->storage->store($this->upload($this->files->png()));

        self::assertNotSame($first, $second);
    }

    public function testStoreRejectsFileThatIsNotAnAllowedImage(): void
    {
        $file = $this->upload($this->files->phpScriptNamedPng());

        $this->expectException(\InvalidArgumentException::class);

        $this->storage->store($file);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refusedContentProvider(): iterable
    {
        yield 'SVG (peut contenir du JavaScript)' => ['<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'];
        yield 'GIF' => ["GIF89a\x01\x00\x01\x00\x00\x00\x00;"];
        yield 'script PHP' => ["<?php echo 'piratage';\n"];
        yield 'texte' => ['Bonjour'];
    }

    #[DataProvider('refusedContentProvider')]
    public function testGuessExtensionRefusesOtherContent(string $content): void
    {
        self::assertNull(ProductImageStorage::guessExtension($this->files->create('image.png', $content)));
    }

    public function testRemoveDeletesFile(): void
    {
        $filename = $this->storage->store($this->upload($this->files->png()));

        $this->storage->remove($filename);

        self::assertFileDoesNotExist($this->directory.'/'.$filename);
    }

    public function testRemoveIgnoresMissingFile(): void
    {
        $this->storage->remove('absent.png');

        $this->expectNotToPerformAssertions();
    }

    public function testRemoveCannotLeaveTheDirectory(): void
    {
        $outside = $this->files->create('secret.txt', 'secret');

        $this->storage->remove('../'.basename(dirname($outside)).'/secret.txt');

        self::assertFileExists($outside);
    }

    private function upload(string $path): UploadedFile
    {
        // test: true, car le fichier n'a pas été reçu par un vrai envoi HTTP
        return new UploadedFile($path, basename($path), null, null, true);
    }
}
