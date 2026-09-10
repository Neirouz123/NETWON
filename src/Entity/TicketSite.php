<?php
// src/Entity/TicketSite.php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class TicketSite
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_VALIDATED = 'validated';
    public const STATUS_SUPERVISION = 'supervision';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_BLOCKED = 'blocked';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'ticketSites')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Ticket $ticket = null;

    #[ORM\Column(length: 255)]
    private ?string $siteName = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $typeTrans = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $serviceName = null;

    #[ORM\Column(length: 20, options: ['default' => 'pending'])]
    private string $status = 'pending';

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $actionType = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $comment = null;

    // === Ajouts pour le suivi individuel du site dans sa chaîne d'étapes ===

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $currentStepCode = null;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $currentStepIndex = 0;

    #[ORM\Column(type: 'integer', options: ['default' => 1])]
    private int $totalSteps = 1;

    #[ORM\OneToMany(mappedBy: 'ticketSite', targetEntity: TicketTask::class)]
    private Collection $tasks;

    public function __construct()
    {
        $this->tasks = new ArrayCollection();
    }

    public function getActionType(): ?string { return $this->actionType; }
    public function setActionType(?string $actionType): self { $this->actionType = $actionType; return $this; }

    public function getComment(): ?string { return $this->comment; }
    public function setComment(?string $comment): self { $this->comment = $comment; return $this; }

    public function getId(): ?int { return $this->id; }

    public function getTicket(): ?Ticket { return $this->ticket; }
    public function setTicket(?Ticket $ticket): static { $this->ticket = $ticket; return $this; }

    public function getSiteName(): ?string { return $this->siteName; }
    public function setSiteName(string $siteName): static { $this->siteName = $siteName; return $this; }

    public function getTypeTrans(): ?string { return $this->typeTrans; }
    public function setTypeTrans(?string $typeTrans): static { $this->typeTrans = $typeTrans; return $this; }

    public function getServiceName(): ?string { return $this->serviceName; }
    public function setServiceName(?string $serviceName): static { $this->serviceName = $serviceName; return $this; }

    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): static { $this->status = $status; return $this; }

    public function getCurrentStepCode(): ?string { return $this->currentStepCode; }
    public function setCurrentStepCode(?string $v): static { $this->currentStepCode = $v; return $this; }

    public function getCurrentStepIndex(): int { return $this->currentStepIndex; }
    public function setCurrentStepIndex(int $v): static { $this->currentStepIndex = $v; return $this; }

    public function getTotalSteps(): int { return $this->totalSteps; }
    public function setTotalSteps(int $v): static { $this->totalSteps = $v; return $this; }

    public function getTasks(): Collection { return $this->tasks; }

    public function getProgressPercent(): int
    {
        if ($this->status === self::STATUS_COMPLETED || $this->status === self::STATUS_VALIDATED) {
            return 100;
        }
        if ($this->status === self::STATUS_REJECTED) {
            return 100; // terminal, ne bloque pas la moyenne du workflow
        }
        if ($this->totalSteps <= 0) {
            return 0;
        }
        return (int) round(($this->currentStepIndex / $this->totalSteps) * 100);
    }
}