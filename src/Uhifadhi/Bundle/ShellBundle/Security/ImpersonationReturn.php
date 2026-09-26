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

namespace Uhifadhi\Bundle\ShellBundle\Security;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Http\Event\SwitchUserEvent;

/**
 * EXIT TAKES YOU BACK TO WHERE YOU SWITCHED FROM.
 *
 * A Switch link names the page it sits on in `_return`. When the switch
 * happens, this remembers that page in the session and takes the parameter
 * off the request, so the landing page never shows it; the band's Exit then
 * points back at it. Exiting forgets it.
 *
 * WHY THIS WORKS WITH NO REDIRECT OF OUR OWN. With no `target_route`, the
 * firewall redirects both a switch and an exit to the request's own URL with
 * only the switch parameter removed, and it dispatches `security.switch_user`
 * BEFORE it builds that URL — so a parameter removed here is gone from the
 * redirect, and an exit link on the remembered page lands on that page.
 *
 * ONLY A PATH ON THIS SITE IS REMEMBERED: it starts with one slash, carries
 * no scheme and no host. Anything else is ignored and Exit falls back to the
 * dashboard, so the parameter can never send an administrator elsewhere.
 *
 * @see https://symfony.com/doc/current/security/impersonating_user.html — "the user is redirected to the URL specified in the impersonation_exit_path() function argument"; "Events"
 * @see vendor/symfony/security-http/Firewall/SwitchUserListener.php — authenticate(): the event is dispatched in attemptSwitchUser()/attemptExitUser(), then the redirect is built from $request->getUri() after removing only the switch parameter
 */
final readonly class ImpersonationReturn implements EventSubscriberInterface
{
    /** The query parameter a Switch link names its page in. */
    public const string PARAMETER = '_return';

    /** Where the page is kept while the session is borrowed. */
    public const string SESSION_KEY = 'shell.impersonation.return';

    /** Symfony's own words for the exit. */
    private const string EXIT = '_exit';

    public static function getSubscribedEvents(): array
    {
        // SecurityEvents::SWITCH_USER, spelt out so the shell loads without
        // the security component at all; then the event simply never fires.
        return ['security.switch_user' => 'onSwitchUser'];
    }

    public function onSwitchUser(SwitchUserEvent $event): void
    {
        $request = $event->getRequest();
        $return = $request->query->get(self::PARAMETER);
        $request->query->remove(self::PARAMETER);

        if (!$request->hasSession()) {
            return;
        }
        $session = $request->getSession();

        $exiting = self::EXIT === $request->attributes->get('_switch_user_username')
            || self::EXIT === $request->query->get('_switch_user');
        if ($exiting || !self::isLocalPath($return)) {
            $session->remove(self::SESSION_KEY);

            return;
        }

        $session->set(self::SESSION_KEY, $return);
    }

    /** A path on this site: one leading slash, no scheme, no host, no backslash trick. */
    public static function isLocalPath(mixed $path): bool
    {
        if (!\is_string($path) || '' === $path || '/' !== $path[0]) {
            return false;
        }
        if (str_starts_with($path, '//') || str_contains($path, '\\') || 1 === preg_match('/[\x00-\x1F\x7F]/', $path)) {
            return false;
        }
        $parts = parse_url($path);

        return \is_array($parts) && !isset($parts['scheme']) && !isset($parts['host']);
    }
}
