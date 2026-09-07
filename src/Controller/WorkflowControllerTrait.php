<?php

namespace App\Controller;

use App\Entity\Ticket;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/user/workflow')]
class UserWorkflowController extends AbstractController
{
    #[Route('/{id}', name: 'user_workflow_show')]
    public function show(Ticket $ticket): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');

        /** @var \App\Entity\User $user */
        $user = $this->getUser();

        // Vérifier que l'utilisateur est assigné à au moins une tâche du ticket
        $isAssigned = false;
        foreach ($ticket->getTasks() as $task) {
            if ($task->getAssignedTo() && $task->getAssignedTo()->getId() === $user->getId()) {
                $isAssigned = true;
                break;
            }
        }

        if (!$isAssigned) {
            throw $this->createAccessDeniedException('Vous n\'êtes pas autorisé à voir ce ticket.');
        }

        return $this->render('dashboard/user/workflow/show.html.twig', [
            'ticket' => $ticket,
        ]);
    }
}