<?php
// src/Controller/UserFhController.php

namespace App\Controller;

use App\Entity\Ticket;
use App\Entity\TicketTask;
use App\Entity\User;
use App\Repository\TicketTaskRepository;
use App\Repository\ProcessedSiteRepository;
use App\Service\TicketWorkflowService;
use App\Service\FhWorkflowService;
use App\Service\FoWorkflowService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/user/fh')]
class UserFhController extends AbstractController
{
    private const SERVICE = 'FH';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private ProcessedSiteRepository $processedSiteRepo,
        private FhWorkflowService $fhWorkflowService,
        private TicketWorkflowService $ticketWorkflowService,
        private FoWorkflowService $foWorkflowService, // AJOUT
    ) {}

    #[Route('/tasks', name: 'user_fh_tasks')]
    public function index(Request $request, TicketTaskRepository $taskRepo): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        $user = $this->getUser();

        $search = $request->query->get('search', '');
        $status = $request->query->get('status', '');

        $qb = $taskRepo->createQueryBuilder('t')
            ->join('t.ticket', 'ticket')
            ->where('t.assignedTo = :user')
            ->setParameter('user', $user);

        if ($search) {
            $qb->andWhere('LOWER(t.title) LIKE :search OR LOWER(ticket.title) LIKE :search OR LOWER(ticket.reference) LIKE :search')
               ->setParameter('search', '%' . mb_strtolower($search) . '%');
        }

        if ($status) {
            $qb->andWhere('t.status = :status')
               ->setParameter('status', $status);
        }

        /** @var TicketTask[] $tasks */
        $tasks = $qb->orderBy('t.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        // Construction de la map site par tâche
        $taskSitesMap = [];
        foreach ($tasks as $task) {
            $taskSitesMap[$task->getId()] = $this->getSitesForTask($task);
        }

        $total = count($tasks);
        $pending = 0;
        $inProgress = 0;
        $blocked = 0;
        $overdue = 0;

        foreach ($tasks as $task) {
            if ($task->getStatus() === 'pending') $pending++;
            elseif ($task->getStatus() === 'in_progress') $inProgress++;
            elseif ($task->getStatus() === 'blocked') $blocked++;

            $ticket = $task->getTicket();
            if ($ticket->getDeadline() && $ticket->getDeadline() < new \DateTime() && !in_array($task->getStatus(), ['completed', 'closed'])) {
                $overdue++;
            }
        }

        return $this->render('dashboard/user/fh/index.html.twig', [
            'tasks' => $tasks,
            'user' => $user,
            'total' => $total,
            'pending' => $pending,
            'inProgress' => $inProgress,
            'blocked' => $blocked,
            'overdue' => $overdue,
            'searchQuery' => $search,
            'currentStatus' => $status,
            'taskSitesMap' => $taskSitesMap,
        ]);
    }

    #[Route('/task/{id}', name: 'user_fh_task_show')]
    public function show(TicketTask $task, Request $request): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        if ($task->getAssignedTo() !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }

        $user = $this->getUser();
        $ticket = $task->getTicket();
        $allTicketSites = $this->getSitesForTask($task);

        if (empty($allTicketSites)) {
            $this->addFlash('warning', 'Aucun site rattaché à ce ticket.');
            return $this->redirectToRoute('user_fh_tasks');
        }

        $sites = $allTicketSites;
        $totalSites = count($sites);
        $siteDecisions = $task->getSiteDecisions() ?? [];
        $processedSiteIds = array_keys($siteDecisions);
        $processedCount = count(array_intersect($processedSiteIds, array_map(fn($s) => $s->getId(), $sites)));

        $selectedSiteId = $request->query->get('site');
        if (!$selectedSiteId) {
            foreach ($sites as $site) {
                if (!in_array($site->getId(), $processedSiteIds)) {
                    $selectedSiteId = $site->getId();
                    break;
                }
            }
            if (!$selectedSiteId && !empty($sites)) {
                $selectedSiteId = $sites[0]->getId();
            }
        }

        $selectedSite = null;
        foreach ($sites as $site) {
            if ((string)$site->getId() === (string)$selectedSiteId) {
                $selectedSite = $site;
                break;
            }
        }
        if (!$selectedSite && !empty($sites)) {
            $selectedSite = $sites[0];
        }

        $currentStep = $task->getStepCode();
        if ($currentStep === 'initial_analysis') {
            $task->setStepCode(TicketTask::STEP_FH_ETUDE_PREREQUIS);
            $task->setTitle('Étude des prérequis FH');
            $task->setDescription('Compléter l\'étude des prérequis transmission FH');
            $this->entityManager->flush();
            $currentStep = TicketTask::STEP_FH_ETUDE_PREREQUIS;
            $this->addFlash('info', 'La tâche a été mise à jour pour le workflow FH.');
        }

        $fhFields = $task->getFhFields() ?? [];
        $history = $ticket->getHistory()->toArray();

        $previousTasks = $ticket->getTasks()->filter(function($t) use ($task) {
            return $t->getStepOrder() < $task->getStepOrder() && $t->getStatus() === TicketTask::STATUS_DONE;
        });

        $siteType = $selectedSite ? strtoupper($selectedSite->getTypeTrans() ?? '') : null;

        return $this->render('dashboard/user/fh/show.html.twig', [
            'task' => $task,
            'history' => $history,
            'sites' => $sites,
            'selectedSite' => $selectedSite,
            'totalSites' => $totalSites,
            'processedSites' => $processedCount,
            'siteDecisions' => $siteDecisions,
            'processedSiteIds' => $processedSiteIds,
            'canEdit' => $task->getAssignedTo() === $this->getUser() || $this->isGranted('ROLE_SUPERUSER'),
            'user' => $this->getUser(),
            'currentStep' => $currentStep,
            'fhFields' => $fhFields,
            'previousTasks' => $previousTasks,
            'siteType' => $siteType,
        ]);
    }

    #[Route('/task/{id}/start', name: 'user_fh_task_start', methods: ['GET'])]
    public function startTask(TicketTask $task): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');

        /** @var User $user */
        $user = $this->getUser();

        if ($task->getAssignedTo() !== $user) {
            throw $this->createAccessDeniedException('Vous n\'êtes pas assigné à cette tâche.');
        }

        if ($task->getStatus() !== TicketTask::STATUS_PENDING) {
            $this->addFlash('error', 'Seules les tâches en attente peuvent être démarrées.');
            return $this->redirectToRoute('user_fh_tasks');
        }

        $task->setStatus(TicketTask::STATUS_IN_PROGRESS);
        $task->setStartedAt(new \DateTime());
        $task->setUpdatedAt(new \DateTime());

        $this->entityManager->flush();

        $this->addFlash('success', 'La tâche a été démarrée.');
        return $this->redirectToRoute('user_fh_task_show', ['id' => $task->getId()]);
    }

    #[Route('/task/{id}/site/{siteId}/complete', name: 'user_fh_site_complete', methods: ['POST'])]
    public function completeSite(TicketTask $task, int $siteId, Request $request): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');

        /** @var User $user */
        $user = $this->getUser();
        if ($task->getAssignedTo() !== $user) {
            throw $this->createAccessDeniedException('Vous n\'êtes pas assigné à cette tâche.');
        }

        $ticket = $task->getTicket();
        $ticketSite = null;
        foreach ($this->getSitesForTask($task) as $ts) {
            if ($ts->getId() === $siteId) {
                $ticketSite = $ts;
                break;
            }
        }
        if (!$ticketSite) {
            $this->addFlash('error', 'Site introuvable.');
            return $this->redirectToRoute('user_fh_task_show', ['id' => $task->getId()]);
        }

        if (!$task->getTicketSite()) {
            $task->setTicketSite($ticketSite);
        }

        $siteDecisions = $task->getSiteDecisions() ?? [];
        if (isset($siteDecisions[$siteId])) {
            $this->addFlash('warning', 'Ce site a déjà été traité pour cette tâche.');
            return $this->redirectToRoute('user_fh_task_show', ['id' => $task->getId()]);
        }

        $formData = $request->request->all();
        $formData = array_filter($formData, fn($v) => $v !== '' && $v !== null);

        $decision = $request->request->get('faisabilite_ok') ?: $request->request->get('decision', 'OK');

        $siteDecisions[$siteId] = [
            'status' => 'completed',
            'completed_at' => (new \DateTime())->format('Y-m-d H:i:s'),
            'form_data' => $formData,
            'decision' => $decision,
        ];
        $task->setSiteDecisions($siteDecisions);

        $existingFhFields = $task->getFhFields() ?? [];
        $task->setFhFields(array_merge($existingFhFields, $formData));

        $allDone = true;
        foreach ($this->getSitesForTask($task) as $ts) {
            if (!isset($siteDecisions[$ts->getId()])) {
                $allDone = false;
                break;
            }
        }

        if ($allDone) {
            $task->setStatus(TicketTask::STATUS_DONE);
            $task->setCompletedAt(new \DateTime());

            // Rediriger l'étape capillaire vers FoWorkflowService
            if ($task->getStepCode() === TicketTask::STEP_FO_CAPILLAIRE_STUDY) {
                $this->foWorkflowService->completeFoTask($task, $decision, 'capillaire_study', $user);
            } else {
                $this->fhWorkflowService->processFhTask($task, $decision, $formData, $user);
            }
        } else {
            $task->setStatus(TicketTask::STATUS_IN_PROGRESS);
            $this->ticketWorkflowService->addHistory($ticket, $user, 'site_processed', 'Site ' . $ticketSite->getSiteName() . ' traité.');
        }

        $this->entityManager->flush();
        $this->ticketWorkflowService->refreshTicketProgress($ticket);

        $this->addFlash('success', 'Site traité avec succès.');
        return $this->redirectToRoute('user_fh_task_show', ['id' => $task->getId()]);
    }

    /**
     * Retourne la liste des sites pour une tâche donnée.
     * Cette méthode est définie une seule fois.
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

        $siteIds = $task->getSiteData() ?? [];
        if ($siteIds === []) {
            return $ticket->getTicketSites()->toArray();
        }

        return array_values(array_filter(
            $ticket->getTicketSites()->toArray(),
            fn($site) => in_array($site->getId(), $siteIds, true)
        ));
    }
}