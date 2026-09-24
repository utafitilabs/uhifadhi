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

namespace Uhifadhi\Bundle\ShellBundle\Tests\Integration;

use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Twig\Error\RuntimeError;
use Uhifadhi\Bundle\ShellBundle\Tests\Integration\Fixtures\HostKernel;

/**
 * Shared plumbing for the contract specification.
 *
 * Note what is NOT here: a database, a schema tool, a fixture loader. The whole
 * of this suite is "render this and look at it", which is the shape a layout
 * bundle's tests should have and the shape they only keep if the bundle never
 * acquires a domain.
 *
 * The host kernel this boots is a STAND-IN HOST: it implements the shell's
 * contracts with fixtures. That is not a shortcut — it is the specification's main
 * claim. If the shell can be driven to a full page by a host that has no areas,
 * no modules and no database, then the contracts are real contracts and not a polite
 * name for reaching into the application.
 */
abstract class ContractTestCase extends ShellKernelTestCase
{
    protected static function getKernelClass(): string
    {
        return HostKernel::class;
    }

    protected function setUp(): void
    {
        parent::setUp();

        static::resetTheHost();
    }

    protected function tearDown(): void
    {
        static::resetTheHost();

        parent::tearDown();
    }

    /**
     * THE HOST THIS CASE ACTUALLY BOOTS, reset — not HostKernel by name. A case
     * whose subject is a contract the plain stand-in host does not implement
     * boots a host that does ({@see Fixtures\NamedHostKernel}), and resetting
     * the wrong class would leave that host's statics standing between tests.
     */
    protected static function resetTheHost(): void
    {
        $kernel = static::getKernelClass();
        \assert(is_a($kernel, HostKernel::class, true));

        $kernel::reset();
    }

    /**
     * Every block a template declares, its own and every one it inherits —
     * which is the list a module author actually sees, and therefore the list
     * the contract is about.
     *
     * @return list<string>
     */
    protected function blocksOf(string $template): array
    {
        $blocks = $this->twig()->load($template)->getBlockNames();
        sort($blocks);

        return $blocks;
    }

    /**
     * @param array<string, mixed> $context
     */
    protected function render(string $template, array $context = []): string
    {
        $this->seedTheHostsRequest();

        try {
            return $this->twig()->render($template, $context);
        } catch (RuntimeError $wrapper) {
            // TWIG WRAPS EVERYTHING. Any throwable raised while a block is
            // rendering comes back as a RuntimeError carrying the real one as
            // its cause (Twig\Template::yield). The shell's refusals — two lit
            // tabs, two lit sibling rows — are raised during a render, so
            // without this a test could only assert on Twig's wrapper and the
            // contract would be pinned to the engine's error class instead of
            // to the shell's own. Re-raise the cause; leave Twig's own errors
            // (a missing template, a bad syntax) exactly as they are.
            $cause = $wrapper->getPrevious();

            throw $cause instanceof \LogicException ? $cause : $wrapper;
        }
    }

    /**
     * A REQUEST, WITH A SESSION, CARRYING WHATEVER THE HOST HAS PENDING.
     *
     * The shell reads flashes the way every Symfony application writes them —
     * off the session, through `app.flashes` — rather than through a contract of
     * its own, because a bundle that invented a second flash mechanism would be
     * a bundle every host had to feed twice. So the stand-in host is given a
     * real request with a real session, and the frame's flash region is
     * exercised through the real path rather than a double.
     *
     * Note what this does NOT do: it never invents a user. There is no security
     * bundle under this kernel and no token storage in it, so `app.user` would
     * throw if the shell touched it — which is exactly the guarantee the
     * stand-alone rule needs and the reason the shell's own furniture asks for no viewer.
     */
    private function seedTheHostsRequest(): void
    {
        $stack = self::getContainer()->get('request_stack');
        \assert($stack instanceof RequestStack);

        if (null !== $stack->getCurrentRequest()) {
            return;
        }

        $session = new Session(new MockArraySessionStorage());
        foreach (HostKernel::$flashes as [$label, $message]) {
            $session->getFlashBag()->add($label, $message);
        }

        $request = Request::create('/');
        $request->setSession($session);
        $stack->push($request);
    }

    /**
     * @param array<string, mixed> $context
     */
    protected function crawl(string $template, array $context = []): Crawler
    {
        return new Crawler($this->render($template, $context));
    }

    /**
     * The shipped stylesheet, read as text. Tokens are a contract like the
     * blocks are, and the only place they exist is this file.
     */
    protected function stylesheet(): string
    {
        $css = file_get_contents(__DIR__.'/../../public/shell.css');
        self::assertIsString($css, 'The shell ships one stylesheet, and the theme contract lives in it.');

        return $css;
    }

    protected function service(string $id): object
    {
        return self::getContainer()->get('test.'.$id);
    }
}
