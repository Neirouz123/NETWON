<?php
// src/Controller/SuperuserWorkflowController.php

namespace App\Controller;

use App\Service\WorkflowAutoAssigner;
use App\Entity\Ticket;
use App\Entity\TicketComment;
use App\Entity\TicketSite;
use App\Entity\TicketTask;
use App\Entity\User;
use App\Form\SuperuserTicketType;
use App\Repository\ProcessedSiteRepository;
use App\Repository\TicketRepository;
use App\Service\NotificationService;
use App\Service\TicketWorkflowService;
use App\Service\WorkflowEngineService;
use Doctrine\ORM\EntityManagerInterface;
use Dompdf\Dompdf;
use Dompdf\Options;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/dashboard/superuser/workflow')]
class SuperuserWorkflowController extends AbstractController
{
    #[Route('/', name: 'superuser_workflow_index', methods: ['GET'])]
    public function index(Request $request, TicketRepository $ticketRepository): Response
    {
        $this->denyAccessUnlessGranted('ROLE_SUPERUSER');
        $status = $request->query->get('status');
        $search = trim((string) $request->query->get('search', ''));
        $allowedStatuses = ['open', 'in_progress', 'completed', 'closed', 'blocked'];
        if ($status && !in_array($status, $allowedStatuses, true)) $status = null;
        $tickets = $ticketRepository->findByStatusOrdered($status, $search);
        return $this->render('dashboard/superuser/workflow/index.html.twig', [
            'tickets' => $tickets,
            'currentStatus' => $status,
            'currentSearch' => $search,
        ]);
    }

    #[Route('/ticket/{id}', name: 'superuser_workflow_show', methods: ['GET'])]
    public function show(Ticket $ticket, ProcessedSiteRepository $processedSiteRepository): Response
    {
        $this->denyAccessUnlessGranted('ROLE_SUPERUSER');
        $siteDetails = [];
        foreach ($ticket->getTicketSites() as $ticketSite) {
            $siteName = $ticketSite->getSiteName();
            $processedSite = $processedSiteRepository->findOneBy(['siteName' => $siteName]);
            if ($processedSite) {
                $siteDetails[$siteName] = $processedSite;
            }
        }

        $canValidate = ($ticket->getStatus() === 'waiting_superuser');

        $validationTask = null;
        foreach ($ticket->getTasks() as $task) {
            if ($task->getStepCode() === TicketTask::STEP_SUPERUSER_VALIDATION && $task->getStatus() !== 'done') {
                $validationTask = $task;
                break;
            }
        }

        $blockerInfo = $this->getBlockerInfo($ticket);

        return $this->render('dashboard/superuser/workflow/show.html.twig', [
            'ticket' => $ticket,
            'siteDetails' => $siteDetails,
            'blockerInfo' => $blockerInfo,
            'canValidate' => $canValidate,
            'validationTask' => $validationTask,
        ]);
    }

    #[Route('/new', name: 'superuser_workflow_new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        EntityManagerInterface $em,
        TicketWorkflowService $ticketWorkflowService,
        ProcessedSiteRepository $processedSiteRepository,
        NotificationService $notificationService,
        WorkflowAutoAssigner $autoAssigner
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_SUPERUSER');

        $ticket = new Ticket();
        $form = $this->createForm(SuperuserTicketType::class, $ticket);
        $form->handleRequest($request);

        /** @var User $currentUser */
        $currentUser = $this->getUser();
        $availableSites = $processedSiteRepository->findLatestSites(null, 5000);

        if ($form->isSubmitted()) {
            if (!$form->isValid()) {
                foreach ($form->getErrors(true) as $error) {
                    $this->addFlash('error', $error->getMessage());
                }
            } else {
                $selectedSiteIds = $request->request->all('site_ids');
                if (empty($selectedSiteIds)) {
                    $this->addFlash('error', 'Veuillez choisir au moins un site.');
                    return $this->redirectToRoute('superuser_workflow_new');
                }

                $priority = $form->get('priority')->getData();

                $ticket->setCreatedBy($currentUser);
                $ticket->setStatus('open');
                $ticket->setProgress(0);
                $ticket->setCreatedAt(new \DateTime());
                $ticket->setUpdatedAt(new \DateTime());
                $ticket->setPriority($priority);

                $deadline = $form->get('deadline')->getData();
                if ($deadline) $ticket->setDeadline($deadline);

                $em->persist($ticket);

                // Chaque site reçoit sa propre chaîne de traitement, indépendante
                // du service des autres sites du même workflow.
                $createdTicketSites = [];
                foreach ($selectedSiteIds as $siteId) {
                    $processedSite = $processedSiteRepository->find((int) $siteId);
                    if (!$processedSite) continue;

                    $ticketSite = new TicketSite();
                    $ticketSite->setTicket($ticket);
                    $ticketSite->setSiteName($processedSite->getSiteName());
                    $ticketSite->setTypeTrans($processedSite->getTypeTrans());
                    $ticketSite->setServiceName(strtoupper($processedSite->getServiceName() ?: 'SHARED'));
                    $em->persist($ticketSite);

                    $createdTicketSites[] = $ticketSite;
                }

                if (empty($createdTicketSites)) {
                    $this->addFlash('error', 'Aucun site valide sélectionné.');
                    return $this->redirectToRoute('superuser_workflow_new');
                }

                $assignedUsers = $autoAssigner->assignUsersForTicketSites($createdTicketSites, $ticket, $currentUser);

                $ticketWorkflowService->refreshTicketProgress($ticket);
                $ticketWorkflowService->addHistory(
                    $ticket,
                    $currentUser,
                    'ticket_created',
                    sprintf('Workflow créé avec %d site(s) et %d utilisateur(s) assigné(s).', count($createdTicketSites), count($assignedUsers))
                );

                $em->flush();
                if (!empty($assignedUsers)) {
                    $notificationService->notifyWorkflowAssignment($ticket, $assignedUsers, count($assignedUsers));
                    $em->flush();
                }

                $this->addFlash('success', 'Workflow créé avec succès (assignation auto).');
                return $this->redirectToRoute('superuser_workflow_show', ['id' => $ticket->getId()]);
            }
        }

        return $this->render('dashboard/superuser/workflow/new.html.twig', [
            'form' => $form->createView(),
            'availableSites' => $availableSites,
            'users' => [],
            'availableUsers' => [],
            'currentService' => $request->query->get('service'),
        ]);
    }

    #[Route('/stats', name: 'superuser_workflow_stats', methods: ['GET'])]
    public function stats(Request $request, TicketRepository $ticketRepository): Response
    {
        $this->denyAccessUnlessGranted('ROLE_SUPERUSER');
        $month = (int) $request->query->get('month', date('m'));
        $year = (int) $request->query->get('year', date('Y'));
        $startDate = (new \DateTime())->setDate($year, $month, 1)->setTime(0, 0, 0);
        $endDate = (clone $startDate)->modify('last day of this month')->setTime(23, 59, 59);

        $tickets = $ticketRepository->createQueryBuilder('t')
            ->where('t.createdAt BETWEEN :start AND :end')
            ->setParameter('start', $startDate)
            ->setParameter('end', $endDate)
            ->orderBy('t.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        $totalWorkflows = count($tickets);
        $closedWorkflows = 0;
        $overdueWorkflows = 0;
        $totalSitesProcessed = 0;
        $workflowDetails = [];

        foreach ($tickets as $ticket) {
            if (in_array($ticket->getStatus(), ['completed', 'closed'])) {
                $closedWorkflows++;
            }
            if ($ticket->getDeadline() && $ticket->getDeadline() < new \DateTime() && !in_array($ticket->getStatus(), ['completed', 'closed'])) {
                $overdueWorkflows++;
            }
            $sites = $ticket->getTicketSites();
            $completedSites = 0;
            foreach ($sites as $site) {
                if (in_array($site->getStatus(), ['completed', 'validated'], true)) {
                    $completedSites++;
                }
            }
            $totalSitesProcessed += $completedSites;
            $users = [];
            foreach ($ticket->getTasks() as $task) {
                if ($task->getAssignedTo()) {
                    $users[] = $task->getAssignedTo()->getUsername();
                }
            }
            $users = array_unique($users);
            $workflowDetails[] = [
                'ticket' => $ticket,
                'sites' => $sites,
                'siteCount' => $sites->count(),
                'completedSites' => $completedSites,
                'users' => implode(', ', $users),
                'hasPlanData' => $sites->count() > 0,
            ];
        }

        $stats = [
            'total' => $totalWorkflows,
            'closed' => $closedWorkflows,
            'overdue' => $overdueWorkflows,
            'open' => $totalWorkflows - $closedWorkflows,
            'sitesProcessed' => $totalSitesProcessed,
        ];

        $availableMonths = [];
        for ($m = 1; $m <= 12; $m++) {
            $availableMonths[] = [
                'month' => $m,
                'label' => (new \DateTime())->setDate($year, $m, 1)->format('F'),
            ];
        }

        return $this->render('dashboard/superuser/workflow/stats.html.twig', [
            'tickets' => $tickets,
            'workflowDetails' => $workflowDetails,
            'stats' => $stats,
            'currentMonth' => $month,
            'currentYear' => $year,
            'availableMonths' => $availableMonths,
            'startDate' => $startDate->format('d/m/Y'),
            'endDate' => $endDate->format('d/m/Y'),
            'monthLabel' => (new \DateTime())->setDate($year, $month, 1)->format('F Y'),
        ]);
    }

    #[Route('/export-stats-pdf', name: 'superuser_workflow_stats_export_pdf', methods: ['GET'])]
    public function exportStatsPdf(Request $request, TicketRepository $ticketRepository): Response
    {
        $this->denyAccessUnlessGranted('ROLE_SUPERUSER');
        $month = (int) $request->query->get('month', date('m'));
        $year = (int) $request->query->get('year', date('Y'));
        $start = new \DateTime("$year-$month-01 00:00:00");
        $end = (clone $start)->modify('last day of this month')->setTime(23, 59, 59);

        $stats = $ticketRepository->getMonthlyStats($start, $end);
        $workflows = $ticketRepository->findWorkflowsForMonth($start, $end);

        $totalSites = 0;
        foreach ($workflows as $wf) {
            $totalSites += $wf->getTicketSites()->count();
        }

        $html = $this->renderView('dashboard/superuser/workflow/stats_pdf.html.twig', [
            'stats' => $stats,
            'workflows' => $workflows,
            'totalSites' => $totalSites,
            'month' => $month,
            'year' => $year,
            'start' => $start,
            'end' => $end,
            'monthLabel' => $start->format('F Y'),
        ]);

        $options = new Options();
        $options->set('defaultFont', 'DejaVu Sans');
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();

        return new Response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="rapport_workflows_' . $month . '_' . $year . '.pdf"',
        ]);
    }

    private function getBlockerInfo(Ticket $ticket): ?array
    {
        if (in_array($ticket->getStatus(), ['waiting_superuser', 'closed', 'completed'])) {
            return null;
        }

        if ($ticket->getStatus() === 'waiting_capillaire') {
            return ['service' => 'Ingénierie Capillaire', 'user' => null];
        }
        if ($ticket->getStatus() === 'waiting_swap') {
            return ['service' => 'Ingénierie IP (swap)', 'user' => null];
        }
        if ($ticket->getStatus() === 'waiting_other_service') {
            return ['service' => 'Autre service', 'user' => null];
        }

        foreach ($ticket->getTasks() as $task) {
            if ($task->getStatus() === 'blocked') {
                return [
                    'service' => $task->getServiceName(),
                    'user' => $task->getAssignedTo(),
                    'since' => $task->getUpdatedAt() ?? $task->getCreatedAt()
                ];
            }
        }
        return null;
    }

    #[Route('/ticket/{id}/comment', name: 'superuser_workflow_comment', methods: ['POST'])]
    public function addComment(Ticket $ticket, Request $request, EntityManagerInterface $em, TicketWorkflowService $workflowService): Response
    {
        $this->denyAccessUnlessGranted('ROLE_SUPERUSER');

        $message = trim((string) $request->request->get('message'));
        if (empty($message)) {
            $this->addFlash('error', 'Le commentaire ne peut pas être vide.');
            return $this->redirectToRoute('superuser_workflow_show', ['id' => $ticket->getId()]);
        }

        $comment = new TicketComment();
        $comment->setTicket($ticket);
        $comment->setUser($this->getUser());
        $comment->setMessage($message);

        $uploadedFile = $request->files->get('filePath');
        if ($uploadedFile) {
            $filename = uniqid('ticket_comment_', true) . '.' . $uploadedFile->guessExtension();
            $uploadedFile->move($this->getParameter('ticket_proofs_directory'), $filename);
            $comment->setFilePath($filename);
        }

        $em->persist($comment);
        $workflowService->addHistory($ticket, $this->getUser(), 'comment_added', 'Commentaire ajouté.');
        $em->flush();

        $this->addFlash('success', 'Commentaire ajouté.');
        return $this->redirectToRoute('superuser_workflow_show', ['id' => $ticket->getId()]);
    }
#[Route('/ticket/{id}/site/{siteId}/validate', name: 'superuser_workflow_validate_site', methods: ['POST'])]
    public function validateSite(Ticket $ticket, int $siteId, Request $request, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted('ROLE_SUPERUSER');

        $ticketSite = null;
        foreach ($ticket->getTicketSites() as $ts) {
            if ($ts->getId() === $siteId) {
                $ticketSite = $ts;
                break;
            }
        }
        if (!$ticketSite) {
            $this->addFlash('error', 'Site introuvable.');
            return $this->redirectToRoute('superuser_workflow_show', ['id' => $ticket->getId()]);
        }

        // Trouver la tâche de validation pour ce site
        $validationTask = null;
        foreach ($ticket->getTasks() as $task) {
            if ($task->getTicketSite() && $task->getTicketSite()->getId() === $siteId
                && $task->getStepCode() === TicketTask::STEP_SUPERUSER_VALIDATION
                && $task->getStatus() !== TicketTask::STATUS_DONE) {
                $validationTask = $task;
                break;
            }
        }
        if (!$validationTask) {
            $this->addFlash('error', 'Aucune tâche de validation en attente pour ce site.');
            return $this->redirectToRoute('superuser_workflow_show', ['id' => $ticket->getId()]);
        }

        $comment = $request->request->get('comment');
        $validationTask->setStatus(TicketTask::STATUS_DONE);
        $validationTask->setCompletedAt(new \DateTime());
        $validationTask->setDecision('OK');
        $validationTask->setComment($comment);

        $ticketSite->setStatus(TicketSite::STATUS_VALIDATED);

        $em->flush();

        $this->addFlash('success', 'Site ' . $ticketSite->getSiteName() . ' validé avec succès.');

        // Vérifier si tous les sites sont validés ou rejetés
        $allDone = true;
        foreach ($ticket->getTicketSites() as $ts) {
            if (!in_array($ts->getStatus(), [TicketSite::STATUS_VALIDATED, TicketSite::STATUS_REJECTED])) {
                $allDone = false;
                break;
            }
        }
        if ($allDone) {
            $ticket->setStatus('completed');
            $ticket->setUpdatedAt(new \DateTime());
            $em->flush();
            $this->addFlash('success', 'Tous les sites sont validés, le ticket est terminé.');
        }

        return $this->redirectToRoute('superuser_workflow_show', ['id' => $ticket->getId()]);
    }

    }
