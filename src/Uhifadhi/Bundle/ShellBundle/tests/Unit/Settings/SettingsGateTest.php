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

namespace Uhifadhi\Bundle\ShellBundle\Tests\Unit\Settings;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Uhifadhi\Bundle\ShellBundle\Access\ShellConcerns;
use Uhifadhi\Bundle\ShellBundle\Controller\SettingsController;
use Uhifadhi\Bundle\ShellBundle\Service\SettingsNavigation;
use Uhifadhi\Bundle\ShellBundle\Service\SettingsReading;
use Uhifadhi\Bundle\ShellBundle\Service\SettingsSection;
use Uhifadhi\Contracts\Access\ScopeKind;
use Uhifadhi\Contracts\Access\Verb;

/**
 * THE SETTINGS SECTION ASKS FOR ITS OWN PAIR, in its source and at its
 * address, and asks nothing where there is nobody to ask.
 */
#[CoversClass(SettingsNavigation::class)]
#[CoversClass(SettingsController::class)]
#[CoversClass(ShellConcerns::class)]
final class SettingsGateTest extends TestCase
{
    public function testWithoutThePairTheSourceYieldsNoRow(): void
    {
        self::assertSame([], $this->rows(self::checker(false), signedIn: true));
    }

    public function testWithThePairTheSourceYieldsTheRow(): void
    {
        self::assertCount(1, $this->rows(self::checker(true), signedIn: true));
    }

    /** NOBODY IDENTIFIED HOLDS NOTHING, and the checker is never asked. */
    public function testWithNobodySignedInTheSourceYieldsNoRowAndAsksNothing(): void
    {
        self::assertSame([], $this->rows(self::checker(true, asked: false), signedIn: false));
    }

    /** A KERNEL WITH NO SECURITY HAS NOBODY TO ASK, and keeps the row. */
    public function testWithNoSecurityAtAllTheRowStays(): void
    {
        $navigation = new SettingsNavigation($this->section(), new RequestStack());

        self::assertCount(1, iterator_to_array($navigation->sections(), false));
    }

    public function testWithoutThePairTheAddressIsRefused(): void
    {
        $this->expectException(AccessDeniedException::class);

        (new SettingsController($this->twig(), $this->section(), self::checker(false)))();
    }

    public function testWithThePairTheAddressDrawsTheScreen(): void
    {
        $response = (new SettingsController($this->twig(), $this->section(), self::checker(true)))();

        self::assertSame(200, $response->getStatusCode());
    }

    public function testThePairIsDeclaredOnceReadOnlyAndOrganizationWide(): void
    {
        $concerns = iterator_to_array((new ShellConcerns())->concerns(), false);

        self::assertCount(1, $concerns);
        self::assertSame(ShellConcerns::SETTINGS, $concerns[0]->key());
        self::assertSame('settings.read', ShellConcerns::SETTINGS_READ);
        self::assertSame([Verb::Read], $concerns[0]->verbs());
        self::assertSame([ScopeKind::Organization], $concerns[0]->scopeKinds());
    }

    /**
     * @return list<mixed>
     */
    private function rows(AuthorizationCheckerInterface $checker, bool $signedIn): array
    {
        $tokens = new TokenStorage();
        if ($signedIn) {
            $tokens->setToken(new NullToken());
        }

        return iterator_to_array((new SettingsNavigation($this->section(), new RequestStack(), $checker, $tokens))->sections(), false);
    }

    private function section(): SettingsSection
    {
        $urls = $this->createStub(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturnCallback(static fn (string $route, array $parameters = []): string => '/settings'.(isset($parameters['tab']) && \is_string($parameters['tab']) ? '/'.$parameters['tab'] : ''));
        $urls->method('getContext')->willReturn(new RequestContext());

        return new SettingsSection($urls, (new \ReflectionClass(SettingsReading::class))->newInstanceWithoutConstructor());
    }

    private function twig(): Environment
    {
        $templates = [];
        foreach (\Uhifadhi\Contracts\Settings\SettingsTab::cases() as $tab) {
            $templates['@Shell/settings/'.$tab->value.'.html.twig'] = 'screen';
        }

        return new Environment(new ArrayLoader($templates));
    }

    private static function checker(bool $grants, bool $asked = true): AuthorizationCheckerInterface
    {
        return new readonly class($grants, $asked) implements AuthorizationCheckerInterface {
            public function __construct(private bool $grants, private bool $asked)
            {
            }

            public function isGranted(mixed $attribute, mixed $subject = null, ?\Symfony\Component\Security\Core\Authorization\AccessDecision $accessDecision = null): bool
            {
                TestCase::assertTrue($this->asked, 'The checker was asked with nobody signed in.');
                TestCase::assertSame(ShellConcerns::SETTINGS_READ, $attribute);

                return $this->grants;
            }
        };
    }
}
