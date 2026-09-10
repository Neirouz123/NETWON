<?php

namespace App\Controller;

use App\Entity\Notification;
use App\Entity\Ticket;
use App\Entity\TicketComment;
use App\Entity\TicketTask;
use App\Entity\User;
use App\Entity\Service;
use App\Repository\ServiceRepository;
use App\Repository\UserRepository;
use App\Service\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;

trait WorkflowControllerTrait
{
    protected function saveComment(
        Ticket $ticket,
        FormInterface $form,
        Request $request,
        EntityManagerInterface $em,
        NotificationService $notificationService
    ): void {
        if (! $form->isSubmitted() || ! $form->isValid()) {
            return;
        }

        /** @var User $user */
        $user = $this->getUser();
        $data = $form->getData();

        $comment = new TicketComment();
        $comment->setTicket($ticket);
        $comment->setUser($user);
        $comment->setMessage($data['message'] ?? '');

        $file = $form->get('filePath')->getData();
        if ($file instanceof UploadedFile) {
            $filename = uniqid('comment_', true) . '.' . $file->guessExtension();
            $file->move($this->getParameter('kernel.project_dir') . '/public/uploads/ticket_proofs', $filename);
            $comment->setFilePath($filename);
        }

        $em->persist($comment);
        $em->flush();

        $notificationService->notify(
            $ticket->getCreatedBy(),
            Notification::TYPE_TICKET_STATUS_CHANGED,
            sprintf('Un nouveau commentaire a été ajouté au ticket #%d.', $ticket->getId()),
            $ticket
        );
    }

    protected function saveCommentFromRequest(
        Ticket $ticket,
        Request $request,
        EntityManagerInterface $em,
        NotificationService $notificationService
    ): void {
        $message = trim((string) $request->request->get('comment', ''));
        if ($message === '') {
            return;
        }

        /** @var User $user */
        $user = $this->getUser();
        $comment = new TicketComment();
        $comment->setTicket($ticket);
        $comment->setUser($user);
        $comment->setMessage($message);

        $file = $request->files->get('comment_file');
        if ($file instanceof UploadedFile) {
            $filename = uniqid('comment_', true) . '.' . $file->guessExtension();
            $file->move($this->getParameter('kernel.project_dir') . '/public/uploads/comments', $filename);
            $comment->setFilePath($filename);
        }

        $em->persist($comment);
        $em->flush();

        $notificationService->notify(
            $ticket->getCreatedBy(),
            Notification::TYPE_TICKET_STATUS_CHANGED,
            sprintf('Un nouveau commentaire a été ajouté au ticket #%d.', $ticket->getId()),
            $ticket
        );
    }

    protected function handleFileUpload(UploadedFile $file, string $directory): string
    {
        $filename = uniqid('upload_', true) . '.' . $file->guessExtension();
        $file->move($directory, $filename);
        return $filename;
    }

    protected function getUsersByRole(string $role, UserRepository $userRepository): array
    {
        return array_filter($userRepository->findAll(), fn(User $user) => in_array($role, $user->getRoles(), true));
    }

    protected function getServiceChoices(ServiceRepository $serviceRepository): array
    {
        $services = $serviceRepository->findAllOrdered();
        $choices = [];
        foreach ($services as $service) {
            if ($service instanceof Service) {
                $choices[$service->getName()] = $service->getName();
            }
        }
        return $choices;
    }
}
