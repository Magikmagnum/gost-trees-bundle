<?php

declare(strict_types=1);

namespace EricGansa\GhostTreesBundle\Command;

use Doctrine\ORM\EntityManagerInterface;
use EricGansa\GhostTreesBundle\Contract\GhostableInterface;
use EricGansa\GhostTreesBundle\Handler\IncarnateGhostHandler;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'ghosts:incarnate',
    description: 'Incarne un fantôme en racine autonome (matérialise + détache).',
)]
final class IncarnateGhostCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly IncarnateGhostHandler $handler,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('class', InputArgument::REQUIRED, 'FQCN de l\'entité')
            ->addArgument('id', InputArgument::REQUIRED, 'Identifiant de l\'entité');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $class = $input->getArgument('class');
        $id = $input->getArgument('id');

        // Validation préalable du FQCN : em->find() lèverait une exception
        // Doctrine opaque si la classe n'existe pas ou n'est pas une entité.
        if (!class_exists($class)) {
            $io->error(\sprintf('Classe "%s" introuvable. Vérifiez le FQCN (ex: "App\\Entity\\Trajet").', $class));

            return Command::FAILURE;
        }

        $entity = $this->em->find($class, $id);

        if (null === $entity) {
            $io->error(\sprintf('Aucune entité %s avec id=%s.', $class, $id));

            return Command::FAILURE;
        }

        if (!$entity instanceof GhostableInterface) {
            $io->error(\sprintf('"%s" n\'implémente pas GhostableInterface.', $class));

            return Command::FAILURE;
        }

        if (!$entity->isGhost()) {
            $io->warning('Cette entité est déjà une racine. Rien à incarner.');

            return Command::SUCCESS;
        }

        if (!$io->confirm(\sprintf(
            'Confirmer l\'incarnation de %s #%s ? Cette opération matérialise toutes les valeurs héritées et détache le lien parent.',
            $class,
            $id,
        ), false)) {
            $io->writeln('Annulé.');

            return Command::SUCCESS;
        }

        $this->handler->handle($entity);

        $io->success(\sprintf('Entité %s #%s incarnée avec succès.', $class, $id));

        return Command::SUCCESS;
    }
}
