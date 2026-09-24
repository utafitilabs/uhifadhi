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

namespace Uhifadhi\Bundle\TeamBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LazyCommand;
use Symfony\Component\Console\CommandLoader\CommandLoaderInterface;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Uhifadhi\Bundle\TeamBundle\Command\CreateUserCommand;
use Uhifadhi\Bundle\TeamBundle\Enum\TeamRoleEnum;
use Uhifadhi\Bundle\TeamBundle\Repository\UserRepository;
use Uhifadhi\Bundle\TeamBundle\Tests\Integration\TestKernel;

/**
 * THE FIRST ADMINISTRATOR — the one account an installation cannot make through
 * a screen, because every screen is behind the sign-in it does not yet have.
 *
 * The core's commands are the ones a production build runs, so this suite runs it the
 * way an operator does: through the application's own console, found by name in
 * the command loader the compiler pass built, driven by the tester the console
 * documents.
 *
 * THE KERNEL HERE CARRIES NO DEV TOOL. devkit installs through `require-dev`
 * and is absent from a production build, which is exactly the shape this kernel
 * has — so a command that is found here is a command an operator finds on the
 * server.
 *
 * WHAT IS ASSERTED IS THE ROW, not the message. The account has to be one the
 * firewall would actually accept, which means a hash the framework's own
 * verifier agrees with — so the password is checked back through
 * `security.user_password_hasher`, the service a firewall uses.
 *
 * @see https://symfony.com/doc/current/console.html#testing-commands
 */
#[CoversClass(CreateUserCommand::class)]
final class CreateUserCommandTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    /**
     * NAMED IN CODE, not by KERNEL_CLASS. One repository holds several
     * packages, so one env var could only ever name one of their kernels.
     */
    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    protected function setUp(): void
    {
        self::bootKernel();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $this->em = $em;

        $tool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
    }

    protected function tearDown(): void
    {
        $this->em->close();
        parent::tearDown();

        // The debug error handler is registered during the test and never
        // popped; PHPUnit flags that as risky. Pop whatever is left.
        while (true) {
            $previous = set_exception_handler(static fn () => null);
            restore_exception_handler();
            if (null === $previous) {
                break;
            }
            restore_exception_handler();
        }
    }

    /**
     * THE ONE COMMAND THE CORE SHIPS IS REGISTERED, in a kernel with no dev
     * tool in it. `find()` reads the console's own index of what an
     * installation can be told to run — the map the compiler pass built from
     * every `console.command` tag it collected.
     */
    public function testTheCommandIsRegisteredInAKernelWithoutADevTool(): void
    {
        $command = $this->command();

        self::assertSame('team:user:create', $command->getName());
        // Lazily, as every tagged command is: the service is only instantiated
        // when the command is run.
        self::assertInstanceOf(LazyCommand::class, $command);
        self::assertInstanceOf(CreateUserCommand::class, $command->getCommand());
    }

    /**
     * AND THESE ARE THE ONLY TWO COMMANDS THIS BUNDLE CONTRIBUTES.
     *
     * A bundle's commands are its console surface and the list is short on
     * purpose: one an installation is bootstrapped with, and one it
     * schedules because a closed period cannot be recomputed. Anything
     * that could be a screen is a screen.
     */
    public function testTheseAreTheOnlyCommandsTheBundleContributes(): void
    {
        $loader = static::getContainer()->get('console.command_loader');
        self::assertInstanceOf(CommandLoaderInterface::class, $loader);

        $mine = array_values(array_filter(
            $loader->getNames(),
            static fn (string $name): bool => str_starts_with($name, 'team:'),
        ));

        sort($mine);
        self::assertSame(['team:performance:snapshot', 'team:user:create'], $mine);
    }

    /**
     * SCRIPTED: everything on the command line, nothing asked. A provisioning
     * script gets an account and an exit code.
     */
    public function testItCreatesAnAccountWhosePasswordVerifies(): void
    {
        $tester = $this->tester();

        $exit = $tester->execute([
            'email' => 'ada@example.test',
            'first-name' => 'Ada',
            'last-name' => 'Mwangi',
            '--password' => 'a-long-enough-passphrase',
        ]);

        self::assertSame(Command::SUCCESS, $exit, $tester->getDisplay());

        $user = $this->users()->findOneByEmail('ada@example.test');
        self::assertNotNull($user);
        self::assertSame('Ada Mwangi', $user->getFullName());

        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get('test_public.hasher');
        self::assertTrue($hasher->isPasswordValid($user, 'a-long-enough-passphrase'));
        self::assertNotSame('a-long-enough-passphrase', $user->getPassword());
    }

    /**
     * SUPER ADMIN BY DEFAULT, because the account this command exists to make
     * is the one an installation is bootstrapped with — and a first
     * administrator who could not administer would leave nobody who can.
     */
    public function testTheAccountIsASuperAdminUnlessAnotherTierIsAsked(): void
    {
        $this->tester()->execute($this->tail());

        self::assertSame(TeamRoleEnum::SuperAdmin, $this->users()->findOneByEmail('ada@example.test')?->getTeamRole());
    }

    public function testAnotherTierCanBeAsked(): void
    {
        $tester = $this->tester();

        $exit = $tester->execute($this->tail(['--tier' => 'staff']));

        self::assertSame(Command::SUCCESS, $exit, $tester->getDisplay());
        self::assertSame(TeamRoleEnum::Staff, $this->users()->findOneByEmail('ada@example.test')?->getTeamRole());
    }

    /**
     * THE ACCOUNT IS READY TO SIGN IN. An invited account is unverified and
     * carries no usable credential on purpose; this one is the opposite — it is
     * the credential somebody signs in with in the installation's first minute.
     */
    public function testTheAccountIsVerifiedAndActive(): void
    {
        $this->tester()->execute($this->tail());

        $user = $this->users()->findOneByEmail('ada@example.test');
        self::assertNotNull($user);
        self::assertTrue($user->isVerified());
        self::assertTrue($user->isActive());
    }

    /** A second account on the same address is refused, and nothing is written. */
    public function testAnAddressThatIsAlreadyTakenIsRefused(): void
    {
        $this->tester()->execute($this->tail());

        $tester = $this->tester();
        $exit = $tester->execute([
            'email' => 'ada@example.test',
            'first-name' => 'Someone',
            'last-name' => 'Else',
            '--password' => 'another-long-passphrase',
        ]);

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString('ada@example.test', $tester->getDisplay());
        self::assertSame('Ada Mwangi', $this->users()->findOneByEmail('ada@example.test')?->getFullName());
    }

    public function testATierNobodyHasIsRefused(): void
    {
        $tester = $this->tester();

        $exit = $tester->execute($this->tail(['--tier' => 'emperor']));

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString('emperor', $tester->getDisplay());
        self::assertNull($this->users()->findOneByEmail('ada@example.test'));
    }

    /** A scripted run that named nobody has nothing to create, and says so. */
    public function testAScriptedTailThatNamesNobodyIsRefused(): void
    {
        $tester = $this->tester();

        $exit = $tester->execute(['email' => 'ada@example.test', '--password' => 'a-long-enough-passphrase'], ['interactive' => false]);

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString('team:user:create', $tester->getDisplay());
        self::assertNull($this->users()->findOneByEmail('ada@example.test'));
    }

    public function testAnEmptyPasswordIsRefused(): void
    {
        $tester = $this->tester();

        $exit = $tester->execute($this->tail(['--password' => '']), ['interactive' => false]);

        self::assertSame(Command::FAILURE, $exit);
        self::assertNull($this->users()->findOneByEmail('ada@example.test'));
    }

    /**
     * PIPED: a passphrase on standard input reaches no shell history and no
     * process list, and a run with nobody watching asks nothing at all.
     *
     *     printf '%s' "$PASSPHRASE" | bin/console team:user:create ada@example.test Ada Mwangi -n
     */
    public function testThePassphraseIsReadFromStandardInputWhenNothingIsInteractive(): void
    {
        $tester = $this->tester();
        $tester->setInputs(['a-piped-passphrase']);

        $exit = $tester->execute([
            'email' => 'ada@example.test',
            'first-name' => 'Ada',
            'last-name' => 'Mwangi',
        ], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $exit, $tester->getDisplay());

        $user = $this->users()->findOneByEmail('ada@example.test');
        self::assertNotNull($user);
        self::assertSame(TeamRoleEnum::SuperAdmin, $user->getTeamRole());

        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get('test_public.hasher');
        self::assertTrue($hasher->isPasswordValid($user, 'a-piped-passphrase'));
    }

    /** A standard input that offers nothing is no passphrase, and is refused. */
    public function testAnInputThatOffersNothingIsRefused(): void
    {
        $tester = $this->tester();

        $exit = $tester->execute($this->tail(withPassword: false), ['interactive' => false]);

        self::assertSame(Command::FAILURE, $exit);
        self::assertNull($this->users()->findOneByEmail('ada@example.test'));
        self::assertStringContainsString('password', strtolower($tester->getDisplay()));
    }

    /**
     * A TAIL THAT NAMED EVERYTHING IS ASKED NOTHING BUT THE PASSPHRASE, which
     * is what keeps the piped form working at a terminal too: a tier question
     * put to a pipe would be answered by the line the passphrase was on.
     */
    public function testATailThatNamedEverythingIsAskedNothingButThePassphrase(): void
    {
        $tester = $this->tester();
        $tester->setInputs(['a-piped-passphrase']);

        $exit = $tester->execute([
            'email' => 'ada@example.test',
            'first-name' => 'Ada',
            'last-name' => 'Mwangi',
        ]);

        self::assertSame(Command::SUCCESS, $exit, $tester->getDisplay());

        $user = $this->users()->findOneByEmail('ada@example.test');
        self::assertNotNull($user);
        self::assertSame(TeamRoleEnum::SuperAdmin, $user->getTeamRole(), 'The pipe\'s one line is the passphrase, not a tier.');

        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get('test_public.hasher');
        self::assertTrue($hasher->isPasswordValid($user, 'a-piped-passphrase'));
    }

    /**
     * NOTHING TYPED AT ALL, AND THE COMMAND ASKS. The person running this is at
     * the console of an installation with no account in it, and the tail they
     * are expected to have memorised is four things long. So a tail that named
     * nobody is a person, and a person is asked — the address, the two names,
     * the tier, and last the passphrase.
     */
    public function testATailThatNamesNothingIsAskedForEverything(): void
    {
        $tester = $this->tester();
        $tester->setInputs(['ada@example.test', 'Ada', 'Mwangi', 'staff', 'a-long-enough-passphrase']);

        $exit = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exit, $tester->getDisplay());

        $user = $this->users()->findOneByEmail('ada@example.test');
        self::assertNotNull($user);
        self::assertSame('Ada Mwangi', $user->getFullName());
        self::assertSame(TeamRoleEnum::Staff, $user->getTeamRole());

        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get('test_public.hasher');
        self::assertTrue($hasher->isPasswordValid($user, 'a-long-enough-passphrase'));
    }

    /** An answer that is not given is the default, and the default is Super Admin. */
    public function testAnUnansweredTierIsTheDefaultOne(): void
    {
        $tester = $this->tester();
        $tester->setInputs(['ada@example.test', 'Ada', 'Mwangi', '', 'a-long-enough-passphrase']);

        self::assertSame(Command::SUCCESS, $tester->execute([]), $tester->getDisplay());
        self::assertSame(TeamRoleEnum::SuperAdmin, $this->users()->findOneByEmail('ada@example.test')?->getTeamRole());
    }

    /** What was given is not asked for again; only what is missing is. */
    public function testOnlyWhatWasNotGivenIsAskedFor(): void
    {
        $tester = $this->tester();
        $tester->setInputs(['Ada', 'Mwangi', '', 'a-long-enough-passphrase']);

        $exit = $tester->execute(['email' => 'ada@example.test']);

        self::assertSame(Command::SUCCESS, $exit, $tester->getDisplay());
        self::assertSame('Ada Mwangi', $this->users()->findOneByEmail('ada@example.test')?->getFullName());
        self::assertStringNotContainsString('Email address', $tester->getDisplay(), 'The address was given on the command line and is not asked for again.');
        self::assertStringContainsString('First name', $tester->getDisplay());
    }

    /**
     * A TYPED TIER THAT IS NOT ONE IS ASKED AGAIN, unlike a `--tier=` that is
     * not one. A person mid-prompt has nowhere to go back to and no way to
     * correct a word except by typing another; a tail was written before the
     * command ran and can be written again.
     */
    public function testATierTypedWrongIsAskedAgain(): void
    {
        $tester = $this->tester();
        $tester->setInputs(['ada@example.test', 'Ada', 'Mwangi', 'emperor', 'staff', 'a-long-enough-passphrase']);

        $exit = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exit, $tester->getDisplay());
        self::assertSame(TeamRoleEnum::Staff, $this->users()->findOneByEmail('ada@example.test')?->getTeamRole());
    }

    /**
     * THE QUESTIONS AND THE REFUSALS ARE ON STANDARD ERROR, because neither is
     * what the command produced — a person whose output is being piped
     * somewhere still reads them, and they never land in that pipe.
     */
    public function testTheQuestionsAreAskedOnTheErrorStream(): void
    {
        $tester = $this->tester();
        $tester->setInputs(['ada@example.test', 'Ada', 'Mwangi', '', 'a-long-enough-passphrase']);

        $tester->execute([], ['capture_stderr_separately' => true]);

        self::assertStringContainsString('Email address', $tester->getErrorOutput());
        self::assertStringNotContainsString('Email address', $tester->getDisplay(), 'A question is not the command\'s result.');
    }

    /** And what it created is on standard output, where a pipeline reads it. */
    public function testWhatItCreatedIsSaidOnStandardOutput(): void
    {
        $tester = $this->tester();

        $tester->execute($this->tail(), ['capture_stderr_separately' => true]);

        self::assertStringContainsString('Ada Mwangi', $tester->getDisplay());
        self::assertStringContainsString('ada@example.test', $tester->getDisplay());
        self::assertStringNotContainsString('Ada Mwangi', $tester->getErrorOutput(), 'What it created is the command\'s result.');
    }

    /** A passphrase that was asked for is never said back. */
    public function testTheTypedPassphraseIsNeverEchoed(): void
    {
        $tester = $this->tester();
        $tester->setInputs(['ada@example.test', 'Ada', 'Mwangi', '', 'a-typed-passphrase']);

        self::assertSame(Command::SUCCESS, $tester->execute([]), $tester->getDisplay());
        self::assertStringNotContainsString('a-typed-passphrase', $tester->getDisplay());
    }

    /** A prompt answered with nothing is no passphrase, and is refused. */
    public function testAPassphraseTypedEmptyIsRefused(): void
    {
        $tester = $this->tester();
        $tester->setInputs(['ada@example.test', 'Ada', 'Mwangi', '', '']);

        $exit = $tester->execute([]);

        self::assertSame(Command::FAILURE, $exit);
        self::assertNull($this->users()->findOneByEmail('ada@example.test'));
        self::assertStringContainsString('password', strtolower($tester->getDisplay()));
    }

    /** The option is the answer, so the question is never put. */
    public function testTheOptionBypassesThePassphrasePrompt(): void
    {
        $tester = $this->tester();
        $tester->setInputs(['a-typed-passphrase']);

        $exit = $tester->execute($this->tail());

        self::assertSame(Command::SUCCESS, $exit, $tester->getDisplay());

        $user = $this->users()->findOneByEmail('ada@example.test');
        self::assertNotNull($user);

        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get('test_public.hasher');
        self::assertTrue($hasher->isPasswordValid($user, 'a-long-enough-passphrase'));
        self::assertFalse($hasher->isPasswordValid($user, 'a-typed-passphrase'));
    }

    /**
     * AN OPTION WRITTEN WITH A SPACE IS THE SAME OPTION. `--tier staff` and
     * `--tier=staff` are one input definition's two spellings, and the console
     * binds both — a person who typed the first is not told they got it wrong.
     */
    public function testAnOptionWrittenWithASpaceIsTheSameOption(): void
    {
        $application = new Application(self::$kernel ?? self::bootKernel());
        $application->setAutoExit(false);
        $tester = new ApplicationTester($application);

        $exit = $tester->run([
            'command' => 'team:user:create',
            'email' => 'ada@example.test',
            'first-name' => 'Ada',
            'last-name' => 'Mwangi',
            '--tier' => 'staff',
            '--password' => 'a-long-enough-passphrase',
        ], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $exit, $tester->getDisplay());
        self::assertSame(TeamRoleEnum::Staff, $this->users()->findOneByEmail('ada@example.test')?->getTeamRole());
    }

    /**
     * A PASSPHRASE TOO SHORT FOR AN ACCOUNT IS REFUSED BY THE SERVICE, not by a
     * second copy of the rule living in a command.
     */
    public function testAPassphraseTooShortIsRefused(): void
    {
        $tester = $this->tester();

        $exit = $tester->execute($this->tail(['--password' => 'short']));

        self::assertSame(Command::FAILURE, $exit);
        self::assertNull($this->users()->findOneByEmail('ada@example.test'));
    }

    /**
     * IT SPEAKS THROUGH THE CONSOLE AND NOWHERE ELSE. A command that wrote to
     * \STDOUT itself would have escaped the process it was given: its output
     * would ignore `--quiet`, be invisible to a caller capturing the command's
     * output, and turn up uninvited in a test run.
     */
    public function testItWritesNothingBehindTheConsolesBack(): void
    {
        $file = new \ReflectionClass(CreateUserCommand::class)->getFileName();
        self::assertIsString($file);
        $source = file_get_contents($file);
        self::assertIsString($source);

        self::assertSame(0, preg_match('/\bf(write|puts)\s*\(/', $source), 'Output goes through the console\'s output.');
        self::assertStringNotContainsString('STDOUT', $source);
    }

    /**
     * The argument tail and the option the docs promise, named exactly as the
     * README writes them.
     *
     * @param array<string, string> $extra
     *
     * @return array<string, string>
     */
    private function tail(array $extra = [], bool $withPassword = true): array
    {
        return [
            'email' => 'ada@example.test',
            'first-name' => 'Ada',
            'last-name' => 'Mwangi',
            ...$withPassword ? ['--password' => 'a-long-enough-passphrase'] : [],
            ...$extra,
        ];
    }

    private function command(): Command
    {
        $application = new Application(self::$kernel ?? self::bootKernel());
        $application->setAutoExit(false);

        return $application->find('team:user:create');
    }

    private function tester(): CommandTester
    {
        return new CommandTester($this->command());
    }

    private function users(): UserRepository
    {
        $this->em->clear();

        $repository = static::getContainer()->get('test_public.'.UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $repository);

        return $repository;
    }
}
