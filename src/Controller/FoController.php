<?php
// src/Controller/FoController.php

namespace App\Controller;

use App\Entity\TicketTask;
use App\Entity\TicketSite;
use App\Entity\User;
use App\Repository\TicketTaskRepository;
use App\Service\FoWorkflowService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/dashboard/fo')]
#[IsGranted('ROLE_USER')]
class FoController extends AbstractController
{
    private const SERVICE = 'FO';

    /**
     * Départements qui passent par CE dashboard, quel que soit le service
     * (FO ou FH) du site traité. support_trans et ingenierie_ip sont des
     * départements du service FO, mais reçoivent aussi des tâches FH
     * (soft upgrade -> support_trans, LLD après MLO NOK -> ingenierie_ip).
     */
    private const FO_DASHBOARD_DEPARTMENTS = ['ingenierie_ip', 'support_trans'];

    public function __construct(
        private TicketTaskRepository $taskRepo,
        private FoWorkflowService $foWorkflowService,
        private EntityManagerInterface $em,
    ) {}

    #[Route('', name: 'dashboard_fo_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $search = trim((string) $request->query->get('search', ''));
        $status = (string) $request->query->get('status', '');

        $allTasks = $this->taskRepo->findBy(['assignedTo' => $user], ['createdAt' => 'DESC']);

        $foTasks = array_values(array_filter($allTasks, fn(TicketTask $t) => $this->belongsToFoDashboard($t)));

        $tasks = $foTasks;
        if ($search !== '') {
            $needle = mb_strtolower($search);
            $tasks = array_values(array_filter($tasks, function (TicketTask $t) use ($needle) {
                $haystack = mb_strtolower($t->getTitle() . ' ' . ($t->getTicket()?->getTitle() ?? '') . ' ' . ($t->getTicket()?->getSiteName() ?? ''));
                return str_contains($haystack, $needle);
            }));
        }
        if ($status !== '') {
            $tasks = array_values(array_filter($tasks, fn(TicketTask $t) => $t->getStatus() === $status));
        }

        $total = count($foTasks);
        $pending = count(array_filter($foTasks, fn(TicketTask $t) => $t->getStatus() === TicketTask::STATUS_PENDING));
        $inProgress = count(array_filter($foTasks, fn(TicketTask $t) => $t->getStatus() === TicketTask::STATUS_IN_PROGRESS));
        $blocked = count(array_filter($foTasks, fn(TicketTask $t) => $t->getStatus() === TicketTask::STATUS_BLOCKED));
        $done = count(array_filter($foTasks, fn(TicketTask $t) => $t->isDone()));

        $now = new \DateTime();
        $overdue = 0;
        foreach ($foTasks as $t) {
            $deadline = $t->getTicket()?->getDeadline();
            if ($deadline && $deadline < $now && !$t->isDone()) {
                $overdue++;
            }
        }

        $taskSitesMap = [];
        foreach ($tasks as $task) {
            $taskSitesMap[$task->getId()] = $this->getSitesForTask($task);
        }

        foreach ($tasks as $task) {
            $this->updateTicketProgress($task);
        }

        return $this->render('dashboard/user/fo/index.html.twig', [
            'tasks' => $tasks,
            'taskSitesMap' => $taskSitesMap,
            'total' => $total,
            'pending' => $pending,
            'inProgress' => $inProgress,
            'blocked' => $blocked,
            'done' => $done,
            'overdue' => $overdue,
            'searchQuery' => $search,
            'currentStatus' => $status,
            'now' => $now,
        ]);
    }

    #[Route('/task/{id}', name: 'dashboard_fo_task_show', methods: ['GET'])]
    public function show(TicketTask $task, Request $request): Response
    {
        $this->denyAccessUnlessTaskBelongsToFo($task);

        $currentStep = $task->getStepCode();
        if ($currentStep === 'initial_analysis') {
            $task->setStepCode(TicketTask::STEP_FO_INITIAL_ANALYSIS);
            $task->setTitle('Étude initiale FO');
            $task->setDescription('Analyser la demande et décider OK / NOK.');
            $this->em->flush();
            $currentStep = TicketTask::STEP_FO_INITIAL_ANALYSIS;
        }

        $sites = $this->getSitesForTask($task);

        if (empty($sites)) {
            $this->addFlash('warning', 'Aucun site rattaché à ce ticket.');
        }

        $selectedSiteId = $request->query->get('site');
        $selectedSite = null;
        if ($selectedSiteId) {
            foreach ($sites as $s) {
                if ((string) $s->getId() === (string) $selectedSiteId) {
                    $selectedSite = $s;
                    break;
                }
            }
        }
        if (!$selectedSite && !empty($sites)) {
            $selectedSite = $sites[0];
        }

        $siteDecisions = $task->getSiteDecisions() ?? [];
        $processedCount = count(array_filter($sites, fn(TicketSite $s) => $s->getStatus() === 'completed'));
        $fhFields = $task->getFhFields() ?? [];
        $ticket = $task->getTicket();

        $previousTasks = $ticket->getTasks()->filter(function ($t) use ($task) {
            return $t->getStepOrder() < $task->getStepOrder() && $t->getStatus() === TicketTask::STATUS_DONE;
        });

        $woIpContent = $task->getWoIpContent() ?? '';

        return $this->render('dashboard/user/fo/show.html.twig', [
            'task'           => $task,
            'ticket'         => $ticket,
            'sites'          => $sites,
            'selectedSite'   => $selectedSite,
            'siteDecisions'  => $siteDecisions,
            'motifs'         => FoWorkflowService::MOTIFS,
            'totalSites'     => count($sites),
            'processedSites' => $processedCount,
            'woIpContent'    => $woIpContent,
            'fhFields'       => $fhFields,
            'previousTasks'  => $previousTasks,
        ]);
    }

    #[Route('/task/{id}/site/{siteId}/decision', name: 'dashboard_fo_site_decision', methods: ['POST'])]
    public function siteDecision(TicketTask $task, int $siteId, Request $request): Response
    {
        $this->denyAccessUnlessTaskBelongsToFo($task);

        // Les tâches FH transmises via advanceTo() (fh_execution_wo,
        // fh_lld) sont traitées par processFhTask(), pas par le flux
        // "processSiteDecision" propre à l'analyse initiale FO.
        if ($this->isFhStep($task->getStepCode())) {
            $decision = $request->request->get('decision', 'OK');
            $comment = $request->request->get('comment');
            $formData = $request->request->all();
            $formData = array_filter($formData, fn($v) => $v !== '' && $v !== null);

            $this->foWorkflowService->completeFhTaskViaFoDashboard($task, $decision, $formData, $comment, $this->getUser());

            $this->addFlash('success', 'Étape FH traitée avec succès.');
            return $this->redirectToRoute('dashboard_fo_index');
        }

        $decision = $request->request->get('decision', 'OK');
        $motif    = $request->request->get('motif') ?: null;
        $woIpRef  = $request->request->get('wo_ip_reference');
        $comment  = $request->request->get('comment');

        $woIpContent = $request->request->get('wo_ip_content');
        if ($woIpContent !== null) {
            $task->setWoIpContent($woIpContent);
        }

        if ($decision === 'NOK' && $task->getStepCode() === TicketTask::STEP_FO_INITIAL_ANALYSIS && !$motif) {
            $this->addFlash('error', 'Veuillez préciser un motif pour une décision NOK.');
            return $this->redirectToRoute('dashboard_fo_task_show', ['id' => $task->getId(), 'site' => $siteId]);
        }

        $sites = $this->getSitesForTask($task);
        $ticketSite = null;
        foreach ($sites as $s) {
            if ($s->getId() === $siteId) {
                $ticketSite = $s;
                break;
            }
        }

        if (!$ticketSite) {
            $this->addFlash('error', 'Site introuvable.');
            return $this->redirectToRoute('dashboard_fo_task_show', ['id' => $task->getId()]);
        }

        $this->foWorkflowService->processSiteDecision($task, $siteId, $decision, $motif, $this->getUser());

        $allDone = true;
        foreach ($sites as $s) {
            if ($s->getStatus() !== 'completed') {
                $allDone = false;
                break;
            }
        }

        if ($allDone) {
            $this->addFlash('success', 'Tous les sites traités.');
        } else {
            $this->addFlash('success', 'Décision enregistrée pour ce site. Il reste des sites à traiter.');
        }

        return $this->redirectToRoute('dashboard_fo_task_show', ['id' => $task->getId()]);
    }

    #[Route('/task/{id}/start', name: 'dashboard_fo_task_start', methods: ['GET'])]
    public function startTask(TicketTask $task): Response
    {
        $this->denyAccessUnlessTaskBelongsToFo($task);

        if ($task->getStatus() === TicketTask::STATUS_PENDING) {
            $task->setStatus(TicketTask::STATUS_IN_PROGRESS);
            $this->em->flush();
        }

        return $this->redirectToRoute('dashboard_fo_task_show', ['id' => $task->getId()]);
    }

    #[Route('/task/{id}/complete-swap', name: 'dashboard_fo_complete_swap', methods: ['POST'])]
    public function completeSwap(TicketTask $task, Request $request): Response
    {
        $this->denyAccessUnlessTaskBelongsToFo($task);

        $decision = $request->request->get('decision', 'OK');
        $comment = $request->request->get('comment');

        $siteData = $task->getSiteData() ?? [];

        $siteDecisions = $task->getSiteDecisions() ?? [];
        foreach ($siteData as $siteId) {
            $siteDecisions[$siteId] = array_merge($siteDecisions[$siteId] ?? [], [
                'swap_done' => ($decision === 'OK'),
                'swap_comment' => $comment,
            ]);
        }
        $task->setSiteDecisions($siteDecisions);

        $this->foWorkflowService->completeFoTask($task, $decision, 'swap_routeur', $this->getUser());

        $this->addFlash('success', 'Swap routeur validé.');

        return $this->redirectToRoute('dashboard_fo_task_show', ['id' => $task->getId()]);
    }

    /**
     * Une tâche appartient à CE dashboard si elle est traitée par un
     * département FO-natif (ingenierie_ip, support_trans) — que le site
     * d'origine soit FO ou FH. Fallback sur serviceName pour les tâches
     * legacy créées avant que departmentName soit systématiquement rempli.
     */
    private function belongsToFoDashboard(TicketTask $t): bool
    {
        $dept = $t->getDepartmentName();
        if ($dept !== null && $dept !== '') {
            return in_array($dept, self::FO_DASHBOARD_DEPARTMENTS, true);
        }

        return strtoupper($t->getServiceName() ?? '') === self::SERVICE;
    }

    private function isFhStep(?string $stepCode): bool
    {
        return in_array($stepCode, [
            TicketTask::STEP_FH_EXECUTION_WO,
            TicketTask::STEP_FH_LLD,
        ], true);
    }

    /**
     * Sites à afficher pour cette tâche. Priorité au site lié directement
     * (nouveau modèle task <-> site 1:1) — c'est le cas de toutes les
     * tâches FH transmises à ce dashboard, dont le TicketSite garde son
     * service d'origine (FH), qu'il ne faut pas filtrer par service FO.
     */
    private function getSitesForTask(TicketTask $task): array
    {
        if ($task->getTicketSite()) {
            return [$task->getTicketSite()];
        }

        $ticket = $task->getTicket();
        if (!$ticket) {
            return [];
        }

        /** @var User $user */
        $user = $this->getUser();
        $service = $user->getService();

        if ($service === 'DEPLOIEMENT') {
            return $ticket->getTicketSites()->toArray();
        }

        $department = $user->getDepartment();
        $sharedDepartments = ['deploiement_telecom', 'support_radio', 'support_backhaul'];
        if (in_array($department, $sharedDepartments, true)) {
            return $ticket->getTicketSites()->toArray();
        }

        // Legacy : tâche multi-sites sans lien direct, restreindre par le
        // périmètre de la tâche elle-même (siteData), pas par le service
        // affiché sur les TicketSite (une tâche FH transmise ici peut
        // légitimement porter sur des sites de service FH).
        $siteIds = $task->getSiteData() ?? [];
        if ($siteIds !== []) {
            return array_values(array_filter(
                $ticket->getTicketSites()->toArray(),
                fn($site) => in_array($site->getId(), $siteIds, true)
            ));
        }

        $sites = [];
        foreach ($ticket->getTicketSites() as $ticketSite) {
            if (strtoupper($ticketSite->getServiceName() ?? '') === self::SERVICE) {
                $sites[] = $ticketSite;
            }
        }
        return $sites;
    }

    private function updateTicketProgress(TicketTask $task): void
    {
        $ticket = $task->getTicket();
        if (!$ticket) {
            return;
        }

        $sites = $this->getSitesForTask($task);
        $total = count($sites);
        if ($total === 0) {
            return;
        }

        $completed = count(array_filter($sites, fn(TicketSite $s) => $s->getStatus() === 'completed'));
        $progress = (int) round(($completed / $total) * 100);
        $ticket->setProgress($progress);
        $this->em->flush();
    }

    private function denyAccessUnlessTaskBelongsToFo(TicketTask $task): void
    {
        if (!$this->belongsToFoDashboard($task)) {
            throw $this->createAccessDeniedException('Cette tâche n\'appartient pas au périmètre de ce tableau de bord.');
        }
        $user = $this->getUser();
        if ($task->getAssignedTo()?->getId() !== $user->getId()) {
            throw $this->createAccessDeniedException('Vous n\'êtes pas assigné à cette tâche.');
        }
    }
}