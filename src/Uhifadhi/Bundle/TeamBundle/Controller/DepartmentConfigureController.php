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

namespace Uhifadhi\Bundle\TeamBundle\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;
use Twig\Environment;
use Uhifadhi\Bundle\TeamBundle\Entity\Department;
use Uhifadhi\Bundle\TeamBundle\Enum\DepartmentScopeEnum;
use Uhifadhi\Bundle\TeamBundle\Exception\DuplicateDepartmentKindException;
use Uhifadhi\Bundle\TeamBundle\Repository\DepartmentGoalRepository;
use Uhifadhi\Bundle\TeamBundle\Repository\DepartmentKindRepository;
use Uhifadhi\Bundle\TeamBundle\Repository\DepartmentRepository;
use Uhifadhi\Bundle\TeamBundle\Service\DepartmentKindService;
use Uhifadhi\Contracts\Entity\AreaInterface;

/**
 * HOW THE DEPARTMENTS SECTION IS SET UP — its two configure screens.
 *
 * AN ORG-LEVEL SECTION'S CONFIGURE SCREENS ARE ITS OWN ROUTES. The shell's
 * configure page renders a section into an AREA's frame, and this section has
 * no area in its address; so the two screens are addresses here, declared to
 * the shell through the same sections contract, and the shell builds the strip
 * from them. Same contract, same strip, same one position on the page.
 *
 * SETTINGS IS READ-ONLY, AND THAT IS THE DESIGN. Every line on it is a fact
 * about the model or about who may do what — the two scopes, the unique-name
 * rule, which permission each act asks for. A control that let one of them be
 * changed would be a second place the rule lived; the rule is in the code, and
 * this page is where you read it without opening the code.
 *
 * LISTS IS THE ONE THAT WRITES, and it writes exactly one thing: the
 * department kind. The scopes are the model and not a list; the goal kinds
 * arrive with whichever module can measure them and leave with it.
 */
final readonly class DepartmentConfigureController
{
    public const string CSRF_ID = 'team_department_kind';

    /** The section's settings — what `Configure` opens, and the first entry in the strip. */
    public const string SETTINGS = 'team_departments_configure';

    /** The vocabulary this section writes with. */
    public const string LISTS = 'team_departments_configure_lists';

    /** The pair every configure screen of the section enforces, and every door to one asks. */
    public const string PAIR = 'departments.configure';

    public const string KIND_CREATE = 'team_department_kind_create';

    public const string KIND_RENAME = 'team_department_kind_rename';

    public function __construct(
        private Environment $twig,
        private DepartmentRepository $departments,
        private DepartmentKindRepository $kinds,
        private DepartmentGoalRepository $goals,
        private DepartmentKindService $kindWrites,
        private CsrfTokenManagerInterface $csrf,
        private UrlGeneratorInterface $router,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * THE AREAS THE CREATE FORM OFFERS, through the department's own
     * association — the ground package's entity, never named here.
     *
     * @return list<AreaInterface>
     */
    private function areas(): array
    {
        $class = $this->entityManager->getClassMetadata(Department::class)->getAssociationTargetClass('area');

        /** @var list<AreaInterface> $areas */
        $areas = $this->entityManager->getRepository($class)->findBy([], ['name' => 'ASC']);

        return $areas;
    }

    #[Route('/departments/configure', name: self::SETTINGS, defaults: DepartmentController::SURFACE, methods: ['GET'])]
    #[IsGranted('departments.configure')]
    public function settings(): Response
    {
        return new Response($this->twig->render('@Team/departments/configure.html.twig', [
            'areas' => $this->areas(),
            'csrfToken' => $this->csrf->getToken(DepartmentController::CSRF_ID)->getValue(),
            'scopes' => DepartmentScopeEnum::cases(),
            'kinds' => $this->kinds->findAllOrdered(),
            'departments' => \count($this->departments->findAllActiveOrdered()),
        ]));
    }

    /**
     * THE WORDS THIS SECTION WRITES WITH. Three lists, and only one of them is
     * a list: the kinds are edited here, the scopes are the model and say so,
     * and the goal kinds are contributed by the modules that can measure them.
     */
    #[Route('/departments/configure/lists', name: self::LISTS, defaults: DepartmentController::SURFACE, methods: ['GET'])]
    #[IsGranted('departments.configure')]
    public function lists(): Response
    {
        $departments = $this->departments->findAllActiveOrdered();

        $byScope = [DepartmentScopeEnum::Org->value => 0, DepartmentScopeEnum::Area->value => 0];
        $unkinded = 0;
        foreach ($departments as $department) {
            ++$byScope[$department->getScope()->value];
            if (null === $department->getKind()) {
                ++$unkinded;
            }
        }

        // THE GOAL KINDS ARE READ OFF THE GOALS THEMSELVES, not off a table
        // this section keeps. A goal kind arrives with the module that can
        // measure it and leaves with it, so a list stored here would outlive
        // the code that gave it meaning and go on offering a word nothing
        // answers for.
        $goalKinds = [];
        foreach ($this->goals->findAllOrdered() as $goal) {
            $ref = $goal->getKpiRef();
            if (null === $ref || '' === $ref) {
                continue;
            }

            $goalKinds[$ref] ??= ['ref' => $ref, 'unit' => $goal->getUnit(), 'declared' => 0];
            ++$goalKinds[$ref]['declared'];
        }
        ksort($goalKinds);

        return new Response($this->twig->render('@Team/departments/configure_lists.html.twig', [
            'kinds' => $this->kinds->findAllOrdered(),
            'unkinded' => $unkinded,
            'departments' => \count($departments),
            'scopes' => DepartmentScopeEnum::cases(),
            'byScope' => $byScope,
            'goalKinds' => array_values($goalKinds),
            'csrfToken' => $this->csrf->getToken(self::CSRF_ID)->getValue(),
        ]));
    }

    #[Route('/departments/configure/lists/kinds', name: self::KIND_CREATE, methods: ['POST'])]
    #[IsGranted('departments.configure')]
    public function createKind(Request $request): RedirectResponse
    {
        $this->guard($request);

        $name = trim((string) $request->request->get('name'));
        if ('' === $name) {
            return $this->back($request, 'A kind needs a name.');
        }

        try {
            $this->kindWrites->create($name, (string) $request->request->get('meaning'));
        } catch (DuplicateDepartmentKindException $clash) {
            return $this->back($request, $clash->getMessage());
        }

        return $this->back($request, \sprintf('“%s” is a department kind.', $name), 'success');
    }

    #[Route('/departments/configure/lists/kinds/{uuid}/rename', name: self::KIND_RENAME, requirements: ['uuid' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted('departments.configure')]
    public function renameKind(Request $request, string $uuid): RedirectResponse
    {
        $this->guard($request);

        $kind = $this->kinds->findOneByUuid(Uuid::fromString($uuid));
        if (null === $kind) {
            throw new NotFoundHttpException('No kind by that identifier.');
        }

        $name = trim((string) $request->request->get('name'));
        if ('' === $name) {
            return $this->back($request, 'A kind needs a name.');
        }

        try {
            $this->kindWrites->rename($kind, $name, (string) $request->request->get('meaning'));
        } catch (DuplicateDepartmentKindException $clash) {
            return $this->back($request, $clash->getMessage());
        }

        return $this->back($request, \sprintf('The kind is called “%s”.', $name), 'success');
    }

    private function guard(Request $request): void
    {
        if (!$this->csrf->isTokenValid(new CsrfToken(self::CSRF_ID, (string) $request->request->get('_token')))) {
            throw new NotFoundHttpException('That form is stale.');
        }
    }

    private function back(Request $request, string $message, string $tone = 'error'): RedirectResponse
    {
        $session = $request->hasSession() ? $request->getSession() : null;
        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add($tone, $message);
        }

        return new RedirectResponse($this->router->generate(self::LISTS));
    }
}
