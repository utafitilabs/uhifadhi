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

namespace Uhifadhi\Bundle\TeamBundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\StreamableInputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;
use Uhifadhi\Bundle\TeamBundle\Enum\TeamRoleEnum;
use Uhifadhi\Bundle\TeamBundle\Exception\EmailAlreadyUsedException;
use Uhifadhi\Bundle\TeamBundle\Exception\PasswordTooShortException;
use Uhifadhi\Bundle\TeamBundle\Service\UserService;

/**
 * THE FIRST ADMINISTRATOR — the one account an installation cannot make through
 * a screen, because every screen is behind the sign-in it does not yet have.
 *
 * THE CORE'S COMMANDS ARE THE ONES A PRODUCTION BUILD MUST RUN, AND THIS IS
 * ONE OF THEM. Every other command the platform has belongs to devkit, which
 * installs through `require-dev` and is therefore absent from a production
 * build. That arrangement cannot hold this one: a production installation is
 * built WITHOUT development packages, and the account an operator has to make
 * is needed exactly there — on the server, once, after the deploy.
 *
 *     docker exec <web> php bin/console team:user:create
 *
 * NO SETUP SCREEN. A page that creates the first administrator is a page that
 * must be reachable by a stranger, and one that has to be closed afterwards by
 * something remembering that it was used. A command is reachable by whoever can
 * already reach a shell on the server, which is the authority the account is
 * being granted anyway.
 *
 *     team:user:create [<email> <first name> <last name>] [--tier=…] [--password=…]
 *
 * IT ASKS FOR WHAT IT WAS NOT TOLD. The person running this is at the console
 * of an installation with no account in it, and the tail above is four things
 * long, so anything missing from it is asked for in the order it is written:
 * the address, the two names, the tier, and last the passphrase.
 *
 *     $ bin/console team:user:create
 *      Email address:
 *      > ada@example.test
 *      First name:
 *      > Ada
 *      Last name:
 *      > Mwangi
 *      Tier [super-admin]:
 *       [0] super-admin
 *       [1] admin
 *       [2] staff
 *      >
 *      Passphrase (not shown):
 *      >
 *
 *      [OK] Created Ada Mwangi <ada@example.test> as Super Admin.
 *
 * A TAIL THAT NAMED EVERYTHING IS ASKED NOTHING BUT THE PASSPHRASE, and that
 * rule is what keeps the piped form working even at a terminal: a tier question
 * put to a pipe would be answered by the line the passphrase was on.
 *
 *     printf '%s' "$PASSPHRASE" | bin/console team:user:create ada@example.test Ada Mwangi
 *
 * THE PASSPHRASE IS NEVER ECHOED AND NEVER ASKED FOR TWICE. It is read through
 * a hidden {@see Question} where there is somebody to ask, and off standard
 * input where there is not — the shape Symfony's own password command has, down
 * to the stream it reads.
 *
 * @see vendor/symfony/password-hasher/Command/UserPasswordHashCommand.php
 *
 * THE TIER DEFAULTS TO SUPER ADMIN, because the account this exists to make is
 * the one an installation is bootstrapped with, and a first administrator who
 * could not administer would leave nobody who can.
 *
 * IT WRITES NOTHING ITSELF. Making an account is one set of rules, held by
 * {@see UserService} and called by every screen that adds somebody; a command
 * with its own hasher and its own entity manager would be a second copy of them,
 * drifting from the first the moment either changed.
 */
#[AsCommand(
    name: 'team:user:create',
    description: 'Create an account and set its password — the administrator an installation is bootstrapped with',
)]
final class CreateUserCommand extends Command
{
    /** What a person may type after `--tier=` or at the tier prompt, and the tier each names. */
    private const array TIERS = [
        'super-admin' => TeamRoleEnum::SuperAdmin,
        'admin' => TeamRoleEnum::Admin,
        'staff' => TeamRoleEnum::Staff,
    ];

    /** The three positional arguments, in the order they are written and asked for. */
    private const array NAMES = [
        'email' => 'Email address',
        'first-name' => 'First name',
        'last-name' => 'Last name',
    ];

    public function __construct(private readonly UserService $accounts)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::OPTIONAL, 'The address the account signs in with')
            ->addArgument('first-name', InputArgument::OPTIONAL, 'The person\'s first name')
            ->addArgument('last-name', InputArgument::OPTIONAL, 'The person\'s last name')
            ->addOption('tier', null, InputOption::VALUE_REQUIRED, 'One of: '.implode(', ', array_keys(self::TIERS)))
            ->addOption('password', null, InputOption::VALUE_REQUIRED, 'The passphrase. Left out, it is asked for without being echoed, or read from standard input')
            ->setHelp(<<<'HELP'
                Create an account and set its password — the one account an installation cannot
                make through a screen, because every screen is behind the sign-in it does not
                have yet. Run it once on the server after a deploy:

                  <info>docker exec CONTAINER php bin/console %command.name%</info>

                Anything not given is asked for, and the passphrase is never echoed:

                  <info>bin/console %command.name%</info>

                Give all three names and nothing but the passphrase is asked, so it can be piped
                in rather than left in a shell history or a process list:

                  <info>printf '%s' "$PASSPHRASE" | bin/console %command.name% ada@example.test Ada Mwangi</info>

                The account is a verified, active <comment>super-admin</comment> unless <comment>--tier</comment> says otherwise.
                HELP)
        ;
    }

    /**
     * WHAT WAS NOT TYPED IS ASKED FOR, and the console decides whether there is
     * anybody to ask: this method is not called at all under `--no-interaction`.
     *
     * A TAIL CARRYING ALL THREE NAMES IS LEFT ALONE, tier included. It was
     * written by something rather than typed by somebody, and the passphrase it
     * is followed by may be arriving on standard input — where a tier question
     * would consume it.
     *
     * @see https://symfony.com/doc/current/console.html — interact() is "the last place where you can ask for missing required options/arguments"
     */
    protected function interact(InputInterface $input, OutputInterface $output): void
    {
        if ($this->named($input)) {
            return;
        }

        $io = self::diagnostics($input, $output);

        foreach (self::NAMES as $argument => $label) {
            if (null !== $input->getArgument($argument)) {
                continue;
            }

            // A BLANK ANSWER IS ASKED AGAIN, which is what a validator does: an
            // account with no address and no name is not a thing to create, and
            // somebody who pressed return too early has nowhere else to go.
            $question = new Question($label);
            $question->setValidator(static function (mixed $answer) use ($label): string {
                if (!\is_string($answer) || '' === trim($answer)) {
                    throw new \InvalidArgumentException($label.' is required.');
                }

                return trim($answer);
            });

            $input->setArgument($argument, $io->askQuestion($question));
        }

        if (null === $input->getOption('tier')) {
            // A WORD THAT IS NOT A TIER IS ASKED AGAIN rather than refused,
            // because the alternative is telling somebody who typed one letter
            // wrong to start the whole command over. ChoiceQuestion is what
            // does the asking again, and an empty answer is the default.
            $input->setOption('tier', $io->askQuestion(new ChoiceQuestion('Tier', array_keys(self::TIERS), 'super-admin')));
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $errors = self::diagnostics($input, $output);

        $names = [];
        foreach (self::NAMES as $argument => $label) {
            $given = $input->getArgument($argument);
            if (!\is_string($given) || '' === trim($given)) {
                $errors->error(\sprintf('%s: nothing was given. Write it on the command line: team:user:create <email> <first name> <last name>.', $label));

                return self::FAILURE;
            }

            $names[] = trim($given);
        }

        [$email, $firstName, $lastName] = $names;

        // A TAIL IS REFUSED RATHER THAN ASKED AGAIN. It was written before the
        // command ran, so it can be written again with the word corrected; a
        // person mid-prompt has no such second chance.
        $asked = $input->getOption('tier');
        $tier = null === $asked ? TeamRoleEnum::SuperAdmin : null;
        if (\is_string($asked)) {
            $tier = self::TIERS[$asked] ?? null;
        }

        if (null === $tier) {
            $errors->error(\sprintf('Unknown tier "%s". Use one of: %s.', \is_string($asked) ? $asked : '', implode(', ', array_keys(self::TIERS))));

            return self::FAILURE;
        }

        $password = $this->readPassword($input, $errors);
        if ('' === trim($password)) {
            $errors->error('A password is required. Type it at the prompt, pass --password=…, or write it on standard input.');

            return self::FAILURE;
        }

        // VERIFIED AND ACTIVE, unlike an invited account: this is the credential
        // somebody signs in with in the installation's first minute, not an
        // invitation waiting to be accepted. That is what create() means, so the
        // tier is the only thing this has to say.
        try {
            $user = $this->accounts->create($email, $firstName, $lastName, $password, $tier);
        } catch (EmailAlreadyUsedException $taken) {
            $errors->error(\sprintf('An account already answers to "%s". Accounts are never duplicated; reset that one\'s password instead.', $taken->email));

            return self::FAILURE;
        } catch (PasswordTooShortException $short) {
            $errors->error($short->getMessage());

            return self::FAILURE;
        }

        $io->success(\sprintf('Created %s <%s> as %s.', $user->getFullName(), $user->getUserIdentifier(), $tier->label()));

        return self::SUCCESS;
    }

    /**
     * The passphrase: the option where it was given, a hidden prompt where
     * there is somebody to ask, and one line of standard input where there is
     * not — so it never has to appear in a shell history or a process list.
     *
     * READING STANDARD INPUT IS NOT A SECOND CHANNEL. It is the same stream the
     * question would have been answered on, read directly because under
     * `--no-interaction` there is no question; the stream comes off the input
     * the command was given rather than off \STDIN blindly, so a caller that
     * wired the console somewhere else is still read.
     *
     * @see vendor/symfony/password-hasher/Command/UserPasswordHashCommand.php — the same two reads, the same stream
     */
    private function readPassword(InputInterface $input, SymfonyStyle $errors): string
    {
        $given = $input->getOption('password');
        if (\is_string($given)) {
            return $given;
        }

        if (!$input->isInteractive()) {
            $stream = $input instanceof StreamableInputInterface ? $input->getStream() : null;

            return rtrim((string) fgets($stream ?? \STDIN), "\r\n");
        }

        // HIDDEN, so nothing appears as it is typed and nothing is left in the
        // scrollback. Where the response cannot be hidden the console says so
        // and reads the line anyway — its own fallback rule — because a first
        // administrator who cannot be created is worse than one created in view
        // of the person creating it. The style writes the colon and the prompt
        // arrow itself, so the question is the words and nothing else.
        $question = new Question('Passphrase (not shown)');
        $question->setHidden(true);

        $typed = $errors->askQuestion($question);

        return \is_string($typed) ? $typed : '';
    }

    /**
     * Whether the tail named everybody it can: three arguments, nothing left to
     * ask about the person.
     */
    private function named(InputInterface $input): bool
    {
        foreach (array_keys(self::NAMES) as $argument) {
            if (null === $input->getArgument($argument)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The STANDARD ERROR style: questions and refusals go here, because neither
     * is what the command produced — a person whose output is being piped
     * somewhere still reads them, and they never land in that pipe.
     */
    private static function diagnostics(InputInterface $input, OutputInterface $output): SymfonyStyle
    {
        return new SymfonyStyle($input, $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output);
    }
}
