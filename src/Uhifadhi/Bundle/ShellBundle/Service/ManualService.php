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

namespace Uhifadhi\Bundle\ShellBundle\Service;

/**
 * THE INSTALLATION'S MANUAL, FOUND IN THE FOLDER IT NAMED (`shell.manual`).
 *
 * THE CONTENT IS THE INSTALLATION'S, NOT THE CORE'S. The folder is the build
 * of its own manual, committed and deployed with it; the shell only reads it.
 * A manual change is an installation commit and a deploy, never a release.
 *
 * THE PAGE AND THE PDF ARE NAMED AFTER THEIR FOLDER — `handbook/handbook.html`
 * and `handbook/handbook.pdf` — because a manual's folder also holds what
 * builds it (a template that is itself HTML), and a rule that picked "the one
 * HTML file" would pick wrongly the day the build grows a second. The PDF
 * downloads under the same name.
 *
 * A PICTURE IS A NAME, NEVER A PATH. Only a lower-case name with a picture's
 * extension and no separator is looked up, and only under `img/`, so no
 * address can reach a file outside it.
 */
final readonly class ManualService
{
    /** A picture's name: lower case, no separator, no leading dot, a picture's extension. */
    public const string PICTURE_NAME = '/^[a-z0-9][a-z0-9._-]*\.(jpg|jpeg|png|svg|webp)$/D';

    /** What each picture is sent as. */
    private const array PICTURE_TYPES = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'svg' => 'image/svg+xml',
        'webp' => 'image/webp',
    ];

    private ?string $folder;

    public function __construct(?string $folder)
    {
        $this->folder = null === $folder || '' === rtrim($folder, '/') ? null : rtrim($folder, '/');
    }

    /** The manual's page, or null when the installation keeps none or has not built it. */
    public function page(): ?string
    {
        return $this->existing('.html');
    }

    /** The manual's PDF, or null when there is none. */
    public function pdf(): ?string
    {
        return $this->existing('.pdf');
    }

    /** The name the PDF downloads under: the folder's. */
    public function downloadName(): string
    {
        return basename((string) $this->folder).'.pdf';
    }

    /** One picture under `img/`, or null for a name that is not a picture's or a file that is not there. */
    public function picture(string $name): ?string
    {
        if (null === $this->folder || !self::isPictureName($name)) {
            return null;
        }

        $path = $this->folder.'/img/'.$name;

        return is_file($path) ? $path : null;
    }

    public static function isPictureName(string $name): bool
    {
        return 1 === preg_match(self::PICTURE_NAME, $name);
    }

    /** The content type of an accepted picture name. */
    public static function pictureType(string $name): string
    {
        return self::PICTURE_TYPES[strtolower(pathinfo($name, \PATHINFO_EXTENSION))] ?? 'application/octet-stream';
    }

    private function existing(string $extension): ?string
    {
        if (null === $this->folder) {
            return null;
        }

        $path = $this->folder.'/'.basename($this->folder).$extension;

        return is_file($path) ? $path : null;
    }
}
