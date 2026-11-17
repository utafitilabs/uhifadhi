<?php

declare(strict_types=1);

/*
 * This file is part of the Uhifadhi core.
 *
 * (c) Ezekiel Mjema <https://github.com/eemjema>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Uhifadhi\Bundle\ShellBundle\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Uhifadhi\Bundle\ShellBundle\Service\ManualService;

/**
 * THE MANUAL'S FILES, FOUND IN THE FOLDER AN INSTALLATION NAMED — and only
 * those. The folder is the installation's build of its manual: a page, a PDF
 * and the pictures under `img/`. A name that is not a picture's never becomes
 * a path, so no address can reach a file outside `img/`.
 */
final class ManualServiceTest extends TestCase
{
    private string $folder;

    protected function setUp(): void
    {
        $this->folder = sys_get_temp_dir().'/uhifadhi-manual-'.bin2hex(random_bytes(6)).'/handbook';
        mkdir($this->folder.'/img', 0o777, true);
        file_put_contents($this->folder.'/handbook.html', '<!doctype html><title>Handbook</title>');
        file_put_contents($this->folder.'/handbook.pdf', '%PDF-1.4');
        // The build's own template sits beside the page in a real folder, and
        // it is not the manual.
        file_put_contents($this->folder.'/template.html', '<!doctype html><title>$title$</title>');
        file_put_contents($this->folder.'/img/01a-areas.jpg', 'jpg');
        file_put_contents($this->folder.'/img/cover.png', 'png');
        file_put_contents(\dirname($this->folder).'/secret.png', 'outside');
    }

    protected function tearDown(): void
    {
        $root = \dirname($this->folder);
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        /** @var \SplFileInfo $file */
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($root);
    }

    /** The page and the PDF are the files named after their folder. */
    public function testThePageAndThePdfAreTheFilesNamedAfterTheFolder(): void
    {
        $manual = new ManualService($this->folder);

        self::assertSame($this->folder.'/handbook.html', $manual->page());
        self::assertSame($this->folder.'/handbook.pdf', $manual->pdf());
        self::assertSame('handbook.pdf', $manual->downloadName());
    }

    public function testATrailingSlashOnTheFolderChangesNothing(): void
    {
        $manual = new ManualService($this->folder.'/');

        self::assertSame($this->folder.'/handbook.html', $manual->page());
        self::assertSame('handbook.pdf', $manual->downloadName());
    }

    /** No folder configured, or a folder not built yet: there is no manual. */
    public function testNoFolderOrAnUnbuiltOneIsNoManual(): void
    {
        foreach ([null, $this->folder.'/missing'] as $folder) {
            $manual = new ManualService($folder);

            self::assertNull($manual->page());
            self::assertNull($manual->pdf());
            self::assertNull($manual->picture('cover.png'));
        }
    }

    public function testAPdfNotBuiltYetIsNoPdfWhileThePageStillIs(): void
    {
        unlink($this->folder.'/handbook.pdf');
        $manual = new ManualService($this->folder);

        self::assertNotNull($manual->page());
        self::assertNull($manual->pdf());
    }

    public function testAPictureIsFoundUnderImg(): void
    {
        $manual = new ManualService($this->folder);

        self::assertSame($this->folder.'/img/01a-areas.jpg', $manual->picture('01a-areas.jpg'));
        self::assertSame($this->folder.'/img/cover.png', $manual->picture('cover.png'));
        self::assertNull($manual->picture('absent.png'), 'a well-formed name with no file is nothing');
    }

    /**
     * @return \Generator<string, array{string, string}>
     */
    public static function pictureNames(): \Generator
    {
        yield 'a jpg' => ['01a-areas.jpg', 'image/jpeg'];
        yield 'a jpeg' => ['photo.jpeg', 'image/jpeg'];
        yield 'a png' => ['cover.png', 'image/png'];
        yield 'an svg' => ['map.svg', 'image/svg+xml'];
        yield 'a webp' => ['shot_2.webp', 'image/webp'];
        yield 'dots inside the name' => ['v1.2.png', 'image/png'];
    }

    #[DataProvider('pictureNames')]
    public function testAPictureNameIsAcceptedWithItsType(string $name, string $type): void
    {
        self::assertTrue(ManualService::isPictureName($name));
        self::assertSame($type, ManualService::pictureType($name));
    }

    /**
     * @return \Generator<string, array{string}>
     */
    public static function refusedNames(): \Generator
    {
        yield 'a parent directory' => ['../secret.png'];
        yield 'two parents' => ['../../etc/passwd.png'];
        yield 'a subdirectory' => ['sub/cover.png'];
        yield 'a backslash' => ['..\\secret.png'];
        yield 'an absolute path' => ['/etc/cover.png'];
        yield 'a hidden file' => ['.cover.png'];
        yield 'a leading dash' => ['-cover.png'];
        yield 'upper case' => ['Cover.PNG'];
        yield 'not a picture' => ['handbook.html'];
        yield 'the pdf' => ['handbook.pdf'];
        yield 'a script' => ['cover.png.php'];
        yield 'an extension alone' => ['.png'];
        yield 'a space' => ['my cover.png'];
        yield 'an encoded slash' => ['..%2fsecret.png'];
        yield 'a null byte' => ["cover.png\0.php"];
        yield 'a newline after the extension' => ["cover.png\n"];
        yield 'nothing' => [''];
    }

    #[DataProvider('refusedNames')]
    public function testANameThatIsNotAPicturesIsRefusedAndNeverBecomesAPath(string $name): void
    {
        self::assertFalse(ManualService::isPictureName($name));
        self::assertNull(new ManualService($this->folder)->picture($name));
    }
}
