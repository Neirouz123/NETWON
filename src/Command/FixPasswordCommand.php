<?php

namespace App\Command;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(name: 'app:fix-password', description: 'Reset a user password to fix hashing')]
class FixPasswordCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserPasswordHasherInterface $passwordHasher
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $user = $this->entityManager->getRepository(User::class)->findOneBy(['username' => 'controller']);
        if (!$user) {
            $output->writeln('<error>User controller not found</error>');
            return Command::FAILURE;
        }

        $hashedPassword = $this->passwordHasher->hashPassword($user, 'controller1234');
        $user->setPassword($hashedPassword);
        $this->entityManager->flush();

        $output->writeln('<info>Password re-hashed for controller user</info>');
        return Command::SUCCESS;
    }
}
