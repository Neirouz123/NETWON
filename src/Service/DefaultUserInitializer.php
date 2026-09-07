// src/Service/DefaultUserInitializer.php
<?php

namespace App\Service;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class DefaultUserInitializer
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserPasswordHasherInterface $passwordHasher,
        private string $defaultControllerUsername,
        private string $defaultControllerPassword
    ) {
    }

    public function initialize(): void
    {
        // Vérifier si un utilisateur avec ROLE_CONTROLLER existe déjà
        $existing = $this->entityManager
            ->getRepository(User::class)
            ->findOneBy(['roles' => ['ROLE_CONTROLLER']]);

        if ($existing !== null) {
            return; // déjà présent, on ne fait rien
        }

        // Créer l'utilisateur par défaut
        $user = new User();
        $user->setUsername($this->defaultControllerUsername);
        $user->setRoles(['ROLE_CONTROLLER', 'ROLE_USER']); // Ajoutez d'autres rôles si nécessaire
        $user->setEmail(null); // ou une adresse par défaut
        $user->setService(null);
        $user->setDepartment(null);

        $hashed = $this->passwordHasher->hashPassword($user, $this->defaultControllerPassword);
        $user->setPassword($hashed);

        $this->entityManager->persist($user);
        $this->entityManager->flush();
    }
}