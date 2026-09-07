<?php
// src/Controller/UserSharedController.php

namespace App\Controller;

use App\Entity\TicketTask;
use App\Entity\User;
use App\Repository\TicketTaskRepository;
use App\Service\FoWorkflowService;
use App\Service\NotificationService;
use App\Service\TicketWorkflowService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Attribute\Route;
use Psr\Log\LoggerInterface;

#[Route('/user/shared')]
class UserSharedController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private TicketTaskRepository $taskRepo,
        private TicketWorkflowService $ticketWorkflowService,
        private NotificationService $notificationService,
        private MailerInterface $mailer,
        private LoggerInterface $logger,
        private FoWorkflowService $foWorkflowService,
    ) {}

    #[Route('/tasks', name: 'user_shared_tasks', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        /** @var User $user */
        $user = $this->getUser();

        if (strtoupper($user->getService() ?? '') !== 'SHARED') {
            return $this->redirectToRoute('user_tasks_dashboard');
        }

        $search = trim((string) $request->query->get('search', ''));
        $status = (string) $request->query->get('status', '');

        $qb = $this->taskRepo->createQueryBuilder('t')
            ->leftJoin('t.ticket', 'tk')->addSelect('tk')
            ->where('t.assignedTo = :user')
            ->setParameter('user', $user)
            ->orderBy('t.createdAt', 'DESC');

        if ($search) {
            $qb->andWhere('LOWER(t.title) LIKE :search OR LOWER(tk.title) LIKE :search')
               ->setParameter('search', '%' . mb_strtolower($search) . '%');
        }
        if ($status) {
            $qb->andWhere('t.status = :status')
               ->setParameter('status', $status);
        }

        $tasks = $qb->getQuery()->getResult();

        $total = count($tasks);
        $pending = count(array_filter($tasks, fn(TicketTask $t) => $t->getStatus() === TicketTask::STATUS_PENDING));
        $inProgress = count(array_filter($tasks, fn(TicketTask $t) => $t->getStatus() === TicketTask::STATUS_IN_PROGRESS));
        $blocked = count(array_filter($tasks, fn(TicketTask $t) => $t->getStatus() === TicketTask::STATUS_BLOCKED));
        $done = count(array_filter($tasks, fn(TicketTask $t) => $t->isDone()));

        $taskSitesMap = [];
        foreach ($tasks as $task) {
            $taskSitesMap[$task->getId()] = $this->resolveSitesFromSiteData($task->getSiteData());
        }

        return $this->render('dashboard/user/shared/index.html.twig', [
            'tasks' => $tasks,
            'taskSitesMap' => $taskSitesMap,
            'total' => $total,
            'pending' => $pending,
            'inProgress' => $inProgress,
            'blocked' => $blocked,
            'done' => $done,
            'searchQuery' => $search,
            'currentStatus' => $status,
        ]);
    }

#[Route('/task/{id}', name: 'user_shared_task_show', methods: ['GET'])]
public function show(TicketTask $task): Response
{
    $this->denyAccessUnlessGranted('ROLE_USER');
    /** @var User $user */
    $user = $this->getUser();

    if ($task->getAssignedTo()?->getId() !== $user->getId()) {
        throw $this->createAccessDeniedException('Vous n\'êtes pas assigné à cette tâche.');
    }

    $ticket = $task->getTicket();
    $sites = $this->getSitesForTask($task);
    $siteNames = array_map(fn($s) => $s->getSiteName(), $sites);

    // Filtrer l'historique pour ne garder que les entrées concernant les sites de la tâche
    $allHistory = $ticket->getHistory()->toArray();
    $filteredHistory = array_filter($allHistory, function ($entry) use ($siteNames) {
        // Si l'entrée a un site, on vérifie qu'il est dans la liste des sites de la tâche
        if ($entry->getSite()) {
            return in_array($entry->getSite(), $siteNames);
        }
        // Si l'entrée n'a pas de site, on la garde (considérée comme générale)
        return true;
    });

    return $this->render('dashboard/user/shared/show.html.twig', [
        'task' => $task,
        'ticket' => $ticket,
        'sites' => $sites,
        'history' => array_values($filteredHistory), // réindexer
    ]);
}

/**
 * Retourne la liste des sites concernés par cette tâche.
 */
// src/Controller/UserSharedController.php

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
    if (!empty($siteIds)) {
        return array_values(array_filter(
            $ticket->getTicketSites()->toArray(),
            fn($site) => in_array($site->getId(), $siteIds, true)
        ));
    }
    return $ticket->getTicketSites()->toArray();
}

#[Route('/task/{id}/complete', name: 'user_shared_task_complete', methods: ['POST'])]
    public function complete(TicketTask $task, Request $request): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        /** @var User $user */
        $user = $this->getUser();

        if ($task->getAssignedTo()?->getId() !== $user->getId()) {
            throw $this->createAccessDeniedException('Vous n\'êtes pas assigné à cette tâche.');
        }

        $intervenantEmail = trim((string) $request->request->get('intervenant_email'));
        $siteEtat = trim((string) $request->request->get('site_etat'));
        $actionProposee = trim((string) $request->request->get('action_proposee'));

        if (empty($intervenantEmail) || empty($siteEtat) || empty($actionProposee)) {
            $this->addFlash('error', 'Tous les champs sont obligatoires.');
            return $this->redirectToRoute('user_shared_task_show', ['id' => $task->getId()]);
        }

        $ticket = $task->getTicket();

        // 1. Terminer la tâche
        $task->setStatus(TicketTask::STATUS_DONE);
        $task->setCompletedAt(new \DateTime());

        // 2. Enregistrer les données
        $existingFields = $task->getFhFields() ?? [];
        $task->setFhFields(array_merge($existingFields, [
            'intervenant_email' => $intervenantEmail,
            'site_etat' => $siteEtat,
            'action_proposee' => $actionProposee,
            'completed_by' => $user->getUsername(),
            'completed_at' => (new \DateTime())->format('Y-m-d H:i:s'),
        ]));

        // 3. Créer la tâche de validation superuser (sans flush pour le moment)
        $this->ticketWorkflowService->refreshTicketProgress($ticket);

        if ($ticket->getStatus() === 'waiting_superuser') {
            $this->foWorkflowService->createSuperuserValidationTask($ticket, $task);
        }

        // 6. Historique
        $this->ticketWorkflowService->addHistory(
            $ticket,
            $user,
            'task_completed_shared',
            "Tâche SHARED terminée par {$user->getUsername()}. Intervenant : $intervenantEmail, État : $siteEtat, Action : $actionProposee"
        );

        // 7. Envoyer l'email à l'intervenant
        $this->sendIntervenantEmail($intervenantEmail, $ticket, $task, $siteEtat, $actionProposee);

        $this->em->flush();

        $this->addFlash('success', $ticket->getStatus() === 'waiting_superuser'
            ? 'La tâche a été terminée. Les superusers peuvent maintenant valider le workflow.'
            : 'La tâche a été terminée. Le workflow reste en cours pour les autres sites ou étapes.');

        return $this->redirectToRoute('user_shared_tasks');
    }

    /**
     * Envoie un email à l'intervenant avec fallback texte.
     */
    private function sendIntervenantEmail(string $email, $ticket, $task, string $siteEtat, string $actionProposee): void
    {
        try {
            $htmlContent = $this->renderView('email/intervenant_notification.html.twig', [
                'ticket' => $ticket,
                'task' => $task,
                'site_etat' => $siteEtat,
                'action_proposee' => $actionProposee,
                'intervenant_email' => $email,
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('Template email introuvable, utilisation du texte brut.', ['error' => $e->getMessage()]);
            $htmlContent = null;
        }

        try {
            $emailMessage = (new Email())
                ->from('no-reply@yourdomain.com')
                ->to($email)
                ->subject('Intervention requise - Ticket #' . $ticket->getId());

            if ($htmlContent) {
                $emailMessage->html($htmlContent);
            } else {
                $text = "Intervention requise pour le ticket #{$ticket->getId()} - {$ticket->getTitle()}\n\n";
                $text .= "État du site : $siteEtat\n";
                $text .= "Action proposée : $actionProposee\n\n";
                $text .= "Cet email vous a été envoyé par {$task->getAssignedTo()?->getUsername()} depuis la plateforme Workflow.";
                $emailMessage->text($text);
            }

            $this->mailer->send($emailMessage);
            $this->logger->info('Email envoyé à l\'intervenant', ['email' => $email]);
        } catch (\Throwable $e) {
            $this->logger->error('Erreur lors de l\'envoi de l\'email à l\'intervenant', ['error' => $e->getMessage()]);
            $this->addFlash('warning', 'L\'email à l\'intervenant n\'a pas pu être envoyé : ' . $e->getMessage());
        }
    }

    private function resolveSitesFromSiteData(?array $siteData): array
    {
        if (!$siteData) {
            return [];
        }
        $sites = [];
        foreach ($siteData as $siteId) {
            if (!is_int($siteId) && !(is_string($siteId) && ctype_digit($siteId))) {
                continue;
            }
            $site = $this->em->getRepository(\App\Entity\TicketSite::class)->find((int) $siteId);
            if ($site) {
                $sites[] = $site;
            }
        }
        return $sites;
    }
}
